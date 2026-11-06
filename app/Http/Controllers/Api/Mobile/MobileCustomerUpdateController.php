<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Requests\UpdateMobileCustomerRequest;
use App\Models\Customer;
use App\Models\LegalEntity;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Changes a customer's details from the phone, for what is still to come. An
 * issued document keeps the name, NIF and address it was signed with.
 *
 * Saving the same details twice changes nothing, so a repeat after a dropped
 * connection needs no key.
 */
class MobileCustomerUpdateController
{
    public function __invoke(UpdateMobileCustomerRequest $request, string $customerPublicId): JsonResponse
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

        DB::transaction(fn () => $customer->update($request->customerAttributes()));

        return response()->json(['data' => self::details($customer)]);
    }

    /**
     * What the phone can change, as it stands.
     *
     * @return array{name: string, tax_identification_number: string|null, address_line: string|null, email: string|null, phone: string|null, payment_terms_days: int}
     */
    public static function details(Customer $customer): array
    {
        return [
            'name' => $customer->name,
            'tax_identification_number' => $customer->tax_identification_number,
            'address_line' => $customer->address_line,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'payment_terms_days' => (int) $customer->payment_terms_days,
        ];
    }
}
