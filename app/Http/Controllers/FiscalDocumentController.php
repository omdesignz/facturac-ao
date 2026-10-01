<?php

namespace App\Http\Controllers;

use App\Actions\ResolveCustomerPrices;
use App\Actions\SaveFiscalDocumentDraft;
use App\Fiscal\Agt\Support\CanonicalNumber;
use App\Fiscal\SupportedTaxTreatment;
use App\FiscalDocumentType;
use App\FiscalOperationType;
use App\FiscalSeriesContingency;
use App\FiscalSeriesStatus;
use App\Http\Requests\StoreFiscalDocumentRequest;
use App\Http\Requests\UpdateFiscalDocumentRequest;
use App\Models\CatalogueItem;
use App\Models\Customer;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLine;
use App\Models\FiscalDocumentLineTax;
use App\Models\FiscalSeries;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\Workspace;
use App\PaymentMethod;
use App\WithholdingType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class FiscalDocumentController extends Controller
{
    public function __construct(private ResolveCustomerPrices $resolvePrices) {}

    public function create(Request $request): Response|RedirectResponse
    {
        $legalEntity = $this->legalEntity($request);

        if (! $legalEntity instanceof LegalEntity) {
            return redirect()
                ->route('onboarding')
                ->with('error', 'Conclua primeiro a identidade fiscal da empresa.');
        }

        Gate::authorize('create', FiscalDocument::class);
        Inertia::encryptHistory();

        $requestedType = FiscalDocumentType::tryFrom((string) $request->query('type'));

        if ($requestedType === null || ! in_array($requestedType, FiscalDocumentType::issuable(), true)) {
            $requestedType = FiscalDocumentType::Invoice;
        }

        return Inertia::render(
            'Invoices/Create',
            $this->pageProps($legalEntity, null, $requestedType),
        );
    }

    public function store(
        StoreFiscalDocumentRequest $request,
        SaveFiscalDocumentDraft $saveDraft,
    ): RedirectResponse {
        $legalEntity = $request->currentLegalEntity();
        $user = $request->user();

        abort_unless($legalEntity instanceof LegalEntity && $user instanceof User, 404);
        $document = $saveDraft->execute($legalEntity, $user, $request->documentProfile());

        return redirect()
            ->route('invoices.edit', $document)
            ->with('success', 'Rascunho guardado. Nenhum número fiscal foi consumido.');
    }

    public function edit(Request $request, FiscalDocument $fiscalDocument): Response
    {
        $legalEntity = $this->legalEntity($request);

        abort_unless(
            $legalEntity instanceof LegalEntity
                && $fiscalDocument->workspace_id === $legalEntity->workspace_id
                && $fiscalDocument->legal_entity_id === $legalEntity->id,
            404,
        );
        Gate::authorize('update', $fiscalDocument);
        Inertia::encryptHistory();

        $fiscalDocument->load(['customer', 'lines.taxes', 'settlements.settledDocument', 'withholdings']);

        return Inertia::render('Invoices/Create', $this->pageProps($legalEntity, $fiscalDocument));
    }

    public function update(
        UpdateFiscalDocumentRequest $request,
        FiscalDocument $fiscalDocument,
        SaveFiscalDocumentDraft $saveDraft,
    ): RedirectResponse {
        $legalEntity = $request->currentLegalEntity();
        $user = $request->user();

        abort_unless(
            $legalEntity instanceof LegalEntity
                && $user instanceof User
                && $fiscalDocument->workspace_id === $legalEntity->workspace_id
                && $fiscalDocument->legal_entity_id === $legalEntity->id,
            404,
        );
        $document = $saveDraft->execute(
            $legalEntity,
            $user,
            $request->documentProfile(),
            $fiscalDocument,
        );

        return redirect()
            ->route('invoices.edit', $document)
            ->with('success', "Rascunho actualizado · revisão {$document->revision}.");
    }

    /** @return array<string, mixed> */
    private function pageProps(
        LegalEntity $legalEntity,
        ?FiscalDocument $document = null,
        FiscalDocumentType $requestedType = FiscalDocumentType::Invoice,
    ): array {
        $establishments = $legalEntity->establishments()
            ->where('is_active', true)
            ->orderByDesc('is_head_office')
            ->orderBy('name')
            ->get();
        $customers = $legalEntity->customers()
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(250)
            ->get();
        $agreedPrices = $this->resolvePrices->forCustomers($customers);
        $catalogueItems = $legalEntity->catalogueItems()
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(500)
            ->get();
        $adjustable = $legalEntity->fiscalDocuments()
            ->with('customer')
            ->withSum('settledBy as settled_minor', 'amount_minor')
            ->whereNotNull('document_no')
            ->whereIn('document_type', [
                FiscalDocumentType::Invoice,
                FiscalDocumentType::InvoiceReceipt,
            ])
            ->latest('document_date')
            ->limit(200)
            ->get();
        $series = $legalEntity->fiscalSeries()
            ->with('establishment')
            ->whereIn('status', [FiscalSeriesStatus::Open, FiscalSeriesStatus::InUse])
            ->where('contingency_indicator', FiscalSeriesContingency::Normal)
            ->where('invoicing_method', 'FESF')
            ->whereColumn('next_number', '<=', 'last_authorized_number')
            ->whereHas('agtConnection', fn ($query) => $query->where('status', 'verified'))
            ->orderByDesc('series_year')
            ->orderBy('series_code')
            ->get();

        return [
            'company' => [
                'legal_name' => $legalEntity->legal_name,
                'trade_name' => $legalEntity->trade_name,
                'tax_identification_number' => $legalEntity->tax_identification_number,
                'currency_code' => $legalEntity->currency_code,
            ],
            'establishments' => $establishments->map(fn ($establishment): array => [
                'public_id' => $establishment->public_id,
                'name' => $establishment->name,
                'code' => $establishment->code,
                'address_line' => $establishment->address_line,
                'is_head_office' => $establishment->is_head_office,
            ])->values()->all(),
            'catalogueItems' => $catalogueItems->map(fn (CatalogueItem $item): array => [
                'public_id' => $item->public_id,
                'code' => $item->code,
                'name' => $item->name,
                'description' => $item->description,
                'unit_of_measure' => $item->unit_of_measure,
                'unit_price' => number_format($item->unit_price_minor / 100, 2, '.', ''),
                'tax_type' => $item->tax_type,
                'tax_code' => $item->tax_code,
                'tax_percentage' => $item->tax_percentage,
                'tax_exemption_code' => $item->tax_exemption_code,
            ])->values()->all(),
            'customers' => $customers->map(fn (Customer $customer): array => [
                'public_id' => $customer->public_id,
                'name' => $customer->name,
                'tax_identification_number' => $customer->tax_identification_number,
                'country_code' => $customer->country_code,
                'address_line' => $customer->address_line,
                'payment_terms_days' => $customer->payment_terms_days,
                // What this customer pays, keyed by article, so choosing one
                // fills the line at the agreed figure rather than the list
                // price. Resolved centrally: an override beats their tabela,
                // and the tabela beats the catalogue.
                'agreed_prices' => array_map(
                    fn (int $minor): string => number_format($minor / 100, 2, '.', ''),
                    $agreedPrices[$customer->public_id] ?? [],
                ),
                // Whether this buyer keeps tax back. Offered on the document
                // rather than applied to it: the person issuing still has to
                // agree, because the rate turns on what is being supplied.
                'withholding' => $customer->withholding_type === null
                    ? null
                    : [
                        'type' => $customer->withholding_type->value,
                        'rate_percentage' => number_format(
                            ($customer->withholding_rate_basis_points
                                ?? $customer->withholding_type->suggestedRateBasisPoints()) / 100,
                            2,
                            '.',
                            '',
                        ),
                    ],
            ])->values()->all(),
            'operationTypes' => collect(FiscalOperationType::cases())
                ->map(fn (FiscalOperationType $type): array => [
                    'value' => $type->value,
                    'label' => $type->label(),
                ])->all(),
            'taxTreatments' => SupportedTaxTreatment::options(),
            'eligibleSeries' => $series
                ->map(fn (FiscalSeries $fiscalSeries): array => [
                    'public_id' => $fiscalSeries->public_id,
                    'series_code' => $fiscalSeries->series_code,
                    'series_year' => $fiscalSeries->series_year,
                    'document_type' => $fiscalSeries->document_type->value,
                    'document_type_label' => $fiscalSeries->document_type->label(),
                    'establishment_public_id' => $fiscalSeries->establishment->public_id,
                    'next_number' => $fiscalSeries->next_number,
                    'remaining_numbers' => $fiscalSeries->remainingNumbers(),
                ])
                ->values()
                ->all(),
            'document' => $this->documentProps(
                $document,
                $establishments->first()?->public_id,
                $requestedType,
            ),
            'documentTypes' => array_map(
                fn (FiscalDocumentType $type): array => [
                    'value' => $type->value,
                    'label' => $type->label(),
                    'is_adjustment' => $type->isAdjustment(),
                    'is_feminine' => $type->isFeminine(),
                    'is_receipt' => $type->isReceipt(),
                    'settles_other_documents' => $type->settlesOtherDocuments(),
                    'requires_lines' => $type->requiresLines(),
                    'requires_line_operation_date' => $type->requiresLineOperationDate(),
                ],
                FiscalDocumentType::issuable(),
            ),
            'adjustableDocuments' => $adjustable->map(fn (FiscalDocument $issued): array => [
                'public_id' => $issued->public_id,
                'document_no' => $issued->document_no,
                'document_date' => $issued->document_date->toDateString(),
                'customer_name' => $issued->customer_name,
                'customer_public_id' => $issued->customer?->public_id,
                'gross_total_minor' => $issued->gross_total_minor,
                'outstanding_minor' => max(
                    0,
                    $issued->gross_total_minor - (int) ($issued->settled_minor ?? 0),
                ),
            ])->values()->all(),
            'paymentMethods' => PaymentMethod::options(),
            'currencies' => (array) config('fiscal.currencies', ['AOA']),
            'withholdingTypes' => WithholdingType::options(),
            'permissions' => [
                'issue' => $document instanceof FiscalDocument
                    && Gate::allows('issue', $document),
            ],
            'guardrails' => [
                'draft_only' => true,
                'schema_version' => (string) config('agt.schema_version', '1.2'),
                'number_assigned' => false,
                'mfa_enabled' => request()->user()?->hasEnabledTwoFactorAuthentication() === true,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function documentProps(
        ?FiscalDocument $document,
        ?string $defaultEstablishmentPublicId,
        FiscalDocumentType $requestedType = FiscalDocumentType::Invoice,
    ): array {
        if (! $document instanceof FiscalDocument) {
            return [
                'public_id' => null,
                'revision' => 0,
                'status' => 'draft',
                'document_no' => null,
                'document_type' => $requestedType->value,
                'document_date' => now('Africa/Luanda')->toDateString(),
                'due_date' => now('Africa/Luanda')->addDays(30)->toDateString(),
                'currency_code' => 'AOA',
                'exchange_rate' => '1',
                'withholdings' => [],
                'establishment_public_id' => $defaultEstablishmentPublicId,
                'customer_public_id' => null,
                'customer' => [
                    'name' => '',
                    'tax_identification_number' => '',
                    'country_code' => 'AO',
                    'address_line' => '',
                ],
                'notes' => '',
                'references_document_public_id' => null,
                'references_document_no' => null,
                'adjustment_reason' => '',
                'payment_method' => PaymentMethod::Cash->value,
                'payment_date' => now('Africa/Luanda')->toDateString(),
                'settlements' => [],
                'lines' => [$this->emptyLine()],
                'totals' => $this->totalsProps(0, 0, 0, 0),
            ];
        }

        return [
            'public_id' => $document->public_id,
            'revision' => $document->revision,
            'status' => $document->status->value,
            'document_no' => $document->document_no,
            'document_type' => $document->document_type->value,
            'document_date' => $document->document_date->toDateString(),
            'due_date' => $document->due_date?->toDateString(),
            'currency_code' => $document->currency_code,
            'exchange_rate' => number_format($document->exchange_rate_micro / 1_000_000, 6, '.', ''),
            'withholdings' => $this->withholdingProps($document),
            'establishment_public_id' => $document->establishment->public_id,
            'customer_public_id' => $document->customer?->public_id,
            'references_document_public_id' => $document->referencesDocument?->public_id,
            'references_document_no' => $document->references_document_no,
            'adjustment_reason' => $document->adjustment_reason ?? '',
            'payment_method' => ($document->payment_method ?? PaymentMethod::Cash)->value,
            'payment_date' => $document->payment_date?->toDateString()
                ?? now('Africa/Luanda')->toDateString(),
            'settlements' => $document->settlements
                ->map(fn ($settlement): array => [
                    'document_public_id' => $settlement->settledDocument->public_id,
                    'document_no' => $settlement->settled_document_no,
                    'amount' => number_format($settlement->amount_minor / 100, 2, '.', ''),
                ])
                ->values()
                ->all(),
            'customer' => [
                'name' => $document->customer_name,
                'tax_identification_number' => $document->customer_tax_identification_number,
                'country_code' => $document->customer_country_code,
                'address_line' => $document->customer_address ?? '',
            ],
            'notes' => $document->notes ?? '',
            'lines' => $document->lines
                ->map(fn (FiscalDocumentLine $line): array => $this->lineProps($line))
                ->values()
                ->all(),
            'totals' => $this->totalsProps(
                $document->settlement_total_minor,
                $document->net_total_minor,
                $document->tax_payable_minor,
                $document->gross_total_minor,
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function lineProps(FiscalDocumentLine $line): array
    {
        /** @var FiscalDocumentLineTax|null $tax */
        $tax = $line->taxes->first();

        return [
            'operation_type' => $line->operation_type->value,
            'operation_date' => $line->operation_date?->toDateString() ?? '',
            'product_code' => $line->product_code,
            'product_description' => $line->product_description,
            'quantity' => (string) CanonicalNumber::fromScaledInteger(
                $line->quantity_units,
                $line->quantity_scale,
            ),
            'unit_of_measure' => $line->unit_of_measure,
            'unit_price' => (string) CanonicalNumber::fromMinorUnits($line->unit_price_base_minor),
            'discount_percentage' => (string) CanonicalNumber::fromBasisPoints($line->discount_rate_basis_points),
            'tax_treatment' => $this->taxTreatmentValue($tax),
        ];
    }

    /** @return array<string, string> */
    private function emptyLine(): array
    {
        return [
            'operation_type' => FiscalOperationType::GoodsTransfer->value,
            'operation_date' => now('Africa/Luanda')->toDateString(),
            'product_code' => '',
            'product_description' => '',
            'quantity' => '1',
            'unit_of_measure' => 'un',
            'unit_price' => '0',
            'discount_percentage' => '0',
            'tax_treatment' => 'IVA_NOR_14',
        ];
    }

    private function taxTreatmentValue(?FiscalDocumentLineTax $tax): string
    {
        if ($tax === null) {
            return SupportedTaxTreatment::VatNormal14->value;
        }

        return SupportedTaxTreatment::fromLegacyComponents(
            $tax->tax_type,
            $tax->tax_code,
            (string) CanonicalNumber::fromBasisPoints($tax->tax_rate_basis_points),
            $tax->tax_exemption_code,
        )?->value ?? SupportedTaxTreatment::NotSubject->value;
    }

    /** @return array<string, string> */
    /**
     * What the buyer keeps back, in the shape the form edits it.
     *
     * Only the type and the rate come back: the base and the amount are worked
     * out from the document when it is saved, so there is nothing here for the
     * form to get out of step with.
     *
     * @return list<array{type: string, rate_percentage: string}>
     */
    private function withholdingProps(FiscalDocument $document): array
    {
        $rows = [];

        foreach ($document->withholdings as $withholding) {
            $rows[] = [
                'type' => $withholding->withholding_type->value,
                'rate_percentage' => number_format(
                    $withholding->rate_basis_points / 100,
                    2,
                    '.',
                    '',
                ),
            ];
        }

        return $rows;
    }

    /** @return array<string, string> */
    private function totalsProps(int $settlement, int $net, int $tax, int $gross): array
    {
        return [
            'settlement' => (string) CanonicalNumber::fromMinorUnits($settlement),
            'net' => (string) CanonicalNumber::fromMinorUnits($net),
            'tax' => (string) CanonicalNumber::fromMinorUnits($tax),
            'gross' => (string) CanonicalNumber::fromMinorUnits($gross),
        ];
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
