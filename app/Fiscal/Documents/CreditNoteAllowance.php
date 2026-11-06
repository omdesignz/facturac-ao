<?php

namespace App\Fiscal\Documents;

use App\Fiscal\Agt\Support\CanonicalNumber;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLine;
use App\Models\FiscalDocumentLineTax;

/**
 * What a credit note may still take from the document it corrects.
 *
 * Each line of the original can be credited once in full, across however many
 * notes: whatever issued notes have already taken of it is gone. A note also
 * credits a line in the unit, and at the price, discount and tax, it was
 * charged at, so it can only give back what the customer actually paid.
 * Saving a draft checks this so the form can point at the line; issuing
 * checks it again under a lock on the original, so two notes cannot both
 * take the last of a line.
 *
 * @phpstan-type Refusal array{field: string, message: string, line_number: int|null}
 */
final class CreditNoteAllowance
{
    /** Quantities are compared at the finest scale a line may have. */
    public const int SCALE = 4;

    /**
     * Holds the original until the transaction ends, so a second note against
     * it waits for this one to be issued before counting what is left.
     */
    public function lockSource(FiscalDocument $note): void
    {
        if ($note->document_type !== FiscalDocumentType::CreditNote || $note->references_document_id === null) {
            return;
        }

        $this->source($note, lock: true);
    }

    /**
     * Why this note cannot credit its lines against the original, or null when
     * it can. References themselves are AdjustmentLineReference's to check;
     * a line without a valid one is left alone here.
     *
     * @return Refusal|null
     */
    public function refusal(FiscalDocument $note): ?array
    {
        if ($note->document_type !== FiscalDocumentType::CreditNote || $note->references_document_id === null) {
            return null;
        }

        $source = $this->source($note);

        if (! $source instanceof FiscalDocument) {
            return null;
        }

        if ($note->currency_code !== $source->currency_code) {
            return [
                'field' => 'currency_code',
                'message' => "A nota de crédito tem de usar a moeda do documento original ({$source->currency_code}).",
                'line_number' => null,
            ];
        }

        $sourceLines = $source->lines()->with('taxes')->get()->keyBy('line_number');
        $remaining = $this->remainingByLine($source);

        foreach ($note->lines()->with('taxes')->get()->values() as $index => $line) {
            $original = $sourceLines->get((int) $line->reference_line_number);

            if (! $original instanceof FiscalDocumentLine) {
                continue;
            }

            $mismatch = $this->termsMismatch($line, $original);

            if ($mismatch !== null) {
                return ['field' => "lines.{$index}.{$mismatch['field']}", 'message' => $mismatch['message'], 'line_number' => $line->line_number];
            }

            $left = $remaining[$original->line_number] ?? 0;
            $requested = $this->scaled($this->quantity($line));

            if ($requested > $left) {
                return [
                    'field' => "lines.{$index}.quantity",
                    'message' => "Só pode creditar até {$this->unscaled($left)} {$original->unit_of_measure} de {$original->product_description}.",
                    'line_number' => $line->line_number,
                ];
            }

            // Two lines of one note crediting the same original line share it.
            $remaining[$original->line_number] = $left - $requested;
        }

        return null;
    }

    /**
     * How much of each line of the original is still there to credit, at SCALE.
     *
     * @return array<int, int>
     */
    public function remainingByLine(FiscalDocument $source): array
    {
        $credited = $this->creditedByLine($source);

        return $source->lines()->get(['line_number', 'quantity_units', 'quantity_scale'])
            ->mapWithKeys(fn (FiscalDocumentLine $line): array => [
                $line->line_number => max(0, $this->scaled($this->quantity($line)) - ($credited[$line->line_number] ?? 0)),
            ])
            ->all();
    }

    public function quantity(FiscalDocumentLine $line): string
    {
        return CanonicalNumber::fromScaledInteger($line->quantity_units, $line->quantity_scale)->value();
    }

    public function scaled(string $quantity): int
    {
        [$whole, $fraction] = array_pad(explode('.', $quantity, 2), 2, '');

        return (int) $whole * 10 ** self::SCALE + (int) str_pad(substr($fraction, 0, self::SCALE), self::SCALE, '0');
    }

    public function unscaled(int $quantity): string
    {
        return CanonicalNumber::fromScaledInteger($quantity, self::SCALE)->value();
    }

    /**
     * How much of each line issued credit notes have already taken, at SCALE.
     * A note the AGT returned still counts: whether one may be replaced is a
     * question the AGT has not answered yet.
     *
     * @return array<int, int>
     */
    private function creditedByLine(FiscalDocument $source): array
    {
        $credited = [];

        FiscalDocumentLine::query()
            ->whereHas('fiscalDocument', fn ($query) => $query
                ->where('references_document_id', $source->id)
                ->where('legal_entity_id', $source->legal_entity_id)
                ->where('document_type', FiscalDocumentType::CreditNote)
                ->where('status', '!=', FiscalDocumentStatus::Draft))
            ->whereNotNull('reference_line_number')
            ->get(['reference_line_number', 'quantity_units', 'quantity_scale'])
            ->each(function (FiscalDocumentLine $line) use (&$credited): void {
                $credited[(int) $line->reference_line_number] = ($credited[(int) $line->reference_line_number] ?? 0)
                    + $this->scaled($this->quantity($line));
            });

        return $credited;
    }

    /**
     * @return array{field: string, message: string}|null
     */
    private function termsMismatch(FiscalDocumentLine $line, FiscalDocumentLine $original): ?array
    {
        // Quantities only compare in the same unit: 2 kg is not 2 of a line in g.
        if ($line->unit_of_measure !== $original->unit_of_measure) {
            return ['field' => 'unit_of_measure', 'message' => "A unidade tem de ser a do documento original: {$original->unit_of_measure}."];
        }

        if ((int) $line->unit_price_base_minor !== (int) $original->unit_price_base_minor) {
            $price = CanonicalNumber::fromMinorUnits((int) $original->unit_price_base_minor)->value();

            return ['field' => 'unit_price', 'message' => "O preço unitário tem de ser o do documento original: {$price}."];
        }

        if ((int) $line->discount_rate_basis_points !== (int) $original->discount_rate_basis_points) {
            $discount = CanonicalNumber::fromBasisPoints((int) $original->discount_rate_basis_points)->value();

            return ['field' => 'discount_percentage', 'message' => "O desconto tem de ser o do documento original: {$discount}%."];
        }

        /** @var FiscalDocumentLineTax|null $tax */
        $tax = $line->taxes->first();
        /** @var FiscalDocumentLineTax|null $originalTax */
        $originalTax = $original->taxes->first();

        if (! $tax instanceof FiscalDocumentLineTax || ! $originalTax instanceof FiscalDocumentLineTax
            || $line->taxes->count() !== $original->taxes->count()
            || $tax->tax_type !== $originalTax->tax_type
            || $tax->tax_code !== $originalTax->tax_code
            || (int) $tax->tax_rate_basis_points !== (int) $originalTax->tax_rate_basis_points
            || $tax->tax_exemption_code !== $originalTax->tax_exemption_code) {
            $rate = $originalTax instanceof FiscalDocumentLineTax
                ? ": {$originalTax->tax_type->value} ".CanonicalNumber::fromBasisPoints($originalTax->tax_rate_basis_points)->value().'%'
                : '';

            return ['field' => 'tax', 'message' => "O imposto tem de ser o do documento original{$rate}."];
        }

        return null;
    }

    private function source(FiscalDocument $note, bool $lock = false): ?FiscalDocument
    {
        return FiscalDocument::query()
            ->whereKey($note->references_document_id)
            ->where('workspace_id', $note->workspace_id)
            ->where('legal_entity_id', $note->legal_entity_id)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
    }
}
