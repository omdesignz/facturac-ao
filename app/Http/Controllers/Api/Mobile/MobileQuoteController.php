<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Actions\ConfirmMobileIssuer;
use App\Actions\ConvertQuoteToInvoice;
use App\Actions\CreateMobileQuote;
use App\Actions\IssueFiscalDocument;
use App\Actions\TransitionQuote;
use App\Billing\SubscriptionAccess;
use App\Exceptions\BillingActionRefused;
use App\Exceptions\FiscalFinalizationBlocked;
use App\Exceptions\SubscriptionRequired;
use App\Fiscal\Documents\MobileDocumentReceipt;
use App\Fiscal\Documents\MobileQuotePresenter;
use App\Fiscal\Pos\PosSeriesResolver;
use App\FiscalDocumentType;
use App\Http\Requests\MobileListRequest;
use App\Http\Requests\PreviewMobileQuoteRequest;
use App\Http\Requests\StoreMobileQuoteRequest;
use App\Models\Customer;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\Quote;
use App\Models\User;
use App\Models\Workspace;
use App\QuoteStatus;
use App\SubscriptionFeature;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Quotes from the phone, under the web's rules and permissions. A quote is not a
 * fiscal document, so only the last step, issuing the invoice it became, asks for
 * the password and spends a number.
 */
class MobileQuoteController
{
    public function __construct(private readonly MobileQuotePresenter $presenter) {}

    public function index(MobileListRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $search = $request->search();
        $quotes = Quote::query()
            ->where('legal_entity_id', $this->legalEntity($request)->id)
            ->when((string) $request->string('status', 'open') === 'open', fn (Builder $query) => $query->open())
            ->when($search !== '', fn (Builder $query) => $query->where(function ($matching) use ($search): void {
                $matching
                    ->whereSearchLike('reference', $search)
                    ->orWhereSearchLike('customer_name', $search);
            }))
            ->latest('issue_date')
            ->latest('id')
            ->paginate(20);

        return $request->pageOf($quotes, array_values($quotes->getCollection()
            ->map(fn (Quote $quote): array => $this->presenter->row($quote))
            ->all()));
    }

    public function show(Request $request, string $quotePublicId): JsonResponse
    {
        Gate::authorize('viewAny', Customer::class);

        return $this->answer($request, $this->quote($request, $quotePublicId));
    }

    public function preview(PreviewMobileQuoteRequest $request, CreateMobileQuote $quotes): JsonResponse
    {
        Gate::authorize('create', Customer::class);

        return response()->json(['data' => $quotes->preview(
            $this->legalEntity($request),
            (string) $request->validated('customer_public_id'),
            $request->invoiceLines(),
        )]);
    }

    public function store(StoreMobileQuoteRequest $request, CreateMobileQuote $quotes): JsonResponse
    {
        if ($refusal = $this->managementRefusal($request)) {
            return $refusal;
        }

        try {
            $quote = $quotes->execute($this->legalEntity($request), $this->user($request), [
                'client_key' => (string) $request->validated('client_key'),
                'customer_public_id' => (string) $request->validated('customer_public_id'),
                'lines' => $request->invoiceLines(),
                'valid_for_days' => (int) $request->validated('valid_for_days'),
                'expected_total_minor' => (int) $request->validated('expected_total_minor'),
            ]);
        } catch (FiscalFinalizationBlocked $exception) {
            return $this->refusal('QUOTE_BLOCKED', $exception->getMessage(), Response::HTTP_CONFLICT);
        }

        return $this->answer($request, $quote, $quote->wasRecentlyCreated ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    /**
     * Records what happened to the quote. Asking for the status it already has is a
     * retry after a dropped connection, and answers with the quote as it stands.
     */
    public function status(Request $request, string $quotePublicId, TransitionQuote $transition): JsonResponse
    {
        $quote = $this->quote($request, $quotePublicId);

        if ($refusal = $this->managementRefusal($request)) {
            return $refusal;
        }

        $validated = $request->validate(['status' => ['required', 'string', 'in:sent,accepted,rejected']]);
        $status = QuoteStatus::from((string) $validated['status']);

        if ($quote->status !== $status) {
            $workspace = $request->attributes->get('currentWorkspace');

            try {
                $transition->execute($quote, $this->user($request), $status, $workspace instanceof Workspace ? $workspace : null);
            } catch (BillingActionRefused $exception) {
                return $this->refusal('QUOTE_STATUS_REFUSED', $exception->getMessage(), Response::HTTP_CONFLICT);
            }
        }

        return $this->answer($request, $quote);
    }

    /**
     * The quote becomes a draft invoice, which uses no number yet. Repeated, it
     * answers with the draft it already made.
     */
    public function convert(Request $request, string $quotePublicId, ConvertQuoteToInvoice $convert): JsonResponse
    {
        $quote = $this->quote($request, $quotePublicId);

        if ($refusal = $this->managementRefusal($request)) {
            return $refusal;
        }

        if ($quote->converted_document_id === null) {
            if ($quote->hasLapsed()) {
                return $this->refusal('QUOTE_LAPSED', 'A validade deste orçamento já terminou.', Response::HTTP_CONFLICT);
            }

            try {
                $convert->execute($quote, $this->user($request));
            } catch (BillingActionRefused $exception) {
                return $this->refusal('QUOTE_NOT_CONVERTIBLE', $exception->getMessage(), Response::HTTP_CONFLICT);
            }
        }

        return $this->answer($request, $quote->refresh());
    }

    /**
     * Issues the invoice a quote became, on the web's two conditions: the account has
     * two-factor authentication on, and the person proves the password now.
     */
    public function issue(
        Request $request,
        string $quotePublicId,
        IssueFiscalDocument $issue,
        PosSeriesResolver $series,
        MobileDocumentReceipt $receipt,
        ConfirmMobileIssuer $issuer,
    ): JsonResponse {
        $legalEntity = $this->legalEntity($request);
        $user = $this->user($request);
        $document = $this->quote($request, $quotePublicId)->convertedDocument;

        abort_unless($document instanceof FiscalDocument, 404);

        $validated = $request->validate([
            'password' => [Rule::requiredIf(fn (): bool => ! $request->has('passkey')), 'nullable', 'string', 'max:255'],
            'passkey' => ['nullable', 'array:challenge_id,credential'],
            'passkey.challenge_id' => ['required_with:passkey', 'string', 'max:64'],
            'passkey.credential' => ['required_with:passkey', 'array'],
            'revision' => ['required', 'integer', 'min:1'],
            'expected_total_minor' => ['required', 'integer', 'min:0', 'max:999999999999999'],
        ], ['password.required' => 'Confirme a palavra-passe para emitir.']);

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return $this->refusal('TWO_FACTOR_REQUIRED_TO_ISSUE', 'Active a autenticação em dois passos em facturac.ao para emitir.', Response::HTTP_FORBIDDEN);
        }

        $unconfirmed = $issuer->refusal($user, $validated);

        if ($unconfirmed !== null) {
            return $this->refusal($unconfirmed['code'], $unconfirmed['message'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($document->document_no !== null) {
            return response()->json(['data' => $receipt->forDocument($document, true, $legalEntity)]);
        }

        if (Gate::denies('issue', $document)) {
            return $this->refusal('INVOICE_NOT_ALLOWED', 'A sua função nesta empresa não permite emitir facturas.', Response::HTTP_FORBIDDEN);
        }

        // The draft can be edited on the web in the meantime: what was reviewed is what is issued.
        if ($document->gross_total_minor !== (int) $validated['expected_total_minor'] || $document->revision !== (int) $validated['revision']) {
            return $this->refusal('INVOICE_CHANGED', 'Esta factura mudou desde que a abriu. Reveja-a antes de emitir.', Response::HTTP_CONFLICT);
        }

        $chosen = $series->forEstablishment($legalEntity, $document->establishment, FiscalDocumentType::Invoice);

        if ($chosen === null) {
            return $this->refusal('INVOICE_BLOCKED', 'Não há série de Factura disponível. Peça uma série à AGT em facturac.ao.', Response::HTTP_CONFLICT);
        }

        try {
            $issue->execute($document, $user, $chosen->public_id, $document->revision);
        } catch (FiscalFinalizationBlocked $exception) {
            // A repeat that crossed with its own first attempt finds the document issued.
            return $document->refresh()->document_no !== null
                ? response()->json(['data' => $receipt->forDocument($document, true, $legalEntity)])
                : $this->refusal('INVOICE_BLOCKED', $exception->getMessage(), Response::HTTP_CONFLICT);
        } catch (SubscriptionRequired $exception) {
            return $this->refusal('SUBSCRIPTION_REQUIRED', $exception->getMessage(), Response::HTTP_FORBIDDEN);
        }

        return response()->json(
            ['data' => $receipt->forDocument($document->refresh(), false, $legalEntity)],
            Response::HTTP_CREATED,
        );
    }

    /**
     * The web keeps these behind its permission and paywall; the phone says why.
     */
    private function managementRefusal(Request $request): ?JsonResponse
    {
        if (Gate::denies('create', Customer::class)) {
            return $this->refusal('QUOTE_NOT_ALLOWED', 'A sua função nesta empresa não permite gerir orçamentos.', Response::HTTP_FORBIDDEN);
        }

        if (! app(SubscriptionAccess::class)->allows($this->legalEntity($request)->workspace_id, SubscriptionFeature::Invoicing)) {
            return $this->refusal('SUBSCRIPTION_REQUIRED', 'O plano actual não inclui orçamentos. Gira o plano em facturac.ao.', Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    private function answer(Request $request, Quote $quote, int $status = Response::HTTP_OK): JsonResponse
    {
        return response()->json(
            ['data' => $this->presenter->detail($quote, $this->user($request), $this->legalEntity($request))],
            $status,
        );
    }

    private function quote(Request $request, string $publicId): Quote
    {
        return Quote::query()
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
