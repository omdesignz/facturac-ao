<?php

namespace App\Http\Controllers;

use App\Actions\DeleteSubscriptionPackage;
use App\Actions\SaveSubscriptionPackage;
use App\Exceptions\BillingActionRefused;
use App\Http\Requests\StoreSubscriptionPackageRequest;
use App\Models\SubscriptionCharge;
use App\Models\SubscriptionPackage;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\WorkspaceSubscription;
use App\SubscriptionFeature;
use App\SubscriptionInterval;
use App\WorkspaceSubscriptionStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lets support staff manage the packages customers choose between, and the
 * price of each payment period, without a deploy.
 */
class SubscriptionPackageController extends Controller
{
    public function index(): Response
    {
        $packages = SubscriptionPackage::query()
            ->with('plans')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $planIds = $packages->flatMap(fn (SubscriptionPackage $package) => $package->plans->pluck('id'))->all();

        /** @var array<int, int> $activeSubscribers */
        $activeSubscribers = WorkspaceSubscription::query()
            ->whereIn('subscription_plan_id', $planIds)
            ->where('status', WorkspaceSubscriptionStatus::Active)
            ->selectRaw('subscription_plan_id, count(*) as total')
            ->groupBy('subscription_plan_id')
            ->pluck('total', 'subscription_plan_id')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();
        $plansWithHistory = array_flip(array_merge(
            WorkspaceSubscription::query()->whereIn('subscription_plan_id', $planIds)->distinct()->pluck('subscription_plan_id')->all(),
            SubscriptionCharge::query()->whereIn('subscription_plan_id', $planIds)->distinct()->pluck('subscription_plan_id')->all(),
        ));

        return Inertia::render('Support/Packages', [
            'packages' => $packages->map(fn (SubscriptionPackage $package): array => $this->present(
                $package,
                $activeSubscribers,
                $plansWithHistory,
            ))->values()->all(),
            'intervals' => array_map(fn (SubscriptionInterval $interval): array => [
                'value' => $interval->value,
                'label' => $interval->label(),
                'months' => $interval->months(),
                'period_label' => $interval->periodLabel(),
            ], SubscriptionInterval::ordered()),
            'featureOptions' => array_map(fn (SubscriptionFeature $feature): array => ['value' => $feature->value, 'label' => $feature->label()], SubscriptionFeature::cases()),
            'trialDays' => (int) config('subscriptions.trial_days', 14),
            'currencies' => array_values((array) config('fiscal.currencies', ['AOA'])),
        ]);
    }

    public function store(StoreSubscriptionPackageRequest $request, SaveSubscriptionPackage $save): RedirectResponse
    {
        $save->execute(null, $request->packageData(), $this->actor($request));

        return back()->with('success', 'Pacote criado.');
    }

    public function update(
        StoreSubscriptionPackageRequest $request,
        SubscriptionPackage $package,
        SaveSubscriptionPackage $save,
    ): RedirectResponse {
        $save->execute($package, $request->packageData(), $this->actor($request));

        return back()->with('success', 'Pacote actualizado.');
    }

    public function destroy(SubscriptionPackage $package, DeleteSubscriptionPackage $delete): RedirectResponse
    {
        try {
            $delete->execute($package);
        } catch (BillingActionRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Pacote apagado.');
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 404);

        return $user;
    }

    /**
     * @param  array<int, int>  $activeSubscribers
     * @param  array<int, int>  $plansWithHistory
     * @return array<string, mixed>
     */
    private function present(SubscriptionPackage $package, array $activeSubscribers, array $plansWithHistory): array
    {
        $periods = $package->plans->map(fn (SubscriptionPlan $plan): array => [
            'interval' => $plan->interval->value,
            'interval_label' => $plan->interval->label(),
            'months' => $plan->interval->months(),
            'amount_minor' => $plan->amount_minor,
            'amount' => number_format($plan->amount_minor / 100, 2, '.', ''),
            'is_active' => $plan->is_active,
            'plan_public_id' => $plan->public_id,
            'subscribers_count' => $activeSubscribers[$plan->id] ?? 0,
        ])->values()->all();

        return [
            'public_id' => $package->public_id,
            'code' => $package->code,
            'name' => $package->name,
            'summary' => $package->summary,
            'features' => $package->featureList(),
            'entitlements' => array_values(array_map(fn (SubscriptionFeature $feature): string => $feature->value, array_filter(SubscriptionFeature::cases(), fn (SubscriptionFeature $feature): bool => ! isset($package->limits['entitlement_version']) || ($package->limits[$feature->key()] ?? false) === true))),
            'trial_days' => $package->trial_days,
            'currency_code' => $package->currency_code,
            'is_active' => $package->is_active,
            'is_featured' => $package->is_featured,
            'sort_order' => $package->sort_order,
            'subscribers_count' => array_sum(array_column($periods, 'subscribers_count')),
            'can_delete' => $package->plans->every(
                fn (SubscriptionPlan $plan): bool => ! isset($plansWithHistory[$plan->id]),
            ),
            'periods' => $periods,
        ];
    }
}
