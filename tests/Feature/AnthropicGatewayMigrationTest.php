<?php

use App\Fiscal\AiInferenceRequest;
use App\Fiscal\AiInferenceResult;
use App\Fiscal\AnthropicIntentPlanner;
use App\Fiscal\AssistantPlanner;
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
    $this->secret = tempnam(sys_get_temp_dir(), 'phase7b2-inert-');
    file_put_contents($this->secret, 'synthetic-not-a-real-provider-key');
    chmod($this->secret, 0600);
    $this->transport = new SyntheticIntentTransport;
    app()->instance(AssistantProviderTransport::class, $this->transport);
    $this->gateway = new class(app(LegacyAssistantAiGateway::class)) implements VapAiGateway
    {
        public int $calls = 0;

        public function __construct(private VapAiGateway $delegate) {}

        public function infer(AiInferenceRequest $request): AiInferenceResult
        {
            $this->calls++;

            return $this->delegate->infer($request);
        }
    };
    app()->instance(VapAiGateway::class, $this->gateway);
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

test('migration production binding selects only the gateway while disabled defaults remain closed', function () {
    $defaults = require config_path('assistant.php');
    expect($defaults['provider']['enabled'])->toBeFalse()
        ->and($defaults['provider']['egress_enabled'])->toBeFalse()
        ->and($defaults['provider']['secret_reference'])->toBeNull()
        ->and(array_unique(array_values($defaults['provider']['budgets'])))->toBe([0]);
    expect(app(AssistantPlanner::class))->toBeInstanceOf(UnavailableAssistantPlanner::class);
    config(['assistant.provider.enabled' => true]);
    expect(app(AssistantPlanner::class))->toBeInstanceOf(GatewayAssistantPlanner::class);
    foreach (['Providers/AppServiceProvider.php', 'Fiscal/AssistantInteraction.php', 'Fiscal/GatewayAssistantPlanner.php', 'Fiscal/VapAiGateway.php'] as $file) {
        expect(file_get_contents(app_path($file)))->not->toContain('AnthropicIntentPlanner', 'AnthropicProvider', 'AnthropicGateway');
    }
    expect($this->gateway->calls)->toBe(0)->and($this->transport->bodies)->toBe([]);
});

test('migration production HTTP path is differentially equivalent to the previous binding', function (string $scenario, int $status, bool $sent) {
    $f = assistantFixture();
    $f['customer']->update(['name' => 'STORED_PRIVATE_SENTINEL']);
    providerApprovals($f, $this->secret);
    $question = match ($scenario) {
        'tab' => "\tConsulta limitada",
        'control' => "Consulta\u{200B} limitada",
        'characters' => str_repeat(' ', 2001).'Consulta limitada',
        'bytes' => str_repeat(' ', 8193).'Consulta limitada',
        default => 'Consulta limitada',
    };
    if ($scenario === 'acknowledgement') {
        DB::table('assistant_provider_acknowledgements')->delete();
    }
    if ($scenario === 'disabled') {
        config(['assistant.provider.enabled' => false]);
    }
    if ($scenario === 'egress') {
        config(['assistant.provider.egress_enabled' => false]);
    }
    if ($scenario === 'quota') {
        config(['assistant.provider.budgets.user_day' => 552815]);
    }
    if ($scenario === 'audit_rollback') {
        Activity::saving(function ($activity): void {
            if ($activity->event === 'assistant.provider.attempted') {
                throw new RuntimeException('PRIVATE_AUDIT_SENTINEL');
            }
        });
    }
    $binding = app()->getBindings()[AssistantPlanner::class]['concrete'];
    $observed = [];
    foreach ([false, true] as $migrated) {
        app()->bind(AssistantPlanner::class, $migrated ? $binding : fn ($app) => config('assistant.provider.enabled') === true
            ? $app->make(AnthropicIntentPlanner::class) : new UnavailableAssistantPlanner);
        foreach (Route::getRoutes() as $route) {
            $route->flushController();
        }
        $this->gateway->calls = 0;
        $this->transport->bodies = [];
        providerLegacyControlUpdate(['circuit_blocked' => false]);
        $this->transport->response = providerEnvelope(match ($scenario) {
            'read' => '{"decision":"read","calls":[{"tool":"getCustomer","arguments":{"ref":"r1"}}]}',
            'malicious' => '{"decision":"read","calls":[{"tool":"issueInvoice","arguments":{}}]}',
            'malformed_plan' => '{"decision":',
            default => '{"decision":"unsupported","reason":"outside_read_contract"}',
        });
        if ($scenario === 'malformed_envelope') {
            $this->transport->response = '{"PRIVATE_RESPONSE_SENTINEL":true}';
        }
        $this->transport->during = $scenario === 'transport_failure'
            ? fn () => throw new RuntimeException('PRIVATE_TRANSPORT_SENTINEL') : null;
        $auditStart = DB::table('activity_log')->max('id') ?? 0;
        $attemptIds = DB::table('assistant_provider_attempts')->pluck('id');
        $reserved = DB::table('assistant_provider_windows')->sum('reserved_micro_usd');
        $payload = assistantPayload($f, ['question' => $question]);
        if ($scenario === 'context_bag') {
            $payload['context'] = ['answer' => 'PRIVATE_CONTEXT_SENTINEL'];
        }
        if ($scenario === 'anonymous') {
            $response = $this->postJson($f['url'], $payload);
        } else {
            $response = $this->actingAs($f['user'])->postJson($f['url'], $payload);
        }
        $response->assertStatus($status);
        expect($this->gateway->calls)->toBe($migrated && ! in_array($scenario, ['disabled', 'anonymous', 'context_bag'], true) ? 1 : 0);
        expect($response->getContent())->not->toContain('PRIVATE_RESPONSE_SENTINEL', 'PRIVATE_TRANSPORT_SENTINEL', 'PRIVATE_AUDIT_SENTINEL', 'PRIVATE_CONTEXT_SENTINEL');
        $attempt = DB::table('assistant_provider_attempts')->whereNotIn('id', $attemptIds)->first();
        $events = DB::table('activity_log')->where('id', '>', $auditStart)->orderBy('id')->pluck('event')->all();
        $observed[] = [
            'status' => $response->status(), 'outcome' => $response->json('outcome'),
            'facts' => $response->json('results.0.data'), 'events' => $events, 'bodies' => $this->transport->bodies,
            'reserve' => DB::table('assistant_provider_windows')->sum('reserved_micro_usd') - $reserved,
            'attempt' => $attempt === null ? null : [$attempt->state, $attempt->outcome, (int) $attempt->reserved_micro_usd, $attempt->input_tokens, $attempt->output_tokens],
        ];
        expect($this->transport->bodies)->toHaveCount($sent ? 1 : 0);
        if ($sent) {
            expect($this->transport->bodies[0])->not->toContain('STORED_PRIVATE_SENTINEL', $f['customer']->public_id, $f['document']->public_id, $f['user']->attribution_id, 'workspace_id', 'PRIVATE_CONTEXT_SENTINEL');
        } else {
            expect($attempt)->toBeNull()->and($events)->not->toContain('assistant.provider.attempted', 'assistant.provider.received', 'assistant.tool.requested');
        }
        if ($scenario !== 'read') {
            expect($events)->not->toContain('assistant.tool.requested');
        }
    }
    expect($observed[1])->toBe($observed[0]);
    expect($observed[1]['reserve'])->toBe($sent ? 5 * 552816 : 0);
    if ($scenario === 'read') {
        expect($observed[1]['facts']['name'])->toBe('STORED_PRIVATE_SENTINEL');
    }
    Http::assertNothingSent();
    Queue::assertNothingPushed();
})->with([
    ['read', 200, true], ['unsupported', 200, true], ['tab', 503, false], ['control', 503, false],
    ['characters', 503, false], ['bytes', 503, false], ['acknowledgement', 503, false],
    ['disabled', 503, false], ['egress', 503, false], ['anonymous', 401, false], ['context_bag', 422, false],
    ['quota', 429, false], ['audit_rollback', 503, false], ['malicious', 503, true],
    ['malformed_plan', 503, true], ['malformed_envelope', 503, true], ['transport_failure', 503, true],
]);

test('migration HTTP replay cannot invoke the gateway or reserve twice', function () {
    $f = assistantFixture();
    providerApprovals($f, $this->secret);
    $payload = assistantPayload($f);
    $this->actingAs($f['user'])->postJson($f['url'], $payload)->assertOk();
    $this->postJson($f['url'], $payload)->assertStatus(409);
    expect($this->gateway->calls)->toBe(1)->and($this->transport->bodies)->toHaveCount(1)
        ->and(DB::table('assistant_provider_attempts')->count())->toBe(1)
        ->and((int) DB::table('assistant_provider_windows')->sum('reserved_micro_usd'))->toBe(5 * 552816);
});
