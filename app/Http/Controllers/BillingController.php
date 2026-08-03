<?php

namespace App\Http\Controllers;

use App\EmisPaymentReferenceStatus;
use App\Models\EmisPaymentReference;
use App\Models\SubscriptionCharge;
use App\Models\SubscriptionPlan;
use App\Models\Workspace;
use App\Models\WorkspaceSubscription;
use App\Pay4AllEnvironment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class BillingController extends Controller
{
    public function __invoke(Request $request): Response
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
            ->with(['plan', 'paymentReference'])
            ->where('workspace_id', $workspace->id)
            ->latest('id')
            ->limit(20)
            ->get();
        $environment = Pay4AllEnvironment::tryFrom(
            (string) config('billing.pay4all.environment'),
        ) ?? Pay4AllEnvironment::Simulation;

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
            'charges' => $charges->map(fn (SubscriptionCharge $charge): array => [
                'public_id' => $charge->public_id,
                'amount_minor' => $charge->amount_minor,
                'currency_code' => $charge->currency_code,
                'status' => $charge->status->value,
                'status_label' => $charge->status->label(),
                'created_at' => $charge->created_at?->toIso8601String(),
                'paid_at' => $charge->paid_at?->toIso8601String(),
                'plan_name' => $charge->plan->name,
                'reference' => $charge->paymentReference === null
                    ? null
                    : $this->referenceProps($charge->paymentReference),
            ]),
            'gateway' => [
                'provider' => 'Pay4All é+',
                'method' => 'Referência EMIS',
                'environment' => $environment->value,
                'environment_label' => $environment->label(),
                'available' => $environment === Pay4AllEnvironment::Simulation,
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
