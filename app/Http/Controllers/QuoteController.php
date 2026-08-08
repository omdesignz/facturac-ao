<?php

namespace App\Http\Controllers;

use App\Actions\ConvertQuoteToInvoice;
use App\Actions\ResolveCustomerPrices;
use App\Actions\SaveQuote;
use App\Exceptions\BillingActionRefused;
use App\Http\Requests\StoreQuoteRequest;
use App\Models\CatalogueItem;
use App\Models\Customer;
use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\Quote;
use App\Models\Workspace;
use App\Notifications\QuoteWasAccepted;
use App\QuoteStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Orçamentos: proposals that stay editable until the customer decides.
 */
class QuoteController extends Controller
{
    public function __construct(private ResolveCustomerPrices $resolvePrices) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $legalEntity = $this->legalEntity($request);

        if (! $legalEntity instanceof LegalEntity) {
            return redirect()
                ->route('onboarding')
                ->with('error', 'Conclua primeiro a identidade fiscal da empresa.');
        }

        Gate::authorize('viewAny', Customer::class);

        $status = (string) $request->string('status', 'open');

        $quotes = Quote::query()
            ->with(['customer', 'convertedDocument'])
            ->where('legal_entity_id', $legalEntity->id)
            ->when($status === 'open', fn ($query) => $query->open())
            ->when(
                $status !== 'open' && $status !== 'all',
                fn ($query) => $query->where('status', $status),
            )
            ->latest('issue_date')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Quotes/Index', [
            'quotes' => [
                'data' => array_values($quotes->getCollection()
                    ->map(fn (Quote $quote): array => $this->present($quote))
                    ->all()),
                'links' => $quotes->linkCollection()->all(),
                'total' => $quotes->total(),
            ],
            'filters' => ['status' => $status],
            'statuses' => array_map(
                fn (QuoteStatus $value): array => [
                    'value' => $value->value,
                    'label' => $value->label(),
                ],
                QuoteStatus::cases(),
            ),
            'currencyCode' => $legalEntity->currency_code,
        ]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        return $this->form($request, null);
    }

    public function edit(Request $request, Quote $quote): Response|RedirectResponse
    {
        Gate::authorize('viewAny', Customer::class);

        return $this->form($request, $quote);
    }

    public function store(StoreQuoteRequest $request, SaveQuote $saveQuote): RedirectResponse
    {
        $legalEntity = $this->legalEntity($request);
        abort_unless($legalEntity instanceof LegalEntity, 404);
        Gate::authorize('create', Customer::class);

        $quote = $saveQuote->execute($legalEntity, $request->user(), $request->profile());

        return redirect()
            ->route('quotes.edit', $quote)
            ->with('success', "Orçamento {$quote->reference} guardado.");
    }

    public function update(
        StoreQuoteRequest $request,
        Quote $quote,
        SaveQuote $saveQuote,
    ): RedirectResponse {
        $legalEntity = $this->legalEntity($request);
        abort_unless($legalEntity instanceof LegalEntity, 404);
        abort_unless($quote->legal_entity_id === $legalEntity->id, 404);
        Gate::authorize('create', Customer::class);

        try {
            $saveQuote->execute($legalEntity, $request->user(), $request->profile(), $quote);
        } catch (BillingActionRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Orçamento actualizado.');
    }

    /** Records that the quote went out, was accepted, or was turned down. */
    public function transition(Request $request, Quote $quote): RedirectResponse
    {
        $legalEntity = $this->legalEntity($request);
        abort_unless($legalEntity instanceof LegalEntity, 404);
        abort_unless($quote->legal_entity_id === $legalEntity->id, 404);
        Gate::authorize('create', Customer::class);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:sent,accepted,rejected'],
        ]);

        $status = QuoteStatus::from((string) $validated['status']);

        if ($quote->status->isClosed()) {
            return back()->with('error', 'Este orçamento já está fechado.');
        }

        $quote->forceFill([
            'status' => $status,
            'sent_at' => $status === QuoteStatus::Sent ? now() : $quote->sent_at,
            'decided_at' => $status === QuoteStatus::Sent ? null : now(),
        ])->save();

        activity('quote')
            ->causedBy($request->user())
            ->performedOn($quote)
            ->event($status->value)
            ->withProperties(['reference' => $quote->reference])
            ->log("quote marked {$status->value}");

        $workspace = $request->attributes->get('currentWorkspace');

        if ($status === QuoteStatus::Accepted && $workspace instanceof Workspace) {
            QuoteWasAccepted::fromQuote($quote)->sendToWorkspace($workspace);
        }

        return back()->with('success', "Orçamento marcado como {$status->label()}.");
    }

    public function convert(
        Request $request,
        Quote $quote,
        ConvertQuoteToInvoice $convert,
    ): RedirectResponse {
        $legalEntity = $this->legalEntity($request);
        abort_unless($legalEntity instanceof LegalEntity, 404);
        abort_unless($quote->legal_entity_id === $legalEntity->id, 404);
        Gate::authorize('create', Customer::class);

        try {
            $document = $convert->execute($quote, $request->user());
        } catch (BillingActionRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('invoices.edit', $document)
            ->with(
                'success',
                "Rascunho criado a partir do orçamento {$quote->reference}. Reveja antes de emitir.",
            );
    }

    private function form(Request $request, ?Quote $quote): Response|RedirectResponse
    {
        $legalEntity = $this->legalEntity($request);

        if (! $legalEntity instanceof LegalEntity) {
            return redirect()
                ->route('onboarding')
                ->with('error', 'Conclua primeiro a identidade fiscal da empresa.');
        }

        if ($quote instanceof Quote && $quote->legal_entity_id !== $legalEntity->id) {
            abort(404);
        }

        $customers = $legalEntity->customers()
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(250)
            ->get();
        $agreedPrices = $this->resolvePrices->forCustomers($customers);

        return Inertia::render('Quotes/Form', [
            'quote' => $quote === null ? null : [
                ...$this->present($quote->load('lines')),
                'establishment_public_id' => $quote->establishment->public_id,
                'customer_public_id' => $quote->customer?->public_id,
                'customer' => [
                    'name' => $quote->customer_name,
                    'tax_identification_number' => $quote->customer_tax_identification_number,
                    'country_code' => $quote->customer_country_code,
                    'address_line' => $quote->customer_address,
                ],
                'notes' => $quote->notes,
                'lines' => array_values($quote->lines->map(fn ($line): array => [
                    'product_code' => $line->product_code,
                    'product_description' => $line->product_description,
                    'unit_of_measure' => $line->unit_of_measure,
                    'quantity' => $this->decimal($line->quantity_units, 3),
                    'unit_price' => $this->decimal($line->unit_price_minor, 2),
                    'discount_rate' => $this->decimal($line->discount_rate_basis_points, 2),
                    'tax_type' => $line->tax_type,
                    'tax_code' => $line->tax_code,
                    'tax_percentage' => $line->tax_percentage,
                    'tax_exemption_code' => $line->tax_exemption_code,
                ])->all()),
            ],
            'establishments' => array_values($legalEntity->establishments()
                ->where('is_active', true)
                ->orderByDesc('is_head_office')
                ->get()
                ->map(fn (Establishment $establishment): array => [
                    'value' => $establishment->public_id,
                    'label' => $establishment->name,
                ])->all()),
            'customers' => array_values($customers
                ->map(fn (Customer $customer): array => [
                    'public_id' => $customer->public_id,
                    'name' => $customer->name,
                    'tax_identification_number' => $customer->tax_identification_number,
                    'country_code' => $customer->country_code,
                    'address_line' => $customer->address_line,
                    'agreed_prices' => array_map(
                        fn (int $minor): string => $this->decimal($minor, 2),
                        $agreedPrices[$customer->public_id] ?? [],
                    ),
                ])->all()),
            'catalogueItems' => array_values($legalEntity->catalogueItems()
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->map(fn (CatalogueItem $item): array => [
                    'public_id' => $item->public_id,
                    'code' => $item->code,
                    'name' => $item->name,
                    'description' => $item->description,
                    'unit_of_measure' => $item->unit_of_measure,
                    'unit_price' => $this->decimal($item->unit_price_minor, 2),
                    'tax_type' => $item->tax_type,
                    'tax_code' => $item->tax_code,
                    'tax_percentage' => $item->tax_percentage,
                    'tax_exemption_code' => $item->tax_exemption_code,
                ])->all()),
            'currencyCode' => $legalEntity->currency_code,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Quote $quote): array
    {
        $effective = $quote->effectiveStatus();

        return [
            'public_id' => $quote->public_id,
            'reference' => $quote->reference,
            'status' => $effective->value,
            'status_label' => $effective->label(),
            'is_editable' => $effective->isEditable(),
            'can_convert' => $effective->canConvert() && $quote->converted_document_id === null,
            'customer_name' => $quote->customer_name,
            'customer_public_id' => $quote->customer?->public_id,
            'issue_date' => $quote->issue_date->toIso8601String(),
            'valid_until' => $quote->valid_until->toIso8601String(),
            'has_lapsed' => $quote->hasLapsed(),
            'net_total_minor' => $quote->net_total_minor,
            'tax_total_minor' => $quote->tax_total_minor,
            'gross_total_minor' => $quote->gross_total_minor,
            'converted_document' => $quote->convertedDocument === null ? null : [
                'public_id' => $quote->convertedDocument->public_id,
                'document_no' => $quote->convertedDocument->document_no,
            ],
        ];
    }

    /** Formats scaled integers back into a plain decimal string. */
    private function decimal(int $units, int $scale): string
    {
        return number_format($units / (10 ** $scale), $scale, '.', '');
    }

    private function legalEntity(Request $request): ?LegalEntity
    {
        $workspace = $request->attributes->get('currentWorkspace');

        if (! $workspace instanceof Workspace) {
            return null;
        }

        return $workspace->legalEntities()->oldest('id')->first();
    }
}
