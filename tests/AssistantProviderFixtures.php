<?php

use App\Fiscal\AssistantExecutionGuard;
use App\Fiscal\AssistantInput;
use App\Fiscal\AssistantInteractionContext;
use App\Fiscal\AssistantProviderInvocation;
use App\Fiscal\AssistantProviderProfile;
use App\Fiscal\AssistantProviderResponseBuffer;
use App\Fiscal\AssistantProviderTransport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

require_once __DIR__.'/AssistantFixtures.php';

function providerEnvelope(string $plan = '{"decision":"unsupported","reason":"outside_read_contract"}'): string
{
    return json_encode(['id' => 'synthetic-message', 'type' => 'message', 'role' => 'assistant', 'model' => AssistantProviderProfile::MODEL,
        'content' => [['type' => 'text', 'text' => $plan]], 'stop_reason' => 'end_turn', 'stop_sequence' => null,
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'service_tier' => 'standard', 'inference_geo' => 'us']], JSON_THROW_ON_ERROR);
}

class SyntheticIntentTransport extends AssistantProviderTransport
{
    public array $bodies = [];

    public ?Closure $during = null;

    public string $response;

    public function __construct()
    {
        $this->response = providerEnvelope();
    }

    protected function wire(#[SensitiveParameter] string $body, #[SensitiveParameter] string $key,
        float $deadline, AssistantProviderResponseBuffer $buffer, Closure $fresh): void
    {
        expect($key)->toBe('synthetic-not-a-real-provider-key');
        expect(DB::connection()->transactionLevel())->toBe(0);
        expect(DB::table('assistant_provider_attempts')->where('state', 'admitted')->exists())->toBeTrue();
        expect(DB::table('activity_log')->where('event', 'assistant.provider.attempted')->exists())->toBeTrue();
        $fresh();
        $this->bodies[] = $body;
        if ($this->during !== null) {
            ($this->during)();
        }
        foreach (["HTTP/1.1 200 OK\r\n", "Content-Type: application/json\r\n", "\r\n"] as $line) {
            abort_unless($buffer->header($line) === strlen($line), 503);
        }
        foreach (str_split($this->response, 257) as $chunk) {
            abort_unless($buffer->chunk($chunk) === strlen($chunk), 503);
        }
    }
}

/** Synthetic approvals only; no live account or privacy approval is asserted. */
function providerApprovals(array $fixture, string $secretPath, bool $acknowledge = true): void
{
    $budget = (string) Str::uuid();
    config(['assistant.provider.enabled' => true, 'assistant.provider.egress_enabled' => true,
        'assistant.provider.budget_id' => $budget, 'assistant.provider.approval_reference' => 'synthetic-approval',
        'assistant.provider.secret_reference' => $secretPath, 'assistant.provider.notice_version' => AssistantProviderProfile::POLICY,
        'assistant.provider.notice_url' => '/privacidade',
        'assistant.provider.budgets' => AssistantProviderProfile::BUDGET_CEILINGS]);
    DB::table('assistant_provider_controls')->insert(['budget_id' => $budget, 'enabled' => true, 'circuit_blocked' => false,
        'profile' => AssistantProviderProfile::ID, 'policy' => AssistantProviderProfile::POLICY,
        'approval_reference' => 'synthetic-approval', 'approval_expires_at' => now()->addDay()]);
    if (DB::getDriverName() === 'pgsql') {
        $deployment = (string) Str::uuid();
        $shared = (string) Str::uuid();
        DB::table('ai_gateway_controls')->insert(['id' => (string) Str::uuid(), 'deployment_id' => $deployment, 'kind' => 'global',
            'subject_key' => 'root', 'circuit_blocked' => false, 'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String()]);
        DB::table('assistant_provider_controls')->insert(['budget_id' => $shared, 'contract_version' => 'gateway_v1',
            'deployment_id' => $deployment, 'ownership_kind' => 'shared_usage', 'account_role' => 'usage', 'enabled' => true, 'circuit_blocked' => false,
            'profile' => AssistantProviderProfile::ID, 'policy' => AssistantProviderProfile::POLICY,
            'approval_reference' => 'synthetic-shared-approval', 'approval_expires_at' => CarbonImmutable::parse(DB::selectOne('SELECT clock_timestamp() AS instant')->instant)->addDay()->toIso8601String()]);
        $limits = [];
        foreach (['deployment_month', 'deployment_day', 'workspace_month', 'workspace_day', 'user_day'] as $scope) {
            $limits[$scope] = ['reserved_attempt_units' => 100000, 'reserved_output_units' => 1000000000];
        }
        config(['tenant_ai.legacy_accounting.'.$budget => ['deployment_id' => $deployment, 'usage_budget_id' => $shared,
            'reconciled' => true, 'limits' => $limits]]);
    }
    DB::table('assistant_provider_tenants')->insert(['workspace_id' => $fixture['entity']->workspace_id, 'policy' => AssistantProviderProfile::POLICY,
        'profile' => AssistantProviderProfile::ID, 'owner_attribution_id' => $fixture['user']->attribution_id,
        'approval_reference' => 'synthetic-tenant-approval', 'expires_at' => now()->addDay()]);
    if ($acknowledge) {
        DB::table('assistant_provider_acknowledgements')->insert(['actor_attribution_id' => $fixture['user']->attribution_id,
            'workspace_id' => $fixture['entity']->workspace_id, 'policy' => AssistantProviderProfile::POLICY, 'acknowledged_at' => now()]);
    }
}

function providerTestInvocation(array $f): AssistantProviderInvocation
{
    $request = Request::create($f['url'], 'POST');
    $request->setUserResolver(fn () => User::findOrFail($f['user']->id));
    $request->setRouteResolver(fn () => Route::getRoutes()->match($request));
    $session = app('session')->driver();
    $session->start();
    $session->put('work_session_started_at', now()->getTimestamp());
    $request->setLaravelSession($session);
    app()->instance('request', $request);
    app('auth')->guard('web')->setUser($f['user']);
    $request->setUserResolver(fn () => User::findOrFail($f['user']->id));
    $context = AssistantInteractionContext::resolve($request);
    $guard = new AssistantExecutionGuard;
    $request->attributes->set('assistant_context', $context);
    $request->attributes->set('assistant_guard', $guard);

    return AssistantProviderInvocation::forInteraction($context, AssistantInput::fromJson(json_encode(assistantPayload($f))), $guard);
}

/** Update only the legacy control, retaining the successor schema revision and root fence. */
function providerLegacyControlUpdate(array $changes): void
{
    DB::transaction(function () use ($changes): void {
        $budget = config('assistant.provider.budget_id');
        if (DB::getDriverName() === 'pgsql') {
            $mapping = config('tenant_ai.legacy_accounting.'.$budget);
            DB::table('ai_gateway_controls')->where('deployment_id', $mapping['deployment_id'])
                ->where('kind', 'global')->lockForUpdate()->firstOrFail();
        }
        $control = DB::table('assistant_provider_controls')->where('budget_id', $budget)->lockForUpdate()->firstOrFail();
        if (DB::getDriverName() === 'pgsql') {
            $changes['revision'] = $control->revision + 1;
        }
        DB::table('assistant_provider_controls')->where('budget_id', $budget)->update($changes);
    });
}
