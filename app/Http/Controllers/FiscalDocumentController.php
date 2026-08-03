<?php

namespace App\Http\Controllers;

use App\Actions\SaveFiscalDocumentDraft;
use App\Fiscal\Agt\Support\CanonicalNumber;
use App\FiscalDocumentType;
use App\FiscalOperationType;
use App\FiscalSeriesContingency;
use App\FiscalSeriesStatus;
use App\Http\Requests\StoreFiscalDocumentRequest;
use App\Http\Requests\UpdateFiscalDocumentRequest;
use App\Models\Customer;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLine;
use App\Models\FiscalDocumentLineTax;
use App\Models\FiscalSeries;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class FiscalDocumentController extends Controller
{
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

        return Inertia::render('Invoices/Create', $this->pageProps($legalEntity));
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

        $fiscalDocument->load(['customer', 'lines.taxes']);

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
            'customers' => $customers->map(fn (Customer $customer): array => [
                'public_id' => $customer->public_id,
                'name' => $customer->name,
                'tax_identification_number' => $customer->tax_identification_number,
                'country_code' => $customer->country_code,
                'address_line' => $customer->address_line,
            ])->values()->all(),
            'operationTypes' => collect(FiscalOperationType::cases())
                ->map(fn (FiscalOperationType $type): array => [
                    'value' => $type->value,
                    'label' => $type->label(),
                ])->all(),
            'taxTreatments' => [
                [
                    'value' => 'IVA_NOR_14',
                    'label' => 'IVA · taxa normal (14%)',
                    'type' => 'IVA',
                    'code' => 'NOR',
                    'percentage' => '14',
                    'exemption_code' => null,
                ],
                [
                    'value' => 'IVA_ISE_M00',
                    'label' => 'Isento · regime simplificado (M00)',
                    'type' => 'IVA',
                    'code' => 'ISE',
                    'percentage' => '0',
                    'exemption_code' => 'M00',
                ],
                [
                    'value' => 'NS_M02',
                    'label' => 'Não sujeito (M02)',
                    'type' => 'NS',
                    'code' => null,
                    'percentage' => '0',
                    'exemption_code' => 'M02',
                ],
                [
                    'value' => 'IVA_ISE_M04',
                    'label' => 'Isento · exclusão (M04)',
                    'type' => 'IVA',
                    'code' => 'ISE',
                    'percentage' => '0',
                    'exemption_code' => 'M04',
                ],
            ],
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
            'document' => $this->documentProps($document, $establishments->first()?->public_id),
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
    ): array {
        if (! $document instanceof FiscalDocument) {
            return [
                'public_id' => null,
                'revision' => 0,
                'status' => 'draft',
                'document_no' => null,
                'document_type' => FiscalDocumentType::Invoice->value,
                'document_date' => now('Africa/Luanda')->toDateString(),
                'due_date' => now('Africa/Luanda')->addDays(30)->toDateString(),
                'currency_code' => 'AOA',
                'establishment_public_id' => $defaultEstablishmentPublicId,
                'customer_public_id' => null,
                'customer' => [
                    'name' => '',
                    'tax_identification_number' => '',
                    'country_code' => 'AO',
                    'address_line' => '',
                ],
                'notes' => '',
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
            'establishment_public_id' => $document->establishment->public_id,
            'customer_public_id' => $document->customer?->public_id,
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
            return 'IVA_NOR_14';
        }

        return match (true) {
            $tax->tax_type->value === 'IVA' && $tax->tax_code === 'NOR' => 'IVA_NOR_14',
            $tax->tax_type->value === 'IVA' && $tax->tax_exemption_code === 'M00' => 'IVA_ISE_M00',
            $tax->tax_type->value === 'IVA' && $tax->tax_exemption_code === 'M04' => 'IVA_ISE_M04',
            default => 'NS_M02',
        };
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
