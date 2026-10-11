<?php

namespace App\Http\Controllers;

use App\Models\PlatformSetting;
use App\Models\SubscriptionPackage;
use App\Models\SubscriptionPlan;
use App\Province;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public front page.
 *
 * Sits at the root, which used to send everyone straight to the dashboard —
 * fine while the only visitors had accounts, useless for anyone deciding
 * whether to open one.
 */
class LandingController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        // Someone already signed in did not come here to be sold to.
        if ($request->user() !== null) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Landing', [
            'plans' => $this->plans(),
            'packages' => $this->packages(),
            // Counted rather than written down, so the page cannot go stale the
            // next time the map changes.
            'provinceCount' => count(array_filter(
                Province::cases(),
                fn (Province $province): bool => $province->isCurrent(),
            )),
            'contact' => [
                'support_email' => PlatformSetting::get('support_email'),
                'company' => PlatformSetting::get('company_legal_name'),
            ],
        ]);
    }

    /**
     * The published plans, or an empty list.
     *
     * Read from the table rather than written into the page: a price is a
     * commercial promise, and the only place that is allowed to state one is
     * the place that charges it.
     *
     * @return list<array{name: string, summary: string|null, amount: string, currency: string, interval: string, trial_days: int, features: list<string>}>
     */
    private function plans(): array
    {
        $plans = [];

        foreach (SubscriptionPlan::query()->where('is_active', true)->orderBy('sort_order')->get() as $plan) {
            // Rebuilt rather than cast: the column is free-form JSON, and a
            // plan recorded with keyed features would otherwise reach the page
            // as an object the list rendering cannot walk.
            $features = [];

            foreach (is_array($plan->features) ? $plan->features : [] as $feature) {
                $features[] = (string) $feature;
            }

            $plans[] = [
                'name' => $plan->name,
                'summary' => $plan->summary,
                'amount' => number_format($plan->amount_minor / 100, 0, ',', ' '),
                'currency' => $plan->currency_code,
                // The label, not the case value: "monthly" is the storage key,
                // and it has no business appearing on a Portuguese page.
                'interval' => $plan->interval->label(),
                'trial_days' => (int) $plan->trial_days,
                'features' => $features,
            ];
        }

        return $plans;
    }

    /**
     * The packages on sale, each with its prices by payment period.
     *
     * Read from the same rows that are charged, for the same reason as the
     * plans above.
     *
     * @return list<array{name: string, summary: string|null, features: list<string>, trial_days: int, is_featured: bool, prices: list<array{interval: string, interval_label: string, period_label: string, months: int, amount: string, currency: string, monthly_equivalent: string, saving_percentage: int|null}>}>
     */
    private function packages(): array
    {
        $packages = [];

        foreach (SubscriptionPackage::offered() as $package) {
            $prices = [];

            foreach ($package->plans as $plan) {
                $months = $plan->interval->months();

                $prices[] = [
                    'interval' => $plan->interval->value,
                    'interval_label' => $plan->interval->label(),
                    'period_label' => $plan->interval->periodLabel(),
                    'months' => $months,
                    'amount' => number_format($plan->amount_minor / 100, 0, ',', ' '),
                    'currency' => $plan->currency_code,
                    'monthly_equivalent' => number_format(intdiv($plan->amount_minor, $months) / 100, 0, ',', ' '),
                    'saving_percentage' => $package->savingPercentage($plan),
                ];
            }

            $packages[] = [
                'name' => $package->name,
                'summary' => $package->summary,
                'features' => $package->featureList(),
                'trial_days' => $package->trial_days,
                'is_featured' => $package->is_featured,
                'prices' => $prices,
            ];
        }

        return $packages;
    }
}
