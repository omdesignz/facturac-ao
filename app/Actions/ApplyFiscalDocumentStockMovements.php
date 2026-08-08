<?php

namespace App\Actions;

use App\Models\CatalogueItem;
use App\Models\FiscalDocument;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Draws stock down when a document is issued, and puts it back on a credit note.
 *
 * Runs inside the issuance transaction: if the document does not get issued, no
 * stock moved, and if stock cannot move the document does not get issued either.
 * Half of that pair happening is how an inventory silently stops matching the
 * shelf.
 */
class ApplyFiscalDocumentStockMovements
{
    public function __construct(private RecordStockMovement $recordStockMovement) {}

    /**
     * @return int Number of lines that moved stock.
     */
    public function execute(FiscalDocument $document, ?User $issuer = null): int
    {
        $effect = $document->document_type->stockEffect();

        if ($effect === null || $document->lines->isEmpty()) {
            return 0;
        }

        $establishment = $document->establishment;
        $items = $this->trackedItems($document);
        $moved = 0;

        foreach ($document->lines as $line) {
            $item = $items->get($line->product_code);

            if (! $item instanceof CatalogueItem) {
                continue;
            }

            $quantity = $this->rescale(
                $line->quantity_units,
                $line->quantity_scale,
                $item->stock_scale,
            );

            if ($quantity <= 0) {
                continue;
            }

            $this->recordStockMovement->execute(
                item: $item,
                establishment: $establishment,
                type: $effect,
                quantityUnits: $quantity,
                actor: $issuer,
                unitCostMicros: null,
                fiscalDocumentId: $document->id,
                reference: $document->document_no,
            );

            $moved++;
        }

        return $moved;
    }

    /**
     * The stock-tracked catalogue items behind this document's lines, keyed by
     * the product code the line recorded.
     *
     * Lines store a copy of the code rather than a foreign key, because a
     * document must keep saying what it said even if the article is later
     * renamed or deleted. That copy is the only link back.
     *
     * @return Collection<string, CatalogueItem>
     */
    private function trackedItems(FiscalDocument $document): Collection
    {
        $codes = $document->lines
            ->pluck('product_code')
            ->filter()
            ->unique()
            ->all();

        if ($codes === []) {
            return collect();
        }

        return CatalogueItem::query()
            ->where('legal_entity_id', $document->legal_entity_id)
            ->where('tracks_stock', true)
            ->whereIn('code', $codes)
            ->get()
            ->keyBy('code');
    }

    /**
     * Converts a quantity between two decimal scales without floats.
     *
     * Scaling down truncates, which can only ever move less stock than the line
     * sold — the safe direction for a rounding decision nobody will review.
     */
    private function rescale(int $units, int $fromScale, int $toScale): int
    {
        if ($fromScale === $toScale) {
            return $units;
        }

        return $fromScale < $toScale
            ? $units * (10 ** ($toScale - $fromScale))
            : intdiv($units, 10 ** ($fromScale - $toScale));
    }
}
