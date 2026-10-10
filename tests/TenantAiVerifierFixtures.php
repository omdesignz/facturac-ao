<?php

use App\Fiscal\TenantAiActiveLifecycle;
use App\Fiscal\TenantAiContext;
use App\Fiscal\TenantAiCredentialVerifier;
use App\Fiscal\TenantAiVerificationContext;
use App\Fiscal\TenantAiVerificationRequest;
use App\Fiscal\TenantAiVerificationResponseBuffer;
use App\Fiscal\TenantAiVerificationTransport;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/TenantAiVerificationQuotaFixtures.php';

/** Synthetic grants only. Positive authority is created through the real verifier, never raw receipts. */
function verifierFixture(object $test, ?User $owner = null): array
{
    if (PHP_SAPI !== 'cli' || ! $test instanceof TestCase || ! app()->runningUnitTests()
        || DB::connection()->getDatabaseName() !== 'facturac_test_verification_cr1_runtime') {
        throw new LogicException('Dev-only verification fixture');
    }
    $user = $owner ?? User::factory()->create(['two_factor_secret' => 'synthetic', 'two_factor_confirmed_at' => now()]);
    $fixture = DB::transaction(fn () => verificationQuotaFixture($user, null, false));
    $row = $fixture['row'];
    $root = storage_path('framework/testing/verification-'.Str::uuid());
    mkdir($root, 0700, true);
    foreach (['keks', 'receipts'] as $directory) {
        mkdir($root.'/'.$directory, 0700);
        file_put_contents($root.'/'.$directory.'/key', random_bytes(32));
        chmod($root.'/'.$directory.'/key', 0600);
    }
    $test->verificationRoots[] = $root;
    $manifest = ['profile_manifest_sha256' => $row['profile_manifest_sha256'], 'model' => 'claude-haiku-5-5',
        'endpoint_policy_key' => $row['endpoint_policy_key'], 'reservation_micro_usd' => $row['reserved_micro_usd'],
        'output_envelope' => $row['reserved_output_units'], 'request_cost_ceiling_micro_usd' => 100,
        'cost_approval_reference' => 'offline-only-no-real-price-claim', 'verifier_policy_key' => 'anthropic-model-metadata-v1',
        'verifier_policy_sha256' => $row['verifier_policy_sha256']];
    $account = ['deployment_id' => $row['deployment_id'], 'workspace_public_id' => $row['workspace_public_id'],
        'legal_entity_public_id' => $row['legal_entity_public_id'], 'connection_id' => $fixture['connection'], 'profile_id' => $row['profile_id'],
        'purpose' => 'connection_probe', 'egress_approval_reference' => 'offline-only', 'expires_at' => now()->addDay()->toIso8601String(),
        'organization_id' => (string) Str::uuid(), 'workspace_id' => 'wrkspc_OfflineFixture', 'mapping_sha256' => $row['account_mapping_sha256'],
        'disclosure_id' => $row['disclosure_id'], 'account_reference' => DB::table('assistant_provider_controls')->where('budget_id', $row['budget_id'])->value('account_reference'),
        'aggregate_budget_id' => $row['aggregate_budget_id'], 'usage_budget_id' => $row['usage_budget_id'], 'limits' => []];
    foreach ([$row['budget_id'], $row['aggregate_budget_id'], $row['usage_budget_id']] as $budget) {
        foreach (['deployment_month', 'deployment_day', 'workspace_month', 'workspace_day', 'user_day'] as $scope) {
            $account['limits'][$budget][$scope] = ['reserved_attempt_units' => 10000, 'reserved_output_units' => 10000000, 'reserved_micro_usd' => 1000000000];
        }
        DB::table('assistant_provider_controls')->where('budget_id', $budget)->update(['enabled' => true, 'circuit_blocked' => false,
            'approval_reference' => 'offline-only', 'approval_expires_at' => now()->addDay()->toIso8601String(), 'revision' => 2]);
    }
    DB::table('ai_gateway_controls')->where('deployment_id', $row['deployment_id'])->where('kind', 'global')->update(['circuit_blocked' => false, 'revision' => 2]);
    foreach (['provider', 'model'] as $kind) {
        DB::table('ai_gateway_controls')->insert(['id' => (string) Str::uuid(), 'deployment_id' => $row['deployment_id'], 'kind' => $kind,
            'subject_key' => $kind === 'provider' ? 'anthropic' : $row['profile'], 'profile_id' => $kind === 'provider' ? null : $row['profile_id'],
            'circuit_blocked' => false, 'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String()]);
    }
    config(['tenant_ai.deployment_id' => $row['deployment_id'], 'tenant_ai.kek_root' => $root.'/keks',
        'tenant_ai.kek_files' => ['verification-test' => $root.'/keks/key'], 'tenant_ai.kek_version' => 'verification-test',
        'tenant_ai.verification' => ['enabled' => true, 'anthropic_egress_enabled' => true, 'profiles' => [$row['profile_id'] => $manifest],
            'accounts' => [$row['budget_id'] => $account], 'receipt_key_root' => $root.'/receipts', 'signing_key_id' => 'offline-test',
            'receipt_keys' => ['offline-test' => ['deployment_id' => $row['deployment_id'], 'policy_sha256' => $row['verifier_policy_sha256'],
                'trusted' => true, 'signing' => true, 'path' => $root.'/receipts/key', 'valid_from' => now()->subDay()->toIso8601String(), 'valid_until' => now()->addDay()->toIso8601String()]]]]);
    $session = new Store('verification-test', new ArraySessionHandler(120));
    $session->start();
    $session->put((string) config('work_session.started_at_key'), time());
    $session->put('auth.password_confirmed_at', time());
    $request = Request::create('/test-only-verification', 'POST');
    $request->setLaravelSession($session);
    $request->headers->set('X-CSRF-TOKEN', $session->token());
    $request->setUserResolver(fn () => $user);
    app()->instance('request', $request);
    auth('web')->login($user);
    $owner = TenantAiContext::resolve($request, $row['workspace_public_id']);
    $secret = 'synthetic-verification-key-'.bin2hex(random_bytes(20));
    $candidate = (new TenantAiActiveLifecycle)->candidate($owner, $fixture['connection'], 2, 2, $fixture['credential'], $secret);
    $context = TenantAiVerificationContext::resolve($request, $row['workspace_public_id'], $row['legal_entity_public_id'], 'production');
    $command = new TenantAiVerificationRequest($fixture['connection'], $candidate['id'], 2, 3, null, 0);

    return [...$fixture, ...compact('user', 'request', 'owner', 'context', 'command', 'secret', 'account', 'manifest', 'root')];
}

/** The only substitute is raw wire delivery; parser, session, admission, signer and lifecycle stay real. */
function offlineVerifier(array $fixture, ?Closure $wire = null, ?Closure $beforeSend = null): TenantAiCredentialVerifier
{
    if (PHP_SAPI !== 'cli' || ! class_exists(TestCase::class, false) || ! app()->runningUnitTests()) {
        throw new LogicException('Dev-only wire harness');
    }
    $transport = new class($fixture, $wire, $beforeSend) extends TenantAiVerificationTransport
    {
        public function __construct(private array $fixture, private ?Closure $response, private ?Closure $beforeSend) {}

        protected function wire(string $model, string $workspace, #[SensitiveParameter] string $key, float $deadline,
            TenantAiVerificationResponseBuffer $buffer, Closure $ready): void
        {
            expect($key)->toBe($this->fixture['secret'])->and($model)->toBe($this->fixture['manifest']['model'])
                ->and($workspace)->toBe($this->fixture['account']['workspace_id'])->and(DB::transactionLevel())->toBe(0);
            expect(DB::selectOne("SELECT EXISTS(SELECT 1 FROM pg_locks WHERE pid=pg_backend_pid() AND locktype='advisory' AND classid=1180058417) AS held")->held)->toBeFalse();
            if ($this->beforeSend !== null) {
                ($this->beforeSend)($this->fixture);
            }
            $ready();
            if ($this->response !== null) {
                ($this->response)($buffer, $this->fixture);

                return;
            }
            $buffer->header("HTTP/1.1 200 OK\r\n");
            $buffer->header("Content-Type: application/json\r\n");
            $buffer->header('anthropic-organization-id: '.$this->fixture['account']['organization_id']."\r\n");
            $buffer->header('anthropic-workspace-id: '.$workspace."\r\n");
            $buffer->header("\r\n");
            $buffer->chunk(json_encode(['type' => 'model', 'id' => $model, 'description' => 'untrusted stored instruction must not escape']));
        }
    };
    $verifier = new TenantAiCredentialVerifier;
    (new ReflectionProperty($verifier, 'transport'))->setValue($verifier, $transport);
    (new ReflectionProperty($verifier, 'evidenceRealm'))->setValue($verifier, 'offline_fixture');

    return $verifier;
}
