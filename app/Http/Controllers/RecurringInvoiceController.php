<?php

namespace App\Http\Controllers;

use App\Actions\GenerateRecurringInvoices;
use App\Fiscal\Agt\Support\CanonicalNumber;
use App\Fiscal\Calculation\FiscalCalculator;
use App\Fiscal\SupportedTaxTreatment;
use App\FiscalDocumentType;
use App\Http\Requests\StoreRecurringInvoiceRequest;
use App\Models\CatalogueItem;
use App\Models\Customer;
use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\RecurringInvoice;
use App\Models\Workspace;
use App\RecurrenceFrequency;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Standing arrangements for continuous services.
 */
class RecurringInvoiceController extends Controller
{
    public function __construct(private FiscalCalculator $calculator) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $legalEntity = $this->legalEntity($request);

        if (! $legalEntity instanceof LegalEntity) {
            return redirect()
                ->route('onboarding')
                ->with('error', 'Conclua primeiro a identidade fiscal da empresa.');
        }

        Gate::authorize('viewAny', Customer::class);

        $profiles = RecurringInvoice::query()
            ->with('customer')
            ->where('legal_entity_id', $legalEntity->id)
            ->orderByDesc('is_active')
            ->orderBy('next_run_on')
            ->get();

        return Inertia::render('Recurring/Index', [
            'profiles' => array_values($profiles
                ->map(fn (RecurringInvoice $profile): array => $this->present($profile))
                ->all()),
            'customers' => $this->customerOptions($legalEntity),
            'establishments' => $this->establishmentOptions($legalEntity),
            'catalogueItems' => $this->catalogueOptions($legalEntity),
            'frequencies' => RecurrenceFrequency::options(),
            'documentTypes' => [
                [
                    'value' => FiscalDocumentType::Invoice->value,
                    'label' => FiscalDocumentType::Invoice->label(),
                ],
                [
                    'value' => FiscalDocumentType::InvoiceReceipt->value,
                    'label' => FiscalDocumentType::InvoiceReceipt->label(),
                ],
            ],
            'currencyCode' => $legalEntity->currency_code,
        ]);
    }

    public function store(StoreRecurringInvoiceRequest $request): RedirectResponse
    {
        $legalEntity = $this->legalEntity($request);
        abort_unless($legalEntity instanceof LegalEntity, 404);
        Gate::authorize('create', Customer::class);

        $profile = new RecurringInvoice;
        $this->fill($profile, $legalEntity, $request);
        $profile->save();

        return back()->with('success', "Avença “{$profile->name}” criada.");
    }

    public function update(
        StoreRecurringInvoiceRequest $request,
        RecurringInvoice $recurringInvoice,
    ): RedirectResponse {
        $legalEntity = $this->legalEntity($request);
        abort_unless($legalEntity instanceof LegalEntity, 404);
        abort_unless($recurringInvoice->legal_entity_id === $legalEntity->id, 404);
        Gate::authorize('create', Customer::class);

        $this->fill($recurringInvoice, $legalEntity, $request);
        $recurringInvoice->save();

        return back()->with('success', 'Avença actualizada.');
    }

    public function destroy(Request $request, RecurringInvoice $recurringInvoice): RedirectResponse
    {
        $legalEntity = $this->legalEntity($request);
        abort_unless($legalEntity instanceof LegalEntity, 404);
        abort_unless($recurringInvoice->legal_entity_id === $legalEntity->id, 404);
        Gate::authorize('create', Customer::class);

        // Deactivated rather than deleted: the documents it already raised
        // reference an arrangement that should stay explainable.
        $recurringInvoice->forceFill(['is_active' => false])->save();

        return back()->with('success', 'Avença desactivada. Deixa de gerar documentos.');
    }

    /** Runs the due profiles now instead of waiting for the daily schedule. */
    public function run(
        Request $request,
        GenerateRecurringInvoices $generate,
    ): RedirectResponse {
        $legalEntity = $this->legalEntity($request);
        abort_unless($legalEntity instanceof LegalEntity, 404);
        Gate::authorize('create', Customer::class);

        $result = $generate->execute();

        return back()->with(
            'success',
            $result['generated'] === 0
                ? 'Nenhuma avença estava por gerar.'
                : "{$result['generated']} documento(s) gerado(s).",
        );
    }

    private function fill(
        RecurringInvoice $profile,
        LegalEntity $legalEntity,
        StoreRecurringInvoiceRequest $request,
    ): void {
        $customer = Customer::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->where('public_id', (string) $request->validated('customer_public_id'))
            ->firstOrFail();

        $establishment = Establishment::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->where('public_id', (string) $request->validated('establishment_public_id'))
            ->firstOrFail();

        $startsOn = CarbonImmutable::parse((string) $request->validated('starts_on'));

        $profile->fill([
            'workspace_id' => $legalEntity->workspace_id,
            'legal_entity_id' => $legalEntity->id,
            'establishment_id' => $establishment->id,
            'customer_id' => $customer->id,
            'created_by_user_id' => $profile->created_by_user_id ?? $request->user()->id,
            'name' => (string) $request->validated('name'),
            'document_type' => (string) $request->validated('document_type'),
            'frequency' => (string) $request->validated('frequency'),
            'is_active' => (bool) $request->validated('is_active'),
            'auto_issue' => (bool) $request->validated('auto_issue'),
            'starts_on' => $startsOn->toDateString(),
            'ends_on' => $request->validated('ends_on'),
            'lines' => $request->lines(),
            'notes' => $request->validated('notes'),
        ]);

        // A new profile runs first on its start date; an existing one keeps the
        // schedule it is already on so editing the price does not re-bill.
        if (! $profile->exists) {
            $profile->next_run_on = $startsOn;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(RecurringInvoice $profile): array
    {
        $calculation = $this->calculator->calculate(array_map(
            fn (array $line): array => [
                'operation_type' => (string) ($line['operation_type'] ?? 'SG'),
                'product_code' => (string) ($line['product_code'] ?? ''),
                'product_description' => (string) ($line['product_description'] ?? ''),
                'quantity' => (string) CanonicalNumber::fromScaledInteger(
                    (int) ($line['quantity_units'] ?? 0),
                    (int) ($line['quantity_scale'] ?? 3),
                ),
                'unit_of_measure' => (string) ($line['unit_of_measure'] ?? 'UN'),
                'unit_price' => (string) CanonicalNumber::fromMinorUnits(
                    (int) ($line['unit_price_minor'] ?? 0),
                ),
                'discount_percentage' => (string) CanonicalNumber::fromBasisPoints(
                    (int) ($line['discount_rate_basis_points'] ?? 0),
                ),
                'tax_type' => (string) ($line['tax_type'] ?? 'IVA'),
                'tax_code' => $line['tax_code'] ?? null,
                'tax_percentage' => (string) ($line['tax_percentage'] ?? '14'),
                'tax_exemption_code' => $line['tax_exemption_code'] ?? null,
            ],
            $profile->lines,
        ));

        return [
            'public_id' => $profile->public_id,
            'name' => $profile->name,
            'customer_name' => $profile->customer->name,
            'customer_public_id' => $profile->customer->public_id,
            'establishment_public_id' => $profile->establishment->public_id,
            'document_type' => $profile->document_type->value,
            'document_type_label' => $profile->document_type->label(),
            'frequency' => $profile->frequency->value,
            'frequency_label' => $profile->frequency->label(),
            'is_active' => $profile->is_active,
            'auto_issue' => $profile->auto_issue,
            'starts_on' => $profile->starts_on->toIso8601String(),
            'ends_on' => $profile->ends_on?->toIso8601String(),
            'next_run_on' => $profile->next_run_on->toIso8601String(),
            'last_run_at' => $profile->last_run_at?->toIso8601String(),
            'generated_count' => $profile->generated_count,
            'estimated_total_minor' => $calculation->grossTotalMinor,
            'notes' => $profile->notes,
            'lines' => array_map(
                function (array $line): array {
                    $treatment = SupportedTaxTreatment::fromLegacyComponents(
                        $line['tax_type'] ?? 'IVA',
                        $line['tax_code'] ?? null,
                        $line['tax_percentage'] ?? '14',
                        $line['tax_exemption_code'] ?? null,
                    );
                    $tax = $treatment?->profile();

                    return [
                        'product_code' => $line['product_code'] ?? null,
                        'product_description' => $line['product_description'] ?? '',
                        'unit_of_measure' => $line['unit_of_measure'] ?? 'UN',
                        'quantity' => (string) CanonicalNumber::fromScaledInteger(
                            (int) ($line['quantity_units'] ?? 0),
                            (int) ($line['quantity_scale'] ?? 3),
                        ),
                        'unit_price' => (string) CanonicalNumber::fromMinorUnits(
                            (int) ($line['unit_price_minor'] ?? 0),
                        ),
                        'tax_type' => $tax['type'] ?? ($line['tax_type'] ?? 'IVA'),
                        'tax_code' => $tax !== null ? $tax['code'] : ($line['tax_code'] ?? null),
                        'tax_percentage' => $tax['percentage'] ?? ($line['tax_percentage'] ?? '14'),
                        'tax_exemption_code' => $tax !== null
                            ? $tax['exemption_code']
                            : ($line['tax_exemption_code'] ?? null),
                    ];
                },
                $profile->lines,
            ),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function customerOptions(LegalEntity $legalEntity): array
    {
        return array_values($legalEntity->customers()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (Customer $customer): array => [
                'value' => $customer->public_id,
                'label' => $customer->name,
            ])->all());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function establishmentOptions(LegalEntity $legalEntity): array
    {
        return array_values($legalEntity->establishments()
            ->where('is_active', true)
            ->orderByDesc('is_head_office')
            ->get()
            ->map(fn (Establishment $establishment): array => [
                'value' => $establishment->public_id,
                'label' => $establishment->name,
            ])->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function catalogueOptions(LegalEntity $legalEntity): array
    {
        return array_values($legalEntity->catalogueItems()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (CatalogueItem $item): array => [
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
            ])->all());
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
