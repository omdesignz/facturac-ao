<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Actions\UpdateCollectionFollowUp;
use App\Analytics\CollectionFollowUps;
use App\Http\Requests\UpdateCollectionFollowUpRequest;
use App\Models\LegalEntity;
use Illuminate\Http\JsonResponse;

class MobileCustomerFollowUpController
{
    public function update(UpdateCollectionFollowUpRequest $request, string $customerPublicId, UpdateCollectionFollowUp $update, CollectionFollowUps $followUps): JsonResponse
    {
        /** @var LegalEntity $entity */
        $entity = $request->attributes->get('currentLegalEntity');
        $customer = $entity->customers()->where('workspace_id', $entity->workspace_id)->where('public_id', $customerPublicId)->firstOrFail();
        $saved = $update->execute($customer, $request->user(), $request->followUp());

        return response()->json(['data' => $followUps->forCustomer($saved)])->header('Cache-Control', 'private, no-store');
    }
}
