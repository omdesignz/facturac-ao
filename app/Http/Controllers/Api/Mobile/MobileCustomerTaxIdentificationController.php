<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Requests\UpdateMobileCustomerTaxIdentificationRequest;
use App\Models\Customer;
use App\Models\LegalEntity;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Corrects a customer's NIF on their record, for the documents still to come.
 *
 * An issued document keeps the NIF it was signed with: it is copied into the
 * document at issue, so nothing here reaches back into one.
 */
class MobileCustomerTaxIdentificationController
{
    public function __invoke(UpdateMobileCustomerTaxIdentificationRequest $request, string $customerPublicId): JsonResponse
    {
        /** @var LegalEntity $legalEntity */
        $legalEntity = $request->attributes->get('currentLegalEntity');

        $customer = Customer::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->where('public_id', $customerPublicId)
            ->firstOrFail();

        if (Gate::denies('update', $customer)) {
            return response()->json(['error' => [
                'code' => 'CUSTOMER_NOT_ALLOWED',
                'message' => 'A sua função nesta empresa não permite alterar clientes.',
            ]], Response::HTTP_FORBIDDEN);
        }

        $customer->update(['tax_identification_number' => $request->validated('tax_identification_number')]);

        return response()->json(['data' => [
            'tax_identification_number' => $customer->tax_identification_number,
        ]]);
    }
}
