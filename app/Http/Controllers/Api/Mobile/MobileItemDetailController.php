<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Fiscal\SupportedTaxTreatment;
use App\Http\Requests\UpdateMobileCatalogueItemRequest;
use App\Models\CatalogueItem;
use App\Models\LegalEntity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * One article on the phone, and changing it. A new price or tax counts from the
 * next document: issued lines keep the price and tax they were signed with.
 */
class MobileItemDetailController
{
    public function show(Request $request, string $itemPublicId): JsonResponse
    {
        $item = $this->item($request, $itemPublicId);

        Gate::authorize('view', $item);

        return response()->json(['data' => $this->details($item)]);
    }

    public function update(UpdateMobileCatalogueItemRequest $request, string $itemPublicId): JsonResponse
    {
        $item = $this->item($request, $itemPublicId);

        if (Gate::denies('update', $item)) {
            return response()->json(['error' => [
                'code' => 'ITEM_NOT_ALLOWED',
                'message' => 'A sua função nesta empresa não permite alterar artigos.',
            ]], Response::HTTP_FORBIDDEN);
        }

        $item->update($request->itemAttributes());

        return response()->json(['data' => $this->details($item->refresh())]);
    }

    private function item(Request $request, string $publicId): CatalogueItem
    {
        /** @var LegalEntity $legalEntity */
        $legalEntity = $request->attributes->get('currentLegalEntity');

        return $legalEntity->catalogueItems()->where('public_id', $publicId)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function details(CatalogueItem $item): array
    {
        return [
            ...MobileItemController::present($item),
            'barcode' => $item->barcode,
            'tax_treatment' => SupportedTaxTreatment::fromComponents(
                $item->tax_type,
                $item->tax_code,
                $item->tax_percentage,
                $item->tax_exemption_code,
            )?->value,
            'tracks_stock' => $item->tracks_stock,
            'is_active' => $item->is_active,
            'can_edit' => Gate::allows('update', $item),
        ];
    }
}
