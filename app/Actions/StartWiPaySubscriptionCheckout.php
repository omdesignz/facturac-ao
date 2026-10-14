<?php

namespace App\Actions;

use App\Billing\WiPay\WiPayConfiguration;
use App\BillingPaymentEventType;
use App\EmisPaymentReferenceStatus;
use App\Models\Payment;
use App\Models\SubscriptionCharge;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Pay4AllEnvironment;
use App\PaymentEnvironment;
use App\PaymentStatus;
use App\SubscriptionChargeStatus;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class StartWiPaySubscriptionCheckout
{
    public function __construct(
        private WiPayConfiguration $configuration,
        private CreateHostedPayment $createPayment,
    ) {}

    public function execute(Workspace $workspace, SubscriptionPlan $plan, User $actor, string $customer): Payment
    {
        $this->configuration->assertAvailable();

        if (! $actor->can('update', $workspace) || ! $plan->is_active || $plan->currency_code !== 'AOA'
            || $plan->amount_minor <= 0 || $plan->amount_minor > (int) config('billing.wipay.maximum_amount_minor')
            || preg_match('/\A9\d{8}\z/', $customer) !== 1) {
            throw new DomainException('Não é possível iniciar este pagamento. Verifique o plano e o telemóvel.');
        }

        if ($this->configuration->environment === PaymentEnvironment::Sandbox && app()->isProduction()) {
            throw new DomainException('Os pagamentos de teste não podem activar assinaturas em produção.');
        }

        $payment = DB::transaction(function () use ($workspace, $plan, $actor, $customer): Payment {
            Workspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $existing = SubscriptionCharge::query()->with(['hostedPayment', 'paymentReference'])
                ->where('workspace_id', $workspace->id)->where('active_checkout_key', (string) $workspace->id)
                ->lockForUpdate()->first();

            if ($existing !== null && $this->retireSimulatedCheckout($existing, $actor)) {
                $existing = null;
            }

            if ($existing !== null) {
                if ($existing->hostedPayment === null) {
                    throw new DomainException('Existe uma cobrança antiga por reconciliar. Contacte o apoio antes de iniciar outro pagamento.');
                }

                if ($existing->subscription_plan_id !== $plan->id) {
                    throw new DomainException('Conclua ou verifique o pagamento pendente antes de escolher outro plano.');
                }

                return $existing->hostedPayment;
            }

            $startsAt = CarbonImmutable::now('Africa/Luanda');
            $charge = SubscriptionCharge::query()->create([
                'workspace_id' => $workspace->id,
                'subscription_plan_id' => $plan->id,
                'created_by_user_id' => $actor->id,
                'checkout_token' => (string) Str::ulid(),
                'active_checkout_key' => (string) $workspace->id,
                'amount_minor' => $plan->amount_minor,
                'currency_code' => $plan->currency_code,
                'provider_fee_basis_points' => 0,
                'estimated_provider_fee_minor' => 0,
                'status' => SubscriptionChargeStatus::Creating,
                'period_starts_at' => $startsAt,
                'period_ends_at' => $plan->interval->advance($startsAt),
                'due_at' => $startsAt->addMinutes(max(5, (int) config('billing.wipay.review_after_minutes'))),
            ]);

            return $charge->hostedPayment()->create([
                'workspace_id' => $workspace->id,
                'provider' => 'wipay',
                'environment' => $this->configuration->environment,
                'client_fingerprint' => $this->configuration->fingerprint(),
                'amount_minor' => $charge->amount_minor,
                'currency_code' => $charge->currency_code,
                'customer_identifier' => $customer,
                'status' => PaymentStatus::Created,
            ]);
        }, 5);

        try {
            return $this->createPayment->execute(
                $payment,
                (string) (config('billing.wipay.callback_url') ?: route('webhooks.wipay')),
                route('billing.show'),
            );
        } finally {
            $currentStatus = $payment->fresh()?->status;
            $chargeStatus = match ($currentStatus) {
                PaymentStatus::Pending => SubscriptionChargeStatus::Pending,
                PaymentStatus::Review => SubscriptionChargeStatus::Review,
                default => null,
            };

            if ($chargeStatus !== null) {
                SubscriptionCharge::query()->whereKey($payment->payable_id)->where('workspace_id', $workspace->id)
                    ->whereIn('status', [SubscriptionChargeStatus::Creating, SubscriptionChargeStatus::Pending, SubscriptionChargeStatus::Review])
                    ->update(['status' => $chargeStatus]);
            }
        }
    }

    private function retireSimulatedCheckout(SubscriptionCharge $charge, User $actor): bool
    {
        $reference = $charge->paymentReference;

        if ($charge->hostedPayment !== null || $reference === null
            || $reference->workspace_id !== $charge->workspace_id
            || $reference->provider !== 'pay4all'
            || $reference->environment !== Pay4AllEnvironment::Simulation
            || $reference->status === EmisPaymentReferenceStatus::Paid
            || $charge->status === SubscriptionChargeStatus::Paid) {
            return false;
        }

        $reference->forceFill(['status' => EmisPaymentReferenceStatus::Cancelled])->save();
        $charge->forceFill(['active_checkout_key' => null, 'status' => SubscriptionChargeStatus::Cancelled])->save();
        $reference->events()->create([
            'workspace_id' => $charge->workspace_id,
            'actor_user_id' => $actor->id,
            'event_type' => BillingPaymentEventType::ReferenceCancelled,
            'payload_sha256' => hash('sha256', $reference->public_id.'|pay4all-retired'),
            'safe_context' => ['reason' => 'pay4all_retired'],
            'occurred_at' => now('Africa/Luanda'),
        ]);

        return true;
    }
}
