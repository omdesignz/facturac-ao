<?php

use App\Fiscal\AiGatewayFailure;
use App\Fiscal\AiInferenceRequest;
use App\Fiscal\AiInferenceResult;
use App\Fiscal\AiModelIdentity;
use App\Fiscal\AnthropicIntentPlanner;
use App\Fiscal\AssistantPlan;
use App\Fiscal\AssistantPlanner;
use App\Fiscal\AssistantProviderInvocation;
use App\Fiscal\AssistantProviderProfile;
use App\Fiscal\AssistantProviderTransport;
use App\Fiscal\GatewayAssistantPlanner;
use App\Fiscal\LegacyAssistantAiGateway;
use App\Fiscal\UnavailableAssistantPlanner;
use App\Fiscal\VapAiGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require_once __DIR__.'/../AssistantProviderFixtures.php';

beforeEach(function () {
    while (DB::connection()->transactionLevel() > 0) {
        DB::rollBack();
    }
    expect(app()->environment())->toBe('testing');
    expect(DB::getDriverName() === 'pgsql' ? str_starts_with(DB::connection()->getDatabaseName(), 'facturac_test_') : DB::connection()->getDatabaseName() === ':memory:')->toBeTrue();
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    $this->travelTo(CarbonImmutable::parse('2026-10-08T12:00:00Z'));
    config(['assistant.enabled' => true]);
    Http::preventStrayRequests();
    Queue::fake();
    Mail::fake();
    Notification::fake();
    $this->secret = tempnam(sys_get_temp_dir(), 'phase7b1-inert-');
    file_put_contents($this->secret, 'synthetic-not-a-real-provider-key');
    chmod($this->secret, 0600);
    $this->transport = new SyntheticIntentTransport;
    app()->instance(AssistantProviderTransport::class, $this->transport);
});

afterEach(function () {
    @unlink($this->secret);
    Activity::flushEventListeners();
    $this->travelBack();
    while (DB::connection()->transactionLevel() > 0) {
        DB::rollBack();
    }
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
});

test('gateway container seam preserves unavailable default and routes enabled planning through the gateway', function () {
    expect(app(VapAiGateway::class))->toBeInstanceOf(LegacyAssistantAiGateway::class)
        ->and(app(GatewayAssistantPlanner::class))->toBeInstanceOf(GatewayAssistantPlanner::class)
        ->and(app(AssistantPlanner::class))->toBeInstanceOf(UnavailableAssistantPlanner::class);
    config(['assistant.provider.enabled' => true]);
    expect(app(AssistantPlanner::class))->toBeInstanceOf(GatewayAssistantPlanner::class);
    expect($this->transport->bodies)->toBe([]);
});

test('gateway caller depends only on the application contract and cannot select provider context or endpoint', function () {
    $caller = new ReflectionClass(GatewayAssistantPlanner::class);
    expect((string) $caller->getConstructor()->getParameters()[0]->getType())->toBe(VapAiGateway::class);
    $method = new ReflectionMethod(VapAiGateway::class, 'infer');
    expect($method->getParameters())->toHaveCount(1)
        ->and((string) $method->getParameters()[0]->getType())->toBe(AiInferenceRequest::class)
        ->and((string) $method->getReturnType())->toBe(AiInferenceResult::class);
    $request = new ReflectionClass(AiInferenceRequest::class);
    expect($request->getConstructor()->getParameters())->toHaveCount(1)
        ->and((string) $request->getConstructor()->getParameters()[0]->getType())->toBe(AssistantProviderInvocation::class);
    expect(fn () => new AiInferenceRequest(['provider' => 'openai', 'model' => 'arbitrary', 'url' => 'http://127.0.0.1', 'context' => []]))->toThrow(TypeError::class);
    foreach ([VapAiGateway::class, AiInferenceRequest::class, AiInferenceResult::class, AiModelIdentity::class,
        AiGatewayFailure::class, GatewayAssistantPlanner::class, LegacyAssistantAiGateway::class] as $class) {
        $source = file_get_contents((new ReflectionClass($class))->getFileName());
        expect($source)->not->toContain('Laravel\\Ai', 'Http::', 'curl_', 'config(', 'getenv(', 'AiManager');
    }
});

test('gateway substitution remains explicit and returned proposals still require local validation', function () {
    $f = assistantFixture();
    $invocation = providerTestInvocation($f);
    $fake = new class implements VapAiGateway
    {
        public function infer(AiInferenceRequest $request): AiInferenceResult
        {
            return AiInferenceResult::proposedPlan(AiModelIdentity::LegacyAnthropicIntent, '{"decision":"read","calls":[{"tool":"issueInvoice","arguments":{}}]}');
        }
    };
    app()->instance(VapAiGateway::class, $fake);
    $plan = app(GatewayAssistantPlanner::class)->plan([], $invocation);
    expect(fn () => AssistantPlan::fromJson($plan, $invocation->input, $invocation->context->execution->permissions))->toThrow(HttpException::class);
    expect($this->transport->bodies)->toBe([]);
});

test('gateway request and untrusted result refuse serialization and redact debugging', function () {
    $f = assistantFixture();
    $request = new AiInferenceRequest(providerTestInvocation($f));
    $result = AiInferenceResult::proposedPlan(AiModelIdentity::LegacyAnthropicIntent, '{"sentinel":"PRIVATE_PLAN_SENTINEL"}');
    foreach ([$request, $result] as $value) {
        expect($value->__debugInfo())->toBe([]);
        foreach ([fn () => serialize($value), fn () => json_encode($value, JSON_THROW_ON_ERROR)] as $serialize) {
            try {
                $serialize();
                $this->fail('Serialization must deny.');
            } catch (LogicException $error) {
                expect($error->getMessage())->not->toContain('PRIVATE_PLAN_SENTINEL', $f['customer']->public_id)
                    ->and($error->getPrevious())->toBeNull();
            }
        }
    }
    expect($result->identity->provider())->toBe('anthropic')
        ->and($result->identity->model())->toBe(AssistantProviderProfile::MODEL)
        ->and($result->identity->profile())->toBe(AssistantProviderProfile::ID);
});

test('gateway classifications preserve known statuses without sensitive exception payloads', function (AiGatewayFailure $failure) {
    $result = AiInferenceResult::failed(AiModelIdentity::LegacyAnthropicIntent, $failure);
    expect($result->failure)->toBe($failure);
    try {
        $result->untrustedPlan();
        $this->fail('Failure cannot return a plan.');
    } catch (HttpExceptionInterface $error) {
        expect($error->getStatusCode())->toBe($failure->value)
            ->and($error->getPrevious())->toBeNull()
            ->and($error->getMessage())->toBe('')
            ->and($error->getHeaders())->toBe($failure === AiGatewayFailure::QuotaExceeded ? ['Retry-After' => '60'] : []);
    }
})->with(AiGatewayFailure::cases());

test('gateway cannot infer without invocation or live accepted admission', function () {
    expect(fn () => app(GatewayAssistantPlanner::class)->plan([]))->toThrow(HttpException::class);
    $f = assistantFixture();
    $result = app(VapAiGateway::class)->infer(new AiInferenceRequest(providerTestInvocation($f)));
    expect($result->failure)->toBe(AiGatewayFailure::Unavailable)
        ->and($this->transport->bodies)->toBe([])
        ->and(DB::table('assistant_provider_attempts')->count())->toBe(0);
});

test('gateway production context and original authority still control direct seam invocation', function () {
    $f = assistantFixture();
    providerApprovals($f, $this->secret);
    $invocation = providerTestInvocation($f);
    DB::table('workspace_memberships')->where('id', $invocation->context->membershipId)->update(['is_active' => false]);
    $result = app(VapAiGateway::class)->infer(new AiInferenceRequest($invocation));
    expect($result->failure)->toBe(AiGatewayFailure::Forbidden)
        ->and($this->transport->bodies)->toBe([])
        ->and(DB::table('assistant_provider_attempts')->count())->toBe(0);
});

test('gateway differential HTTP path preserves bytes facts audit and liability', function (string $scenario, int $status) {
    $f = assistantFixture();
    providerApprovals($f, $this->secret);
    $responses = [];
    foreach ([false, true] as $wrapped) {
        $this->transport->bodies = [];
        $this->transport->during = null;
        $this->transport->response = providerEnvelope($scenario === 'read'
            ? '{"decision":"read","calls":[{"tool":"getCustomer","arguments":{"ref":"r1"}}]}'
            : '{"decision":"unsupported","reason":"outside_read_contract"}');
        // Each path starts with the same synthetic circuit state; retained liability is never reset.
        providerLegacyControlUpdate(['circuit_blocked' => false]);
        if ($scenario === 'malformed') {
            $this->transport->response = '{"content":"SECRET_RESPONSE_SENTINEL"}';
        }
        if ($scenario === 'interrupted') {
            $this->transport->during = fn () => throw new RuntimeException('SECRET_EXCEPTION_SENTINEL');
        }
        if ($scenario === 'quota') {
            config(['assistant.provider.budgets.user_day' => 552815]);
        }
        if ($scenario === 'privacy') {
            DB::table('assistant_provider_acknowledgements')->delete();
        }
        app()->instance(AssistantPlanner::class, app($wrapped ? GatewayAssistantPlanner::class : AnthropicIntentPlanner::class));
        foreach (Route::getRoutes() as $route) {
            $route->flushController();
        }
        $auditStart = DB::table('activity_log')->max('id') ?? 0;
        $attemptIds = DB::table('assistant_provider_attempts')->pluck('id');
        $beforeReserve = DB::table('assistant_provider_windows')->sum('reserved_micro_usd');
        $response = $this->actingAs($f['user'])->postJson($f['url'], assistantPayload($f,
            $scenario === 'original_question' ? ['question' => "\tConsulta limitada"] : []));
        $response->assertStatus($status);
        expect($response->getContent())->not->toContain('SECRET_RESPONSE_SENTINEL', 'SECRET_EXCEPTION_SENTINEL');
        $attempt = DB::table('assistant_provider_attempts')->whereNotIn('id', $attemptIds)->first();
        $responses[] = [
            'bodies' => $this->transport->bodies,
            'outcome' => $response->json('outcome'),
            'facts' => $response->json('results.0.data'),
            'events' => DB::table('activity_log')->where('id', '>', $auditStart)->orderBy('id')->pluck('event')->all(),
            'reserve_delta' => DB::table('assistant_provider_windows')->sum('reserved_micro_usd') - $beforeReserve,
            'attempt' => $attempt === null ? null : [$attempt->state, $attempt->outcome, (int) $attempt->reserved_micro_usd, $attempt->input_tokens, $attempt->output_tokens],
        ];
    }
    expect($responses[1])->toBe($responses[0]);
    if (in_array($scenario, ['quota', 'privacy', 'original_question'], true)) {
        expect($responses[0]['bodies'])->toBe([])->and($responses[0]['reserve_delta'])->toBe(0);
    } else {
        expect($responses[0]['bodies'])->toHaveCount(1)->and($responses[0]['reserve_delta'])->toBe(5 * 552816);
    }
    if ($scenario === 'read') {
        expect($responses[0]['facts']['name'])->toBe('Cliente Consulta');
    }
    Http::assertNothingSent();
    Queue::assertNothingPushed();
})->with([['unsupported', 200], ['read', 200], ['malformed', 503], ['interrupted', 503], ['quota', 429], ['privacy', 503], ['original_question', 503]]);
