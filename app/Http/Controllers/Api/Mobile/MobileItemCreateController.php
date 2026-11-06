<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Actions\CreateMobileRecord;
use App\Http\Requests\StoreMobileCatalogueItemRequest;
use App\Models\CatalogueItem;
use App\Models\LegalEntity;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * A new article from the phone, usually in the middle of a sale. It answers with
 * the same row the list gives, so the sale can carry on with it.
 */
class MobileItemCreateController
{
    public function __invoke(StoreMobileCatalogueItemRequest $request, CreateMobileRecord $records): JsonResponse
    {
        if (Gate::denies('create', CatalogueItem::class)) {
            return response()->json(['error' => [
                'code' => 'ITEM_NOT_ALLOWED',
                'message' => 'A sua função nesta empresa não permite criar artigos.',
            ]], Response::HTTP_FORBIDDEN);
        }

        /** @var LegalEntity $legalEntity */
        $legalEntity = $request->attributes->get('currentLegalEntity');
        /** @var User $user */
        $user = $request->user();

        $record = $records->item(
            $legalEntity,
            $user,
            (string) $request->validated('client_key'),
            $request->itemValues(),
            $request->productAttributes(...),
        );

        /** @var CatalogueItem $item */
        $item = $record->catalogueItem;

        return response()->json(
            ['data' => MobileItemController::present($item)],
            $record->replayed ? Response::HTTP_OK : Response::HTTP_CREATED,
        );
    }
}
