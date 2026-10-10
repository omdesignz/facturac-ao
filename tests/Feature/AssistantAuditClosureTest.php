<?php

use App\Exceptions\AiExecutionDisabled;
use App\Fiscal\AssistantAudit;
use App\Fiscal\AssistantPlanner;
use App\Fiscal\UnavailableAssistantPlanner;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\AiManager;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../AssistantFixtures.php';

beforeEach(function () {
    while (DB::connection()->transactionLevel() > 0) {
        DB::rollBack();
    }
    expect(app()->environment())->toBe('testing');
    expect(DB::getDriverName() === 'pgsql' ? str_starts_with(DB::connection()->getDatabaseName(), 'facturac_test_') : DB::connection()->getDatabaseName() === ':memory:')->toBeTrue();
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    config(['assistant.enabled' => true]);
    Http::preventStrayRequests();
});

afterEach(function () {
    Activity::flushEventListeners();
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
});

test('assistant audit categories are fixed from trusted status or dedicated denial never exception text', function () {
    foreach ([401 => 'authorization_rejected', 403 => 'authorization_rejected', 419 => 'authorization_rejected', 409 => 'interaction_conflict', 422 => 'invalid_input', 405 => 'invalid_input', 429 => 'quota_rejected', 404 => 'unavailable', 503 => 'unavailable'] as $status => $category) {
        expect(AssistantAudit::failureOutcome(new HttpException($status, 'SECRET quota_rejected')))->toBe($category);
    }
    expect(AssistantAudit::failureOutcome(new RuntimeException('provider_disabled SECRET')))->toBe('unavailable');
    expect(AssistantAudit::failureOutcome(new AiExecutionDisabled))->toBe('provider_disabled');
});

test('assistant default planner refusal is audited as provider disabled without provider invocation or payload', function () {
    $f = assistantFixture();
    expect(app(AssistantPlanner::class))->toBeInstanceOf(UnavailableAssistantPlanner::class);
    $response = $this->actingAs($f['user'])->withHeader('User-Agent', 'SECRET-UA')->postJson($f['url'], assistantPayload($f, ['question' => 'SECRET-PROMPT']))
        ->assertStatus(503)->assertJsonPath('error.code', 'unavailable')->assertJsonMissingPath('results');
    $events = Activity::where('log_name', 'assistant')->get();
    expect($events->where('event', 'assistant.interaction.started'))->toHaveCount(1);
    $failed = $events->firstWhere('event', 'assistant.interaction.failed');
    expect($failed->properties->get('outcome'))->toBe('provider_disabled');
    expect($failed->properties->get('actor_attribution_id'))->toBe($f['user']->attribution_id);
    expect($failed->properties->get('request_id'))->toBe($response->json('request_id'));
    expect($events->toJson())->not->toContain('SECRET-', 'provider_invoked', 'Cliente Consulta');
    expect($events->where('event', 'assistant.tool.requested'))->toHaveCount(0);
    Http::assertNothingSent();
});

test('assistant SDK manager refusal behind the port has the same fixed audit and public error', function () {
    $f = assistantFixture();
    assistantPlanner(fn () => app(AiManager::class));
    $this->actingAs($f['user'])->postJson($f['url'], assistantPayload($f))->assertStatus(503)->assertJsonMissingPath('results');
    expect(Activity::where('event', 'assistant.interaction.failed')->first()->properties->get('outcome'))->toBe('provider_disabled');
    Http::assertNothingSent();
});

test('assistant fresh per-tool withdrawal writes denied with server UUID before requested or business query', function () {
    $f = assistantFixture();
    assistantPlanner(function () use ($f) {
        WorkspaceMembership::where('user_id', $f['user']->id)->update(['is_active' => false]);

        return assistantReadPlan([['tool' => 'getCustomer', 'arguments' => ['public_id' => $f['customer']->public_id]]]);
    });
    $this->actingAs($f['user'])->postJson($f['url'], assistantPayload($f))->assertForbidden()->assertJsonMissingPath('results');
    $denied = Activity::where('event', 'assistant.tool.denied')->sole();
    expect($denied->properties->get('tool'))->toBe('getCustomer')->and($denied->properties->get('outcome'))->toBe('authorization_rejected');
    expect(Str::isUuid($denied->properties->get('tool_call_id')))->toBeTrue();
    expect(Activity::whereIn('event', ['assistant.tool.requested', 'assistant.tool.succeeded', 'customers.read'])->count())->toBe(0);
    expect(Activity::where('event', 'assistant.interaction.failed')->sole()->properties->get('outcome'))->toBe('authorization_rejected');
});

test('assistant quota rejection is categorized without planner read or uncontrolled metadata', function () {
    $f = assistantFixture();
    $planner = assistantPlanner('{"decision":"unsupported","reason":"outside_read_contract"}');
    foreach ([intdiv(time(), 60), intdiv(time(), 60) + 1] as $window) {
        Cache::store('database')->put('assistant-quota:'.hash('sha256', 'user-minute:'.$f['user']->id).':'.$window, 6, 61);
    }
    $this->actingAs($f['user'])->postJson($f['url'], assistantPayload($f))->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
    expect($planner->inputs)->toBe([]);
    expect(Activity::where('event', 'assistant.interaction.failed')->sole()->properties->get('outcome'))->toBe('quota_rejected');
});

test('assistant conflict and invalid input keep safe envelopes and fixed categories', function () {
    $f = assistantFixture();
    assistantPlanner('{"decision":"unsupported","reason":"outside_read_contract"}');
    $payload = assistantPayload($f);
    $this->actingAs($f['user'])->postJson($f['url'], $payload)->assertSuccessful();
    $this->postJson($f['url'], $payload)->assertStatus(409)->assertJsonMissingPath('results');
    expect(Activity::where('event', 'assistant.interaction.failed')->latest('id')->first()->properties->get('outcome'))->toBe('interaction_conflict');
    $this->postJson($f['url'], assistantPayload($f, ['extra' => 'SECRET-INVALID']))->assertStatus(422)->assertJsonMissingPath('results');
    expect(Activity::where('event', 'assistant.interaction.failed')->latest('id')->first()->properties->get('outcome'))->toBe('invalid_input');
    expect(Activity::where('log_name', 'assistant')->get()->toJson())->not->toContain('SECRET-INVALID');
});

test('assistant failure to persist tool denial cannot disclose any buffered result', function () {
    $f = assistantFixture();
    assistantPlanner(function () use ($f) {
        WorkspaceMembership::where('user_id', $f['user']->id)->update(['is_active' => false]);

        return assistantReadPlan([['tool' => 'getCustomer', 'arguments' => ['public_id' => $f['customer']->public_id]]]);
    });
    Activity::creating(function ($activity) {
        if ($activity->event === 'assistant.tool.denied') {
            throw new RuntimeException('SECRET-AUDIT');
        }
    });
    $response = $this->actingAs($f['user'])->postJson($f['url'], assistantPayload($f))->assertStatus(503)->assertJsonMissingPath('results');
    expect($response->getContent())->not->toContain('SECRET-', 'Cliente Consulta');
});
