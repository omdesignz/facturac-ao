<?php

namespace App\Fiscal;

use App\Exceptions\TenantAiInvocationRefused;
use App\Exceptions\TenantAiStorageUnavailable;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Primary admission, immutable snapshot and settlement of one inference attempt; no network or secret access. */
final class TenantAiInvocationAdmission
{
    /** @return array{policy: TenantAiInvocationPolicy, attempt: array<string, mixed>} */
    public function admit(TenantAiInvocationSession $session, AssistantProviderInvocation $invocation, #[\SensitiveParameter] string $body): array
    {
        $session->requireStage('admitting');
        if (DB::transactionLevel() !== 0) {
            throw new TenantAiStorageUnavailable;
        }

        return DB::transaction(function () use ($session, $invocation, $body): array {
            TenantAiVerificationAdmission::limits(hrtime(true) / 1e9 + $invocation->remaining());
            $policy = (new TenantAiInvocationPolicyResolver)->resolve($invocation, $session->realm(), true);
            $instant = TenantAiVerificationAdmission::instant();
            abort_if(DB::table('assistant_provider_attempts')->useWritePdo()->where('interaction_id', $invocation->context->interactionId)->exists(), 409);
            $windows = $this->windows($invocation, $policy, $instant);
            $locked = [];
            foreach ($windows as $window) {
                $identity = array_intersect_key($window, array_flip(['budget_id', 'scope', 'scope_key', 'window_start']));
                DB::table('assistant_provider_windows')->insertOrIgnore([...$identity, 'window_end' => $window['window_end']]);
                $locked[] = DB::table('assistant_provider_windows')->where($identity)->lockForUpdate()->firstOrFail();
            }
            $attempt = $this->snapshot($invocation, $policy, $body);
            $attempt += ['id' => (string) Str::uuid(), 'interaction_id' => $invocation->context->interactionId,
                'admitted_at' => TenantAiVerificationAdmission::time($instant), 'day_start' => TenantAiVerificationAdmission::time($instant->startOfDay()),
                'month_start' => TenantAiVerificationAdmission::time($instant->startOfMonth()), 'state' => 'admitted'];
            foreach ($locked as $index => $row) {
                $window = $windows[$index];
                $updates = [];
                foreach (['reserved_micro_usd', 'reserved_attempt_units', 'reserved_output_units'] as $unit) {
                    $amount = $window[$unit];
                    $ceiling = $this->ceiling($policy, $window, $unit);
                    if ($amount > 0 && ((int) $row->{$unit} > $ceiling - $amount || $ceiling < $amount)) {
                        throw new TenantAiInvocationRefused(AiInvocationDenial::QuotaExceeded);
                    }
                    $updates[$unit] = TenantAiVerificationAdmission::add((int) $row->{$unit}, $amount);
                }
                $updates['attempt_count'] = TenantAiVerificationAdmission::add((int) $row->attempt_count, 1);
                DB::table('assistant_provider_windows')->where('id', $row->id)->update($updates);
            }
            DB::table('assistant_provider_attempts')->insert($attempt);
            foreach ($locked as $index => $row) {
                DB::table('assistant_provider_allocations')->insert(['attempt_id' => $attempt['id'], 'window_id' => $row->id,
                    'role' => $windows[$index]['role'], 'created_at' => $attempt['admitted_at'],
                    ...array_intersect_key($windows[$index], array_flip(['reserved_micro_usd', 'reserved_attempt_units', 'reserved_output_units']))]);
            }
            self::audit($invocation->context, $attempt, 'invocation_credential_selected', 'admitted');
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $invocation->permissions();

            return ['policy' => $policy, 'attempt' => $attempt];
        }, 1);
    }

    /** @return array<string, mixed> */
    public function snapshot(AssistantProviderInvocation $invocation, TenantAiInvocationPolicy $policy, #[\SensitiveParameter] string $body): array
    {
        $context = $invocation->context;

        return ['workspace_id' => $context->execution->workspaceId, 'legal_entity_id' => $context->execution->legalEntityId,
            'deployment_id' => $policy->connection->deployment_id, 'environment' => 'production', 'budget_id' => $policy->connection->payer_budget_id,
            'actor_attribution_id' => $context->actorAttributionId, 'profile' => $policy->profile->profile_key,
            'price_profile' => $policy->profile->price_key, 'policy' => $policy->approval->policy,
            'reserved_micro_usd' => (int) $policy->profile->reservation_micro_usd, 'estimated_tokens' => AssistantProviderProfile::estimate($body),
            'request_bytes' => strlen($body), 'contract_version' => 'gateway_v1', 'provider_key' => $policy->profile->provider_key,
            'ownership_kind' => 'customer_managed', 'profile_id' => $policy->profile->id, 'selection_revision' => (int) $policy->settings->revision,
            'connection_id' => $policy->connection->id, 'credential_version_id' => $policy->credential->id,
            'endpoint_policy_key' => $policy->profile->endpoint_policy_key, 'disclosure_id' => $policy->approval->disclosure_id,
            'entitlement_revision' => (int) $policy->settings->entitlement_revision, 'purpose' => 'assistant_intent', 'monetary_applicable' => true,
            'reserved_output_units' => (int) $policy->profile->output_envelope, 'usage_budget_id' => $policy->account['usage_budget_id'],
            'aggregate_budget_id' => $policy->account['aggregate_budget_id'], 'owner_approval_id' => (int) $policy->approval->id,
            'acknowledgement_id' => (int) $policy->acknowledgement->id, 'workspace_public_id' => $policy->workspacePublicId,
            'legal_entity_public_id' => $policy->legalEntityPublicId, 'credential_version_number' => (int) $policy->credential->version_number,
            'credential_created_at' => TenantAiVerificationAdmission::time(CarbonImmutable::parse($policy->credential->created_at)),
            'credential_wrap_revision' => (int) $policy->credential->wrap_revision,
            'expected_settings_revision' => (int) $policy->settings->revision, 'expected_connection_revision' => (int) $policy->connection->revision,
            'prior_selected_version_id' => $policy->credential->id, 'prior_active_generation' => (int) $policy->credential->activated_generation,
            'proposed_activated_generation' => null, 'actor_membership_id' => $context->membershipId,
            'profile_manifest_sha256' => $policy->profile->manifest_sha256, 'credential_family' => $policy->profile->credential_family,
            'verifier_policy_key' => null, 'verifier_policy_sha256' => null, 'account_mapping_sha256' => $policy->account['mapping_sha256'],
            'authority_references' => json_encode($policy->references, JSON_THROW_ON_ERROR),
            'acknowledged_at_snapshot' => TenantAiVerificationAdmission::time(CarbonImmutable::parse($policy->acknowledgement->acknowledged_at)),
            'evidence_realm' => $policy->realm, 'verification_outcome' => 'not_observed'];
    }

    /** Settles against the persisted allocations and send mark only, so it succeeds after revoke, rotate or disable.
     * @param  array{input: int, output: int}|null  $usage
     */
    public function settle(string $attemptId, ?array $usage, bool $received, bool $anomaly, ?AssistantInteractionContext $context = null, ?int $staleSeconds = null): bool
    {
        if (DB::getDriverName() !== 'pgsql' || DB::transactionLevel() !== 0) {
            throw new TenantAiStorageUnavailable;
        }

        return DB::transaction(function () use ($attemptId, $usage, $received, $anomaly, $context, $staleSeconds): bool {
            TenantAiVerificationAdmission::limits(hrtime(true) / 1e9 + 10);
            $initial = DB::table('assistant_provider_attempts')->useWritePdo()->where('id', $attemptId)->where('contract_version', 'gateway_v1')
                ->where('purpose', 'assistant_intent')->first();
            if ($initial === null) {
                return false;
            }
            DB::table('ai_gateway_controls')->where('deployment_id', $initial->deployment_id)->where('kind', 'global')->where('subject_key', 'root')->lockForUpdate()->firstOrFail();
            $budgets = [$initial->budget_id, $initial->aggregate_budget_id, $initial->usage_budget_id];
            sort($budgets, SORT_STRING);
            foreach ($budgets as $budget) {
                DB::table('assistant_provider_controls')->where('budget_id', $budget)->lockForUpdate()->firstOrFail();
            }
            $windows = DB::table('assistant_provider_windows as w')->useWritePdo()->select(['w.id', 'a.role'])
                ->join('assistant_provider_allocations as a', 'a.window_id', '=', 'w.id')->where('a.attempt_id', $initial->id)
                ->orderBy('w.budget_id')->orderByRaw("CASE w.scope WHEN 'deployment_month' THEN 1 WHEN 'deployment_day' THEN 2 WHEN 'workspace_month' THEN 3 WHEN 'workspace_day' THEN 4 ELSE 5 END")
                ->orderByRaw('w.scope_key COLLATE "C"')->orderBy('w.window_start')->limit(10)->get();
            if ($windows->count() !== 9) {
                throw new TenantAiStorageUnavailable;
            }
            $locked = [];
            foreach ($windows as $window) {
                $locked[] = [DB::table('assistant_provider_windows')->where('id', $window->id)->lockForUpdate()->firstOrFail(), $window->role];
            }
            $attempt = DB::table('assistant_provider_attempts')->where('id', $initial->id)->lockForUpdate()->firstOrFail();
            $now = TenantAiVerificationAdmission::instant();
            if ($attempt->state !== 'admitted' || ($staleSeconds !== null && ! CarbonImmutable::parse($attempt->admitted_at)->lt($now->subSeconds($staleSeconds)))) {
                return false;
            }
            $sent = $attempt->send_authorized_at !== null;
            if (! $sent && ($usage !== null || $received)) {
                throw new TenantAiStorageUnavailable;
            }
            $charge = $usage === null ? null : AssistantProviderProfile::actualCharge($usage['input'], $usage['output']);
            $outcome = ! $sent ? 'not_sent' : ($usage === null ? 'usage_unknown' : ($anomaly ? 'profile_mismatch' : ($received ? 'received' : 'failed')));
            $state = ! $sent ? 'failed' : ($usage === null ? 'usage_unknown' : ($received && ! $anomaly ? 'received' : 'failed'));
            foreach ($locked as [$row, $role]) {
                DB::table('assistant_provider_windows')->where('id', $row->id)->update([
                    'actual_input_tokens' => TenantAiVerificationAdmission::add((int) $row->actual_input_tokens, $usage['input'] ?? 0),
                    'actual_output_tokens' => TenantAiVerificationAdmission::add((int) $row->actual_output_tokens, $usage['output'] ?? 0),
                    'actual_micro_usd' => TenantAiVerificationAdmission::add((int) $row->actual_micro_usd, $role === 'shared_usage' ? 0 : ($charge ?? 0)),
                    'unknown_usage_count' => TenantAiVerificationAdmission::add((int) $row->unknown_usage_count, $outcome === 'usage_unknown' ? 1 : 0),
                ]);
            }
            DB::table('assistant_provider_attempts')->where('id', $attempt->id)->where('state', 'admitted')->update([
                'state' => $state, 'outcome' => $outcome, 'finalized_at' => TenantAiVerificationAdmission::time($now),
                'input_tokens' => $usage['input'] ?? null, 'output_tokens' => $usage['output'] ?? null, 'actual_micro_usd' => $charge]);
            if (in_array($outcome, ['usage_unknown', 'profile_mismatch'], true)) {
                // One tenant's provider anomaly blocks only that tenant's account budget, never the root or shared usage controls.
                DB::table('assistant_provider_controls')->where('budget_id', $attempt->budget_id)
                    ->update(['circuit_blocked' => true, 'outcome' => $outcome, 'revision' => DB::raw('revision + 1')]);
            }
            $metadata = [...array_intersect_key((array) $attempt, array_flip(['id', 'interaction_id', 'actor_attribution_id', 'workspace_public_id',
                'legal_entity_public_id', 'environment', 'deployment_id', 'connection_id', 'credential_version_id', 'profile_id', 'provider_key',
                'ownership_kind', 'purpose', 'reserved_micro_usd'])), 'outcome' => $outcome,
                'input_tokens' => $usage['input'] ?? null, 'output_tokens' => $usage['output'] ?? null];
            $event = $state === 'received' ? 'assistant.provider.received' : 'assistant.provider.failed';
            if ($context !== null) {
                abort_unless($context->interactionId === $attempt->interaction_id, 503);
                AssistantAudit::record($context, $event, $metadata);
            } else {
                RequiredAudit::record(fn () => activity('assistant')->causedByAnonymous()->event($event)
                    ->withProperties(['actor_kind' => 'system', 'schema_version' => 1, ...$metadata, 'user_agent' => null, 'ip_address' => null])
                    ->log('Bounded invocation recovery'));
            }
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

            return true;
        }, 1);
    }

    /** @param array<string, mixed> $properties */
    public static function audit(AssistantInteractionContext $context, array $properties, string $event, string $outcome): void
    {
        $actor = User::query()->useWritePdo()->find($context->execution->actorId);
        $properties = array_intersect_key($properties, array_flip(['id', 'interaction_id', 'actor_attribution_id', 'workspace_public_id',
            'legal_entity_public_id', 'environment', 'deployment_id', 'connection_id', 'credential_version_id', 'profile_id', 'provider_key',
            'ownership_kind', 'purpose', 'expected_settings_revision', 'expected_connection_revision', 'prior_active_generation', 'entitlement_revision']));
        RequiredAudit::record(fn () => activity('assistant')->causedBy($actor)->event('assistant.ai.'.$event)
            ->withProperties(['actor_kind' => 'human', 'interaction_id' => $context->interactionId, 'actor_attribution_id' => $context->actorAttributionId,
                'workspace_public_id' => $context->workspacePublicId, 'legal_entity_public_id' => $context->entityPublicId, 'environment' => 'production',
                'purpose' => 'assistant_intent', ...$properties, 'outcome' => $outcome, 'user_agent' => null, 'ip_address' => null])
            ->log('Bounded credential invocation'));
    }

    /** @return list<array<string, mixed>> */
    private function windows(AssistantProviderInvocation $invocation, TenantAiInvocationPolicy $policy, CarbonImmutable $instant): array
    {
        $roles = [$policy->account['usage_budget_id'] => 'shared_usage', $policy->account['aggregate_budget_id'] => 'customer_aggregate',
            $policy->connection->payer_budget_id => 'customer_account'];
        ksort($roles, SORT_STRING);
        $windows = [];
        foreach ($roles as $budget => $role) {
            $scopes = $role === 'shared_usage' ? ['deployment_month', 'deployment_day', 'workspace_month', 'workspace_day', 'user_day'] : ['workspace_month', 'workspace_day'];
            foreach ($scopes as $scope) {
                $start = str_ends_with($scope, 'month') ? $instant->startOfMonth() : $instant->startOfDay();
                $windows[] = ['budget_id' => $budget, 'role' => $role, 'scope' => $scope,
                    'scope_key' => str_starts_with($scope, 'deployment') ? 'deployment' : ($scope === 'user_day' ? $invocation->context->actorAttributionId : (string) $invocation->context->execution->workspaceId),
                    'window_start' => TenantAiVerificationAdmission::time($start),
                    'window_end' => TenantAiVerificationAdmission::time(str_ends_with($scope, 'month') ? $start->addMonth() : $start->addDay()),
                    'reserved_micro_usd' => $role === 'shared_usage' ? 0 : (int) $policy->profile->reservation_micro_usd,
                    'reserved_attempt_units' => $role === 'shared_usage' ? 1 : 0,
                    'reserved_output_units' => $role === 'shared_usage' ? (int) $policy->profile->output_envelope : 0];
            }
        }

        return $windows;
    }

    /** @param array<string, mixed> $window */
    private function ceiling(TenantAiInvocationPolicy $policy, array $window, string $unit): int
    {
        if ($window[$unit] === 0) {
            return 0;
        }
        $cap = $policy->account['limits'][$window['budget_id']][$window['scope']][$unit] ?? null;
        if (! is_int($cap) || $cap <= 0) {
            throw new TenantAiInvocationRefused(AiInvocationDenial::QuotaExceeded);
        }
        if (str_starts_with($window['scope'], 'workspace')) {
            $period = str_ends_with($window['scope'], 'month') ? 'month' : 'day';
            $field = match ($unit) {
                'reserved_attempt_units' => 'attempts_'.$period.'_cap', 'reserved_output_units' => 'output_'.$period.'_cap',
                default => 'money_'.$period.'_micro_usd_cap',
            };
            $cap = min($cap, (int) $policy->settings->{$field});
        }

        return $cap;
    }
}
