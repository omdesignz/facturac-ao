<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Actions\CreateMobileInvoice;
use App\Billing\SubscriptionAccess;
use App\Exceptions\BillingActionRefused;
use App\Exceptions\FiscalFinalizationBlocked;
use App\Exceptions\SubscriptionRequired;
use App\Fiscal\Documents\MobileDocumentReceipt;
use App\Http\Requests\PreviewMobileInvoiceRequest;
use App\Http\Requests\StoreMobileInvoiceRequest;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\User;
use App\SubscriptionFeature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

/**
 * Making an invoice from the phone.
 *
 * Issuing keeps the web's two conditions: the account has two-factor
 * authentication on, and the person proves the password at that moment. A token
 * says which device this is, not who is holding it, so the token alone is not
 * enough to spend a fiscal number.
 */
class MobileInvoiceController
{
    public function __construct(private readonly CreateMobileInvoice $invoices) {}

    /**
     * What this person can do here, so the app can say so before they start.
     */
    public function setup(Request $request, SubscriptionAccess $subscription): JsonResponse
    {
        $legalEntity = $this->legalEntity($request);
        $user = $this->user($request);
        $canCreate = Gate::allows('create', FiscalDocument::class);

        $reason = match (true) {
            ! $canCreate => 'A sua função nesta empresa não permite criar facturas.',
            ! $subscription->allows($legalEntity->workspace_id, SubscriptionFeature::Invoicing) => 'O plano actual não inclui facturação. Veja a assinatura em facturac.ao.',
            ! $user->hasEnabledTwoFactorAuthentication() => 'Para emitir, active a autenticação em dois passos em facturac.ao. Pode guardar rascunhos.',
            ! $this->invoices->canBeNumbered($legalEntity) => 'Não há série de Factura disponível. Pode guardar rascunhos.',
            default => null,
        };

        return response()->json(['data' => [
            'can_create' => $canCreate && $subscription->allows($legalEntity->workspace_id, SubscriptionFeature::Invoicing),
            'can_issue' => $reason === null,
            'reason' => $reason,
        ]]);
    }

    public function preview(PreviewMobileInvoiceRequest $request): JsonResponse
    {
        Gate::authorize('create', FiscalDocument::class);

        return response()->json(['data' => $this->invoices->preview(
            $this->legalEntity($request),
            (string) $request->validated('customer_public_id'),
            $request->invoiceLines(),
        )]);
    }

    public function store(StoreMobileInvoiceRequest $request, MobileDocumentReceipt $receipt): JsonResponse
    {
        $user = $this->user($request);
        $legalEntity = $this->legalEntity($request);
        $issue = $request->boolean('issue');

        if (Gate::denies('create', FiscalDocument::class)) {
            return $this->refusal('INVOICE_NOT_ALLOWED', 'A sua função nesta empresa não permite criar facturas.', Response::HTTP_FORBIDDEN);
        }

        if ($issue && ! $user->hasEnabledTwoFactorAuthentication()) {
            return $this->refusal('TWO_FACTOR_REQUIRED_TO_ISSUE', 'Active a autenticação em dois passos em facturac.ao para emitir.', Response::HTTP_FORBIDDEN);
        }

        if ($issue && ! Hash::check((string) $request->validated('password'), $user->password)) {
            return $this->refusal('PASSWORD_INCORRECT', 'A palavra-passe não está certa.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $invoice = $this->invoices->execute($legalEntity, $user, [
                'client_key' => (string) $request->validated('client_key'),
                'customer_public_id' => (string) $request->validated('customer_public_id'),
                'lines' => $request->invoiceLines(),
                'expected_total_minor' => (int) $request->validated('expected_total_minor'),
                'issue' => $issue,
            ]);
        } catch (FiscalFinalizationBlocked|BillingActionRefused $exception) {
            return $this->refusal('INVOICE_BLOCKED', $exception->getMessage(), Response::HTTP_CONFLICT);
        } catch (SubscriptionRequired $exception) {
            return $this->refusal('SUBSCRIPTION_REQUIRED', $exception->getMessage(), Response::HTTP_FORBIDDEN);
        }

        return response()->json(
            ['data' => $receipt->for($invoice, $legalEntity)],
            $invoice->replayed ? Response::HTTP_OK : Response::HTTP_CREATED,
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
