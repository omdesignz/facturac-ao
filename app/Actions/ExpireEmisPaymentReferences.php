<?php

namespace App\Actions;

use App\BillingPaymentEventType;
use App\EmisPaymentReferenceStatus;
use App\Models\EmisPaymentReference;
use App\Models\SubscriptionCharge;
use App\SubscriptionChargeStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class ExpireEmisPaymentReferences
{
    public function execute(?int $workspaceId = null): int
    {
        $expiredCount = 0;

        EmisPaymentReference::query()
            ->where('status', EmisPaymentReferenceStatus::Pending)
            ->where('expires_at', '<=', now('Africa/Luanda'))
            ->when(
                $workspaceId !== null,
                fn (Builder $query): Builder => $query->where('workspace_id', $workspaceId),
            )
            ->select(['id', 'workspace_id'])
            ->chunkById(100, function ($references) use (&$expiredCount): void {
                foreach ($references as $reference) {
                    $expiredCount += $this->expire($reference->id, $reference->workspace_id) ? 1 : 0;
                }
            });

        return $expiredCount;
    }

    private function expire(int $referenceId, int $workspaceId): bool
    {
        return DB::transaction(function () use ($referenceId, $workspaceId): bool {
            $reference = EmisPaymentReference::query()
                ->whereKey($referenceId)
                ->where('workspace_id', $workspaceId)
                ->lockForUpdate()
                ->first();

            if (! $reference instanceof EmisPaymentReference
                || $reference->status !== EmisPaymentReferenceStatus::Pending
                || $reference->expires_at->isFuture()) {
                return false;
            }

            $charge = SubscriptionCharge::query()
                ->whereKey($reference->subscription_charge_id)
                ->where('workspace_id', $reference->workspace_id)
                ->lockForUpdate()
                ->firstOrFail();

            $reference->forceFill([
                'status' => EmisPaymentReferenceStatus::Expired,
                'last_checked_at' => now('Africa/Luanda'),
            ])->save();
            $charge->forceFill([
                'active_checkout_key' => null,
                'status' => SubscriptionChargeStatus::Expired,
            ])->save();
            $payloadSha256 = hash(
                'sha256',
                $reference->provider_reference_id.'|expired|'.$reference->expires_at->toIso8601String(),
            );
            $reference->events()->create([
                'workspace_id' => $reference->workspace_id,
                'event_type' => BillingPaymentEventType::ReferenceExpired,
                'payload_sha256' => $payloadSha256,
                'safe_context' => ['expired_at' => $reference->expires_at->toIso8601String()],
                'occurred_at' => now('Africa/Luanda'),
            ]);

            return true;
        }, 5);
    }
}
