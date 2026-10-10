<?php

namespace App\Http\Middleware;

use App\LegalDocumentType;
use App\Models\ImpersonationSession;
use App\Models\LegalAcceptance;
use App\Models\LegalDocument;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Notifications\NotificationFeed;
use Illuminate\Http\Request;
use Inertia\Middleware;
use JsonException;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    public function __construct(private NotificationFeed $feed) {}

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => fn (): array => $this->authProps($request),
            'workSession' => fn (): ?array => $this->workSessionProps($request),
            'impersonation' => fn (): ?array => $this->impersonationProps($request),
            'legal' => fn (): array => $this->legalProps($request),
            'currentWorkspace' => fn (): ?array => $this->currentWorkspaceProps($request),
            'assistant' => fn (): ?array => $this->assistantProps($request),
            'notifications' => fn (): ?array => $this->notificationProps($request),
            'flash' => [
                'status' => fn (): ?string => $request->session()->get('status'),
                'success' => fn (): ?string => $request->session()->get('success'),
                'error' => fn (): ?string => $request->session()->get('error'),
            ],
        ];
    }

    /**
     * What the visitor still needs to be asked about the legal documents.
     *
     * `cookie_consent_required` is true until they answer, and true again when a
     * newer cookie policy is published — consent given against an older text is
     * not consent to the new one.
     *
     * @return array{cookie_consent_required: bool, cookie_policy_version: int, terms_reacceptance_required: bool, links: list<array{label: string, slug: string}>}
     */
    private function legalProps(Request $request): array
    {
        $cookiePolicy = LegalDocument::current(LegalDocumentType::Cookies);
        $cookieVersion = $cookiePolicy->version ?? 0;

        return [
            'cookie_consent_required' => $cookiePolicy !== null
                && $this->consentedCookieVersion($request) < $cookieVersion,
            'cookie_policy_version' => $cookieVersion,
            'terms_reacceptance_required' => $this->termsReacceptanceRequired($request),
            'links' => array_map(
                fn (LegalDocumentType $type): array => [
                    'label' => $type->label(),
                    'slug' => $type->slug(),
                ],
                LegalDocumentType::ordered(),
            ),
        ];
    }

    /** The cookie policy version the visitor last answered against. */
    private function consentedCookieVersion(Request $request): int
    {
        $raw = $request->cookie('cookie_consent');

        if (! is_string($raw)) {
            return 0;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // A malformed cookie is treated as never having answered, which
            // asks again rather than assuming consent.
            return 0;
        }

        return is_array($decoded) && is_int($decoded['version'] ?? null)
            ? $decoded['version']
            : 0;
    }

    private function termsReacceptanceRequired(Request $request): bool
    {
        $user = $request->user();
        $terms = LegalDocument::current(LegalDocumentType::Terms);

        if ($user === null || $terms === null) {
            return false;
        }

        return ! LegalAcceptance::query()
            ->where('user_id', $user->id)
            ->where('type', LegalDocumentType::Terms)
            ->where('version', '>=', $terms->version)
            ->exists();
    }

    /**
     * The live troubleshooting session, if one is running.
     *
     * Present on every page so the banner cannot be escaped by navigating: at
     * no point should support forget whose data is on screen.
     *
     * @return array{subject_name: string, subject_email: string, impersonator_name: string, reason: string, remaining_seconds: int, writes: int}|null
     */
    private function impersonationProps(Request $request): ?array
    {
        $recordId = $request->session()->get((string) config('impersonation.session.record'));

        if (! is_int($recordId)) {
            return null;
        }

        $session = ImpersonationSession::query()
            ->with(['impersonator', 'subject'])
            ->find($recordId);

        if (! $session instanceof ImpersonationSession || $session->hasEnded()) {
            return null;
        }

        return [
            'subject_name' => $session->subject->name,
            'subject_email' => $session->subject->email,
            'impersonator_name' => $session->impersonator->name,
            'reason' => $session->reason,
            'remaining_seconds' => $session->remainingSeconds(),
            'writes' => $session->write_count,
        ];
    }

    /**
     * The remaining work block, as seconds rather than an absolute time.
     *
     * Sending a relative value lets the client anchor the countdown to its own
     * clock, so a browser whose time is wrong still counts down correctly. It
     * is recomputed on every Inertia response, which is why a refresh shows the
     * real remaining time instead of starting over.
     *
     * @return array{enabled: bool, total_seconds: int, remaining_seconds: int, warning_seconds: int}|null
     */
    private function workSessionProps(Request $request): ?array
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        $minutes = max(
            (int) config('work_session.min_minutes'),
            min(
                $user->work_session_minutes ?? (int) config('work_session.default_minutes'),
                (int) config('work_session.max_minutes'),
            ),
        );
        $total = $minutes * 60;
        $startedAt = $request->session()->get((string) config('work_session.started_at_key'));
        $elapsed = 0;

        if (is_int($startedAt)) {
            $elapsed = max(0, now()->getTimestamp() - $startedAt);
        }
        $warning = min((int) config('work_session.warning_seconds'), $total - 10);

        return [
            'enabled' => (bool) config('work_session.enabled'),
            'total_seconds' => $total,
            'remaining_seconds' => max(0, $total - $elapsed),
            'warning_seconds' => max(10, $warning),
        ];
    }

    /**
     * @return array{
     *     user: array{
     *         id: int,
     *         name: string,
     *         email: string,
     *         email_verified_at: string|null,
     *         two_factor_enabled: bool,
     *         is_support_staff: bool
     *     }|null,
     *     workspaces: list<array{
     *         public_id: string,
     *         name: string,
     *         role: string,
     *         role_label: string,
     *         current: bool
     *     }>
     * }
     */
    private function authProps(Request $request): array
    {
        $user = $request->user();

        if ($user === null) {
            return [
                'user' => null,
                'workspaces' => [],
            ];
        }

        $memberships = $user->workspaceMemberships()
            ->with('workspace')
            ->where('is_active', true)
            ->oldest('id')
            ->get();

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'two_factor_enabled' => $user->hasEnabledTwoFactorAuthentication(),
                'is_support_staff' => $user->isSupportStaff(),
            ],
            'workspaces' => array_values($memberships->map(fn (WorkspaceMembership $membership): array => [
                'public_id' => $membership->workspace->public_id,
                'name' => $membership->workspace->name,
                'role' => $membership->role->value,
                'role_label' => $membership->role->label(),
                'current' => $membership->workspace_id === $user->current_workspace_id,
            ])->all()),
        ];
    }

    /**
     * The bell's contents: how many are unread, and the newest few.
     *
     * Shared on every response rather than fetched by the header, so a
     * notification raised by the action you just took is visible the moment
     * that action's redirect lands.
     *
     * @return array{unread: int, recent: list<array<string, mixed>>}|null
     */
    private function notificationProps(Request $request): ?array
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return null;
        }

        $workspace = $request->attributes->get('currentWorkspace');
        $workspaceId = $workspace instanceof Workspace ? $workspace->id : null;

        return [
            'unread' => $this->feed->unreadCount($user, $workspaceId),
            'recent' => $this->feed->recent($user, $workspaceId),
        ];
    }

    /**
     * Where the navigation sends someone to the read-only assistant.
     *
     * Null unless the assistant is switched on and the company they are working
     * in has a legal entity, so the entry never appears for a page that would
     * only answer 404. The assistant has no other environment than production.
     *
     * @return array{url: string}|null
     */
    private function assistantProps(Request $request): ?array
    {
        if (config('assistant.enabled') !== true) {
            return null;
        }

        // The assistant names its company in its own address instead of using the current one,
        // so on that page the entry keeps pointing at the page being shown.
        if ($request->routeIs('assistant.show')) {
            return ['url' => '/'.ltrim($request->path(), '/')];
        }

        $workspace = $this->currentWorkspaceProps($request);
        $legalEntity = $workspace['legal_entity'] ?? null;

        if (! is_array($workspace) || ! is_array($legalEntity)) {
            return null;
        }

        return [
            'url' => route('assistant.show', [
                'workspacePublicId' => $workspace['public_id'],
                'entityPublicId' => $legalEntity['public_id'],
                'environment' => 'production',
            ], false),
        ];
    }

    /**
     * Resolved once per request: the assistant entry reads the same company
     * the header does, and neither should pay for the lookup twice.
     *
     * @return array<string, mixed>|null
     */
    private function currentWorkspaceProps(Request $request): ?array
    {
        $key = 'inertia.current_workspace_props';

        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, $this->resolveCurrentWorkspaceProps($request));
        }

        return $request->attributes->get($key);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveCurrentWorkspaceProps(Request $request): ?array
    {
        $workspace = $request->attributes->get('currentWorkspace');
        $membership = $request->attributes->get('currentWorkspaceMembership');

        if (! $workspace instanceof Workspace || ! $membership instanceof WorkspaceMembership) {
            return null;
        }

        $legalEntity = $workspace->legalEntities()
            ->with(['establishments' => fn ($query) => $query->orderByDesc('is_head_office')->oldest('id')])
            ->oldest('id')
            ->first();
        $establishment = $legalEntity?->establishments->firstWhere('is_head_office', true)
            ?? $legalEntity?->establishments->first();

        return [
            'public_id' => $workspace->public_id,
            'name' => $workspace->name,
            'role' => $membership->role->value,
            'role_label' => $membership->role->label(),
            'requires_mfa' => $membership->role->requiresMultiFactorAuthentication(),
            'legal_entity' => $legalEntity === null ? null : [
                'public_id' => $legalEntity->public_id,
                'legal_name' => $legalEntity->legal_name,
                'trade_name' => $legalEntity->trade_name,
                'masked_nif' => $this->maskTaxIdentificationNumber($legalEntity->tax_identification_number),
                'status' => $legalEntity->status->value,
                'status_label' => $legalEntity->status->label(),
                'establishment_name' => $establishment?->name,
            ],
        ];
    }

    private function maskTaxIdentificationNumber(?string $taxIdentificationNumber): ?string
    {
        if ($taxIdentificationNumber === null || mb_strlen($taxIdentificationNumber) < 5) {
            return $taxIdentificationNumber;
        }

        $visiblePrefix = mb_substr($taxIdentificationNumber, 0, 3);
        $visibleSuffix = mb_substr($taxIdentificationNumber, -2);

        return $visiblePrefix.str_repeat('•', max(3, mb_strlen($taxIdentificationNumber) - 5)).$visibleSuffix;
    }
}
