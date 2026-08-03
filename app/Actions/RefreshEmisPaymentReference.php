<?php

namespace App\Actions;

use App\Billing\Contracts\EmisPaymentGateway;
use App\BillingPaymentEventType;
use App\EmisPaymentReferenceStatus;
use App\Models\EmisPaymentReference;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class RefreshEmisPaymentReference
{
    public function __construct(
        private EmisPaymentGateway $gateway,
        private ConfirmEmisPayment $confirmPayment,
        private ExpireEmisPaymentReferences $expirePaymentReferences,
    ) {}

    public function execute(
        EmisPaymentReference $paymentReference,
        ?User $actor = null,
    ): EmisPaymentReference {
        if ($paymentReference->status !== EmisPaymentReferenceStatus::Pending) {
            return $paymentReference;
        }

        if ($paymentReference->expires_at->isPast()) {
            $this->expirePaymentReferences->execute($paymentReference->workspace_id);

            return $paymentReference->fresh();
        }

        $status = $this->gateway->status($paymentReference->provider_reference_id);

        if ($status->status === EmisPaymentReferenceStatus::Paid) {
            $this->confirmPayment->execute(
                paymentReference: $paymentReference,
                providerEventId: (string) $status->providerEventId,
                amountMinor: $status->amountMinor,
                currencyCode: $status->currencyCode,
                occurredAt: $status->occurredAt,
                payloadSha256: $status->payloadSha256,
                actor: $actor,
            );

            return $paymentReference->fresh();
        }

        DB::transaction(function () use ($paymentReference, $status, $actor): void {
            $reference = EmisPaymentReference::query()
                ->whereKey($paymentReference->id)
                ->where('workspace_id', $paymentReference->workspace_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($reference->status !== EmisPaymentReferenceStatus::Pending) {
                return;
            }

            $reference->forceFill(['last_checked_at' => now('Africa/Luanda')])->save();
            $reference->events()->create([
                'workspace_id' => $reference->workspace_id,
                'actor_user_id' => $actor?->id,
                'event_type' => BillingPaymentEventType::StatusChecked,
                'provider_event_id' => $status->providerEventId,
                'payload_sha256' => $status->payloadSha256,
                'safe_context' => ['status' => $status->status->value],
                'occurred_at' => $status->occurredAt,
            ]);
        }, 5);

        return $paymentReference->fresh();
    }
}
