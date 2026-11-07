<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Actions\ConfirmMobileIssuer;
use App\Actions\CreateMobileInvoice;
use App\Actions\PrepareRepeatInvoice;
use App\Analytics\AgingQuery;
use App\Billing\SubscriptionAccess;
use App\Exceptions\BillingActionRefused;
use App\Exceptions\FiscalFinalizationBlocked;
use App\Exceptions\SubscriptionRequired;
use App\Fiscal\Documents\MobileDocumentReceipt;
use App\Fiscal\SupportedTaxTreatment;
use App\Http\Requests\PreviewMobileInvoiceRequest;
use App\Http\Requests\StoreMobileInvoiceRequest;
use App\Models\CatalogueItem;
use App\Models\Customer;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\MobileInvoice;
use App\Models\User;
use App\SubscriptionFeature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
    public function __construct(
        private readonly CreateMobileInvoice $invoices,
        private readonly ConfirmMobileIssuer $issuer,
    ) {}

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
            'recovery_scope' => implode(':', [$user->id, $legalEntity->workspace_id, $legalEntity->public_id]),
            'can_create' => $canCreate && $subscription->allows($legalEntity->workspace_id, SubscriptionFeature::Invoicing),
            'can_issue' => $reason === null,
            'reason' => $reason,
            'can_create_customers' => Gate::allows('create', Customer::class),
            'can_create_items' => Gate::allows('create', CatalogueItem::class),
            'tax_treatments' => array_map(
                fn (array $option): array => ['value' => $option['value'], 'label' => $option['label']],
                SupportedTaxTreatment::options(),
            ),
        ]]);
    }

    public function repeat(Request $request, string $documentPublicId, PrepareRepeatInvoice $repeat, AgingQuery $aging): JsonResponse
    {
        $entity = $this->legalEntity($request);
        $decimalQuantities = $request->query('quantity_format') === 'decimal';
        $seed = $repeat->execute($entity, $documentPublicId, wholeQuantities: ! $decimalQuantities, combineItems: $decimalQuantities);
        $owed = array_column($aging->byCustomer($entity), 'outstanding_minor', 'customer_public_id');
        $items = $seed['items']->keyBy('public_id');

        return response()->json(['data' => [
            'source_public_id' => $seed['source']->public_id,
            'source_document_no' => $seed['source']->document_no,
            'customer' => MobileCustomerController::present($seed['customer'], $entity, $owed[$seed['customer']->public_id] ?? 0),
            'lines' => array_map(fn (array $line): array => [
                'item' => MobileItemController::present($items->get($line['catalogue_item_public_id'])),
                'quantity' => $decimalQuantities ? $line['quantity'] : (int) $line['quantity'],
            ], $seed['requested']),
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function preview(PreviewMobileInvoiceRequest $request): JsonResponse
    {
        Gate::authorize('create', FiscalDocument::class);

        if ($request->hasOneOffLines() && Gate::denies('create', CatalogueItem::class)) {
            return $this->oneOffRefused();
        }

        return response()->json(['data' => $this->invoices->preview(
            $this->legalEntity($request),
            (string) $request->validated('customer_public_id'),
            $request->invoiceLines(),
        )]);
    }

    /** Resolve a previous request without creating or issuing a document. */
    public function recover(Request $request, string $clientKey, MobileDocumentReceipt $receipt): JsonResponse
    {
        $legalEntity = $this->legalEntity($request);
        $invoice = MobileInvoice::query()->with('fiscalDocument')
            ->where('workspace_id', $legalEntity->workspace_id)
            ->where('legal_entity_id', $legalEntity->id)
            ->where('created_by_user_id', $this->user($request)->id)
            ->where('client_key', $clientKey)->first();

        if ($invoice !== null) {
            Gate::authorize('view', $invoice->fiscalDocument);
            $invoice->replayed = true;
        }

        return response()->json(['data' => $invoice === null ? null : $receipt->for($invoice, $legalEntity)])
            ->header('Cache-Control', 'private, no-store');
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

        // A one-off line sets its own price: only someone who may price items may write one.
        if ($request->hasOneOffLines() && Gate::denies('create', CatalogueItem::class)) {
            return $this->oneOffRefused();
        }

        $unconfirmed = $issue ? $this->issuer->refusal($user, $request->validated()) : null;

        if ($unconfirmed !== null) {
            return $this->refusal($unconfirmed['code'], $unconfirmed['message'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $invoice = $this->invoices->execute($legalEntity, $user, [
                'client_key' => (string) $request->validated('client_key'),
                'customer_public_id' => (string) $request->validated('customer_public_id'),
                'lines' => $request->invoiceLines(),
                'notes' => $request->notes(),
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

    private function oneOffRefused(): JsonResponse
    {
        return $this->refusal(
            'ONE_OFF_LINE_NOT_ALLOWED',
            'A sua função nesta empresa não permite linhas avulsas. Escolha artigos do catálogo.',
            Response::HTTP_FORBIDDEN,
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
