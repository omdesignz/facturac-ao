<?php

use App\Fiscal\AssistantIntentGateway;
use App\Fiscal\AssistantProviderLedger;
use App\Fiscal\AssistantProviderPermit;
use App\Fiscal\AssistantProviderProfile;
use App\Fiscal\AssistantProviderResponse;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../AssistantProviderFixtures.php';

/** Synthetic operator settings only; the key file never holds a real provider key. */
function assistantActivationConfig(object $test): string
{
    $budget = (string) Str::uuid();
    $test->assistantKey = tempnam(sys_get_temp_dir(), 'assistant-activation-');
    file_put_contents($test->assistantKey, "synthetic-not-a-real-provider-key\n");
    chmod($test->assistantKey, 0600);
    config(['assistant.enabled' => true, 'assistant.provider.enabled' => true, 'assistant.provider.egress_enabled' => true,
        'assistant.provider.budget_id' => $budget, 'assistant.provider.approval_reference' => 'operator-2026',
        'assistant.provider.secret_reference' => $test->assistantKey, 'assistant.provider.notice_version' => AssistantProviderProfile::POLICY,
        'assistant.provider.notice_url' => '/privacidade', 'assistant.provider.budgets' => AssistantProviderProfile::BUDGET_CEILINGS]);

    return $budget;
}

afterEach(function () {
    if (isset($this->assistantKey)) {
        @unlink($this->assistantKey);
    }
});

test('provider settings stay closed until the environment opens them', function () {
    $defaults = require config_path('assistant.php');
    expect($defaults['enabled'])->toBeFalse()->and($defaults['provider']['enabled'])->toBeFalse()
        ->and($defaults['provider']['egress_enabled'])->toBeFalse()->and($defaults['provider']['secret_reference'])->toBeNull()
        ->and($defaults['provider']['budget_id'])->toBeNull()->and(array_unique(array_values($defaults['provider']['budgets'])))->toBe([0])
        ->and((require config_path('tenant_ai.php'))['legacy_accounting'])->toBe([]);
    $this->artisan('assistant:check')->assertFailed();
    $this->artisan('assistant:provision')->assertFailed();
    expect(DB::table('assistant_provider_controls')->count())->toBe(0);
});

test('provisioning and an owner approval open every gate the ledger checks', function () {
    $f = assistantFixture();
    $budget = assistantActivationConfig($this);
    $this->artisan('assistant:check', ['workspace' => $f['entity']->workspace->public_id])->assertFailed();
    $this->artisan('assistant:provision', ['--days' => 30])->assertSuccessful();
    $this->artisan('assistant:workspace', ['owner' => $f['user']->email])->assertSuccessful();
    $this->artisan('assistant:check', ['workspace' => $f['entity']->workspace->public_id])->assertSuccessful();

    $control = DB::table('assistant_provider_controls')->where('budget_id', $budget)->sole();
    $approval = DB::table('assistant_provider_tenants')->sole();
    expect((bool) $control->enabled)->toBeTrue()->and((bool) $control->circuit_blocked)->toBeFalse()
        ->and($control->approval_reference)->toBe('operator-2026')->and($approval->owner_attribution_id)->toBe($f['user']->attribution_id);

    $invocation = providerTestInvocation($f);
    $ledger = app(AssistantProviderLedger::class);
    expect(fn () => $ledger->gates($invocation))->toThrow(HttpException::class);
    $ledger->acknowledge($invocation->context, true);
    expect($ledger->gates($invocation)->budget_id)->toBe($budget)
        ->and($ledger->notice($invocation->context))->toMatchArray(['available' => true, 'acknowledged' => true, 'notice_url' => '/privacidade']);
});

test('the ceilings allow sixty questions a user a day and refuse any higher setting', function () {
    $questions = fn (string $scope): int => intdiv(AssistantProviderProfile::BUDGET_CEILINGS[$scope], AssistantProviderProfile::reservation());
    expect(array_map($questions, ['user_day', 'workspace_day', 'workspace_month', 'deployment_day', 'deployment_month']))->toBe([60, 300, 5000, 3000, 50000])
        ->and($questions('attempt'))->toBe(1);

    $f = assistantFixture();
    assistantActivationConfig($this);
    $this->artisan('assistant:provision')->assertSuccessful();
    $this->artisan('assistant:workspace', ['owner' => $f['user']->email])->assertSuccessful();
    $ledger = app(AssistantProviderLedger::class);
    $ledger->acknowledge(providerTestInvocation($f)->context, true);
    foreach (range(1, 5) as $question) {
        AssistantProviderPermit::admit(providerTestInvocation($f), app(AssistantIntentGateway::class), $ledger);
    }
    expect(DB::table('assistant_provider_attempts')->where('state', 'admitted')->count())->toBe(5);

    config(['assistant.provider.budgets.user_day' => AssistantProviderProfile::BUDGET_CEILINGS['user_day'] + 1]);
    expect(fn () => AssistantProviderPermit::admit(providerTestInvocation($f), app(AssistantIntentGateway::class), $ledger))->toThrow(HttpException::class);
    $this->artisan('assistant:check')->assertFailed();
    expect(DB::table('assistant_provider_attempts')->count())->toBe(5);
});

test('provisioning renews in place and only clears a tripped circuit when asked', function () {
    $budget = assistantActivationConfig($this);
    $this->artisan('assistant:provision')->assertSuccessful();
    DB::table('assistant_provider_controls')->where('budget_id', $budget)->update(['circuit_blocked' => true, 'outcome' => 'usage_unknown']);
    $this->artisan('assistant:provision')->assertFailed();
    expect((bool) DB::table('assistant_provider_controls')->where('budget_id', $budget)->value('circuit_blocked'))->toBeTrue();
    $this->artisan('assistant:check')->assertFailed();
    $this->artisan('assistant:provision', ['--reset-circuit' => true])->assertSuccessful();
    $control = DB::table('assistant_provider_controls')->where('budget_id', $budget)->sole();
    expect((bool) $control->circuit_blocked)->toBeFalse()->and((bool) $control->enabled)->toBeTrue()->and($control->outcome)->toBeNull()
        ->and(DB::table('assistant_provider_controls')->count())->toBe(1);
});

test('only an active owner can approve a company and the approval can be withdrawn', function () {
    $f = assistantFixture();
    assistantActivationConfig($this);
    $outsider = User::factory()->create();
    $this->artisan('assistant:workspace', ['owner' => $outsider->email, 'workspace' => $f['entity']->workspace->public_id])->assertFailed();
    $this->artisan('assistant:workspace', ['owner' => 'nobody@example.test'])->assertFailed();
    expect(DB::table('assistant_provider_tenants')->count())->toBe(0);

    $this->artisan('assistant:workspace', ['owner' => $f['user']->email])->assertSuccessful();
    $this->artisan('assistant:workspace', ['owner' => $f['user']->email])->assertSuccessful();
    expect(DB::table('assistant_provider_tenants')->whereNull('revoked_at')->count())->toBe(1);
    $this->artisan('assistant:workspace', ['owner' => $f['user']->email, '--revoke' => true])->assertSuccessful();
    expect(DB::table('assistant_provider_tenants')->whereNull('revoked_at')->count())->toBe(0)
        ->and(DB::table('assistant_provider_tenants')->count())->toBe(1);
});

test('the readiness check names a key file that is missing or not private', function () {
    assistantActivationConfig($this);
    $this->artisan('assistant:provision')->assertSuccessful();
    $this->artisan('assistant:check')->assertSuccessful();
    chmod($this->assistantKey, 0644);
    $this->artisan('assistant:check')->expectsOutputToContain('chmod 600')->assertFailed();
    chmod($this->assistantKey, 0600);
    file_put_contents($this->assistantKey, "two lines\nare not a key\n");
    $this->artisan('assistant:check')->assertFailed();
});

test('documented nullable response fields are accepted only while they are null', function (array $change, bool $accepted) {
    $envelope = json_decode(providerEnvelope(), true, flags: JSON_THROW_ON_ERROR);
    $envelope = array_replace_recursive($envelope, $change);
    $parse = fn () => AssistantProviderResponse::fromJson(json_encode($envelope, JSON_THROW_ON_ERROR));
    $accepted ? expect($parse()->usage)->toBe(['input' => 10, 'output' => 5]) : expect($parse)->toThrow(HttpException::class);
})->with([
    'null message fields' => [['container' => null, 'diagnostics' => null, 'stop_details' => null], true],
    'container value' => [['container' => ['id' => 'x']], false],
    'stop details value' => [['stop_details' => ['type' => 'refusal']], false],
    'unknown message field' => [['context_management' => null], false],
    'null citations' => [['content' => [['citations' => null]]], true],
    'citations value' => [['content' => [['citations' => [['type' => 'char_location']]]]], false],
    'null usage details and counters' => [['usage' => ['output_tokens_details' => null, 'cache_creation_input_tokens' => null,
        'cache_read_input_tokens' => null, 'cache_creation' => null, 'server_tool_use' => null]], true],
    'zero thinking tokens' => [['usage' => ['output_tokens_details' => ['thinking_tokens' => 0]]], true],
    'thinking tokens spent' => [['usage' => ['output_tokens_details' => ['thinking_tokens' => 12]]], false],
    'cached tokens' => [['usage' => ['cache_read_input_tokens' => 3]], false],
    'unknown usage field' => [['usage' => ['speed' => 'fast']], false],
]);
