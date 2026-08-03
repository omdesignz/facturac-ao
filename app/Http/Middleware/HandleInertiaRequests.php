<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Http\Request;
use Inertia\Middleware;

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
            'currentWorkspace' => fn (): ?array => $this->currentWorkspaceProps($request),
            'flash' => [
                'status' => fn (): ?string => $request->session()->get('status'),
                'success' => fn (): ?string => $request->session()->get('success'),
                'error' => fn (): ?string => $request->session()->get('error'),
            ],
        ];
    }

    /**
     * @return array{
     *     user: array{
     *         id: int,
     *         name: string,
     *         email: string,
     *         email_verified_at: string|null,
     *         two_factor_enabled: bool
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
     * @return array<string, mixed>|null
     */
    private function currentWorkspaceProps(Request $request): ?array
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
