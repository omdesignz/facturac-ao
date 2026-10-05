<?php

namespace App\Http\Controllers;

use App\Billing\WiPay\WiPayConfiguration;
use App\EmisPaymentReferenceStatus;
use App\Models\EmisPaymentReference;
use App\Models\Payment;
use App\Models\SubscriptionCharge;
use App\Models\SubscriptionPlan;
use App\Models\Workspace;
use App\Models\WorkspaceSubscription;
use App\Pay4AllEnvironment;
use App\PaymentEnvironment;
use App\PaymentStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class BillingController extends Controller
{
    public function __invoke(Request $request, WiPayConfiguration $configuration): Response
    {
        $workspace = $request->attributes->get('currentWorkspace');
        abort_unless($workspace instanceof Workspace, 404);
        Gate::authorize('view', $workspace);
        Inertia::encryptHistory();

        $subscription = WorkspaceSubscription::query()
            ->with('plan')
            ->where('workspace_id', $workspace->id)
            ->first();
        $activeReference = EmisPaymentReference::query()
            ->with('charge.plan')
            ->where('workspace_id', $workspace->id)
            ->where('status', EmisPaymentReferenceStatus::Pending)
            ->where('expires_at', '>', now('Africa/Luanda'))
            ->latest('id')
            ->first();
        $charges = SubscriptionCharge::query()
            ->with(['plan', 'paymentReference', 'hostedPayment'])
            ->where('workspace_id', $workspace->id)
            ->latest('id')
            ->limit(20)
            ->get();
        $environment = Pay4AllEnvironment::tryFrom(
            (string) config('billing.pay4all.environment'),
        );
        $isHosted = config('billing.gateway') === 'wipay';
        $hostedEnvironment = $configuration->environment;
        $gatewayAvailable = [
            'wipay' => $configuration->isAvailable()
                && ($hostedEnvironment !== PaymentEnvironment::Sandbox || ! app()->isProduction()),
            'pay4all' => $environment === Pay4AllEnvironment::Simulation,
        ][(string) config('billing.gateway')] ?? false;
        $hostedAvailable = $isHosted && $gatewayAvailable;
        $activePayment = Payment::query()->where('workspace_id', $workspace->id)
            ->where(function ($query): void {
                $query->whereIn('status', [PaymentStatus::Created, PaymentStatus::Creating, PaymentStatus::Pending, PaymentStatus::Review]);
                $query->orWhere(fn ($settled) => $settled->whereIn('status', [PaymentStatus::Paid, PaymentStatus::Rejected])->whereNull('fulfilled_at'));
            })->latest('id')->first();

        return Inertia::render('Billing/Show', [
            'plans' => SubscriptionPlan::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('amount_minor')
                ->get()
                ->map(fn (SubscriptionPlan $plan): array => $this->planProps($plan)),
            'subscription' => $subscription === null ? null : [
                'public_id' => $subscription->public_id,
                'status' => $subscription->status->value,
                'status_label' => $subscription->status->label(),
                'period_started_at' => $subscription->current_period_started_at?->toIso8601String(),
                'period_ends_at' => $subscription->current_period_ends_at?->toIso8601String(),
                'cancel_at_period_end' => $subscription->cancel_at_period_end,
                'plan' => $this->planProps($subscription->plan),
            ],
            'activeReference' => $activeReference === null
                ? null
                : $this->referenceProps($activeReference),
            'activePayment' => $activePayment === null ? null : $this->paymentProps($activePayment, $configuration, $hostedAvailable),
            'charges' => $charges->map(fn (SubscriptionCharge $charge): array => [
                'public_id' => $charge->public_id,
                'amount_minor' => $charge->amount_minor,
                'currency_code' => $charge->currency_code,
                'status' => $charge->status->value,
                'status_label' => $charge->status->label(),
                'created_at' => $charge->created_at?->toIso8601String(),
                'paid_at' => $charge->paid_at?->toIso8601String(),
                'plan_name' => $charge->plan->name,
                'payment' => $charge->hostedPayment === null ? null : $this->paymentProps($charge->hostedPayment, $configuration, $hostedAvailable),
                'reference' => $charge->paymentReference === null
                    ? null
                    : $this->referenceProps($charge->paymentReference),
            ]),
            'gateway' => $isHosted ? [
                'provider' => 'WiPay', 'method' => 'Pagamento online', 'hosted' => true,
                'environment' => $hostedEnvironment->value ?? 'invalid',
                'environment_label' => $hostedEnvironment?->label() ?? 'Configuração inválida',
                'available' => $gatewayAvailable,
                'simulated' => false,
                'production_enabled' => (bool) config('billing.wipay.production_enabled'),
                'transaction_fee_basis_points' => 0,
                'maximum_amount_minor' => (int) config('billing.wipay.maximum_amount_minor'),
            ] : [
                'hosted' => false,
                'provider' => 'Pay4All é+',
                'method' => 'Referência EMIS',
                'environment' => $environment->value ?? 'invalid',
                'environment_label' => $environment?->label() ?? 'Configuração inválida',
                'available' => $gatewayAvailable,
                'simulated' => $environment === Pay4AllEnvironment::Simulation,
                'production_enabled' => (bool) config('billing.pay4all.production_enabled'),
                'transaction_fee_basis_points' => (int) config('billing.pay4all.transaction_fee_basis_points'),
                'maximum_amount_minor' => (int) config('billing.pay4all.maximum_reference_amount_minor'),
            ],
            'canManage' => $request->user()?->can('update', $workspace) === true,
        ]);
    }

    /** @return array<string, mixed> */
    private function planProps(SubscriptionPlan $plan): array
    {
        return [
            'public_id' => $plan->public_id,
            'code' => $plan->code,
            'name' => $plan->name,
            'summary' => $plan->summary,
            'amount_minor' => $plan->amount_minor,
            'currency_code' => $plan->currency_code,
            'interval' => $plan->interval->value,
            'interval_label' => $plan->interval->label(),
            'trial_days' => $plan->trial_days,
            'features' => $plan->features ?? [],
            'limits' => $plan->limits ?? [],
        ];
    }

    /** @return array<string, mixed> */
    private function paymentProps(Payment $payment, WiPayConfiguration $configuration, bool $gatewayAvailable): array
    {
        return [
            'public_id' => $payment->public_id,
            'provider_id' => $payment->provider_payment_id,
            'amount_minor' => $payment->amount_minor,
            'currency_code' => $payment->currency_code,
            'status' => $payment->status->value,
            'status_label' => $payment->status->label(),
            'environment' => $payment->environment->value,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'retry_after_at' => $payment->retry_after_at?->toIso8601String(),
            'can_resume' => $gatewayAvailable && $payment->environment === $configuration->environment
                && (($payment->status === PaymentStatus::Pending && $payment->checkout_url !== null)
                    || ($payment->status === PaymentStatus::Created && $payment->client_fingerprint === $configuration->fingerprint())),
        ];
    }

    /** @return array<string, mixed> */
    private function referenceProps(EmisPaymentReference $reference): array
    {
        return [
            'public_id' => $reference->public_id,
            'entity' => $reference->entity,
            'reference' => $reference->reference,
            'amount_minor' => $reference->amount_minor,
            'currency_code' => $reference->currency_code,
            'status' => $reference->status->value,
            'status_label' => $reference->status->label(),
            'expires_at' => $reference->expires_at->toIso8601String(),
            'paid_at' => $reference->paid_at?->toIso8601String(),
            'last_checked_at' => $reference->last_checked_at?->toIso8601String(),
            'environment' => $reference->environment->value,
        ];
    }
}
