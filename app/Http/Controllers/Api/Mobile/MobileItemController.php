<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Requests\MobileListRequest;
use App\Models\CatalogueItem;
use App\Models\LegalEntity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class MobileItemController
{
    public function __invoke(MobileListRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', CatalogueItem::class);

        /** @var LegalEntity $legalEntity */
        $legalEntity = $request->attributes->get('currentLegalEntity');
        $search = $request->search();

        $items = $legalEntity->catalogueItems()
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $matching) use ($search): void {
                $matching
                    ->whereSearchLike('name', "%{$search}%")
                    ->orWhereSearchLike('code', "%{$search}%")
                    ->orWhereSearchLike('barcode', "%{$search}%");
            }))
            ->orderBy('name')
            ->paginate(25);

        return $request->pageOf($items, array_values($items->getCollection()
            ->map(fn (CatalogueItem $item): array => [
                'public_id' => $item->public_id,
                'code' => $item->code,
                'name' => $item->name,
                'type_label' => $item->type->label(),
                'unit_of_measure' => $item->unit_of_measure,
                'unit_price_minor' => $item->unit_price_minor,
                'currency_code' => $item->currency_code,
            ])
            ->all()));
    }
}
