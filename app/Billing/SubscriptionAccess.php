<?php

namespace App\Billing;

use App\Exceptions\SubscriptionRequired;
use App\Models\Workspace;
use App\Models\WorkspaceSubscription;
use App\SubscriptionFeature;
use App\WorkspaceSubscriptionStatus;

final class SubscriptionAccess
{
    public function allows(int $workspaceId, SubscriptionFeature $feature): bool
    {
        return $this->status($workspaceId)['features'][$feature->value];
    }

    public function require(int $workspaceId, SubscriptionFeature $feature): void
    {
        if (! $this->allows($workspaceId, $feature)) {
            throw new SubscriptionRequired($feature);
        }
    }

    /** @return array{status: string, plan_name: string|null, trial_ends_at: string|null, features: array<string, bool>} */
    public function status(int $workspaceId): array
    {
        $features = array_fill_keys(array_column(SubscriptionFeature::cases(), 'value'), false);
        $result = ['status' => 'expired', 'plan_name' => null, 'trial_ends_at' => null, 'features' => $features];
        $workspace = Workspace::find($workspaceId);
        if ($workspace === null) {
            return $result;
        }

        $subscription = WorkspaceSubscription::query()->with('plan')->where('workspace_id', $workspaceId)->first();
        if ($subscription !== null && $subscription->status !== WorkspaceSubscriptionStatus::Pending) {
            $result['plan_name'] = $subscription->plan->name;
            if ($subscription->status !== WorkspaceSubscriptionStatus::Active
                || $subscription->current_period_started_at === null
                || $subscription->current_period_started_at->isFuture()
                || $subscription->current_period_ends_at === null
                || ! $subscription->current_period_ends_at->isFuture()) {
                return $result;
            }

            $result['status'] = 'active';
            $limits = $subscription->plan->limits ?? [];
            foreach (SubscriptionFeature::cases() as $feature) {
                // Preserve already-sold legacy plans until their paid period expires.
                $result['features'][$feature->value] = ! array_key_exists('entitlement_version', $limits)
                    || (($limits['entitlement_version'] === 1) && ($limits[$feature->key()] ?? false) === true);
            }

            return $result;
        }

        $trialEndsAt = $workspace->created_at?->copy()->addDays((int) config('subscriptions.trial_days', 14));
        $result['trial_ends_at'] = $trialEndsAt?->toIso8601String();
        if ($workspace->created_at !== null && ! $workspace->created_at->isFuture() && $trialEndsAt?->isFuture()) {
            $result['status'] = 'trial';
            $result['features'] = array_fill_keys(array_keys($features), true);
        }

        return $result;
    }
}
