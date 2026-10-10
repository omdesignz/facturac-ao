<?php

use App\Fiscal\TenantAiCatalogue;
use App\Fiscal\TenantAiVerificationSchema;
use App\Models\LegalEntity;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** @return array{legacy: string, deployment: string, shared: string} */
function verificationMigrationBudgets(): array
{
    $legacy = (string) Str::uuid();
    $deployment = (string) Str::uuid();
    $shared = (string) Str::uuid();
    DB::table('ai_gateway_controls')->insert([
        'id' => (string) Str::uuid(), 'deployment_id' => $deployment,
        'kind' => 'global', 'subject_key' => 'root', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $profile = ['profile' => 'p7-anthropic-haiku55-us-2026-10-08-v1', 'policy' => 'p7-question-v1'];
    DB::table('assistant_provider_controls')->insert(['budget_id' => $legacy, ...$profile]);
    DB::table('assistant_provider_controls')->insert([
        'budget_id' => $shared, ...$profile, 'contract_version' => 'gateway_v1',
        'deployment_id' => $deployment, 'ownership_kind' => 'shared_usage', 'account_role' => 'usage',
    ]);

    return compact('legacy', 'deployment', 'shared');
}

/** @param array{legacy: string, deployment: string, shared: string} $budgets */
function verificationMigrationReconcile(array $budgets, ?array $manifest = null): void
{
    $evidence = json_decode(file_get_contents(base_path('docs/phase-7b-3c-1-migration-review-evidence.json')), true, flags: JSON_THROW_ON_ERROR);
    $listing = $evidence['sql_listings'][4];
    expect(TenantAiVerificationSchema::reconciliation())->toBe($listing['sql']);
    expect(hash('sha256', $listing['sql']))->toBe('19aaabdbf829e0f03f0c0e92c4f8573723909caba63f92c77868158f29726abc');
    $position = strpos($listing['sql'], 'DO $reconcile$');
    $mapping = json_encode($manifest ?? [[
        'legacy_budget_id' => $budgets['legacy'], 'deployment_id' => $budgets['deployment'],
        'usage_budget_id' => $budgets['shared'],
    ]], JSON_THROW_ON_ERROR);
    $load = 'EXECUTE ai3c_load_map('.DB::connection()->getPdo()->quote($mapping).'::jsonb);';
    DB::unprepared(substr($listing['sql'], 0, $position).$load.substr($listing['sql'], $position));
}

/** @return array<string, list<object>> */
function verificationMigrationSnapshot(): array
{
    $snapshot = [];
    foreach (['ai_gateway_controls', 'assistant_provider_controls', 'assistant_provider_windows',
        'assistant_provider_attempts', 'assistant_provider_allocations', 'tenant_ai_credentials', 'activity_log'] as $table) {
        $snapshot[$table] = DB::table($table)->orderBy($table === 'assistant_provider_controls' ? 'budget_id' : ($table === 'assistant_provider_allocations' ? 'attempt_id' : 'id'))->get()->all();
    }

    return $snapshot;
}

function verificationMigrationAttempt(string $budget, bool $windows, array $overrides = [], array $windowOverrides = []): string
{
    $user = User::factory()->withWorkspace()->create();
    $entity = LegalEntity::factory()->create(['workspace_id' => $user->current_workspace_id]);
    $id = (string) Str::uuid();
    DB::table('assistant_provider_attempts')->insert([
        'id' => $id, 'interaction_id' => (string) Str::uuid(), 'budget_id' => $budget,
        'actor_attribution_id' => $user->attribution_id, 'workspace_id' => $entity->workspace_id,
        'legal_entity_id' => $entity->id, 'environment' => 'production',
        'profile' => 'p7-anthropic-haiku55-us-2026-10-08-v1',
        'price_profile' => 'p7-price-haiku55-us-2026-10-08-v1', 'policy' => 'p7-question-v1',
        'day_start' => '2026-10-09 00:00:00+00', 'month_start' => '2026-10-01 00:00:00+00',
        'reserved_micro_usd' => 552816, 'estimated_tokens' => 1024, 'request_bytes' => 100,
        'admitted_at' => '2026-10-09 12:00:00+00', ...$overrides,
    ]);
    if ($windows) {
        $attempt = DB::table('assistant_provider_attempts')->find($id);
        $windowOrdinal = 0;
        foreach ([['deployment_month', 'deployment'], ['deployment_day', 'deployment'],
            ['workspace_month', (string) $entity->workspace_id], ['workspace_day', (string) $entity->workspace_id],
            ['user_day', $attempt->actor_attribution_id]] as [$scope, $key]) {
            $month = str_ends_with($scope, 'month');
            $start = CarbonImmutable::parse($month ? $attempt->month_start : $attempt->day_start)->utc();
            DB::table('assistant_provider_windows')->insert([
                'budget_id' => $budget, 'scope' => $scope, 'scope_key' => $key,
                'window_start' => $start->format('Y-m-d H:i:s.uP'),
                'window_end' => ($month ? $start->addMonth() : $start->addDay())->format('Y-m-d H:i:s.uP'),
                'reserved_micro_usd' => 552816, 'attempt_count' => 1, ...($windowOrdinal++ === 0 ? $windowOverrides : []),
            ]);
        }
    }

    return $id;
}

/**
 * Persistence-only fixture. No signature, decryptable secret, verifier, or runtime authority.
 * All rows are rolled back by the isolated PostgreSQL test transaction.
 *
 * @param  array<string, mixed>  $snapshotOverrides
 * @return array{credential: string, connection: string, attempt: string}
 */
function verificationMigrationActiveFixture(array $snapshotOverrides = [], array $finalOverrides = [], ?string $uppercaseSnapshot = null, ?int $missingAllocation = null, array $allocationOverrides = []): array
{
    $budgets = verificationMigrationBudgets();
    $user = User::factory()->withWorkspace()->create();
    $entity = LegalEntity::factory()->create(['workspace_id' => $user->current_workspace_id]);
    $workspace = $user->currentWorkspace;
    if ($uppercaseSnapshot !== null) {
        $stored = match ($uppercaseSnapshot) {
            'workspace_public_id' => $workspace->public_id,
            'legal_entity_public_id' => $entity->public_id,
        };
        expect($stored)->toMatch('/\\A[0-7][0-9a-hjkmnp-tv-z]{25}\\z/');
        $snapshotOverrides[$uppercaseSnapshot] = strtoupper($stored);
        expect($snapshotOverrides[$uppercaseSnapshot])->not->toBe($stored);
    }
    $profile = TenantAiCatalogue::profile();
    $profile['id'] = (string) Str::uuid();
    $profile['profile_key'] = 'schema-fixture-'.Str::uuid();
    $profile['manifest_sha256'] = hash('sha256', $profile['profile_key']);
    $profile['allows_customer'] = true;
    DB::table('ai_model_profiles')->insert($profile);
    $identity = ['creator_attribution_id' => $user->attribution_id, 'creator_user_id' => $user->id,
        'created_at' => '2026-10-09 11:00:00+00', 'updated_at' => '2026-10-09 11:00:00+00'];
    $context = ['workspace_id' => $workspace->id, 'deployment_id' => $budgets['deployment']];
    DB::table('tenant_ai_settings')->insert([...$context, ...$identity,
        'root_control_id' => DB::table('ai_gateway_controls')->where('deployment_id', $budgets['deployment'])->value('id')]);
    $connection = (string) Str::uuid();
    DB::table('tenant_ai_connections')->insert([...$context, ...$identity, 'id' => $connection,
        'binding_profile_id' => $profile['id'], 'provider_key' => $profile['provider_key'],
        'credential_family' => $profile['credential_family'], 'endpoint_policy_key' => $profile['endpoint_policy_key']]);
    $credential = (string) Str::uuid();
    DB::table('tenant_ai_credentials')->insert([...$context, ...$identity, 'id' => $credential,
        'connection_id' => $connection, 'version_number' => 1,
        'secret_ciphertext' => 'schema-fixture-not-a-secret', 'wrapped_dek' => 'schema-fixture-not-a-key', 'kek_version' => 'fixture']);
    $account = (string) Str::uuid();
    $aggregate = (string) Str::uuid();
    foreach ([$account => 'account', $aggregate => 'aggregate'] as $budget => $role) {
        DB::table('assistant_provider_controls')->insert([...$context, 'budget_id' => $budget,
            'profile' => $profile['profile_key'], 'policy' => 'p7-question-v1', 'contract_version' => 'gateway_v1',
            'ownership_kind' => 'customer_managed', 'account_role' => $role,
            'provider_key' => $role === 'account' ? 'anthropic' : null,
            'account_reference' => $role === 'account' ? (string) Str::uuid() : null]);
    }
    DB::table('tenant_ai_connections')->where('id', $connection)->update(['payer_budget_id' => $account, 'revision' => 2]);
    $disclosure = (string) Str::uuid();
    $grant = DB::table('assistant_provider_tenants')->insertGetId([...$context,
        'contract_version' => 'gateway_v1', 'legal_entity_id' => $entity->id, 'environment' => 'production',
        'profile_id' => $profile['id'], 'ownership_kind' => 'customer_managed', 'account_budget_id' => $account,
        'connection_id' => $connection, 'selection_revision' => 1, 'disclosure_id' => $disclosure,
        'entitlement_revision' => 1, 'purpose' => 'connection_probe', 'verifier_policy_sha256' => str_repeat('a', 64),
        'account_mapping_sha256' => str_repeat('b', 64), 'policy' => 'p7-question-v1', 'profile' => $profile['profile_key'],
        'owner_attribution_id' => $user->attribution_id, 'approval_reference' => 'schema_fixture', 'expires_at' => '2026-10-10 12:00:00+00']);
    $ack = DB::table('assistant_provider_acknowledgements')->insertGetId([
        'contract_version' => 'gateway_v1', 'disclosure_id' => $disclosure, 'actor_attribution_id' => $user->attribution_id,
        'workspace_id' => $workspace->id, 'policy' => 'p7-question-v1', 'acknowledged_at' => '2026-10-09 11:00:00+00']);
    $attempt = (string) Str::uuid();
    DB::table('assistant_provider_attempts')->insert([...$context, 'id' => $attempt,
        'interaction_id' => (string) Str::uuid(), 'budget_id' => $account, 'actor_attribution_id' => $user->attribution_id,
        'legal_entity_id' => $entity->id, 'environment' => 'production', 'profile' => $profile['profile_key'],
        'price_profile' => $profile['price_key'], 'policy' => 'p7-question-v1', 'day_start' => '2026-10-09 00:00:00+00',
        'month_start' => '2026-10-01 00:00:00+00', 'reserved_micro_usd' => $profile['reservation_micro_usd'],
        'estimated_tokens' => null, 'request_bytes' => 0, 'admitted_at' => '2026-10-09 12:00:00+00',
        'contract_version' => 'gateway_v1', 'provider_key' => 'anthropic', 'ownership_kind' => 'customer_managed',
        'profile_id' => $profile['id'], 'selection_revision' => 1, 'connection_id' => $connection, 'credential_version_id' => $credential,
        'endpoint_policy_key' => $profile['endpoint_policy_key'], 'disclosure_id' => $disclosure, 'entitlement_revision' => 1,
        'purpose' => 'connection_probe', 'monetary_applicable' => true, 'reserved_output_units' => $profile['output_envelope'],
        'usage_budget_id' => $budgets['shared'], 'aggregate_budget_id' => $aggregate, 'owner_approval_id' => $grant,
        'acknowledgement_id' => $ack, 'workspace_public_id' => $workspace->public_id, 'legal_entity_public_id' => $entity->public_id,
        'credential_version_number' => 1, 'credential_created_at' => $identity['created_at'], 'credential_wrap_revision' => 1,
        'expected_settings_revision' => 1, 'expected_connection_revision' => 2, 'prior_active_generation' => 0,
        'proposed_activated_generation' => 1, 'actor_membership_id' => 1, 'profile_manifest_sha256' => $profile['manifest_sha256'],
        'credential_family' => $profile['credential_family'], 'verifier_policy_key' => 'schema-fixture',
        'verifier_policy_sha256' => str_repeat('a', 64), 'account_mapping_sha256' => str_repeat('b', 64),
        'authority_references' => json_encode([['budget_control', $account, '1']], JSON_THROW_ON_ERROR),
        'acknowledged_at_snapshot' => '2026-10-09 11:00:00+00', 'evidence_realm' => 'offline_fixture', 'verification_outcome' => 'not_observed', ...$snapshotOverrides]);
    $allocationOrdinal = 0;
    foreach ([$budgets['shared'] => 'shared_usage', $aggregate => 'customer_aggregate', $account => 'customer_account'] as $budget => $role) {
        $scopes = $role === 'shared_usage' ? ['deployment_month', 'deployment_day', 'workspace_month', 'workspace_day', 'user_day'] : ['workspace_month', 'workspace_day'];
        foreach ($scopes as $scope) {
            $month = str_ends_with($scope, 'month');
            $units = ['reserved_micro_usd' => $role === 'shared_usage' ? 0 : $profile['reservation_micro_usd'],
                'reserved_attempt_units' => $role === 'shared_usage' ? 1 : 0,
                'reserved_output_units' => $role === 'shared_usage' ? $profile['output_envelope'] : 0];
            $window = DB::table('assistant_provider_windows')->insertGetId(['budget_id' => $budget, 'scope' => $scope,
                'scope_key' => str_starts_with($scope, 'deployment') ? 'deployment' : ($scope === 'user_day' ? $user->attribution_id : (string) $workspace->id),
                'window_start' => $month ? '2026-10-01 00:00:00+00' : '2026-10-09 00:00:00+00',
                'window_end' => $month ? '2026-11-01 00:00:00+00' : '2026-10-10 00:00:00+00', ...$units]);
            if ($allocationOrdinal++ !== $missingAllocation) {
                DB::table('assistant_provider_allocations')->insert(['attempt_id' => $attempt, 'window_id' => $window,
                    'role' => $role, ...$units, 'created_at' => '2026-10-09 12:00:00+00', ...($allocationOrdinal === 1 ? $allocationOverrides : [])]);
            }
        }
    }
    DB::table('assistant_provider_attempts')->where('id', $attempt)->update([
        'state' => 'usage_unknown', 'outcome' => 'usage_unknown', 'finalized_at' => '2026-10-09 12:00:02+00',
        'send_authorized_at' => '2026-10-09 12:00:00+00', 'observed_at' => '2026-10-09 12:00:01+00',
        'verification_outcome' => 'provider_authenticated_model_visible', 'claim_strength' => 'organization_and_workspace_observed',
        'observed_organization_id' => (string) Str::uuid(), 'observed_workspace_id' => 'wrkspc_fixture',
        'observed_model_id' => $profile['model_key'], 'evidence_id' => (string) Str::uuid(), 'receipt_key_id' => 'fixture',
        'receipt_mac' => str_repeat('0', 64), 'promotion_expires_at' => '2026-10-09 12:00:05+00',
        'promotion_disposition' => 'promoted', 'committed_activated_generation' => 1, ...$finalOverrides]);
    DB::table('tenant_ai_credentials')->where('id', $credential)->update([
        'state' => 'active', 'verification_state' => 'verified', 'activated_generation' => 1,
        'verified_profile_id' => $profile['id'], 'verification_operation_id' => $attempt,
        'last_test_operation_id' => $attempt, 'last_verified_at' => '2026-10-09 12:00:01+00',
        'last_tested_at' => '2026-10-09 12:00:01+00', 'last_test_outcome' => 'success']);
    DB::table('tenant_ai_connections')->where('id', $connection)->update(['active_generation' => 1, 'revision' => 3]);
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

    return compact('credential', 'connection', 'attempt');
}

/** @param array{credential: string, connection: string, attempt: string} $fixture */
function verificationMigrationRotate(array $fixture, bool $selectCandidate = false): string
{
    DB::statement('SET CONSTRAINTS ALL DEFERRED');
    $old = (array) DB::table('tenant_ai_credentials')->find($fixture['credential']);
    $candidate = $old;
    $candidate['id'] = (string) Str::uuid();
    $candidate['version_number']++;
    $candidate['state'] = 'pending';
    $candidate['verification_state'] = 'unverified';
    foreach (['activated_generation', 'verified_profile_id', 'verification_operation_id', 'last_verified_at',
        'last_test_operation_id', 'last_tested_at', 'last_test_outcome'] as $field) {
        $candidate[$field] = null;
    }
    DB::table('tenant_ai_credentials')->insert($candidate);
    $attempt = (array) DB::table('assistant_provider_attempts')->find($fixture['attempt']);
    $attempt['id'] = (string) Str::uuid();
    $attempt['interaction_id'] = (string) Str::uuid();
    $attempt['credential_version_id'] = $candidate['id'];
    $attempt['credential_version_number'] = $candidate['version_number'];
    $attempt['expected_connection_revision'] = 3;
    $attempt['prior_active_generation'] = 1;
    $attempt['proposed_activated_generation'] = 2;
    $attempt['state'] = 'admitted';
    $attempt['verification_outcome'] = 'not_observed';
    foreach (['outcome', 'finalized_at', 'send_authorized_at', 'observed_at', 'claim_strength',
        'observed_organization_id', 'observed_workspace_id', 'observed_model_id', 'evidence_id',
        'receipt_key_id', 'receipt_mac', 'promotion_expires_at', 'promotion_disposition', 'committed_activated_generation'] as $field) {
        $attempt[$field] = null;
    }
    DB::table('assistant_provider_attempts')->insert($attempt);
    foreach (DB::table('assistant_provider_allocations')->where('attempt_id', $fixture['attempt'])->get() as $allocation) {
        DB::table('assistant_provider_allocations')->insert([...((array) $allocation), 'attempt_id' => $attempt['id']]);
        DB::table('assistant_provider_windows')->where('id', $allocation->window_id)->incrementEach([
            'reserved_micro_usd' => $allocation->reserved_micro_usd,
            'reserved_attempt_units' => $allocation->reserved_attempt_units,
            'reserved_output_units' => $allocation->reserved_output_units,
        ]);
    }
    $originalAttempt = (array) DB::table('assistant_provider_attempts')->find($fixture['attempt']);
    $final = [];
    foreach (['state', 'outcome', 'finalized_at', 'send_authorized_at', 'observed_at', 'verification_outcome',
        'claim_strength', 'observed_organization_id', 'observed_workspace_id', 'observed_model_id',
        'receipt_key_id', 'receipt_mac', 'promotion_expires_at', 'promotion_disposition'] as $field) {
        $final[$field] = $originalAttempt[$field];
    }
    DB::table('assistant_provider_attempts')->where('id', $attempt['id'])->update([
        ...$final, 'evidence_id' => (string) Str::uuid(), 'committed_activated_generation' => 2,
    ]);
    DB::table('tenant_ai_credentials')->where('id', $fixture['credential'])->update([
        'state' => 'replaced', 'replaced_at' => '2026-10-09 12:00:03+00', 'revoked_at' => '2026-10-09 12:00:03+00',
        'secret_destroyed_at' => '2026-10-09 12:00:03+00', 'secret_ciphertext' => null, 'wrapped_dek' => null, 'kek_version' => null,
    ]);
    $verification = [];
    foreach (['verified_profile_id', 'last_verified_at', 'last_tested_at', 'last_test_outcome'] as $field) {
        $verification[$field] = $old[$field];
    }
    DB::table('tenant_ai_credentials')->where('id', $candidate['id'])->update([
        ...$verification, 'state' => 'active', 'verification_state' => 'verified', 'activated_generation' => 2,
        'verification_operation_id' => $attempt['id'], 'last_test_operation_id' => $attempt['id'],
    ]);
    DB::table('tenant_ai_connections')->where('id', $fixture['connection'])->update(['active_generation' => 2, 'revision' => 4]);
    if ($selectCandidate) {
        DB::table('tenant_ai_settings')->where('workspace_id', $old['workspace_id'])->update([
            'credential_version_id' => $candidate['id'], 'revision' => 3,
        ]);
    }
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

    return $candidate['id'];
}
