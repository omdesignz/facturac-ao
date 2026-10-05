<?php

namespace App\Jobs;

use App\Actions\FulfillHostedPayment;
use App\Models\Payment;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class FulfillPayment implements ShouldBeUnique, ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public int $uniqueFor = 120;

    public function __construct(public readonly int $paymentId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'payment-fulfillment:'.$this->paymentId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 30, 120, 300];
    }

    public function handle(FulfillHostedPayment $fulfill): void
    {
        $payment = Payment::query()->find($this->paymentId);

        if ($payment !== null) {
            $fulfill->execute($payment);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Payment fulfillment requires recovery.', ['payment_id' => $this->paymentId]);
    }
}
