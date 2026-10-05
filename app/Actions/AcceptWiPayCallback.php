<?php

namespace App\Actions;

use App\Billing\Contracts\WiPayTokenStore;
use App\Billing\Data\PaymentCallbackData;
use App\Billing\WiPay\WiPaySignatureVerifier;
use App\Models\Payment;
use App\PaymentStatus;
use Illuminate\Support\Facades\DB;

final readonly class AcceptWiPayCallback
{
    public function __construct(
        private WiPayTokenStore $tokens,
        private WiPaySignatureVerifier $signatures,
    ) {}

    public function execute(string $rawBody, string $signature): Payment
    {
        $data = PaymentCallbackData::fromRawBody($rawBody);
        $payment = Payment::query()->where('provider', 'wipay')->where('public_id', $data->merchantReference)->first();
        abort_if($payment === null, 404, 'Pagamento desconhecido.');
        $keys = array_filter(array_merge([$payment->signature_token], $this->tokens->signatureKeys($payment->client_fingerprint)));
        $verified = false;

        foreach ($keys as $key) {
            $verified = $this->signatures->verify($rawBody, $signature, $key) || $verified;
        }

        abort_unless($verified, 401, 'Assinatura inválida.');
        $occurredAt = $data->occurredAt->setTimezone((string) config('app.timezone'));

        [$updated, $mismatched] = DB::transaction(function () use ($payment, $data, $occurredAt): array {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $mismatch = $locked->amount_minor !== $data->amountMinor || $locked->currency_code !== $data->currencyCode
                || ($locked->provider_payment_id !== null && $locked->provider_payment_id !== $data->providerId);

            if ($mismatch) {
                $locked->events()->firstOrCreate(['event_key' => hash('sha256', 'mismatch:'.$data->payloadSha256)], [
                    'event_type' => 'callback-mismatch', 'payload_sha256' => $data->payloadSha256,
                    'safe_context' => ['reason' => 'identity_or_amount_mismatch'], 'occurred_at' => now(),
                ]);

                if ($locked->status !== PaymentStatus::Paid) {
                    $locked->forceFill(['status' => PaymentStatus::Review, 'failure_code' => 'callback_mismatch'])->save();
                }

                return [$locked, true];
            }

            if ($locked->events()->where('event_key', $data->eventKey())->exists()) {
                return [$locked, false];
            }

            $nextStatus = $data->status === 'accepted' ? PaymentStatus::Paid : PaymentStatus::Rejected;
            $conflict = in_array($locked->status, [PaymentStatus::Paid, PaymentStatus::Rejected], true)
                && $locked->status !== $nextStatus;
            $locked->events()->create([
                'event_key' => $data->eventKey(), 'event_type' => $conflict ? 'callback-conflict' : 'callback-'.$data->status,
                'payload_sha256' => $data->payloadSha256,
                'safe_context' => ['status' => $data->status, 'reason' => $data->reason, 'processor' => $data->processor],
                'occurred_at' => $occurredAt,
            ]);

            if ($conflict) {
                $locked->forceFill(['failure_code' => 'callback_conflict',
                    'status' => $locked->status === PaymentStatus::Paid ? PaymentStatus::Paid : PaymentStatus::Review])->save();
            } else {
                $locked->forceFill(['provider_payment_id' => $data->providerId, 'status' => $nextStatus,
                    'paid_at' => $nextStatus === PaymentStatus::Paid ? $occurredAt : null,
                    'finalized_at' => now(), 'failure_code' => $nextStatus === PaymentStatus::Rejected ? 'wipay_reason_'.$data->reason : null])->save();
            }

            return [$locked, false];
        }, 5);

        abort_if($mismatched, 422, 'Os dados não correspondem ao pagamento.');

        return $updated;
    }
}
