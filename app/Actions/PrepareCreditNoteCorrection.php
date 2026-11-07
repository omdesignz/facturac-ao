<?php

namespace App\Actions;

use App\Fiscal\Agt\Support\CanonicalNumber;
use App\Fiscal\Documents\CreditNoteAllowance;
use App\Fiscal\Documents\CreditNoteEligibility;
use App\Fiscal\Documents\CreditNoteObstacle;
use App\Fiscal\SupportedTaxTreatment;
use App\FiscalDocumentType;
use App\Models\Customer;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLine;
use App\Models\FiscalDocumentLineTax;
use App\Models\LegalEntity;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * A credit note on the web that starts out correct.
 *
 * The note is drafted from the document it corrects: each line comes with the
 * description, unit, price, discount and tax it was charged at, and as much of
 * it as earlier notes have left. The person only lowers quantities or leaves
 * lines out. Nothing is written here; the draft is saved and issued through
 * the usual steps, which check the same allowance again.
 *
 * @phpstan-type GuideLine array{line_number: int, operation_type: string, operation_date: string, product_code: string, product_description: string, unit_of_measure: string, unit_price: string, discount_percentage: string, tax_treatment: string, quantity: string, credited_quantity: string, creditable_quantity: string}
 * @phpstan-type Guide array{source: array{public_id: string, document_no: string, document_type_label: string, document_date: string}, lines: list<GuideLine>}
 * @phpstan-type FormLine array{reference_line_number: string, origin_transport_line_public_id: string, operation_type: string, operation_date: string, product_code: string, product_description: string, quantity: string, unit_of_measure: string, unit_price: string, discount_percentage: string, tax_treatment: string}
 */
final readonly class PrepareCreditNoteCorrection
{
    public function __construct(
        private CreditNoteEligibility $eligibility,
        private CreditNoteAllowance $allowance,
    ) {}

    /**
     * Everything a new note against this document starts with.
     *
     * @return array{source: FiscalDocument, customer: Customer|null, establishment: Establishment|null, guide: Guide, lines: list<FormLine>}
     *
     * @throws ValidationException when the document cannot be corrected this way
     */
    public function execute(LegalEntity $entity, string $sourcePublicId): array
    {
        Gate::authorize('create', FiscalDocument::class);
        $source = FiscalDocument::query()
            ->where('workspace_id', $entity->workspace_id)
            ->where('legal_entity_id', $entity->id)
            ->where('public_id', $sourcePublicId)
            ->whereNotNull('document_no')
            ->firstOrFail();
        Gate::authorize('view', $source);

        $obstacle = $this->eligibility->obstacle($source);

        if ($obstacle instanceof CreditNoteObstacle) {
            throw ValidationException::withMessages(['corrigir' => $obstacle->webMessage()]);
        }

        $guide = $this->guide($source);
        $lines = [];

        foreach ($guide['lines'] as $line) {
            if ($this->allowance->scaled($line['creditable_quantity']) > 0) {
                $lines[] = $this->formLine($line, $line['creditable_quantity']);
            }
        }

        return [
            'source' => $source,
            'customer' => $this->customer($source),
            'establishment' => Establishment::query()
                ->whereKey($source->establishment_id)
                ->where('legal_entity_id', $source->legal_entity_id)
                ->where('is_active', true)
                ->first(),
            'guide' => $guide,
            'lines' => $lines,
        ];
    }

    /**
     * The guide of a draft note reopened for editing, with its lines back on
     * the original's terms, or null when the note was written by hand: one
     * that names a line twice, or a line the original does not have.
     *
     * @return array{guide: Guide, lines: list<FormLine>}|null
     */
    public function forDraft(FiscalDocument $draft): ?array
    {
        if ($draft->document_type !== FiscalDocumentType::CreditNote || ! $draft->isMutable() || $draft->references_document_id === null) {
            return null;
        }

        $source = FiscalDocument::query()
            ->whereKey($draft->references_document_id)
            ->where('workspace_id', $draft->workspace_id)
            ->where('legal_entity_id', $draft->legal_entity_id)
            ->first();

        if (! $source instanceof FiscalDocument
            || ! in_array($this->eligibility->obstacle($source), [null, CreditNoteObstacle::FullyCredited], true)) {
            return null;
        }

        $guide = $this->guide($source);
        $byNumber = collect($guide['lines'])->keyBy('line_number');
        $draftLines = $draft->lines()->orderBy('line_number')->get();
        $references = $draftLines->pluck('reference_line_number')->map(fn ($number): ?int => $number === null ? null : (int) $number);

        if ($draftLines->isEmpty()
            || $references->contains(null)
            || $references->unique()->count() !== $references->count()
            || $references->contains(fn (?int $number): bool => ! $byNumber->has($number))) {
            return null;
        }

        return [
            'guide' => $guide,
            'lines' => $draftLines->map(fn (FiscalDocumentLine $line): array => $this->formLine(
                $byNumber->get((int) $line->reference_line_number),
                $this->allowance->quantity($line),
            ))->values()->all(),
        ];
    }

    /**
     * Each line of the original on the terms it was charged, with how much of
     * it is still there to credit.
     *
     * @return Guide
     */
    private function guide(FiscalDocument $source): array
    {
        $remaining = $this->allowance->remainingByLine($source);

        return [
            'source' => [
                'public_id' => $source->public_id,
                'document_no' => (string) $source->document_no,
                'document_type_label' => $source->document_type->label(),
                'document_date' => $source->document_date->toDateString(),
            ],
            'lines' => array_values($source->lines()->with('taxes')->orderBy('line_number')->get()
                ->map(function (FiscalDocumentLine $line) use ($remaining): array {
                    $quantity = $this->allowance->scaled($this->allowance->quantity($line));
                    $left = $remaining[$line->line_number] ?? $quantity;
                    /** @var FiscalDocumentLineTax|null $tax */
                    $tax = $line->taxes->first();

                    return [
                        'line_number' => $line->line_number,
                        'operation_type' => $line->operation_type->value,
                        'operation_date' => $line->operation_date?->toDateString() ?? '',
                        'product_code' => $line->product_code,
                        'product_description' => $line->product_description,
                        'unit_of_measure' => $line->unit_of_measure,
                        'unit_price' => CanonicalNumber::fromMinorUnits($line->unit_price_base_minor)->value(),
                        'discount_percentage' => CanonicalNumber::fromBasisPoints($line->discount_rate_basis_points)->value(),
                        'tax_treatment' => ($tax instanceof FiscalDocumentLineTax ? SupportedTaxTreatment::fromLegacyComponents(
                            $tax->tax_type,
                            $tax->tax_code,
                            CanonicalNumber::fromBasisPoints($tax->tax_rate_basis_points)->value(),
                            $tax->tax_exemption_code,
                        ) : null)?->value ?? SupportedTaxTreatment::NotSubject->value,
                        'quantity' => $this->allowance->quantity($line),
                        'credited_quantity' => $this->allowance->unscaled($quantity - $left),
                        'creditable_quantity' => $this->allowance->unscaled($left),
                    ];
                })
                ->all()),
        ];
    }

    /**
     * @param  GuideLine  $line
     * @return FormLine
     */
    private function formLine(array $line, string $quantity): array
    {
        return [
            'reference_line_number' => (string) $line['line_number'],
            'origin_transport_line_public_id' => '',
            'operation_type' => $line['operation_type'],
            'operation_date' => $line['operation_date'],
            'product_code' => $line['product_code'],
            'product_description' => $line['product_description'],
            'quantity' => $quantity,
            'unit_of_measure' => $line['unit_of_measure'],
            'unit_price' => $line['unit_price'],
            'discount_percentage' => $line['discount_percentage'],
            'tax_treatment' => $line['tax_treatment'],
        ];
    }

    /**
     * The original's customer while they are still an active one; otherwise
     * the note carries the details the original was issued with.
     */
    private function customer(FiscalDocument $source): ?Customer
    {
        return $source->customer_id === null ? null : Customer::query()
            ->whereKey($source->customer_id)
            ->where('legal_entity_id', $source->legal_entity_id)
            ->where('is_active', true)
            ->first();
    }
}
