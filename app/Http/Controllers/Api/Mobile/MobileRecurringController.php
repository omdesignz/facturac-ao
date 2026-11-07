<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Actions\ConfirmMobileIssuer;
use App\Actions\GenerateRecurringInvoices;
use App\Actions\RecurringRunRefused;
use App\Billing\SubscriptionAccess;
use App\Fiscal\Documents\MobileDocumentReceipt;
use App\Fiscal\Documents\MobileRecurringPresenter;
use App\Models\Customer;
use App\Models\LegalEntity;
use App\Models\RecurringInvoice;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\SubscriptionFeature;
use App\WorkspaceRole;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Avenças from the phone: read them, and on the eve of a run skip it this once
 * or raise it now, as the web's "Gerar agora" does. Setting them up and
 * changing them stays on the web.
 */
class MobileRecurringController
{
    public function __construct(private readonly MobileRecurringPresenter $presenter) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $profiles = RecurringInvoice::query()
            ->with(['customer', 'legalEntity'])
            ->where('legal_entity_id', $this->legalEntity($request)->id)
            ->orderByDesc('is_active')
            ->orderBy('next_run_on')
            ->get();

        return response()->json(['data' => array_values($profiles
            ->map(fn (RecurringInvoice $profile): array => $this->presenter->row($profile))
            ->all())]);
    }

    public function show(Request $request, string $profilePublicId): JsonResponse
    {
        Gate::authorize('viewAny', Customer::class);

        return $this->answer($request, $this->profile($request, $profilePublicId));
    }

    public function skip(Request $request, string $profilePublicId, GenerateRecurringInvoices $recurring): JsonResponse
    {
        $profile = $this->profile($request, $profilePublicId);
        $validated = $request->validate(['next_run_on' => ['required', 'date_format:Y-m-d']]);

        if ($refusal = $this->actingRefusal($request)) {
            return $refusal;
        }

        try {
            $result = $recurring->skip($profile, $this->user($request), CarbonImmutable::parse((string) $validated['next_run_on']));
        } catch (RecurringRunRefused $refused) {
            return $this->refusal($refused->reason, $refused->getMessage(), Response::HTTP_CONFLICT);
        }

        return $this->answer($request, $result['profile']->fresh() ?? $profile);
    }

    public function run(
        Request $request,
        string $profilePublicId,
        GenerateRecurringInvoices $recurring,
        ConfirmMobileIssuer $issuer,
        MobileDocumentReceipt $receipt,
    ): JsonResponse {
        $profile = $this->profile($request, $profilePublicId);
        $user = $this->user($request);

        $validated = $request->validate([
            'next_run_on' => ['required', 'date_format:Y-m-d'],
            'password' => [Rule::requiredIf(fn (): bool => $profile->auto_issue && ! $request->has('passkey')), 'nullable', 'string', 'max:255'],
            'passkey' => ['nullable', 'array:challenge_id,credential'],
            'passkey.challenge_id' => ['required_with:passkey', 'string', 'max:64'],
            'passkey.credential' => ['required_with:passkey', 'array'],
        ], ['password.required' => 'Confirme a palavra-passe: esta avença emite sozinha.']);

        if ($refusal = $this->actingRefusal($request)) {
            return $refusal;
        }

        // A run that issues files with the AGT, as issuing from the phone always does.
        if ($profile->auto_issue) {
            if (! $user->hasEnabledTwoFactorAuthentication()) {
                return $this->refusal('TWO_FACTOR_REQUIRED_TO_ISSUE', 'Active a autenticação em dois passos em facturac.ao para emitir.', Response::HTTP_FORBIDDEN);
            }

            if ($unconfirmed = $issuer->refusal($user, $validated)) {
                return $this->refusal($unconfirmed['code'], $unconfirmed['message'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        try {
            $result = $recurring->runNow($profile, $user, CarbonImmutable::parse((string) $validated['next_run_on']));
        } catch (RecurringRunRefused $refused) {
            return $this->refusal($refused->reason, $refused->getMessage(), Response::HTTP_CONFLICT);
        }

        return response()->json(
            ['data' => $receipt->forDocument($result['document'], $result['replayed'], $this->legalEntity($request))],
            $result['replayed'] ? Response::HTTP_OK : Response::HTTP_CREATED,
        );
    }

    /** Who may run an avença on the web may skip or run it here; no one else. */
    private function actingRefusal(Request $request): ?JsonResponse
    {
        if (! $this->canAct($request)) {
            return $this->refusal('RECURRING_NOT_ALLOWED', 'A sua função nesta empresa não permite gerir avenças.', Response::HTTP_FORBIDDEN);
        }

        if (! app(SubscriptionAccess::class)->allows($this->legalEntity($request)->workspace_id, SubscriptionFeature::Recurring)) {
            return $this->refusal('RECURRING_NOT_IN_PLAN', 'O plano actual não inclui avenças. Gira o plano em facturac.ao.', Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    /** The same people `GenerateRecurringInvoices::executeFor` lets run them. */
    private function canAct(Request $request): bool
    {
        $user = $this->user($request);

        return Gate::allows('create', Customer::class)
            && $user->hasVerifiedEmail()
            && WorkspaceMembership::query()
                ->where('workspace_id', $this->legalEntity($request)->workspace_id)
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->whereIn('role', [WorkspaceRole::Owner, WorkspaceRole::Administrator, WorkspaceRole::Accountant, WorkspaceRole::Billing])
                ->exists();
    }

    private function answer(Request $request, RecurringInvoice $profile): JsonResponse
    {
        $profile->loadMissing(['customer', 'legalEntity']);

        return response()->json(['data' => $this->presenter->detail($profile, $this->canAct($request))]);
    }

    private function profile(Request $request, string $publicId): RecurringInvoice
    {
        return RecurringInvoice::query()
            ->with(['customer', 'legalEntity'])
            ->where('legal_entity_id', $this->legalEntity($request)->id)
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    private function legalEntity(Request $request): LegalEntity
    {
        /** @var LegalEntity $legalEntity */
        $legalEntity = $request->attributes->get('currentLegalEntity');

        return $legalEntity;
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function refusal(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
