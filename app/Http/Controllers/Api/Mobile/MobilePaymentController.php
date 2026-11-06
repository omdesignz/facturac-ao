<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Actions\ConfirmMobileIssuer;
use App\Actions\RecordMobilePayment;
use App\Billing\SubscriptionAccess;
use App\Exceptions\BillingActionRefused;
use App\Exceptions\FiscalFinalizationBlocked;
use App\Exceptions\SubscriptionRequired;
use App\Fiscal\Documents\MobileDocumentReceipt;
use App\Http\Requests\StoreMobilePaymentRequest;
use App\Models\Customer;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\User;
use App\PaymentMethod;
use App\SubscriptionFeature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Recording a payment from the phone, which issues a Recibo.
 *
 * It spends a fiscal number, so it keeps the conditions of an issued invoice:
 * two-factor authentication on the account and the password at that moment.
 */
class MobilePaymentController
{
    public function __construct(
        private readonly RecordMobilePayment $payments,
        private readonly ConfirmMobileIssuer $issuer,
    ) {}

    public function show(Request $request, string $customerPublicId, SubscriptionAccess $subscription): JsonResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $legalEntity = $this->legalEntity($request);
        $customer = $this->customer($legalEntity, $customerPublicId);
        $user = $this->user($request);
        $documents = $this->payments->openDocuments($customer);

        $reason = match (true) {
            Gate::denies('create', FiscalDocument::class) => 'A sua função nesta empresa não permite emitir recibos.',
            ! $subscription->allows($legalEntity->workspace_id, SubscriptionFeature::Invoicing) => 'O plano actual não inclui facturação. Veja a assinatura em facturac.ao.',
            ! $user->hasEnabledTwoFactorAuthentication() => 'Para emitir recibos, active a autenticação em dois passos em facturac.ao.',
            ! $this->payments->canBeNumbered($legalEntity) => 'Não há série de Recibo disponível. Peça uma série à AGT em facturac.ao.',
            default => null,
        };

        return response()->json(['data' => [
            'customer_name' => $customer->name,
            'currency_code' => $legalEntity->currency_code,
            'today' => now('Africa/Luanda')->toDateString(),
            'documents' => $documents,
            'payment_methods' => PaymentMethod::options(),
            'can_issue' => $reason === null,
            'reason' => $reason,
        ]]);
    }

    public function store(StoreMobilePaymentRequest $request, string $customerPublicId, MobileDocumentReceipt $receipt): JsonResponse
    {
        $user = $this->user($request);
        $legalEntity = $this->legalEntity($request);
        $customer = $this->customer($legalEntity, $customerPublicId);

        if (Gate::denies('create', FiscalDocument::class)) {
            return $this->refusal('PAYMENT_NOT_ALLOWED', 'A sua função nesta empresa não permite emitir recibos.', Response::HTTP_FORBIDDEN);
        }

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return $this->refusal('TWO_FACTOR_REQUIRED_TO_ISSUE', 'Active a autenticação em dois passos em facturac.ao para emitir.', Response::HTTP_FORBIDDEN);
        }

        $unconfirmed = $this->issuer->refusal($user, $request->validated());

        if ($unconfirmed !== null) {
            return $this->refusal($unconfirmed['code'], $unconfirmed['message'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $made = $this->payments->execute($legalEntity, $user, $customer, [
                'client_key' => (string) $request->validated('client_key'),
                'payment_method' => (string) $request->validated('payment_method'),
                'payment_date' => (string) $request->validated('payment_date'),
                'settlements' => $request->settlements(),
            ]);
        } catch (FiscalFinalizationBlocked|BillingActionRefused $exception) {
            return $this->refusal('PAYMENT_BLOCKED', $exception->getMessage(), Response::HTTP_CONFLICT);
        } catch (SubscriptionRequired $exception) {
            return $this->refusal('SUBSCRIPTION_REQUIRED', $exception->getMessage(), Response::HTTP_FORBIDDEN);
        }

        return response()->json(
            ['data' => $receipt->for($made, $legalEntity)],
            $made->replayed ? Response::HTTP_OK : Response::HTTP_CREATED,
        );
    }

    private function customer(LegalEntity $legalEntity, string $publicId): Customer
    {
        return Customer::query()
            ->where('public_id', $publicId)
            ->where('workspace_id', $legalEntity->workspace_id)
            ->where('legal_entity_id', $legalEntity->id)
            ->where('is_active', true)
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
