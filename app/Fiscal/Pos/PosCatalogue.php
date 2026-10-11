<?php

namespace App\Fiscal\Pos;

use App\Models\CatalogueItem;
use App\Models\FiscalDocument;
use App\Models\PosSession;
use App\Models\StockLevel;
use Illuminate\Support\Collection;

/**
 * What a till is shown to sell, and how much of it is on the shelf.
 */
final class PosCatalogue
{
    /** The most articles one till is sent; beyond it, search belongs on the server. */
    public const LIMIT = 1000;

    /**
     * @return list<array{
     *     public_id: string,
     *     code: string,
     *     barcode: string|null,
     *     name: string,
     *     type: string,
     *     unit_of_measure: string,
     *     unit_price_minor: int,
     *     tax_type: string,
     *     tax_code: string|null,
     *     tax_percentage: string,
     *     tax_exemption_code: string|null,
     *     tracks_stock: bool,
     *     quantity_on_hand: string|null
     * }>
     */
    public function forSession(PosSession $session): array
    {
        $items = CatalogueItem::query()
            ->where('workspace_id', $session->workspace_id)
            ->where('legal_entity_id', $session->legal_entity_id)
            ->where('is_active', true)
            ->where('currency_code', $session->currency_code)
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get();

        $levels = $this->levels($session, $items);

        return array_values($items->map(fn (CatalogueItem $item): array => [
            'public_id' => $item->public_id,
            'code' => $item->code,
            'barcode' => $item->barcode,
            'name' => $item->name,
            'type' => $item->type->value,
            'unit_of_measure' => $item->unit_of_measure,
            'unit_price_minor' => $item->unit_price_minor,
            'tax_type' => $item->tax_type,
            'tax_code' => $item->tax_code,
            'tax_percentage' => (string) $item->tax_percentage,
            'tax_exemption_code' => $item->tax_exemption_code,
            'tracks_stock' => $item->tracks_stock,
            'quantity_on_hand' => $item->tracks_stock
                ? $this->decimal($this->quantity($levels, $item), $item->stock_scale)
                : null,
        ])->all());
    }

    /**
     * The stock-tracked articles a sale just sold, with what is left of each.
     *
     * Read back from the document's own lines, which keep the article's code,
     * so it reports exactly what the issuing step drew down.
     *
     * @return list<array{catalogue_item_public_id: string, quantity_on_hand: string}>
     */
    public function afterSale(PosSession $session, FiscalDocument $document): array
    {
        $document->loadMissing('lines');

        $items = CatalogueItem::query()
            ->where('legal_entity_id', $session->legal_entity_id)
            ->where('tracks_stock', true)
            ->whereIn('code', $document->lines->pluck('product_code')->unique()->all())
            ->get();

        $levels = $this->levels($session, $items);

        return array_values($items->map(fn (CatalogueItem $item): array => [
            'catalogue_item_public_id' => $item->public_id,
            'quantity_on_hand' => $this->decimal($this->quantity($levels, $item), $item->stock_scale),
        ])->all());
    }

    /**
     * @param  Collection<int, CatalogueItem>  $items
     * @return Collection<int, StockLevel> keyed by catalogue item id
     */
    private function levels(PosSession $session, Collection $items): Collection
    {
        $trackedIds = $items->where('tracks_stock', true)->pluck('id')->all();

        if ($trackedIds === []) {
            return collect();
        }

        return StockLevel::query()
            ->where('establishment_id', $session->establishment_id)
            ->whereIn('catalogue_item_id', $trackedIds)
            ->get()
            ->keyBy('catalogue_item_id');
    }

    /**
     * @param  Collection<int, StockLevel>  $levels
     */
    private function quantity(Collection $levels, CatalogueItem $item): int
    {
        $level = $levels->get($item->id);

        return $level instanceof StockLevel ? $level->quantity_units : 0;
    }

    /** Scaled units back into a plain decimal string, never through a float. */
    private function decimal(int $units, int $scale): string
    {
        if ($scale === 0) {
            return (string) $units;
        }

        $negative = $units < 0;
        $digits = str_pad((string) abs($units), $scale + 1, '0', STR_PAD_LEFT);
        $whole = substr($digits, 0, -$scale);
        $fraction = rtrim(substr($digits, -$scale), '0');

        return ($negative ? '-' : '').$whole.($fraction === '' ? '' : ".{$fraction}");
    }
}
