<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Actions\ConfirmMobileIssuer;
use App\Actions\CreateMobileTransportDocument;
use App\Billing\SubscriptionAccess;
use App\Exceptions\BillingActionRefused;
use App\Exceptions\SubscriptionRequired;
use App\Fiscal\Documents\MobileTransportDocumentPresenter;
use App\Http\Requests\MobileListRequest;
use App\Http\Requests\PreviewMobileTransportDocumentRequest;
use App\Http\Requests\StoreMobileTransportDocumentRequest;
use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\TransportDocument;
use App\Models\User;
use App\SubscriptionFeature;
use App\TransportDocumentType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Transport guides from the phone, under the web's rules and permissions. A guide
 * goes with the goods, away from a desk, so the phone issues it in one step: on
 * the account's two-factor authentication and the password, or a passkey, proved
 * at that moment. There is no draft from the phone; drafts made on the web are
 * listed and finished there.
 */
class MobileTransportDocumentController
{
    public function __construct(
        private readonly MobileTransportDocumentPresenter $presenter,
        private readonly CreateMobileTransportDocument $guides,
        private readonly ConfirmMobileIssuer $issuer,
    ) {}

    /**
     * What the guide form needs, and whether this person may issue at all.
     */
    public function setup(Request $request, SubscriptionAccess $subscription): JsonResponse
    {
        Gate::authorize('viewAny', TransportDocument::class);

        $legalEntity = $this->legalEntity($request);
        $reason = match (true) {
            Gate::denies('create', TransportDocument::class) => 'A sua função nesta empresa não permite emitir guias.',
            ! $subscription->allows($legalEntity->workspace_id, SubscriptionFeature::Transport) => 'O plano actual não inclui guias de transporte. Veja a assinatura em facturac.ao.',
            ! $this->user($request)->hasEnabledTwoFactorAuthentication() => 'Para emitir, active a autenticação em dois passos em facturac.ao.',
            default => null,
        };

        return response()->json(['data' => [
            'can_issue' => $reason === null,
            'reason' => $reason,
            'types' => array_map(fn (TransportDocumentType $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
                'short_label' => $type->shortLabel(),
                // Who receives the goods: a saved customer, the company itself
                // moving its own assets, or a supplier the goods go back to.
                'recipient' => match ($type) {
                    TransportDocumentType::OwnAssets => 'company',
                    TransportDocumentType::ReturnNote => 'supplier',
                    default => 'customer',
                },
            ], PreviewMobileTransportDocumentRequest::ISSUABLE_TYPES),
            'establishments' => array_values($legalEntity->establishments()
                ->where('is_active', true)
                ->orderByDesc('is_head_office')
                ->orderBy('name')
                ->get()
                ->map(fn (Establishment $establishment): array => [
                    'public_id' => $establishment->public_id,
                    'name' => $establishment->name,
                    'address' => (string) $establishment->address_line,
                    'city' => (string) ($establishment->municipality ?? ''),
                ])->all()),
            'company' => [
                'name' => $legalEntity->trade_name ?: $legalEntity->legal_name,
                'tax_identification_number' => $legalEntity->tax_identification_number,
            ],
        ]]);
    }

    public function index(MobileListRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', TransportDocument::class);

        $search = $request->search();
        $guides = TransportDocument::query()
            ->with('establishment')
            ->where('legal_entity_id', $this->legalEntity($request)->id)
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $matching) use ($search): void {
                $matching
                    ->whereSearchLike('document_no', "%{$search}%")
                    ->orWhereSearchLike('recipient_name', "%{$search}%")
                    ->orWhereSearchLike('vehicle_registration', "%{$search}%")
                    ->orWhereSearchLike('destination_city', "%{$search}%");
            }))
            ->latest('movement_start_at')
            ->latest('id')
            ->paginate(20);

        return $request->pageOf($guides, array_values($guides->getCollection()
            ->map(fn (TransportDocument $guide): array => $this->presenter->row($guide))
            ->all()));
    }

    public function show(Request $request, string $transportDocumentPublicId): JsonResponse
    {
        $guide = TransportDocument::query()
            ->where('legal_entity_id', $this->legalEntity($request)->id)
            ->where('public_id', $transportDocumentPublicId)
            ->firstOrFail();

        Gate::authorize('view', $guide);

        return response()->json(['data' => $this->presenter->detail($guide)]);
    }

    public function preview(PreviewMobileTransportDocumentRequest $request): JsonResponse
    {
        Gate::authorize('create', TransportDocument::class);

        return response()->json(['data' => $this->guides->preview($this->legalEntity($request), $request->guide())]);
    }

    /**
     * Issues the guide, on the web's two conditions: the account has two-factor
     * authentication on, and the person proves who they are now. A repeat with the
     * same client key comes back as the guide it already issued.
     */
    public function store(StoreMobileTransportDocumentRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $legalEntity = $this->legalEntity($request);

        if (Gate::denies('create', TransportDocument::class)) {
            return $this->refusal('TRANSPORT_NOT_ALLOWED', 'A sua função nesta empresa não permite emitir guias.', Response::HTTP_FORBIDDEN);
        }

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return $this->refusal('TWO_FACTOR_REQUIRED_TO_ISSUE', 'Active a autenticação em dois passos em facturac.ao para emitir.', Response::HTTP_FORBIDDEN);
        }

        $unconfirmed = $this->issuer->refusal($user, $request->validated());

        if ($unconfirmed !== null) {
            return $this->refusal($unconfirmed['code'], $unconfirmed['message'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $made = $this->guides->execute(
                $legalEntity,
                $user,
                (string) $request->validated('client_key'),
                $request->guide(),
                (int) $request->validated('expected_total_minor'),
            );
        } catch (BillingActionRefused $exception) {
            return $this->refusal('TRANSPORT_BLOCKED', $exception->getMessage(), Response::HTTP_CONFLICT);
        } catch (SubscriptionRequired $exception) {
            return $this->refusal('SUBSCRIPTION_REQUIRED', $exception->getMessage(), Response::HTTP_FORBIDDEN);
        }

        return response()->json(
            ['data' => $this->presenter->detail($made->transportDocument, $made->replayed)],
            $made->replayed ? Response::HTTP_OK : Response::HTTP_CREATED,
        );
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
