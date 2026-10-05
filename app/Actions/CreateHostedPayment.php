<?php

namespace App\Actions;

use App\Billing\Contracts\HostedPaymentGateway;
use App\Billing\Data\CreateHostedPaymentData;
use App\Billing\Exceptions\PaymentGatewayException;
use App\Billing\WiPay\WiPayConfiguration;
use App\Jobs\FulfillPayment;
use App\Models\Payment;
use App\PaymentStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final readonly class CreateHostedPayment
{
    public function __construct(
        private HostedPaymentGateway $gateway,
        private WiPayConfiguration $configuration,
    ) {}

    public function execute(Payment $payment, string $callbackUrl, string $returnUrl): Payment
    {
        $payment->refresh();

        if ($payment->status !== PaymentStatus::Created) {
            if ($payment->status === PaymentStatus::Pending && $payment->checkout_url !== null) {
                return $payment;
            }

            if (in_array($payment->status, [PaymentStatus::Paid, PaymentStatus::Rejected], true)) {
                return $payment;
            }

            throw new PaymentGatewayException('wipay_request_already_started', outcomeUnknown: true);
        }

        if ($payment->client_fingerprint !== $this->configuration->fingerprint()
            || $payment->environment !== $this->configuration->environment) {
            throw new PaymentGatewayException('wipay_credentials_changed');
        }

        if ($payment->retry_after_at?->isFuture()) {
            throw new PaymentGatewayException('wipay_rate_limited');
        }

        $request = new CreateHostedPaymentData($payment->public_id, $payment->amount_minor, $payment->currency_code,
            $payment->customer_identifier, $callbackUrl, $returnUrl, $returnUrl);
        $signatureToken = $this->gateway->signatureToken();
        $claimed = DB::transaction(function () use ($payment, $signatureToken): bool {
            $locked = Payment::query()->whereKey($payment->id)->where('workspace_id', $payment->workspace_id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PaymentStatus::Created || $locked->request_started_at !== null) {
                return false;
            }

            $locked->forceFill(['status' => PaymentStatus::Creating, 'request_started_at' => now(),
                'signature_token' => $signatureToken, 'failure_code' => null, 'retry_after_at' => null])->save();

            return true;
        }, 5);

        if (! $claimed) {
            return $payment->refresh();
        }

        try {
            $result = $this->gateway->createPayment($request);

            return DB::transaction(function () use ($payment, $result): Payment {
                $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

                if ($locked->provider_payment_id !== null && $locked->provider_payment_id !== $result->providerId) {
                    throw new PaymentGatewayException('wipay_provider_id_conflict', outcomeUnknown: true);
                }

                $locked->forceFill(['provider_payment_id' => $result->providerId, 'checkout_url' => $result->checkoutUrl,
                    'status' => $locked->status === PaymentStatus::Creating ? PaymentStatus::Pending : $locked->status])->save();
                $locked->events()->firstOrCreate(['event_key' => hash('sha256', 'checkout-created')], [
                    'event_type' => 'checkout-created', 'payload_sha256' => $result->payloadSha256,
                    'safe_context' => ['provider' => 'wipay', 'environment' => $locked->environment->value], 'occurred_at' => now(),
                ]);

                return $locked;
            }, 5);
        } catch (Throwable $exception) {
            $failure = $exception instanceof PaymentGatewayException ? $exception
                : new PaymentGatewayException('wipay_creation_uncertain', outcomeUnknown: true);

            $wasFinalized = DB::transaction(function () use ($payment, $failure): bool {
                $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

                if (in_array($locked->status, [PaymentStatus::Paid, PaymentStatus::Rejected], true)) {
                    return true;
                }

                $rateLimited = $failure->failureCode === 'wipay_http_429';
                $locked->forceFill([
                    'status' => $failure->outcomeUnknown ? PaymentStatus::Review : ($rateLimited ? PaymentStatus::Created : PaymentStatus::Rejected),
                    'failure_code' => $failure->failureCode,
                    'request_started_at' => $rateLimited ? null : $locked->request_started_at,
                    'retry_after_at' => $rateLimited ? now()->addSeconds($failure->retryAfterSeconds ?? 60) : null,
                    'finalized_at' => ! $failure->outcomeUnknown && ! $rateLimited ? now() : null,
                ])->save();
                $locked->events()->create([
                    'event_key' => hash('sha256', (string) Str::uuid()), 'event_type' => 'creation-failed',
                    'payload_sha256' => hash('sha256', $failure->failureCode),
                    'safe_context' => ['failure_code' => $failure->failureCode, 'outcome_unknown' => $failure->outcomeUnknown],
                    'occurred_at' => now(),
                ]);

                return false;
            }, 5);

            if ($wasFinalized) {
                return $payment->refresh();
            }

            if ($payment->fresh()?->status === PaymentStatus::Rejected) {
                FulfillPayment::dispatch($payment->id);
            }

            throw $failure;
        }
    }
}
