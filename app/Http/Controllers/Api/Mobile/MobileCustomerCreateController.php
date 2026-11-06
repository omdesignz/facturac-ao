<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Actions\CreateMobileRecord;
use App\Http\Requests\StoreMobileCustomerRequest;
use App\Models\Customer;
use App\Models\LegalEntity;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * A new customer from the phone, usually in the middle of a sale. It answers with
 * the same row the list gives, so the sale can carry on with it.
 */
class MobileCustomerCreateController
{
    public function __invoke(StoreMobileCustomerRequest $request, CreateMobileRecord $records): JsonResponse
    {
        if (Gate::denies('create', Customer::class)) {
            return response()->json(['error' => [
                'code' => 'CUSTOMER_NOT_ALLOWED',
                'message' => 'A sua função nesta empresa não permite criar clientes.',
            ]], Response::HTTP_FORBIDDEN);
        }

        /** @var LegalEntity $legalEntity */
        $legalEntity = $request->attributes->get('currentLegalEntity');
        /** @var User $user */
        $user = $request->user();

        $record = $records->customer(
            $legalEntity,
            $user,
            (string) $request->validated('client_key'),
            $request->customerAttributes(),
        );

        /** @var Customer $customer */
        $customer = $record->customer;

        return response()->json(
            ['data' => MobileCustomerController::present($customer, $legalEntity, 0)],
            $record->replayed ? Response::HTTP_OK : Response::HTTP_CREATED,
        );
    }
}
