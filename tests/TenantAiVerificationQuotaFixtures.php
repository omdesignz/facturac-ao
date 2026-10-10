<?php

use App\Exceptions\TenantAiStorageUnavailable;
use App\Fiscal\TenantAiCatalogue;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/AiVerificationMigrationFixtures.php';

/** Structural pending-admission fixture only; never a provider verification/active fixture.
 * @return array<string, mixed>
 */
function verificationQuotaFixture(User $user, ?array $existingBudgets = null, bool $withWindows = true, string $provider = 'anthropic'): array
{
    $budgets = $existingBudgets ?? verificationMigrationBudgets();
    $workspace = Workspace::factory()->create(['created_by_user_id' => $user->id]);
    $membership = WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'owner', 'is_active' => true]);
    $entity = LegalEntity::factory()->create(['workspace_id' => $workspace->id]);
    $profile = TenantAiCatalogue::profile();
    $profile['id'] = (string) Str::uuid();
    $profile['profile_key'] = 'schema-fixture-'.Str::uuid();
    $profile['manifest_sha256'] = hash('sha256', $profile['profile_key']);
    $profile['allows_customer'] = true;
    $fixtureClock = CarbonImmutable::parse(DB::selectOne('SELECT clock_timestamp() AS instant')->instant);
    $profile['valid_from'] = $fixtureClock->subDay()->toIso8601String();
    $profile['valid_until'] = $fixtureClock->addDay()->toIso8601String();
    if ($provider !== 'anthropic') {
        $profile['provider_key'] = $provider;
        $profile['credential_family'] = 'offline-metadata-only';
        $profile['endpoint_policy_key'] = 'offline-metadata-only';
        $profile['model_key'] = $profile['response_model_key'] = 'offline-metadata-only';
    }
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
            'provider_key' => $role === 'account' ? $profile['provider_key'] : null,
            'account_reference' => $role === 'account' ? (string) Str::uuid() : null]);
    }
    DB::table('tenant_ai_connections')->where('id', $connection)->update(['payer_budget_id' => $account, 'revision' => 2, 'disabled' => $withWindows]);
    if (! $withWindows) {
        DB::table('tenant_ai_settings')->where('workspace_id', $workspace->id)->update(['mode' => 'customer_managed',
            'profile_id' => $profile['id'], 'connection_id' => $connection, 'revision' => 2, 'entitlement_revision' => 1,
            'attempts_day_cap' => 100, 'attempts_month_cap' => 1000, 'output_day_cap' => 100000, 'output_month_cap' => 1000000,
            'money_day_micro_usd_cap' => 100000000, 'money_month_micro_usd_cap' => 1000000000]);
    }
    $disclosure = (string) Str::uuid();
    $grant = DB::table('assistant_provider_tenants')->insertGetId([...$context,
        'contract_version' => 'gateway_v1', 'legal_entity_id' => $entity->id, 'environment' => 'production',
        'profile_id' => $profile['id'], 'ownership_kind' => 'customer_managed', 'account_budget_id' => $account,
        'connection_id' => $connection, 'selection_revision' => $withWindows ? 1 : 2, 'disclosure_id' => $disclosure,
        'entitlement_revision' => 1, 'purpose' => 'connection_probe', 'verifier_policy_sha256' => str_repeat('a', 64),
        'account_mapping_sha256' => str_repeat('b', 64), 'policy' => 'p7-question-v1', 'profile' => $profile['profile_key'],
        'owner_attribution_id' => $user->attribution_id, 'approval_reference' => 'schema_fixture', 'expires_at' => $fixtureClock->addDay()->toIso8601String()]);
    $ack = DB::table('assistant_provider_acknowledgements')->insertGetId([
        'contract_version' => 'gateway_v1', 'disclosure_id' => $disclosure, 'actor_attribution_id' => $user->attribution_id,
        'workspace_id' => $workspace->id, 'policy' => 'p7-question-v1', 'acknowledged_at' => '2026-10-09 11:00:00+00']);
    $attempt = (string) Str::uuid();
    $row = [...$context, 'id' => $attempt,
        'interaction_id' => (string) Str::uuid(), 'budget_id' => $account, 'actor_attribution_id' => $user->attribution_id,
        'legal_entity_id' => $entity->id, 'environment' => 'production', 'profile' => $profile['profile_key'],
        'price_profile' => $profile['price_key'], 'policy' => 'p7-question-v1', 'day_start' => now()->utc()->startOfDay()->format('Y-m-d H:i:sP'),
        'month_start' => now()->utc()->startOfMonth()->format('Y-m-d H:i:sP'), 'reserved_micro_usd' => $profile['reservation_micro_usd'],
        'estimated_tokens' => null, 'request_bytes' => 0, 'admitted_at' => '2026-10-09 12:00:00+00',
        'contract_version' => 'gateway_v1', 'provider_key' => $profile['provider_key'], 'ownership_kind' => 'customer_managed',
        'profile_id' => $profile['id'], 'selection_revision' => $withWindows ? 1 : 2, 'connection_id' => $connection, 'credential_version_id' => $credential,
        'endpoint_policy_key' => $profile['endpoint_policy_key'], 'disclosure_id' => $disclosure, 'entitlement_revision' => 1,
        'purpose' => 'connection_probe', 'monetary_applicable' => true, 'reserved_output_units' => $profile['output_envelope'],
        'usage_budget_id' => $budgets['shared'], 'aggregate_budget_id' => $aggregate, 'owner_approval_id' => $grant,
        'acknowledgement_id' => $ack, 'workspace_public_id' => $workspace->public_id, 'legal_entity_public_id' => $entity->public_id,
        'credential_version_number' => 1, 'credential_created_at' => $identity['created_at'], 'credential_wrap_revision' => 1,
        'expected_settings_revision' => $withWindows ? 1 : 2, 'expected_connection_revision' => 2, 'prior_active_generation' => 0,
        'proposed_activated_generation' => 1, 'actor_membership_id' => $membership->id, 'profile_manifest_sha256' => $profile['manifest_sha256'],
        'credential_family' => $profile['credential_family'], 'verifier_policy_key' => 'schema-fixture',
        'verifier_policy_sha256' => str_repeat('a', 64), 'account_mapping_sha256' => str_repeat('b', 64),
        'authority_references' => json_encode([['budget_control', $account, '1']], JSON_THROW_ON_ERROR),
        'acknowledged_at_snapshot' => '2026-10-09 11:00:00+00', 'evidence_realm' => 'offline_fixture', 'verification_outcome' => 'not_observed'];
    $allocations = [];
    foreach (($withWindows ? [$budgets['shared'] => 'shared_usage', $aggregate => 'customer_aggregate', $account => 'customer_account'] : []) as $budget => $role) {
        $scopes = $role === 'shared_usage' ? ['deployment_month', 'deployment_day', 'workspace_month', 'workspace_day', 'user_day'] : ['workspace_month', 'workspace_day'];
        foreach ($scopes as $scope) {
            $month = str_ends_with($scope, 'month');
            $units = ['reserved_micro_usd' => $role === 'shared_usage' ? 0 : $profile['reservation_micro_usd'],
                'reserved_attempt_units' => $role === 'shared_usage' ? 1 : 0,
                'reserved_output_units' => $role === 'shared_usage' ? $profile['output_envelope'] : 0];
            $key = ['budget_id' => $budget, 'scope' => $scope,
                'scope_key' => str_starts_with($scope, 'deployment') ? 'deployment' : ($scope === 'user_day' ? $user->attribution_id : (string) $workspace->id),
                'window_start' => str_ends_with($scope, 'month') ? now()->utc()->startOfMonth()->format('Y-m-d H:i:sP') : now()->utc()->startOfDay()->format('Y-m-d H:i:sP')];
            $existing = DB::table('assistant_provider_windows')->where($key)->first();
            if ($existing === null) {
                $window = DB::table('assistant_provider_windows')->insertGetId([...$key,
                    'window_end' => str_ends_with($scope, 'month') ? now()->utc()->startOfMonth()->addMonth()->format('Y-m-d H:i:sP') : now()->utc()->startOfDay()->addDay()->format('Y-m-d H:i:sP'), ...$units]);
            } else {
                $window = $existing->id;
                DB::table('assistant_provider_windows')->where('id', $window)->incrementEach($units);
            }
            $allocations[] = ['attempt_id' => $attempt, 'window_id' => $window,
                'role' => $role, ...$units, 'created_at' => '2026-10-09 12:00:00+00'];
        }
    }

    return compact('credential', 'connection', 'attempt', 'row', 'allocations', 'budgets');
}

function quotaWait(Closure $ready): void
{
    $deadline = hrtime(true) / 1e9 + 10;
    while (! $ready()) {
        if (hrtime(true) / 1e9 > $deadline) {
            throw new RuntimeException('Quota process barrier expired');
        }
        usleep(500);
    }
}

/** Independent PostgreSQL sessions; parent observes the actual advisory wait before releasing winner.
 * @param  Closure(int): void  $operation
 * @return list<array{result: string, state: ?string, pid: int}>
 */
function quotaRace(Closure $operation, string $mode = 'contended'): array
{
    $directory = sys_get_temp_dir().'/quota-cr1-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    DB::purge();
    $children = [];
    try {
        foreach ([0, 1] as $worker) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                DB::purge();
                $backend = (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
                file_put_contents("$directory/backend-$worker", (string) $backend);
                if ($worker === 1) {
                    quotaWait(fn () => file_exists("$directory/held"));
                }
                DB::listen(function ($query) use ($worker, $directory) {
                    if ($worker === 0 && str_contains($query->sql, 'pg_advisory_xact_lock(')) {
                        file_put_contents("$directory/held", 'held');
                        quotaWait(fn () => file_exists("$directory/release"));
                    }
                });
                try {
                    $operation($worker);
                    $result = ['result' => 'committed', 'state' => null, 'pid' => $backend];
                } catch (Throwable $error) {
                    $result = ['result' => $error instanceof TenantAiStorageUnavailable ? 'quota_refused' : 'error',
                        'state' => $error instanceof QueryException ? ($error->errorInfo[0] ?? null) : $error::class, 'pid' => $backend];
                }
                file_put_contents("$directory/result-$worker", json_encode($result, JSON_THROW_ON_ERROR));
                DB::disconnect();
                exit(0);
            }
            if ($pid < 0) {
                throw new RuntimeException('Cannot fork quota worker');
            }
            $children[] = $pid;
        }
        quotaWait(fn () => file_exists("$directory/held") && file_exists("$directory/backend-1"));
        $waiter = (int) file_get_contents("$directory/backend-1");
        if ($mode === 'independent') {
            quotaWait(fn () => file_exists("$directory/result-1"));
        } else {
            quotaWait(fn () => DB::selectOne("SELECT EXISTS(SELECT 1 FROM pg_locks WHERE pid=? AND locktype='advisory' AND NOT granted) AS waiting", [$waiter])->waiting);
            if ($mode === 'timeout') {
                quotaWait(fn () => file_exists("$directory/result-1"));
            }
        }
        file_put_contents("$directory/release", 'release');
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            expect(pcntl_wexitstatus($status))->toBe(0);
        }
        $results = array_map(fn ($worker) => json_decode(file_get_contents("$directory/result-$worker"), true, flags: JSON_THROW_ON_ERROR), [0, 1]);
        expect($results[0]['pid'])->not->toBe($results[1]['pid']);

        return $results;
    } finally {
        foreach ($children as $pid) {
            if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
        }
        foreach (glob("$directory/*") as $file) {
            unlink($file);
        }
        rmdir($directory);
        DB::purge();
    }
}
