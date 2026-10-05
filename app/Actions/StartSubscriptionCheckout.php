<?php

namespace App\Actions;

use App\Billing\Contracts\EmisPaymentGateway;
use App\Billing\Data\CreateEmisReferenceData;
use App\BillingPaymentEventType;
use App\EmisPaymentReferenceStatus;
use App\Models\EmisPaymentReference;
use App\Models\SubscriptionCharge;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSubscription;
use App\Pay4AllEnvironment;
use App\SubscriptionChargeStatus;
use App\SubscriptionInterval;
use App\WorkspaceSubscriptionStatus;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final readonly class StartSubscriptionCheckout
{
    public function __construct(
        private EmisPaymentGateway $gateway,
    ) {}

    public function execute(
        Workspace $workspace,
        SubscriptionPlan $plan,
        User $actor,
    ): EmisPaymentReference {
        $environment = $this->environment();
        $this->assertCanCreateReference($plan, $environment);

        $charge = $this->findOrCreateCharge($workspace, $plan, $actor);
        $existingReference = $charge->paymentReference()->first();

        if ($existingReference instanceof EmisPaymentReference) {
            return $existingReference;
        }

        try {
            $referenceData = $this->gateway->createReference(new CreateEmisReferenceData(
                idempotencyKey: $charge->checkout_token,
                merchantReference: $charge->public_id,
                amountMinor: $charge->amount_minor,
                currencyCode: $charge->currency_code,
                description: 'Assinatura '.(string) config('app.name').' - '.$plan->name,
                requestedExpiresAt: $charge->due_at,
            ));

            if ($referenceData->amountMinor !== $charge->amount_minor
                || $referenceData->currencyCode !== $charge->currency_code) {
                throw new DomainException(
                    'A referência devolvida pelo provedor não corresponde ao valor da cobrança.',
                );
            }

            return DB::transaction(function () use (
                $charge,
                $referenceData,
                $environment,
                $actor,
            ): EmisPaymentReference {
                $lockedCharge = SubscriptionCharge::query()
                    ->with('paymentReference')
                    ->whereKey($charge->id)
                    ->where('workspace_id', $charge->workspace_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedCharge->paymentReference instanceof EmisPaymentReference) {
                    return $lockedCharge->paymentReference;
                }

                $reference = $lockedCharge->paymentReference()->create([
                    'workspace_id' => $lockedCharge->workspace_id,
                    'provider' => 'pay4all',
                    'environment' => $environment,
                    'provider_reference_id' => $referenceData->providerReferenceId,
                    'entity' => $referenceData->entity,
                    'reference' => $referenceData->reference,
                    'amount_minor' => $referenceData->amountMinor,
                    'currency_code' => $referenceData->currencyCode,
                    'status' => EmisPaymentReferenceStatus::Pending,
                    'expires_at' => $referenceData->expiresAt,
                    'provider_payload_sha256' => $referenceData->payloadSha256,
                    'provider_metadata' => [
                        'simulation' => $environment === Pay4AllEnvironment::Simulation,
                    ],
                ]);

                $lockedCharge->forceFill([
                    'status' => SubscriptionChargeStatus::Pending,
                    'due_at' => $referenceData->expiresAt,
                ])->save();

                $reference->events()->create([
                    'workspace_id' => $reference->workspace_id,
                    'actor_user_id' => $actor->id,
                    'event_type' => BillingPaymentEventType::ReferenceCreated,
                    'payload_sha256' => $referenceData->payloadSha256,
                    'safe_context' => [
                        'amount_minor' => $reference->amount_minor,
                        'currency_code' => $reference->currency_code,
                        'environment' => $environment->value,
                        'provider' => 'pay4all',
                    ],
                    'occurred_at' => now('Africa/Luanda'),
                ]);

                activity('subscription-billing')
                    ->causedBy($actor)
                    ->performedOn($lockedCharge)
                    ->event('emis-reference-created')
                    ->withProperties([
                        'workspace_id' => $lockedCharge->workspace_id,
                        'charge_public_id' => $lockedCharge->public_id,
                        'payment_reference_public_id' => $reference->public_id,
                        'provider_payload_sha256' => $referenceData->payloadSha256,
                    ])
                    ->log('Referência EMIS criada para cobrança de assinatura.');

                return $reference;
            }, 5);
        } catch (Throwable $exception) {
            $this->markFailed($charge, $actor, $exception);

            throw $exception;
        }
    }

    private function findOrCreateCharge(
        Workspace $workspace,
        SubscriptionPlan $plan,
        User $actor,
    ): SubscriptionCharge {
        return DB::transaction(function () use ($workspace, $plan, $actor): SubscriptionCharge {
            $lockedWorkspace = Workspace::query()
                ->whereKey($workspace->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedWorkspace instanceof Workspace) {
                throw (new ModelNotFoundException)->setModel(Workspace::class, [$workspace->id]);
            }

            $activeCheckoutKey = (string) $workspace->id;
            $existingCharge = SubscriptionCharge::query()
                ->with('paymentReference')
                ->where('workspace_id', $workspace->id)
                ->where('active_checkout_key', $activeCheckoutKey)
                ->lockForUpdate()
                ->first();

            if ($existingCharge instanceof SubscriptionCharge) {
                if ($existingCharge->hostedPayment()->exists()) {
                    throw new DomainException('Conclua ou verifique o pagamento WiPay pendente antes de gerar uma referência.');
                }

                if ($existingCharge->due_at->isFuture()) {
                    if ($existingCharge->subscription_plan_id !== $plan->id) {
                        throw new DomainException(
                            'Já existe uma Referência EMIS pendente para outro plano.',
                        );
                    }

                    return $existingCharge;
                }

                $this->expireLockedCharge($existingCharge, $actor);
            }

            $subscription = WorkspaceSubscription::query()
                ->where('workspace_id', $workspace->id)
                ->lockForUpdate()
                ->first();
            $periodStartsAt = $this->periodStartsAt($subscription, $plan);
            $periodEndsAt = $this->periodEndsAt($periodStartsAt, $plan->interval);
            $feeBasisPoints = max(
                0,
                min(10_000, (int) config('billing.pay4all.transaction_fee_basis_points', 100)),
            );

            return SubscriptionCharge::query()->create([
                'workspace_id' => $workspace->id,
                'workspace_subscription_id' => $subscription?->id,
                'subscription_plan_id' => $plan->id,
                'created_by_user_id' => $actor->id,
                'checkout_token' => (string) Str::ulid(),
                'active_checkout_key' => $activeCheckoutKey,
                'amount_minor' => $plan->amount_minor,
                'currency_code' => $plan->currency_code,
                'provider_fee_basis_points' => $feeBasisPoints,
                'estimated_provider_fee_minor' => intdiv(
                    ($plan->amount_minor * $feeBasisPoints) + 9_999,
                    10_000,
                ),
                'status' => SubscriptionChargeStatus::Creating,
                'period_starts_at' => $periodStartsAt,
                'period_ends_at' => $periodEndsAt,
                'due_at' => now('Africa/Luanda')->addHours(
                    max(1, (int) config('billing.pay4all.reference_lifetime_hours', 24)),
                ),
            ]);
        }, 5);
    }

    private function expireLockedCharge(
        SubscriptionCharge $charge,
        User $actor,
    ): void {
        $paymentReference = $charge->paymentReference()->first();

        if ($paymentReference instanceof EmisPaymentReference
            && $paymentReference->status === EmisPaymentReferenceStatus::Pending) {
            $paymentReference->forceFill([
                'status' => EmisPaymentReferenceStatus::Expired,
            ])->save();

            $paymentReference->events()->create([
                'workspace_id' => $paymentReference->workspace_id,
                'actor_user_id' => $actor->id,
                'event_type' => BillingPaymentEventType::ReferenceExpired,
                'payload_sha256' => hash(
                    'sha256',
                    "checkout-expired:{$paymentReference->public_id}",
                ),
                'safe_context' => [
                    'reason' => 'expired_during_checkout',
                    'expired_at' => now('Africa/Luanda')->toIso8601String(),
                ],
                'occurred_at' => now('Africa/Luanda'),
            ]);
        }

        $charge->forceFill([
            'active_checkout_key' => null,
            'status' => SubscriptionChargeStatus::Expired,
        ])->save();
    }

    private function markFailed(
        SubscriptionCharge $charge,
        User $actor,
        Throwable $exception,
    ): void {
        DB::transaction(function () use ($charge, $actor, $exception): void {
            $lockedCharge = SubscriptionCharge::query()
                ->whereKey($charge->id)
                ->where('workspace_id', $charge->workspace_id)
                ->lockForUpdate()
                ->first();

            if (! $lockedCharge instanceof SubscriptionCharge
                || $lockedCharge->paymentReference()->exists()) {
                return;
            }

            $lockedCharge->forceFill([
                'active_checkout_key' => null,
                'status' => SubscriptionChargeStatus::Failed,
                'failed_at' => now('Africa/Luanda'),
                'failure_code' => class_basename($exception),
            ])->save();

            activity('subscription-billing')
                ->causedBy($actor)
                ->performedOn($lockedCharge)
                ->event('emis-reference-failed')
                ->withProperties([
                    'workspace_id' => $lockedCharge->workspace_id,
                    'charge_public_id' => $lockedCharge->public_id,
                    'failure_code' => class_basename($exception),
                ])
                ->log('Falha segura ao criar referência EMIS.');
        }, 5);
    }

    private function assertCanCreateReference(
        SubscriptionPlan $plan,
        Pay4AllEnvironment $environment,
    ): void {
        if (! $plan->is_active || $plan->amount_minor <= 0) {
            throw new DomainException('O plano seleccionado não está disponível para cobrança.');
        }

        if ($plan->currency_code !== 'AOA') {
            throw new DomainException('As Referências EMIS deste fluxo aceitam apenas valores em kwanzas.');
        }

        if ($plan->amount_minor > (int) config('billing.pay4all.maximum_reference_amount_minor')) {
            throw new DomainException('O valor excede o limite configurado para uma Referência EMIS.');
        }

        if ($environment === Pay4AllEnvironment::Production
            && ! (bool) config('billing.pay4all.production_enabled')) {
            throw new DomainException('A cobrança Pay4All em produção ainda não está autorizada.');
        }
    }

    private function environment(): Pay4AllEnvironment
    {
        return Pay4AllEnvironment::tryFrom(
            (string) config('billing.pay4all.environment'),
        ) ?? Pay4AllEnvironment::Simulation;
    }

    private function periodStartsAt(
        ?WorkspaceSubscription $subscription,
        SubscriptionPlan $plan,
    ): CarbonImmutable {
        $now = CarbonImmutable::now('Africa/Luanda');

        if ($subscription?->status === WorkspaceSubscriptionStatus::Active
            && $subscription->subscription_plan_id === $plan->id
            && $subscription->current_period_ends_at?->isFuture()) {
            return $subscription->current_period_ends_at;
        }

        return $now;
    }

    private function periodEndsAt(
        CarbonImmutable $periodStartsAt,
        SubscriptionInterval $interval,
    ): CarbonImmutable {
        return match ($interval) {
            SubscriptionInterval::Monthly => $periodStartsAt->addMonthNoOverflow(),
            SubscriptionInterval::Annual => $periodStartsAt->addYear(),
        };
    }
}
