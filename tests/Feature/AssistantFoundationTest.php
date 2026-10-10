<?php

use App\Fiscal\AssistantPlanner;
use App\Fiscal\AssistantTools;
use App\Fiscal\Documents\QualifiedAgtStatusRead;
use App\Fiscal\UnavailableAssistantPlanner;
use App\Models\Customer;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
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
    Queue::fake();
    Notification::fake();
    Mail::fake();
});

afterEach(function () {
    while (DB::connection()->transactionLevel() > 0) {
        DB::rollBack();
    }
    Activity::flushEventListeners();
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
});

test('assistant five tools use exact minimized shared reads with audit provenance and no business changes', function () {
    $f = assistantFixture();
    $tables = ['customers', 'catalogue_items', 'fiscal_documents', 'agt_submissions', 'agt_submission_observations', 'external_command_operations'];
    $before = [];
    foreach ($tables as $table) {
        $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
    }
    $planner = assistantPlanner(assistantReadPlan([
        ['tool' => 'searchCustomers', 'arguments' => ['q' => 'Cliente', 'status' => 'all']],
        ['tool' => 'getCustomer', 'arguments' => ['public_id' => $f['customer']->public_id]],
        ['tool' => 'getQualifiedAgtStatus', 'arguments' => ['public_id' => $f['document']->public_id]],
        ['tool' => 'getMonthlyRecordedBilling', 'arguments' => ['month' => '2024-02']],
    ]));
    $r = $this->actingAs($f['user'])->postJson($f['url'], assistantPayload($f))->assertSuccessful()->assertJsonPath('outcome', 'answered')->assertJsonCount(4, 'results');
    expect(array_keys($r->json()))->toBe(['request_id', 'interaction_id', 'context', 'outcome', 'reason', 'results']);
    expect(array_keys($r->json('results.0.data')))->toBe(['items', 'has_more']);
    expect(array_keys($r->json('results.1.data')))->toBe(['public_id', 'name', 'country_code', 'is_active']);
    expect(array_keys($r->json('results.2.data')))->toBe(QualifiedAgtStatusRead::FIELDS);
    expect($r->json('results.3.data.currencies'))->toHaveCount(8);
    expect($r->json('results.3.data.currencies.0.invoiced_gross_minor'))->toBe('0');
    expect($r->headers->get('X-Request-ID'))->toBe($r->json('request_id'));
    expect($planner->inputs)->toHaveCount(1)->and(array_keys($planner->inputs[0]))->toBe(['question', 'references', 'current_month', 'schema_version', 'tools']);
    expect(json_encode($planner->inputs))->not->toContain('Cliente Consulta')->not->toContain('results')->not->toContain($f['user']->email);
    foreach ($r->json('results') as $result) {
        expect(Activity::where('event', 'assistant.tool.succeeded')->where('properties->result_id', $result['result_id'])->count())->toBe(1);
    }
    foreach ($tables as $table) {
        expect(DB::table($table)->orderBy('id')->get()->toJson())->toBe($before[$table]);
    }
    assistantPlanner(assistantReadPlan([['tool' => 'getFiscalDocumentSummary', 'arguments' => ['public_id' => $f['document']->public_id]]]));
    $d = $this->postJson($f['url'], assistantPayload($f))->assertSuccessful();
    expect(array_keys($d->json('results.0.data')))->toBe(['public_id', 'document_no', 'document_type', 'environment', 'revision', 'workflow_state']);
    expect($d->getContent())->not->toContain('gross_total', 'tax_identification', 'agt_status', 'signature', 'receipt_eligible');
    Http::assertNothingSent();
    Queue::assertNothingPushed();
    Mail::assertNothingSent();
    Notification::assertNothingSent();
});

test('assistant defaults unavailable and globally disabled access never runs a planner', function (bool $enabled) {
    $f = assistantFixture();
    config(['assistant.enabled' => $enabled]);
    app()->bind(AssistantPlanner::class, UnavailableAssistantPlanner::class);
    $this->actingAs($f['user'])->postJson($f['url'], assistantPayload($f))->assertStatus($enabled ? 503 : 404)->assertJsonMissingPath('results');
    expect(Activity::where('event', 'assistant.tool.requested')->count())->toBe(0);
    expect(config('integrations.enabled'))->toBeFalse()->and(config('integrations.commands_enabled'))->toBeFalse();
})->with([true, false]);

test('assistant requires fresh verified MFA membership session and production context', function (string $attack) {
    $f = assistantFixture();
    $planner = assistantPlanner('{"decision":"unsupported","reason":"outside_read_contract"}');
    $status = 403;
    if ($attack === 'anonymous') {
        $status = 401;
    } else {
        $this->actingAs($f['user']);
    }
    if ($attack === 'email') {
        $f['user']->forceFill(['email_verified_at' => null])->save();
    }
    if ($attack === 'mfa') {
        $f['user']->forceFill(['two_factor_secret' => null])->save();
    }
    if ($attack === 'inactive') {
        WorkspaceMembership::where('user_id', $f['user']->id)->update(['is_active' => false]);
    }
    if ($attack === 'expired') {
        $this->withSession(['work_session_started_at' => now()->subDays(2)->getTimestamp()]);
        $status = 401;
    }
    if ($attack === 'homologation') {
        $f['url'] = str_replace('/production/', '/homologation/', $f['url']);
    }
    if ($attack === 'unresolved') {
        $f['url'] = str_replace('/production/', '/unresolved/', $f['url']);
    }
    if ($attack === 'impersonation') {
        Context::add('impersonator_id', 999);
    }
    $this->postJson($f['url'], assistantPayload($f))->assertStatus($status)->assertJsonMissingPath('results');
    expect($planner->inputs)->toBe([]);
})->with(['anonymous', 'email', 'mfa', 'inactive', 'expired', 'homologation', 'unresolved', 'impersonation']);

test('assistant inherits role read permissions without upgrading billing authority', function (string $role, string $tool) {
    $f = assistantFixture();
    WorkspaceMembership::where('user_id', $f['user']->id)->update(['role' => $role]);
    $args = $tool === 'getMonthlyRecordedBilling' ? ['month' => '2024-02'] : ['public_id' => $f['customer']->public_id];
    assistantPlanner(assistantReadPlan([['tool' => $tool, 'arguments' => $args]]));
    $allowed = $tool !== 'getMonthlyRecordedBilling' || in_array($role, ['owner', 'administrator', 'accountant'], true);
    $this->actingAs($f['user'])->postJson($f['url'], assistantPayload($f))->assertStatus($allowed ? 200 : 503);
})->with(['owner', 'administrator', 'accountant', 'billing', 'viewer'])->with(['getCustomer', 'getMonthlyRecordedBilling']);

test('assistant identifier lookups do not distinguish foreign sibling environment and absent records', function (string $tool, string $target) {
    $f = assistantFixture();
    $other = assistantFixture();
    $kind = $tool === 'getCustomer' ? 'customer' : 'document';
    $id = $target === 'foreign' ? $other[$kind]->public_id : strtolower((string) Str::ulid());
    if ($target === 'wrong-environment' && $kind === 'document') {
        $f['document']->forceFill(['environment' => 'homologation'])->save();
        $id = $f['document']->public_id;
    }
    if ($target === 'sibling') {
        $entity = LegalEntity::factory()->configured()->create(['workspace_id' => $f['entity']->workspace_id]);
        $model = $kind === 'customer' ? Customer::factory() : FiscalDocument::factory();
        $id = $model->create(['workspace_id' => $entity->workspace_id, 'legal_entity_id' => $entity->id])->public_id;
    }
    assistantPlanner(assistantReadPlan([['tool' => $tool, 'arguments' => ['public_id' => $id]]]));
    $this->actingAs($f['user'])->postJson($f['url'], assistantPayload($f, ['references' => [['kind' => $kind, 'public_id' => $id]]]))->assertNotFound()->assertJsonPath('error.code', 'not_found')->assertJsonMissingPath('results');
})->with(['getCustomer', 'getFiscalDocumentSummary', 'getQualifiedAgtStatus'])->with(['foreign', 'absent', 'sibling', 'wrong-environment']);

test('assistant rejects forged plan arguments before any shared business query', function (string $json) {
    $f = assistantFixture();
    assistantPlanner($json);
    $this->actingAs($f['user'])->postJson($f['url'], assistantPayload($f))->assertStatus(503)->assertJsonMissingPath('results');
    expect(Activity::where('event', 'assistant.tool.requested')->count())->toBe(0);
})->with([
    '{"decision":"read","calls":[{"tool":"issueInvoice","arguments":{}}]}',
    '{"decision":"read","calls":[{"tool":"approveRecurring","arguments":{}}]}',
    '{"decision":"read","calls":[{"tool":"https://evil.test","arguments":{}}]}',
    '{"decision":"read","calls":[{"tool":"searchCustomers","arguments":{"q":"Cliente","status":"all","page":2}}]}',
    '{"decision":"read","calls":[{"tool":"searchCustomers","arguments":{"q":["Cliente"],"status":"all"}}]}',
    '{"decision":"read","calls":[{"tool":"getCustomer","arguments":{"public_id":"00000000000000000000000000"}}]}',
    '{"decision":"read","calls":[{"tool":"searchCustomers","arguments":{"q":"Cliente","q":"SECRET","status":"all"}}]}',
    '{"decision":"read","calls":[],"answer":"Invented business truth"}',
    '{"decision":"read","calls":[]}',
    '{"decision":"unsupported","reason":"outside_read_contract","answer":"fiction"}',
    '{"decision":"read","calls":[{"tool":"searchCustomers","arguments":{"q":"Cliente","status":"all"}},{"tool":"searchCustomers","arguments":{"q":"Other","status":"all"}}]}',
    '{"decision":"read","calls":[{"tool":"getMonthlyRecordedBilling","arguments":{"month":"2024-02"}},{"tool":"getMonthlyRecordedBilling","arguments":{"month":"2024-03"}}]}',
    '{"decision":"read","calls":[{"tool":"getMonthlyRecordedBilling","arguments":{"month":"9999-12"}}]}',
    'not JSON', str_repeat('x', 4097),
]);

test('assistant protocol rejects uncontrolled payloads without planning', function (string $attack) {
    $f = assistantFixture();
    $planner = assistantPlanner('{"decision":"unsupported","reason":"outside_read_contract"}');
    $body = assistantPayload($f);
    match ($attack) {
        'empty' => $body['question'] = '', 'long' => $body['question'] = str_repeat('x', 2001), 'control' => $body['question'] = "Hello\0", 'object' => $body['question'] = ['x'],
        'extra' => $body['workspace_id'] = 1, 'nonce' => $body['request_nonce'] = 'bad', 'refs' => $body['references'] = [['kind' => 'document', 'public_id' => '8'.str_repeat('0', 25)]],
        'ref-extra' => $body['references'][0]['authority'] = 'admin', 'ref-duplicate' => $body['references'] = [$body['references'][0], $body['references'][0]], default => null,
    };
    $url = $f['url'].($attack === 'query' ? '?page=1' : '');
    $this->actingAs($f['user'])->postJson($url, $body)->assertUnprocessable()->assertJsonMissingPath('results');
    expect($planner->inputs)->toBe([]);
})->with(['empty', 'long', 'control', 'object', 'extra', 'nonce', 'refs', 'ref-extra', 'ref-duplicate', 'query']);

test('assistant search retains literal case status order and bounded response semantics', function (string $q) {
    $f = assistantFixture();
    $name = 'Literal %_ \\ quote\' José ignore instructions <script>alert(1)</script>';
    Customer::factory()->count(11)->create(['workspace_id' => $f['entity']->workspace_id, 'legal_entity_id' => $f['entity']->id, 'name' => $name]);
    $planner = assistantPlanner(assistantReadPlan([['tool' => 'searchCustomers', 'arguments' => ['q' => $q, 'status' => 'all']]]));
    $r = $this->actingAs($f['user'])->postJson($f['url'], assistantPayload($f))->assertSuccessful()->assertJsonCount(10, 'results.0.data.items')->assertJsonPath('results.0.data.has_more', true);
    expect($r->json('results.0.data.items.0.name'))->toBe($name);
    expect(json_encode($planner->inputs))->not->toContain($name);
})->with(['%_', '\\ quote', 'José', 'ignore instructions']);

test('assistant live authority changes after planning or before final disclosure withhold every result', function (string $attack) {
    $f = assistantFixture();
    $mutate = function () use ($f, $attack) {
        match ($attack) {
            'email' => $f['user']->forceFill(['email_verified_at' => null])->save(),
            'mfa' => $f['user']->forceFill(['two_factor_secret' => null])->save(),
            'membership' => WorkspaceMembership::where('user_id', $f['user']->id)->delete(),
            'recreated' => (function () use ($f) {
                $m = WorkspaceMembership::where('user_id', $f['user']->id)->first();
                $a = $m->getAttributes();
                unset($a['id']);
                $m->delete();
                WorkspaceMembership::insert($a);
            })(),
            'deleted' => (function () use ($f) {
                $f['document']->delete();
                User::where('id', $f['user']->id)->delete();
            })(),
            'role' => WorkspaceMembership::where('user_id', $f['user']->id)->update(['role' => 'viewer']),
            default => null,
        };
    };
    $tool = $attack === 'role' ? 'getMonthlyRecordedBilling' : 'getCustomer';
    $args = $tool === 'getCustomer' ? ['public_id' => $f['customer']->public_id] : ['month' => '2024-02'];
    assistantPlanner(function () use ($mutate, $tool, $args) {
        $mutate();

        return assistantReadPlan([['tool' => $tool, 'arguments' => $args]]);
    });
    $this->actingAs($f['user'])->postJson($f['url'], assistantPayload($f))->assertForbidden()->assertJsonMissingPath('results');
})->with(['email', 'mfa', 'membership', 'recreated', 'deleted', 'role']);

test('assistant required audit failures and partial tool failure never disclose result sets', function (string $failure) {
    $f = assistantFixture();
    if ($failure === 'buffer') {
        config(['activitylog.buffer.enabled' => true]);
    }
    if ($failure === 'audit') {
        Activity::creating(fn () => false);
    }
    $calls = [['tool' => 'getCustomer', 'arguments' => ['public_id' => $f['customer']->public_id]]];
    if ($failure === 'second-tool') {
        $id = strtolower((string) Str::ulid());
        $calls[] = ['tool' => 'getFiscalDocumentSummary', 'arguments' => ['public_id' => $id]];
    }
    assistantPlanner(assistantReadPlan($calls));
    $payload = assistantPayload($f);
    if ($failure === 'second-tool') {
        $payload['references'][1]['public_id'] = $id;
    }
    $r = $this->actingAs($f['user'])->postJson($f['url'], $payload)->assertStatus($failure === 'second-tool' ? 404 : 503)->assertJsonMissingPath('results');
    expect($r->getContent())->not->toContain('Cliente Consulta');
    config(['activitylog.buffer.enabled' => false]);
})->with(['audit', 'buffer', 'second-tool']);

test('assistant transport replay quota and method boundaries do not execute additional tools', function () {
    $f = assistantFixture();
    $planner = assistantPlanner('{"decision":"unsupported","reason":"outside_read_contract"}');
    $body = assistantPayload($f);
    $this->actingAs($f['user'])->postJson($f['url'], $body)->assertSuccessful();
    $this->postJson($f['url'], [...$body, 'question' => 'Changed question'])->assertConflict();
    foreach (['GET', 'HEAD', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
        $this->json($method, $f['url'], $body)->assertStatus(405);
    }
    for ($i = 0; $i < 4; $i++) {
        $this->postJson($f['url'], assistantPayload($f))->assertSuccessful();
    }
    $this->postJson($f['url'], assistantPayload($f))->assertStatus(429);
    expect($planner->inputs)->toHaveCount(5);
});

test('assistant output validation rejects added fields and noncanonical financial values', function (string $tool) {
    $f = assistantFixture();
    $data = $tool === 'getCustomer' ? ['public_id' => $f['customer']->public_id, 'name' => 'Client', 'country_code' => 'AO', 'is_active' => true, 'email' => 'SECRET'] : ['public_id' => $f['document']->public_id, 'document_no' => null, 'document_type' => 'FT', 'environment' => 'production', 'revision' => 1, 'workflow_state' => 'valid', 'receipt_eligible' => true];
    expect(fn () => AssistantTools::validate($tool, $data))->toThrow(HttpException::class);
})->with(['getCustomer', 'getFiscalDocumentSummary']);

test('assistant page keeps its own navigation entry although it does not use the current company', function () {
    $f = assistantFixture();
    $url = route('assistant.show', $f['parameters'], false);
    $this->actingAs($f['user'])->get($url)->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->component('Assistant/Index')->where('assistant.url', $url)->where('currentWorkspace', null));
});

test('assistant page uses explicit context and clears data without restoring browser defaults', function () {
    $f = assistantFixture();
    $other = assistantFixture();
    $f['user']->update(['current_workspace_id' => $other['entity']->workspace_id]);
    $this->actingAs($f['user'])->get(route('assistant.show', $f['parameters']))->assertSuccessful()->assertInertia(fn (Assert $page) => $page->component('Assistant/Index')->where('context.legal_entity_public_id', $f['entity']->public_id)->where('context.environment', 'production'));
});

test('assistant respects CSRF under a real middleware check and strips debug errors', function () {
    $f = assistantFixture();
    $planner = assistantPlanner('{"decision":"unsupported","reason":"outside_read_contract"}');
    config(['app.debug' => true]);
    app()->bind(PreventRequestForgery::class, function ($app) {
        return new class($app, app('encrypter')) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        };
    });
    $r = $this->actingAs($f['user'])->postJson($f['url'], assistantPayload($f))->assertStatus(419)->assertJsonPath('error.code', 'session_expired');
    expect($r->getContent())->not->toContain('exception', 'trace', 'question')->and($planner->inputs)->toBe([]);
});

test('assistant admission and nested audits suppress ambient secrets under HTTP conditions', function () {
    $f = assistantFixture();
    assistantPlanner(assistantReadPlan([['tool' => 'getFiscalDocumentSummary', 'arguments' => ['public_id' => $f['document']->public_id]]]));
    $before = Activity::max('id');
    $console = new ReflectionProperty(Application::class, 'isRunningInConsole');
    $previous = $console->getValue($this->app);
    $console->setValue($this->app, false);
    try {
        $r = $this->actingAs($f['user'])->withHeader('User-Agent', 'SECRET-UA')->withHeader('X-SECRET', 'SECRET-HEADER')
            ->withHeader('Sec-Fetch-Site', 'same-origin')->postJson($f['url'], assistantPayload($f, ['question' => 'SECRET-PROMPT']))->assertSuccessful();
    } finally {
        $console->setValue($this->app, $previous);
    }
    $events = Activity::where('id', '>', $before)->get();
    foreach ($events as $event) {
        expect($event->properties->get('user_agent'))->toBeNull()->and($event->properties->toJson())->not->toContain('SECRET-');
    }
    expect($events->where('event', 'documents.read'))->toHaveCount(1)->and($r->headers->getCookies())->not->toBeEmpty();
});

test('assistant final authorization and complete audit precede disclosure', function () {
    $f = assistantFixture();
    assistantPlanner(assistantReadPlan([['tool' => 'getCustomer', 'arguments' => ['public_id' => $f['customer']->public_id]]]));
    Activity::created(function ($activity) use ($f) {
        if ($activity->event === 'assistant.interaction.completed') {
            WorkspaceMembership::where('user_id', $f['user']->id)->update(['is_active' => false]);
        }
    });
    $r = $this->actingAs($f['user'])->postJson($f['url'], assistantPayload($f))->assertForbidden()->assertJsonMissingPath('results');
    expect($r->getContent())->not->toContain('Cliente Consulta');
    expect(Activity::where('event', 'assistant.interaction.failed')->count())->toBe(1);
});

test('assistant planner budget and total deadline reject late plans without tool execution', function (string $limit) {
    $f = assistantFixture();
    assistantPlanner(function () use ($f, $limit) {
        if ($limit === 'planner') {
            usleep(10020000);
        } else {
            $guard = request()->attributes->get('assistant_guard');
            $property = new ReflectionProperty($guard, 'started');
            $property->setValue($guard, hrtime(true) / 1e9 - 31);
        }

        return assistantReadPlan([['tool' => 'getCustomer', 'arguments' => ['public_id' => $f['customer']->public_id]]]);
    });
    $this->actingAs($f['user'])->postJson($f['url'], assistantPayload($f))->assertStatus(503)->assertJsonMissingPath('results');
    expect(Activity::where('event', 'assistant.tool.requested')->count())->toBe(0);
})->with(['planner', 'interaction']);

test('assistant fresh role upgrades cannot expand captured authority', function () {
    $f = assistantFixture();
    WorkspaceMembership::where('user_id', $f['user']->id)->update(['role' => 'viewer']);
    assistantPlanner(function () use ($f) {
        WorkspaceMembership::where('user_id', $f['user']->id)->update(['role' => 'owner']);

        return assistantReadPlan([['tool' => 'getMonthlyRecordedBilling', 'arguments' => ['month' => '2024-02']]]);
    });
    $this->actingAs($f['user'])->postJson($f['url'], assistantPayload($f))->assertStatus(503);
    expect(Activity::where('event', 'analytics.billing.read')->count())->toBe(0);
});

test('assistant original session deadline cannot be extended during planning', function () {
    $f = assistantFixture();
    $f['user']->update(['work_session_minutes' => 5]);
    $this->withSession(['work_session_started_at' => now()->subMinutes(4)->getTimestamp()]);
    assistantPlanner(function () {
        $this->travel(2)->minutes();
        request()->session()->put('work_session_started_at', now()->getTimestamp());

        return '{"decision":"unsupported","reason":"outside_read_contract"}';
    });
    $this->actingAs($f['user'])->postJson($f['url'], assistantPayload($f))->assertUnauthorized()->assertJsonMissingPath('results');
    $this->travelBack();
});

test('assistant customer scoped cap precedes search filters and never exposes a partial list', function (int $count) {
    $f = assistantFixture();
    $f['customer']->delete();
    $rows = [];
    for ($i = 1; $i <= $count; $i++) {
        $rows[] = ['public_id' => '0000000000'.str_pad((string) $i, 16, '0', STR_PAD_LEFT), 'workspace_id' => $f['entity']->workspace_id, 'legal_entity_id' => $f['entity']->id, 'name' => $i === 1 ? 'MATCH CUSTOMER' : 'Other', 'tax_identification_number' => (string) (5000000000 + $i), 'country_code' => 'AO', 'is_active' => $i === 1];
    }
    foreach (array_chunk($rows, 1000) as $chunk) {
        DB::table('customers')->insert($chunk);
    }
    assistantPlanner(assistantReadPlan([['tool' => 'searchCustomers', 'arguments' => ['q' => 'MATCH', 'status' => 'active']]]));
    $r = $this->actingAs($f['user'])->postJson($f['url'], assistantPayload($f, ['references' => []]))->assertStatus($count === 10000 ? 200 : 503);
    if ($count === 10000) {
        $r->assertJsonCount(1, 'results.0.data.items');
    } else {
        $r->assertJsonMissingPath('results');
        expect($r->getContent())->not->toContain('MATCH CUSTOMER');
    }
})->with([10000, 10001]);

test('assistant customer search status literal case and empty-result semantics remain precise', function (string $status) {
    $f = assistantFixture();
    $f['customer']->update(['name' => 'Case José', 'is_active' => false]);
    assistantPlanner(assistantReadPlan([['tool' => 'searchCustomers', 'arguments' => ['q' => 'Case', 'status' => $status]]]));
    $this->actingAs($f['user'])->postJson($f['url'], assistantPayload($f))->assertSuccessful()->assertJsonCount($status === 'active' ? 0 : 1, 'results.0.data.items');
    assistantPlanner(assistantReadPlan([['tool' => 'searchCustomers', 'arguments' => ['q' => 'case', 'status' => 'all']]]));
    $this->postJson($f['url'], assistantPayload($f))->assertSuccessful()->assertJsonCount(0, 'results.0.data.items')->assertJsonPath('results.0.data.has_more', false);
})->with(['active', 'inactive', 'all']);

test('assistant fiscal summaries preserve every approved workflow and document type without fiscal certainty', function (string $value, string $field) {
    $f = assistantFixture();
    $f['document']->update([$field => $value]);
    assistantPlanner(assistantReadPlan([['tool' => 'getFiscalDocumentSummary', 'arguments' => ['public_id' => $f['document']->public_id]]]));
    $r = $this->actingAs($f['user'])->postJson($f['url'], assistantPayload($f))->assertSuccessful();
    $r->assertJsonPath('results.0.data.'.($field === 'status' ? 'workflow_state' : 'document_type'), $value)->assertJsonMissingPath('results.0.data.agt_status')->assertJsonMissingPath('results.0.data.receipt_eligible');
})->with([
    ...array_map(fn ($v) => [$v, 'status'], ['draft', 'issued', 'received', 'processing', 'valid', 'invalid', 'contingency']),
    ...array_map(fn ($v) => [$v, 'document_type'], ['FA', 'FT', 'FR', 'FG', 'GF', 'AC', 'AR', 'TV', 'RC', 'RG', 'RE', 'ND', 'NC', 'AF', 'RP', 'RA', 'CS', 'LD']),
]);

test('assistant unsupported or clarification responses are fixed structured outcomes without invented facts', function (string $decision, string $reason) {
    $f = assistantFixture();
    assistantPlanner(json_encode(compact('decision', 'reason')));
    $this->actingAs($f['user'])->postJson($f['url'], assistantPayload($f))->assertSuccessful()->assertJsonCount(0, 'results')->assertJsonPath('reason', $reason)->assertJsonPath('outcome', $decision === 'clarify' ? 'clarification_required' : 'unsupported');
})->with([['clarify', 'select_customer'], ['clarify', 'select_document'], ['clarify', 'specify_month'], ['clarify', 'refine_customer_search'], ['unsupported', 'outside_read_contract']]);

test('assistant database-cache refusal is fail closed without tool execution', function () {
    $f = assistantFixture();
    $planner = assistantPlanner('{"decision":"unsupported","reason":"outside_read_contract"}');
    config(['integrations.cache_store' => 'array']);
    $this->actingAs($f['user'])->postJson($f['url'], assistantPayload($f))->assertStatus(503);
    expect($planner->inputs)->toBe([]);
});

test('assistant maximum call counts and reference limits are enforced before execution', function (string $limit) {
    $f = assistantFixture();
    $call = ['tool' => 'getCustomer', 'arguments' => ['public_id' => $f['customer']->public_id]];
    $calls = array_fill(0, $limit === 'five' ? 5 : 2, $call);
    assistantPlanner(assistantReadPlan($calls));
    $this->actingAs($f['user'])->postJson($f['url'], assistantPayload($f))->assertStatus(503);
    expect(Activity::where('event', 'assistant.tool.requested')->count())->toBe(0);
})->with(['five', 'duplicate']);

test('assistant malformed raw JSON duplicate keys and oversized transport are rejected safely', function (string $raw) {
    $f = assistantFixture();
    $planner = assistantPlanner('{"decision":"unsupported","reason":"outside_read_contract"}');
    $this->actingAs($f['user'])->call('POST', $f['url'], [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $raw)->assertUnprocessable()->assertJsonMissingPath('results');
    expect($planner->inputs)->toBe([]);
})->with(['{"request_nonce":"a","question":"x","question":"SECRET","references":[]}', str_repeat('x', 12289), '{"question":{"nested":{"nested":{"nested":{"nested":{}}}}}}', "\xff"]);

test('assistant retained per-day quota and shared workspace quota use native stable identities', function (string $bucket) {
    $f = assistantFixture();
    $planner = assistantPlanner('{"decision":"unsupported","reason":"outside_read_contract"}');
    $seconds = $bucket === 'day' ? 86400 : 60;
    $identity = $bucket === 'day' ? 'user-day:'.$f['user']->id : 'workspace-minute:'.$f['entity']->workspace_id;
    $count = $bucket === 'day' ? 119 : 30;
    $cache = Cache::store('database');
    foreach ([intdiv(time(), $seconds), intdiv(time(), $seconds) + 1] as $window) {
        $cache->put('assistant-quota:'.hash('sha256', $identity).':'.$window, $count, $seconds + 1);
    }
    $this->actingAs($f['user']);
    if ($bucket === 'day') {
        $this->postJson($f['url'], assistantPayload($f))->assertSuccessful();
    }
    $this->postJson($f['url'], assistantPayload($f))->assertStatus(429)->assertJsonMissingPath('results');
    expect($planner->inputs)->toHaveCount($bucket === 'day' ? 1 : 0);
})->with(['day', 'workspace']);

test('assistant combined extraction cap withholds all facts rather than truncating names or financial results', function () {
    $f = assistantFixture();
    Customer::factory()->count(10)->create(['workspace_id' => $f['entity']->workspace_id, 'legal_entity_id' => $f['entity']->id, 'name' => str_repeat('😀', 255)]);
    assistantPlanner(assistantReadPlan([['tool' => 'searchCustomers', 'arguments' => ['q' => '😀😀', 'status' => 'all']], ['tool' => 'getMonthlyRecordedBilling', 'arguments' => ['month' => '2024-02']]]));
    $this->actingAs($f['user'])->postJson($f['url'], assistantPayload($f))->assertStatus(503)->assertJsonMissingPath('results');
});

test('assistant additive SQLite migration round trip preserves populated data and all prior constraints', function () {
    $f = assistantFixture();
    $before = $f['customer']->fresh()->getAttributes();
    $migration = require base_path('database/migrations/2026_10_08_132807_add_assistant_context_index_to_customers.php');
    $migration->down();
    $migration->up();
    expect($f['customer']->fresh()->getAttributes())->toBe($before);
    expect(Schema::hasIndex('customers', 'customers_assistant_context_id_index'))->toBeTrue();
});

test('assistant missing and foreign workspace contexts have indistinguishable denial shape', function () {
    $f = assistantFixture();
    $other = assistantFixture();
    $planner = assistantPlanner('{"decision":"unsupported","reason":"outside_read_contract"}');
    $this->actingAs($f['user']);
    $errors = [];
    foreach ([$other['entity']->workspace->public_id, strtolower((string) Str::ulid())] as $workspace) {
        $url = str_replace($f['entity']->workspace->public_id, $workspace, $f['url']);
        $r = $this->postJson($url, assistantPayload($f))->assertForbidden();
        $errors[] = $r->json('error');
    }
    expect($errors[0])->toBe($errors[1])->and($planner->inputs)->toBe([]);
});

test('assistant failed audit redaction preserves sanitized session cookies on inner rejection', function () {
    $f = assistantFixture();
    assistantPlanner(assistantReadPlan([['tool' => 'getCustomer', 'arguments' => ['public_id' => strtolower((string) Str::ulid())]]]));
    $r = $this->actingAs($f['user'])->postJson($f['url'], assistantPayload($f, ['references' => []]))->assertStatus(503);
    expect($r->headers->getCookies())->not->toBeEmpty();
});

test('assistant denied malformed interactions after safe context admission consume the same user quota', function () {
    $f = assistantFixture();
    $planner = assistantPlanner('{"decision":"unsupported","reason":"outside_read_contract"}');
    $this->actingAs($f['user']);
    for ($i = 0; $i < 6; $i++) {
        $this->postJson($f['url'], assistantPayload($f, ['question' => '']))->assertUnprocessable();
    }
    $this->postJson($f['url'], assistantPayload($f))->assertStatus(429)->assertJsonMissingPath('results');
    expect($planner->inputs)->toBe([]);
});

test('assistant corrected customer search and detail disclose no foreign matches or identifiers', function (string $case) {
    $local = assistantFixture();
    $foreign = assistantFixture();
    $local['customer']->update(['name' => 'Shared needle']);
    $foreign['customer']->update(['name' => $case === 'equivalent_values' ? 'Shared needle' : 'CONFIDENTIAL-TENANT Shared needle']);
    if ($case === 'empty_authorized') {
        $local['customer']->delete();
    }
    $query = match ($case) {
        'foreign_exact' => 'CONFIDENTIAL-TENANT Shared needle',
        'foreign_partial' => 'CONFIDENTIAL',
        default => 'Shared needle',
    };
    $call = $case === 'foreign_id'
        ? ['tool' => 'getCustomer', 'arguments' => ['public_id' => $foreign['customer']->public_id]]
        : ['tool' => 'searchCustomers', 'arguments' => ['q' => $query, 'status' => 'all']];
    assistantPlanner(assistantReadPlan([$call]));
    $payload = assistantPayload($local);
    if ($case === 'foreign_id') {
        $payload['references'] = [['kind' => 'customer', 'public_id' => $foreign['customer']->public_id]];
    }
    $response = $this->actingAs($local['user'])->postJson($local['url'], $payload);
    if ($case === 'foreign_id') {
        $response->assertNotFound()->assertJsonMissingPath('results');
    } else {
        $response->assertSuccessful()->assertJsonPath('results.0.data.has_more', false);
        $ids = array_column($response->json('results.0.data.items'), 'public_id');
        expect($ids)->toBe($case === 'equivalent_values' ? [$local['customer']->public_id] : []);
    }
    expect($response->getContent())->not->toContain($foreign['customer']->public_id, 'CONFIDENTIAL-TENANT');
})->with(['foreign_exact', 'foreign_partial', 'empty_authorized', 'equivalent_values', 'foreign_id']);
