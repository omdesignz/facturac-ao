<?php

namespace App\Console\Commands;

use App\Jobs\FulfillPayment;
use App\Models\Payment;
use App\Models\SubscriptionCharge;
use App\PaymentStatus;
use App\SubscriptionChargeStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

#[Signature('payments:recover {--limit=500 : Maximum records to inspect} {--show-review : Show identifiers requiring provider verification}')]
#[Description('Recover durable fulfillment and flag unresolved requests without recreating or expiring provider payments')]
class RecoverHostedPayments extends Command
{
    public function handle(): int
    {
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $dispatched = 0;
        $reviewed = 0;

        Payment::query()->whereIn('status', [PaymentStatus::Paid, PaymentStatus::Rejected])->whereNull('fulfilled_at')
            ->oldest('id')->limit($limit)->pluck('id')->each(function (int $id) use (&$dispatched): void {
                FulfillPayment::dispatch($id);
                $dispatched++;
            });
        $reviewMinutes = max(5, (int) config('billing.wipay.review_after_minutes'));
        Payment::query()->where(function ($query) use ($reviewMinutes): void {
            $query->where('status', PaymentStatus::Creating)->where('request_started_at', '<=', now()->subMinutes(2));
            $query->orWhere(fn ($pending) => $pending->where('status', PaymentStatus::Pending)->where('updated_at', '<=', now()->subMinutes($reviewMinutes)));
        })->oldest('id')->limit($limit)->get()->each(function (Payment $payment) use (&$reviewed, $reviewMinutes): void {
            DB::transaction(function () use ($payment, &$reviewed, $reviewMinutes): void {
                $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

                $stale = ($locked->status === PaymentStatus::Creating && $locked->request_started_at?->lte(now()->subMinutes(2)))
                    || ($locked->status === PaymentStatus::Pending && $locked->updated_at->lte(now()->subMinutes($reviewMinutes)));

                if (! $stale) {
                    return;
                }

                $code = $locked->status === PaymentStatus::Creating ? 'creation_response_missing' : 'callback_missing';
                $locked->forceFill(['status' => PaymentStatus::Review, 'failure_code' => $code])->save();
                $locked->events()->create(['event_key' => hash('sha256', 'review:'.$code), 'event_type' => 'review-required',
                    'payload_sha256' => hash('sha256', $locked->public_id.'|'.$code), 'safe_context' => ['reason' => $code], 'occurred_at' => now()]);

                if ($locked->payable instanceof SubscriptionCharge && $locked->payable->workspace_id === $locked->workspace_id) {
                    $locked->payable->forceFill(['status' => SubscriptionChargeStatus::Review])->save();
                }

                Log::warning('Payment requires verification in WiPay; do not recreate the request.', ['payment_public_id' => $locked->public_id, 'reason' => $code]);
                $reviewed++;
            }, 5);
        });

        if ($this->option('show-review')) {
            $this->table(['Pagamento', 'Ambiente', 'Motivo'], Payment::query()->where('status', PaymentStatus::Review)
                ->oldest('id')->limit($limit)->get()->map(fn (Payment $payment): array => [$payment->public_id, $payment->environment->value, $payment->failure_code])->all());
        }

        $this->components->info("Pagamentos: {$dispatched} confirmação(ões) na fila; {$reviewed} pedido(s) para verificação.");

        return self::SUCCESS;
    }
}
