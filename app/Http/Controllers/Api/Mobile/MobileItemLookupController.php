<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Models\CatalogueItem;
use App\Models\LegalEntity;
use App\Models\StockLevel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The item a scanned code belongs to. Exact, unlike the search box: a camera that
 * reads a code must never land on a different item whose code merely contains it.
 * A label may carry the barcode or the item's own code, and the barcode comes first.
 */
class MobileItemLookupController
{
    public function __invoke(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', CatalogueItem::class);

        /** @var LegalEntity $legalEntity */
        $legalEntity = $request->attributes->get('currentLegalEntity');
        $code = trim((string) $request->validate(['code' => ['required', 'string', 'max:64']])['code']);

        $items = $legalEntity->catalogueItems()->where('is_active', true);
        $item = (clone $items)->where('barcode', $code)->first()
            ?? (clone $items)->where('code', Str::upper($code))->first();

        if (! $item instanceof CatalogueItem) {
            return response()->json(['error' => [
                'code' => 'ITEM_NOT_FOUND',
                'message' => 'Nenhum artigo tem este código.',
            ]], Response::HTTP_NOT_FOUND);
        }

        $levels = $item->tracks_stock
            ? StockLevel::query()->with(['catalogueItem', 'establishment'])->where('catalogue_item_id', $item->id)->get()
            : collect();

        return response()->json(['data' => [
            'item' => MobileItemController::present($item),
            'tracks_stock' => $item->tracks_stock,
            'stock' => array_values($levels->map(fn (StockLevel $level): array => MobileStockController::present($level))->all()),
        ]]);
    }
}
