<?php

namespace App\Actions;

use App\Exceptions\BillingActionRefused;
use App\Models\SubscriptionCharge;
use App\Models\SubscriptionPackage;
use App\Models\SubscriptionPlan;
use App\Models\WorkspaceSubscription;
use Illuminate\Support\Facades\DB;

/**
 * Removes a package that was never sold.
 *
 * Once any of its plans has a subscription or a charge, the history points at
 * those rows and they stay: the package can only be switched off.
 */
class DeleteSubscriptionPackage
{
    public function execute(SubscriptionPackage $package): void
    {
        DB::transaction(function () use ($package): void {
            $locked = SubscriptionPackage::query()->whereKey($package->id)->lockForUpdate()->firstOrFail();
            $planIds = SubscriptionPlan::query()
                ->where('subscription_package_id', $locked->id)
                ->lockForUpdate()
                ->pluck('id');

            $isSold = WorkspaceSubscription::query()->whereIn('subscription_plan_id', $planIds)->exists()
                || SubscriptionCharge::query()->whereIn('subscription_plan_id', $planIds)->exists();

            if ($isSold) {
                throw BillingActionRefused::because(
                    'Este pacote já teve subscritores. Desactive-o em vez de o apagar.',
                );
            }

            SubscriptionPlan::query()->whereIn('id', $planIds)->delete();
            $locked->delete();
        }, 5);
    }
}
