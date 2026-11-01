<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Actions\PreparePaymentReminder;
use App\Actions\SendPaymentReminder;
use App\Exceptions\BillingActionRefused;
use App\Http\Requests\StoreMobileReminderRequest;
use App\Models\Customer;
use App\Models\LegalEntity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * The same reminder the web prepares and sends, for the phone. The customer is
 * looked up inside the company on screen, so anything else is simply not found.
 */
class MobileReminderController
{
    public function show(Request $request, string $customerPublicId, PreparePaymentReminder $prepare): JsonResponse
    {
        $customer = $this->customer($request, $customerPublicId);

        return $this->refusedRole($customer) ?? response()->json(['data' => $prepare->execute($customer)]);
    }

    public function store(StoreMobileReminderRequest $request, string $customerPublicId, SendPaymentReminder $send): JsonResponse
    {
        $customer = $this->customer($request, $customerPublicId);
        $refused = $this->refusedRole($customer);

        if ($refused !== null) {
            return $refused;
        }

        try {
            $receipt = $send->execute(
                $customer,
                $request->string('subject')->toString(),
                $request->string('body')->toString(),
                $request->user(),
                $request->string('client_key')->toString(),
            );
        } catch (BillingActionRefused $exception) {
            return $this->refusal('REMINDER_REFUSED', $exception->getMessage(), Response::HTTP_CONFLICT);
        }

        return response()->json(
            ['data' => $receipt],
            $receipt['replayed'] ? Response::HTTP_OK : Response::HTTP_CREATED,
        );
    }

    private function customer(Request $request, string $publicId): Customer
    {
        /** @var LegalEntity $legalEntity */
        $legalEntity = $request->attributes->get('currentLegalEntity');

        return Customer::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    private function refusedRole(Customer $customer): ?JsonResponse
    {
        return Gate::allows('update', $customer)
            ? null
            : $this->refusal('REMINDER_NOT_ALLOWED', 'A sua função nesta empresa não permite enviar lembretes.', Response::HTTP_FORBIDDEN);
    }

    private function refusal(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
