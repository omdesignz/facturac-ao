<?php

namespace App\Fiscal;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Durable, reserve-only accounting. No network operation is allowed in these transactions. */
final class AssistantProviderLedger
{
    public function gates(AssistantProviderInvocation $invocation): \stdClass
    {
        $invocation->permissions();

        return $this->admission($invocation->context, true);
    }

    private function admission(AssistantInteractionContext $context, bool $requireAcknowledgement): \stdClass
    {
        $context->fresh();
        abort_unless(config('assistant.provider.enabled') === true && config('assistant.provider.egress_enabled') === true
            && AssistantProviderProfile::valid() && config('assistant.provider.notice_version') === AssistantProviderProfile::POLICY
            && is_string(config('assistant.provider.notice_url'))
            && preg_match('#^/[a-zA-Z0-9/_-]{1,160}$#D', config('assistant.provider.notice_url')) === 1, 503);
        $budget = config('assistant.provider.budget_id');
        abort_unless(is_string($budget) && Str::isUuid($budget), 503);
        TenantAiLegacyAccounting::authorize($budget);
        $control = $this->legacy('assistant_provider_controls')->useWritePdo()->where('budget_id', $budget)->first();
        abort_unless($control !== null && $control->enabled && ! $control->circuit_blocked
            && $control->profile === AssistantProviderProfile::ID && $control->policy === AssistantProviderProfile::POLICY
            && is_string($control->approval_reference) && preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $control->approval_reference) === 1
            && $control->approval_reference === config('assistant.provider.approval_reference')
            && $control->approval_expires_at !== null && CarbonImmutable::parse($control->approval_expires_at)->isFuture(), 503);
        $tenant = $this->legacy('assistant_provider_tenants')->useWritePdo()->where('workspace_id', $context->execution->workspaceId)
            ->where('policy', AssistantProviderProfile::POLICY)->where('profile', AssistantProviderProfile::ID)->whereNull('revoked_at')->first();
        abort_unless($tenant !== null && CarbonImmutable::parse($tenant->expires_at)->isFuture()
            && preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $tenant->approval_reference) === 1, 503);
        abort_unless(DB::table('workspace_memberships')->useWritePdo()->join('users', 'users.id', '=', 'workspace_memberships.user_id')
            ->where('workspace_memberships.workspace_id', $context->execution->workspaceId)
            ->where('workspace_memberships.is_active', true)->where('workspace_memberships.role', 'owner')
            ->where('users.attribution_id', $tenant->owner_attribution_id)->exists(), 503);
        abort_unless(! $requireAcknowledgement || $this->legacy('assistant_provider_acknowledgements')->useWritePdo()
            ->where('workspace_id', $context->execution->workspaceId)
            ->where('actor_attribution_id', $context->actorAttributionId)
            ->where('policy', AssistantProviderProfile::POLICY)->whereNull('revoked_at')->exists(), 503);

        return $control;
    }

    /** @return array{policy: string, notice_url: ?string, available: bool, acknowledged: bool} */
    public function notice(AssistantInteractionContext $context): array
    {
        $available = false;
        try {
            $this->admission($context, false);
            $available = true;
        } catch (HttpExceptionInterface $error) {
            if ($error->getStatusCode() !== 503) {
                throw $error;
            }
        }
        $acknowledged = $this->legacy('assistant_provider_acknowledgements')->useWritePdo()
            ->where('actor_attribution_id', $context->actorAttributionId)->where('workspace_id', $context->execution->workspaceId)
            ->where('policy', AssistantProviderProfile::POLICY)->whereNull('revoked_at')->exists();

        return ['policy' => AssistantProviderProfile::POLICY,
            'notice_url' => $available ? config('assistant.provider.notice_url') : null,
            'available' => $available, 'acknowledged' => $acknowledged];
    }

    public function acknowledge(AssistantInteractionContext $context, bool $acknowledge): void
    {
        $context->fresh();
        DB::transaction(function () use ($context, $acknowledge): void {
            if ($acknowledge) {
                $this->admission($context, false);
            }
            $identity = ['actor_attribution_id' => $context->actorAttributionId,
                'workspace_id' => $context->execution->workspaceId, 'policy' => AssistantProviderProfile::POLICY];
            $time = CarbonImmutable::now('UTC')->toIso8601String();
            if ($acknowledge) {
                if (DB::getDriverName() === 'pgsql') {
                    DB::statement("INSERT INTO assistant_provider_acknowledgements (actor_attribution_id,workspace_id,policy,acknowledged_at,revoked_at,contract_version)
                        VALUES (?,?,?,?,NULL,'legacy') ON CONFLICT (actor_attribution_id,workspace_id,policy) WHERE contract_version='legacy'
                        DO UPDATE SET acknowledged_at=EXCLUDED.acknowledged_at,revoked_at=NULL", [...array_values($identity), $time]);
                } else {
                    $this->legacy('assistant_provider_acknowledgements')->upsert([
                        [...$identity, 'acknowledged_at' => $time, 'revoked_at' => null],
                    ], array_keys($identity), ['acknowledged_at', 'revoked_at']);
                }
            } else {
                $this->legacy('assistant_provider_acknowledgements')->where($identity)->update(['revoked_at' => $time]);
            }
            AssistantAudit::record($context, $acknowledge ? 'assistant.privacy.acknowledged' : 'assistant.privacy.withdrawn',
                ['policy' => AssistantProviderProfile::POLICY, 'outcome' => $acknowledge ? 'acknowledged' : 'withdrawn']);
            $context->fresh();
        }, 1);
    }

    public function reserve(AssistantProviderInvocation $invocation, #[\SensitiveParameter] string $body): string
    {
        $estimate = AssistantProviderProfile::estimate($body);
        $control = $this->gates($invocation);
        $reserve = AssistantProviderProfile::reservation();
        abort_unless($this->limit('attempt') >= $reserve, 503);
        $time = CarbonImmutable::now('UTC');
        $attempt = [
            'id' => (string) Str::uuid(), 'interaction_id' => $invocation->context->interactionId,
            'budget_id' => $control->budget_id, 'actor_attribution_id' => $invocation->context->actorAttributionId,
            'workspace_id' => $invocation->context->execution->workspaceId, 'legal_entity_id' => $invocation->context->execution->legalEntityId,
            'environment' => 'production', 'profile' => AssistantProviderProfile::ID, 'price_profile' => AssistantProviderProfile::PRICE_ID,
            'policy' => AssistantProviderProfile::POLICY, 'day_start' => $time->startOfDay()->toIso8601String(),
            'month_start' => $time->startOfMonth()->toIso8601String(), 'reserved_micro_usd' => $reserve,
            'estimated_tokens' => $estimate, 'request_bytes' => strlen($body), 'state' => 'admitted', 'admitted_at' => $time->toIso8601String(),
        ];

        return DB::transaction(function () use ($attempt, $invocation, $reserve): string {
            $mapping = TenantAiLegacyAccounting::lock($attempt['budget_id']);
            $this->legacy('assistant_provider_controls')->where('budget_id', $attempt['budget_id'])->lockForUpdate()->firstOrFail();
            $this->gates($invocation);
            abort_if($this->legacy('assistant_provider_attempts')->where('interaction_id', $attempt['interaction_id'])->exists(), 409);
            foreach (TenantAiLegacyAccounting::windows($this->windows((object) $attempt), $mapping) as $window) {
                $identity = array_intersect_key($window, array_flip(['budget_id', 'scope', 'scope_key', 'window_start']));
                DB::table('assistant_provider_windows')->insertOrIgnore($window);
                $row = DB::table('assistant_provider_windows')->where($identity)->lockForUpdate()->firstOrFail();
                if ($mapping !== null && $window['budget_id'] === $mapping['usage_budget_id']) {
                    TenantAiLegacyAccounting::reserve($row, $window, $mapping);

                    continue;
                }
                $limit = $this->limit($window['scope']);
                abort_unless($limit > 0, 503);
                abort_if($row->reserved_micro_usd > $limit - $reserve, 429);
                DB::table('assistant_provider_windows')->where('id', $row->id)->update([
                    'reserved_micro_usd' => $row->reserved_micro_usd + $reserve, 'attempt_count' => $row->attempt_count + 1,
                ]);
            }
            $this->legacy('assistant_provider_attempts')->insert($attempt);
            AssistantAudit::record($invocation->context, 'assistant.provider.attempted', [
                'attempt_id' => $attempt['id'], 'provider_profile' => AssistantProviderProfile::ID, 'price_profile' => AssistantProviderProfile::PRICE_ID,
                'policy' => AssistantProviderProfile::POLICY, 'outcome' => 'admitted', 'reserved_micro_usd' => $reserve,
            ]);

            return $attempt['id'];
        }, 1);
    }

    public function assertAdmitted(string $attemptId, AssistantProviderInvocation $invocation): void
    {
        abort_unless($this->legacy('assistant_provider_attempts')->useWritePdo()->where('id', $attemptId)
            ->where('interaction_id', $invocation->context->interactionId)->where('actor_attribution_id', $invocation->context->actorAttributionId)
            ->where('workspace_id', $invocation->context->execution->workspaceId)->where('legal_entity_id', $invocation->context->execution->legalEntityId)
            ->where('profile', AssistantProviderProfile::ID)->where('state', 'admitted')->whereNull('finalized_at')->exists(), 503);
    }

    /** @param array{input: int, output: int}|null $usage */
    public function finalize(string $attemptId, ?array $usage, bool $received, bool $anomaly, ?AssistantInteractionContext $context = null, bool $notSent = false): void
    {
        abort_if($notSent && ($usage !== null || $received || $anomaly), 503);
        $initial = $this->legacy('assistant_provider_attempts')->useWritePdo()->where('id', $attemptId)->firstOrFail();
        DB::transaction(function () use ($initial, $usage, $received, $anomaly, $context, $notSent): void {
            $mapping = TenantAiLegacyAccounting::lock($initial->budget_id);
            $this->legacy('assistant_provider_controls')->where('budget_id', $initial->budget_id)->lockForUpdate()->firstOrFail();
            $rows = [];
            foreach (TenantAiLegacyAccounting::windows($this->windows($initial), $mapping) as $window) {
                $identity = array_intersect_key($window, array_flip(['budget_id', 'scope', 'scope_key', 'window_start']));
                $rows[] = DB::table('assistant_provider_windows')->where($identity)->lockForUpdate()->firstOrFail();
            }
            $attempt = $this->legacy('assistant_provider_attempts')->where('id', $initial->id)->lockForUpdate()->firstOrFail();
            if ($attempt->state !== 'admitted') {
                return;
            }
            $charge = $usage === null ? null : AssistantProviderProfile::actualCharge($usage['input'], $usage['output']);
            $outcome = $notSent ? 'not_sent' : ($usage === null ? 'usage_unknown' : ($anomaly ? 'profile_mismatch' : ($received ? 'received' : 'failed')));
            foreach ($rows as $row) {
                if ($mapping !== null && $row->budget_id === $mapping['usage_budget_id']) {
                    DB::table('assistant_provider_windows')->where('id', $row->id)->update([
                        'unknown_usage_count' => TenantAiVerificationAdmission::add((int) $row->unknown_usage_count, $usage === null && ! $notSent ? 1 : 0)]);

                    continue;
                }
                DB::table('assistant_provider_windows')->where('id', $row->id)->update([
                    'actual_input_tokens' => $row->actual_input_tokens + ($usage['input'] ?? 0),
                    'actual_output_tokens' => $row->actual_output_tokens + ($usage['output'] ?? 0),
                    'actual_micro_usd' => $row->actual_micro_usd + ($charge ?? 0),
                    'unknown_usage_count' => $row->unknown_usage_count + ($usage === null && ! $notSent ? 1 : 0),
                ]);
            }
            $this->legacy('assistant_provider_attempts')->where('id', $attempt->id)->where('state', 'admitted')->update([
                'state' => $notSent ? 'failed' : ($usage === null ? 'usage_unknown' : ($received && ! $anomaly ? 'received' : 'failed')),
                'input_tokens' => $usage['input'] ?? null, 'output_tokens' => $usage['output'] ?? null,
                'actual_micro_usd' => $charge, 'outcome' => $outcome, 'finalized_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            ]);
            if ($anomaly || ($usage === null && ! $notSent)) {
                $this->legacy('assistant_provider_controls')->where('budget_id', $attempt->budget_id)->update(['circuit_blocked' => true, 'outcome' => $outcome,
                    ...(DB::getDriverName() === 'pgsql' ? ['revision' => DB::raw('revision + 1')] : [])]);
            }
            $metadata = ['attempt_id' => $attempt->id, 'interaction_id' => $attempt->interaction_id,
                'actor_attribution_id' => $attempt->actor_attribution_id, 'workspace_id' => $attempt->workspace_id,
                'legal_entity_id' => $attempt->legal_entity_id, 'environment' => 'production',
                'provider_profile' => $attempt->profile, 'price_profile' => $attempt->price_profile, 'policy' => $attempt->policy, 'outcome' => $outcome,
                'input_tokens' => $usage['input'] ?? null, 'output_tokens' => $usage['output'] ?? null,
                'reserved_micro_usd' => $attempt->reserved_micro_usd];
            $event = $received ? 'assistant.provider.received' : 'assistant.provider.failed';
            if ($context !== null) {
                abort_unless($context->interactionId === $attempt->interaction_id, 503);
                AssistantAudit::record($context, $event, $metadata);
            } else {
                RequiredAudit::record(fn () => activity('assistant')->causedByAnonymous()->event($event)
                    ->withProperties(['actor_kind' => 'system', 'schema_version' => 1, ...$metadata])->log('Bounded provider recovery'));
            }
        }, 1);
    }

    /** Recovery classifies at most 100 stale attempts per invocation; it never sends or refunds. */
    public function recover(): int
    {
        $attempts = $this->legacy('assistant_provider_attempts')->useWritePdo()->where('state', 'admitted')
            ->where('admitted_at', '<', CarbonImmutable::now('UTC')->subMinutes(2)->toIso8601String())->orderBy('admitted_at')->orderBy('id')->limit(100)->pluck('id');
        foreach ($attempts as $attemptId) {
            $this->finalize($attemptId, null, false, true);
        }

        return $attempts->count();
    }

    /** @return list<array{budget_id: string, scope: string, scope_key: string, window_start: string, window_end: string}> */
    private function windows(\stdClass $attempt): array
    {
        $windows = [];
        foreach (['deployment_month', 'deployment_day', 'workspace_month', 'workspace_day', 'user_day'] as $scope) {
            $month = str_ends_with($scope, '_month');
            $start = CarbonImmutable::parse($month ? $attempt->month_start : $attempt->day_start, 'UTC');
            $windows[] = ['budget_id' => $attempt->budget_id, 'scope' => $scope,
                'scope_key' => str_starts_with($scope, 'deployment_') ? 'deployment' : (str_starts_with($scope, 'workspace_') ? (string) $attempt->workspace_id : $attempt->actor_attribution_id),
                'window_start' => $start->toIso8601String(), 'window_end' => ($month ? $start->addMonth() : $start->addDay())->toIso8601String()];
        }

        return $windows;
    }

    private function legacy(string $table): Builder
    {
        $query = DB::table($table);

        return DB::getDriverName() === 'pgsql' ? $query->where('contract_version', 'legacy') : $query;
    }

    private function limit(string $scope): int
    {
        $limit = config('assistant.provider.budgets.'.$scope);
        abort_unless(is_int($limit) && $limit >= 0 && $limit <= (AssistantProviderProfile::BUDGET_CEILINGS[$scope] ?? 0), 503);

        return $limit;
    }
}
