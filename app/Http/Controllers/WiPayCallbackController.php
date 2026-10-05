<?php

namespace App\Http\Controllers;

use App\Actions\AcceptWiPayCallback;
use App\Jobs\FulfillPayment;
use App\PaymentStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class WiPayCallbackController extends Controller
{
    public function __invoke(Request $request, AcceptWiPayCallback $accept): JsonResponse
    {
        abort_unless($request->isJson(), 415);
        $body = $request->getContent();
        abort_if(strlen($body) > 32768, 413);

        try {
            $payment = $accept->execute($body, (string) $request->header('signature'));
        } catch (InvalidArgumentException) {
            return response()->json(['message' => 'Callback inválido.'], 400);
        }

        if ($payment->fulfilled_at === null && in_array($payment->status, [PaymentStatus::Paid, PaymentStatus::Rejected], true)) {
            try {
                FulfillPayment::dispatch($payment->id);
            } catch (Throwable) {
                Log::warning('Payment persisted; fulfillment will be recovered by the scheduler.', ['payment_id' => $payment->id]);
            }
        }

        return response()->json(['received' => true], 202);
    }
}
