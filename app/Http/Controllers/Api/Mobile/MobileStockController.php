<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Requests\MobileListRequest;
use App\Models\CatalogueItem;
use App\Models\LegalEntity;
use App\Models\StockLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class MobileStockController
{
    public function __invoke(MobileListRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', CatalogueItem::class);

        /** @var LegalEntity $legalEntity */
        $legalEntity = $request->attributes->get('currentLegalEntity');
        $search = $request->search();

        $levels = StockLevel::query()
            ->with(['catalogueItem', 'establishment'])
            ->where('legal_entity_id', $legalEntity->id)
            ->whereHas('catalogueItem', fn (Builder $item) => $item
                ->where('tracks_stock', true)
                ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $matching) use ($search): void {
                    $matching
                        ->whereSearchLike('name', "%{$search}%")
                        ->orWhereSearchLike('code', "%{$search}%");
                })))
            ->orderBy(CatalogueItem::query()->select('name')->whereColumn('catalogue_items.id', 'stock_levels.catalogue_item_id'))
            ->paginate(25);

        return $request->pageOf($levels, array_values($levels->getCollection()
            ->map(fn (StockLevel $level): array => self::present($level))
            ->all()));
    }

    /**
     * @return array{id: int, name: string, establishment: string, quantity: string, unit_of_measure: string, is_low: bool}
     */
    public static function present(StockLevel $level): array
    {
        return [
            'id' => $level->id,
            'name' => $level->catalogueItem->name,
            'establishment' => $level->establishment->name,
            'quantity' => self::quantity($level->quantity_units, $level->quantity_scale),
            'unit_of_measure' => $level->catalogueItem->unit_of_measure,
            'is_low' => $level->isBelowReorderLevel(),
        ];
    }

    private static function quantity(int $units, int $scale): string
    {
        $quantity = number_format($units / (10 ** $scale), $scale, ',', ' ');

        return $scale === 0 ? $quantity : rtrim(rtrim($quantity, '0'), ',');
    }
}
