<?php

use App\Actions\SaveFiscalDocumentDraft;
use App\AgtEnvironment;
use App\AgtSubmissionAttemptOperation;
use App\AgtSubmissionStatus;
use App\Analytics\BillingSummaryQuery;
use App\Analytics\ReceivablesQuery;
use App\Fiscal\Agt\Data\AgtInvoiceStatusResult;
use App\Fiscal\AnalyticsCapabilities;
use App\Fiscal\BillingSummaryCommand;
use App\Fiscal\Documents\AgtReconstruction;
use App\Fiscal\Documents\AgtStatusPresentation;
use App\Fiscal\Documents\AgtSubmissionExecution;
use App\Fiscal\Documents\CurrentAgtState;
use App\Fiscal\ExecutionContext;
use App\Fiscal\IntegrationCredentials;
use App\Fiscal\IntegrationManagementContext;
use App\Fiscal\IntegrationReadContext;
use App\Http\Resources\BillingSummaryResource;
use App\Models\FiscalDocument;
use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\WorkspaceRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Actions\LogActivityAction;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    while (DB::connection()->transactionLevel() > 0) {
        DB::rollBack();
    }
    expect(app()->environment())->toBe('testing');
    if (DB::getDriverName() === 'pgsql') {
        expect(str_starts_with(DB::connection()->getDatabaseName(), 'facturac_test_'))->toBeTrue();
    } else {
        expect(DB::connection()->getDatabaseName())->toBe(':memory:');
    }
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    config(['integrations.enabled' => true]);
    Http::preventStrayRequests();
    Queue::fake();
    Notification::fake();
});

afterEach(function () {
    while (DB::connection()->transactionLevel() > 0) {
        DB::rollBack();
    }
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
});

require_once __DIR__.'/../BillingFixtures.php';

test('billing exact summary reuses shared arithmetic and contains no private record fields', function () {
    $f = billingSummaryFixture();
    foreach (['FT', 'FR', 'GF', 'ND'] as $type) {
        billingDocument($f, ['document_type' => $type]);
    }
    billingDocument($f, ['document_type' => 'NC', 'gross_total_minor' => 2500, 'net_total_minor' => 2150, 'tax_payable_minor' => 350]);
    billingDocument($f, ['document_type' => 'RG']);
    FiscalDocument::factory()->create(['workspace_id' => $f['entity']->workspace_id, 'legal_entity_id' => $f['entity']->id, 'establishment_id' => $f['establishment']->id, 'environment' => 'production', 'document_date' => '2024-02-15']);
    $response = $this->withHeader('Authorization', 'Bearer '.$f['secret'])->getJson($f['url'].'?month=2024-02')->assertOk();
    $data = $response->json('data');
    expect(array_keys($response->json()))->toBe(['data', 'meta'])->and(array_keys($data))->toBe(BillingSummaryQuery::FIELDS)
        ->and(array_column($data['currencies'], 'currency_code'))->toBe(BillingSummaryQuery::CURRENCIES);
    $bucket = $data['currencies'][0];
    expect(array_keys($bucket))->toBe(['currency_code', 'minor_unit_scale', ...BillingSummaryQuery::METRICS])
        ->and($bucket['billed_document_count'])->toBe(4)->and($bucket['credit_note_count'])->toBe(1)
        ->and($bucket['invoiced_gross_minor'])->toBe('40000')->and($bucket['after_credits_gross_minor'])->toBe('37500')
        ->and($bucket['after_credits_net_minor'])->toBe('32250')->and($bucket['after_credits_tax_minor'])->toBe('5250');
    $legacy = app(ReceivablesQuery::class)->summarise(app(ReceivablesQuery::class)->forLegalEntity($f['entity']));
    expect($legacy['billed_minor'])->toBe((int) $bucket['invoiced_gross_minor'])->and($legacy['credited_minor'])->toBe((int) $bucket['credit_gross_minor']);
    expect($response->getContent())->not->toContain('customer_name', 'document_no', 'settled_minor', 'receiptEligible', $f['secret']);
    $audit = Activity::where('event', 'analytics.billing.read')->firstOrFail();
    expect($audit->causer_id)->toBe($f['integration']->id)->and($audit->properties['authority_user_id'])->toBe($f['integration']->sponsor_user_id)
        ->and($audit->properties->toJson())->not->toContain('2024-02', '40000', $f['secret'])->and($audit->subject_id)->toBeNull()
        ->and($f['credential']->fresh()->last_used_at)->not->toBeNull();
    Queue::assertNothingPushed();
    Notification::assertNothingSent();
});

test('billing supported fiscal states preserve issuance facts without AGT or settlement authority', function (string $status) {
    $f = billingSummaryFixture();
    billingDocument($f, ['status' => $status]);
    $this->withHeader('Authorization', 'Bearer '.$f['secret'])->getJson($f['url'].'?month=2024-02')->assertOk()->assertJsonPath('data.currencies.0.invoiced_gross_minor', '10000');
    expect(DB::table('agt_submissions')->count())->toBe(0);
})->with(['issued', 'received', 'processing', 'valid', 'invalid', 'contingency']);

test('billing fixed native currency buckets retain exact large integers without FX', function () {
    $f = billingSummaryFixture();
    foreach (BillingSummaryQuery::CURRENCIES as $i => $currency) {
        billingDocument($f, ['currency_code' => $currency, 'gross_total_minor' => 9007199254740993 + $i, 'net_total_minor' => 9007199254740993 + $i, 'tax_payable_minor' => 0, 'exchange_rate_micro' => 987654]);
    }
    $f['entity']->update(['currency_code' => 'USD']);
    $response = $this->withHeader('Authorization', 'Bearer '.$f['secret'])->getJson($f['url'].'?month=2024-02')->assertOk();
    foreach ($response->json('data.currencies') as $i => $bucket) {
        expect($bucket['invoiced_gross_minor'])->toBe((string) (9007199254740993 + $i))->and($bucket['minor_unit_scale'])->toBe(2);
    }
});

test('billing empty and credit only months have complete deterministic zeros or signed amounts', function (bool $credit) {
    $f = billingSummaryFixture();
    if ($credit) {
        billingDocument($f, ['document_type' => 'NC']);
    }
    $r = $this->withHeader('Authorization', 'Bearer '.$f['secret'])->getJson($f['url'].'?month=2024-02')->assertOk();
    expect($r->json('data.currencies.0.after_credits_gross_minor'))->toBe($credit ? '-10000' : '0')->and($r->json('data.period'))->toBe(['month' => '2024-02', 'from' => '2024-02-01', 'to_exclusive' => '2024-03-01']);
})->with([true, false]);

test('billing corrupt issued data never yields reassuring partial totals', function (array $attributes) {
    $f = billingSummaryFixture();
    billingDocument($f);
    billingDocument($f, $attributes);
    $this->withHeader('Authorization', 'Bearer '.$f['secret'])->getJson($f['url'].'?month=2024-02')->assertStatus(503)->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE');
    expect(Activity::where('event', 'analytics.billing.read')->count())->toBe(0)->and($f['credential']->fresh()->last_used_at)->toBeNull();
})->with(array_map(fn ($a) => [$a], [['currency_code' => 'XXX'], ['document_type' => 'FA'], ['issued_at' => null], ['frozen_at' => null], ['document_no' => ''], ['gross_total_minor' => 10001], ['net_total_minor' => -1]]));

test('billing maximum monetary value stays exact and aggregate overflow fails closed', function (bool $overflow) {
    $f = billingSummaryFixture();
    billingDocument($f, ['gross_total_minor' => PHP_INT_MAX, 'net_total_minor' => PHP_INT_MAX, 'tax_payable_minor' => 0]);
    if ($overflow) {
        billingDocument($f, ['gross_total_minor' => 1, 'net_total_minor' => 1, 'tax_payable_minor' => 0]);
    }
    $r = $this->withHeader('Authorization', 'Bearer '.$f['secret'])->getJson($f['url'].'?month=2024-02');
    if ($overflow) {
        $r->assertStatus(503);
    } else {
        $r->assertOk()->assertJsonPath('data.currencies.0.invoiced_gross_minor', (string) PHP_INT_MAX);
    }
})->with([true, false]);

test('billing dates are calendar months not creation issue or payment instants', function () {
    $f = billingSummaryFixture();
    foreach (['2024-01-31', '2024-02-01', '2024-02-29', '2024-03-01'] as $date) {
        billingDocument($f, ['document_date' => $date, 'issued_at' => '2025-01-01 00:00:00', 'payment_date' => '2025-05-10']);
    }
    config(['app.timezone' => 'UTC']);
    $this->withHeader('Authorization', 'Bearer '.$f['secret'])->getJson($f['url'].'?month=2024-02')->assertOk()->assertJsonPath('data.currencies.0.billed_document_count', 2);
});

test('billing closed raw query rejects ambiguity and unsupported dimensions', function (string $query) {
    $f = billingSummaryFixture();
    $this->withHeader('Authorization', 'Bearer '.$f['secret'])->getJson($f['url'].$query)->assertStatus(422);
    expect(Activity::where('event', 'analytics.billing.read.denied')->firstOrFail()->properties->toJson())->not->toContain('month', '2024');
})->with(['', '?month=2024-13', '?month=1999-12', '?month=9999-12', '?month=2024-2', '?month=2024-02&month=2024-03', '?month=2024-02&%6Donth=2024-02', '?month[]=2024-02', '?month=2024-02&currency=AOA', '?month=2024-02&as_of=2024-01-01', '?month=2024-02&customer=x', '?month=2024-02&group=currency', '?month=%xx', '?month=2024-02&']);

test('billing independent scope intersection has no generic implied authority', function (int $mask) {
    $scopes = ['documents:read', 'customers:read', 'catalogue:read', 'documents:agt-status:read', 'analytics:billing:read'];
    $f = billingSummaryFixture(array_values(array_filter($scopes, fn ($s, $i) => ($mask & (1 << $i)) !== 0, ARRAY_FILTER_USE_BOTH)));
    $r = $this->withHeader('Authorization', 'Bearer '.$f['secret'])->getJson($f['url'].'?month=2024-02');
    ($mask & 16) !== 0 ? $r->assertOk() : $r->assertForbidden();
})->with(range(0, 31));

test('billing GET HEAD preserve identical protocol audit quota and body rules', function (string $method, string $variant) {
    $f = billingSummaryFixture();
    $url = match ($variant) {
        'encoded' => str_replace('billing-summary', 'billing%2Dsummary', $f['url']),'prefix' => str_replace('/v2/', '/v%32/', $f['url']),default => $f['url']
    };
    $r = $this->withHeaders(['Authorization' => 'Bearer '.$f['secret'], 'If-None-Match' => '*', 'User-Agent' => 'PRIVATE '.$f['secret']])->json($method, $url.'?%6Donth=2024%2D02')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Request-ID');
    if ($method === 'HEAD') {
        $r->assertContent('');
    }
    expect($r->headers->has('ETag'))->toBeFalse()->and(Activity::where('event', 'analytics.billing.read')->firstOrFail()->properties['method'])->toBe($method);
})->with(['GET', 'HEAD'])->with(['normal', 'encoded', 'prefix']);

test('billing normalized unsupported verbs never receive automatic OPTIONS success', function (string $method) {
    $f = billingSummaryFixture();
    $url = str_replace('/v2/', '/v%32/', str_replace('billing-summary', 'billing%2Dsummary', $f['url']));
    $response = $this->withHeader('Authorization', 'Bearer '.$f['secret'])->json($method, $url.'?month=2024-02')->assertStatus(405)->assertHeader('Allow', 'GET, HEAD');
    billingAssertPublishedHeaders($response, 'GET');
    expect(Activity::where('event', 'analytics.billing.read.denied')->firstOrFail()->properties['metric_version'])->toBe('recorded-billing-v1');
})->with(['OPTIONS', 'POST']);

test('billing nonproduction and foreign bindings deny before fiscal query', function (string $change) {
    $f = billingSummaryFixture(environment: $change === 'homologation' ? 'homologation' : 'production');
    $parameters = $f['parameters'];
    if ($change === 'entity') {
        $parameters['entityPublicId'] = (string) Str::ulid();
    }
    if ($change === 'workspace') {
        $parameters['workspacePublicId'] = (string) Str::ulid();
    }
    if ($change === 'environment') {
        $parameters['environment'] = 'homologation';
    }
    $queries = 0;
    DB::connection()->beforeExecuting(function (string $sql) use (&$queries) {
        if (str_contains($sql, 'fiscal_documents')) {
            $queries++;
        }
    });
    $this->withHeader('Authorization', 'Bearer '.$f['secret'])->getJson(route('integrations.v2.analytics.billing.show', $parameters).'?month=2024-02')->assertForbidden();
    expect($queries)->toBe(0);
})->with(['homologation', 'entity', 'workspace', 'environment']);

test('billing sponsor scope expiry and credential withdrawal remains fresh', function (string $change) {
    $f = billingSummaryFixture();
    match ($change) {
        'scope' => DB::table('integration_scopes')->where('integration_id', $f['integration']->id)->delete(),
        'role' => DB::table('workspace_memberships')->where('id', $f['integration']->sponsor_membership_id)->update(['role' => 'viewer']),
        'mfa' => User::findOrFail($f['integration']->sponsor_user_id)->forceFill(['two_factor_confirmed_at' => null])->save(),
        'revoked' => $f['credential']->update(['revoked_at' => now()]),
        'expired' => $f['credential']->forceFill(['expires_at' => now()->subMinute()])->save(),
    };
    $r = $this->withHeader('Authorization', 'Bearer '.$f['secret'])->getJson($f['url'].'?month=2024-02');
    in_array($change, ['revoked', 'expired'], true) ? $r->assertUnauthorized() : $r->assertForbidden();
})->with(['scope', 'role', 'mfa', 'revoked', 'expired']);

test('billing human context enforces precise role and suppresses ambient audit text', function (string $role) {
    $f = billingSummaryFixture();
    $user = User::findOrFail($f['integration']->sponsor_user_id);
    DB::table('workspace_memberships')->where('id', $f['integration']->sponsor_membership_id)->update(['role' => $role]);
    $context = ExecutionContext::resolve($user, $f['entity'], AgtEnvironment::Production, readOnly: true);
    if (in_array($role, ['owner', 'administrator', 'accountant'], true)) {
        $data = app(AnalyticsCapabilities::class)->billingSummary($context, BillingSummaryCommand::fromMonth('2024-02'));
        expect($data['metric_version'])->toBe('recorded-billing-v1')->and(Activity::where('event', 'analytics.billing.read')->firstOrFail()->properties['user_agent'])->toBeNull();
    } else {
        expect(fn () => app(AnalyticsCapabilities::class)->billingSummary($context, BillingSummaryCommand::fromMonth('2024-02')))->toThrow(HttpException::class);
    }
})->with(['owner', 'administrator', 'accountant', 'billing', 'viewer']);

test('billing audit refusal outer transactions and unsupported timezone withhold data', function (string $failure) {
    $f = billingSummaryFixture();
    if ($failure === 'audit') {
        Activity::creating(fn () => false);
    }
    if ($failure === 'buffer') {
        config(['activitylog.buffer.enabled' => true]);
    }
    if ($failure === 'transaction') {
        DB::beginTransaction();
    }
    if ($failure === 'timezone') {
        $f['entity']->update(['timezone' => 'UTC']);
    }
    try {
        $this->withHeader('Authorization', 'Bearer '.$f['secret'])->getJson($f['url'].'?month=2024-02')->assertStatus(503);
    } finally {
        if ($failure === 'audit') {
            Activity::flushEventListeners();
        }if ($failure === 'transaction') {
            DB::rollBack();
        }
    }
    expect($f['credential']->fresh()->last_used_at)->toBeNull()->and(Activity::where('event', 'analytics.billing.read')->count())->toBe(0);
})->with(['audit', 'buffer', 'transaction', 'timezone']);

test('billing added quota and workspace concurrency guard are shared and fail safely', function () {
    $f = billingSummaryFixture();
    $this->withHeader('Authorization', 'Bearer '.$f['secret']);
    $store = Cache::store('database');
    $lock = $store->lock('billing-query-workspace:'.$f['entity']->workspace_id, 10);
    expect($lock->get())->toBeTrue();
    $this->getJson($f['url'].'?month=2024-02')->assertStatus(429)->assertHeader('Retry-After', '1');
    $lock->release();
    for ($i = 0; $i < 5; $i++) {
        $this->json($i % 2 === 0 ? 'GET' : 'HEAD', $f['url'].'?month=2024-'.($i % 2 === 0 ? '02' : '01'))->assertOk();
    }
    $this->getJson(str_replace('/v2/', '/v%32/', $f['url']).'?month=2024-02')->assertTooManyRequests();
    expect(Activity::where('event', 'analytics.billing.read')->count())->toBe(5);
});

test('billing resource and published specification cannot acquire extra model fields', function () {
    $f = billingSummaryFixture();
    $r = $this->withHeader('Authorization', 'Bearer '.$f['secret'])->getJson($f['url'].'?month=2024-02')->assertOk();
    $data = $r->json('data');
    $data['secret'] = 'PRIVATE';
    $data['period']['customer'] = 'PRIVATE';
    $data['currencies'][0]['document_no'] = 'PRIVATE';
    expect(json_encode((new BillingSummaryResource($data))->resolve(request())))->not->toContain('PRIVATE');
    $contract = file_get_contents(base_path('docs/phase-4d-analytics-read-contract.md'));
    preg_match('/```json\n(.*?)\n```/s', $contract, $matches);
    expect(json_decode($matches[1], true))->toBe(json_decode(file_get_contents(base_path('docs/openapi-external-billing-v2.json')), true));
    $spec = json_decode($matches[1], true);
    $schema = $spec['components']['schemas'];
    expect(array_keys($r->json('data')))->toBe($schema['Data']['required'])->and(array_keys($r->json('data.currencies.0')))->toBe($schema['Bucket']['required']);
    $walk = function (array $node) use (&$walk, $spec): void {
        if (isset($node['$ref'])) {
            expect($node['$ref'])->toStartWith('#/');
            $resolved = $spec;
            foreach (explode('/', substr($node['$ref'], 2)) as $segment) {
                $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
                expect($resolved)->toHaveKey($segment);
                $resolved = $resolved[$segment];
            }
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                $walk($value);
            }
        }
    };
    $walk($spec);
    $route = app('router')->getRoutes()->getByName('integrations.v2.analytics.billing.show');
    expect($route->methods())->toBe(['GET', 'HEAD'])->and('/'.$route->uri())->toBe(array_key_first($spec['paths']));
});

test('billing scope never grants ordinary document master data or qualified status access', function (string $route) {
    $f = billingSummaryFixture();
    $document = billingDocument($f);
    $params = $f['parameters'];
    if ($route === 'integrations.v2.documents.agt-status.show') {
        $params['documentPublicId'] = $document->public_id;
    }
    $this->withHeader('Authorization', 'Bearer '.$f['secret'])->getJson(route($route, $params))->assertForbidden();
})->with(['integrations.v1.documents.index', 'integrations.v1.customers.index', 'integrations.v1.catalogue.index', 'integrations.v2.documents.agt-status.show']);

test('billing malformed scope ceilings remain independent of valid credential grants', function () {
    $f = billingSummaryFixture();
    DB::table('integration_scopes')->where('integration_id', $f['integration']->id)->delete();
    $this->withHeader('Authorization', 'Bearer '.$f['secret'])->getJson($f['url'].'?month=2024-02')->assertForbidden();
});

test('billing current month accepts committed future dated facts and year boundaries', function () {
    $f = billingSummaryFixture();
    $this->travelTo(CarbonImmutable::parse('2026-01-01 00:15:00', 'Africa/Luanda'));
    billingDocument($f, ['document_date' => '2025-12-31']);
    billingDocument($f, ['document_date' => '2026-01-31']);
    $this->withHeader('Authorization', 'Bearer '.$f['secret'])->getJson($f['url'].'?month=2026-01')->assertOk()->assertJsonPath('data.currencies.0.billed_document_count', 1)->assertJsonPath('data.period.to_exclusive', '2026-02-01');
    $this->getJson($f['url'].'?month=2025-12')->assertOk()->assertJsonPath('data.currencies.0.billed_document_count', 1);
});

test('billing draft with issuance markers fails instead of hiding an inconsistent record', function () {
    $f = billingSummaryFixture();
    billingDocument($f, ['status' => 'draft']);
    $this->withHeader('Authorization', 'Bearer '.$f['secret'])->getJson($f['url'].'?month=2024-02')->assertStatus(503);
});

test('billing named denial audit failure remains generic and no data is returned', function (string $method) {
    $f = billingSummaryFixture([]);
    Activity::creating(fn () => false);
    try {
        $r = $this->withHeader('Authorization', 'Bearer '.$f['secret'])->json($method, $f['url'].'?month=2024-02')->assertStatus(500);
        if ($method === 'HEAD') {
            $r->assertContent('');
        } else {
            $r->assertJsonPath('error.code', 'INTERNAL_ERROR');
        }
    } finally {
        Activity::flushEventListeners();
    }
})->with(['GET', 'HEAD']);

test('billing human scheduled contexts never receive a billing permission', function () {
    $f = billingSummaryFixture();
    $user = User::findOrFail($f['integration']->sponsor_user_id);
    $ctx = ExecutionContext::resolve($user, $f['entity'], AgtEnvironment::Production, automationId: 'workflow', readOnly: true);
    expect(fn () => app(AnalyticsCapabilities::class)->billingSummary($ctx, BillingSummaryCommand::fromMonth('2024-02')))->toThrow(HttpException::class);
});

test('billing no browser session can replace machine identity or missing credentials', function () {
    $f = billingSummaryFixture();
    $this->actingAs(User::findOrFail($f['integration']->sponsor_user_id));
    $this->getJson($f['url'].'?month=2024-02')->assertUnauthorized();
    $this->withHeader('Authorization', 'Bearer invalid')->getJson($f['url'].'?month=2024-02')->assertUnauthorized();
    $this->withHeader('Authorization', 'Bearer '.$f['secret'])->getJson($f['url'].'?month=2024-02')->assertOk();
});

/** Validate the closed schema keywords actually used by this contract, not arbitrary OpenAPI documents. */
function billingSchemaMatches(mixed $value, mixed $schema, array $spec): bool
{
    if ($schema === false) {
        return false;
    }if ($schema === true) {
        return true;
    }
    if (isset($schema['$ref'])) {
        $schema = data_get($spec, str_replace('/', '.', substr($schema['$ref'], 2)));
    }
    foreach ($schema['allOf'] ?? [] as $part) {
        if (! billingSchemaMatches($value, $part, $spec)) {
            return false;
        }
    }
    if (array_key_exists('const', $schema) && $value !== $schema['const']) {
        return false;
    }
    if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
        return false;
    }
    $type = $schema['type'] ?? null;
    if (($type === 'object' && (! is_array($value) || array_is_list($value))) || ($type === 'array' && (! is_array($value) || ! array_is_list($value))) || ($type === 'string' && ! is_string($value)) || ($type === 'integer' && ! is_int($value))) {
        return false;
    }
    if (is_array($value)) {
        foreach ($schema['required'] ?? [] as $key) {
            if (! array_key_exists($key, $value)) {
                return false;
            }
        }
        foreach ($schema['properties'] ?? [] as $key => $part) {
            if (array_key_exists($key, $value) && ! billingSchemaMatches($value[$key], $part, $spec)) {
                return false;
            }
        }
        if (($schema['additionalProperties'] ?? true) === false && array_diff(array_keys($value), array_keys($schema['properties'] ?? [])) !== []) {
            return false;
        }
        if ($type === 'array') {
            if (count($value) < ($schema['minItems'] ?? 0) || count($value) > ($schema['maxItems'] ?? PHP_INT_MAX)) {
                return false;
            }
            foreach ($value as $i => $item) {
                if (! billingSchemaMatches($item, $schema['prefixItems'][$i] ?? $schema['items'] ?? true, $spec)) {
                    return false;
                }
            }
        }
    }
    if (is_string($value) && ((isset($schema['pattern']) && preg_match('~'.$schema['pattern'].'~', $value) !== 1) || strlen($value) > ($schema['maxLength'] ?? PHP_INT_MAX))) {
        return false;
    }
    if (is_int($value) && ($value < ($schema['minimum'] ?? PHP_INT_MIN) || $value > ($schema['maximum'] ?? PHP_INT_MAX))) {
        return false;
    }

    return true;
}

function billingAssertPublishedHeaders(TestResponse $response, string $method): void
{
    $spec = json_decode(file_get_contents(base_path('docs/openapi-external-billing-v2.json')), true, flags: JSON_THROW_ON_ERROR);
    $operation = $spec['paths'][array_key_first($spec['paths'])][strtolower($method)];
    foreach ($operation['responses'][$response->status()]['headers'] as $name => $header) {
        $response->assertHeader($name);
        $value = $response->headers->get($name);
        expect(billingSchemaMatches($value, $header['schema'], $spec))->toBeTrue($name.' must match the normative schema.');
        if (($header['schema']['format'] ?? null) === 'uuid') {
            expect(Str::isUuid($value))->toBeTrue();
        }
    }
}

test('billing final review published success headers match actual GET and HEAD transport', function (string $method) {
    $f = billingSummaryFixture();
    $response = $this->withToken($f['secret'])->json($method, $f['url'].'?month=2024-02')->assertOk();
    billingAssertPublishedHeaders($response, $method);
})->with(['GET', 'HEAD']);

test('billing success and generic errors validate against exact normative OpenAPI', function () {
    $f = billingSummaryFixture();
    $spec = json_decode(file_get_contents(base_path('docs/openapi-external-billing-v2.json')), true);
    $r = $this->withHeader('Authorization', 'Bearer '.$f['secret'])->getJson($f['url'].'?month=2024-02')->assertOk();
    expect(billingSchemaMatches($r->json(), $spec['components']['schemas']['Success'], $spec))->toBeTrue();
    $poison = $r->json();
    $poison['data']['currencies'][0]['invoiced_gross_minor'] = 0.1;
    expect(billingSchemaMatches($poison, $spec['components']['schemas']['Success'], $spec))->toBeFalse();
    $r = $this->getJson($f['url'].'?month=2024-00')->assertStatus(422);
    expect(billingSchemaMatches($r->json(), $spec['components']['schemas']['Error422'], $spec))->toBeTrue();
});

test('billing actual issuance credit and source rollback preserve the native recorded ledger', function () {
    $f = billingSummaryFixture();
    $query = app(BillingSummaryQuery::class);
    $context = ExecutionContext::resolve(User::findOrFail($f['integration']->sponsor_user_id), $f['entity'], AgtEnvironment::Production, readOnly: true);
    $command = BillingSummaryCommand::fromMonth('2024-02');
    foreach (['FT', 'FR', 'GF', 'ND'] as $type) {
        $profile = billingIssueProfile($f, $type);
        if ($type === 'ND') {
            $profile['references_document_public_id'] = $documents[0]->public_id;
        }
        $documents[] = billingIssue($f, $profile);
    }
    $credit = billingIssue($f, [...billingIssueProfile($f, 'NC'), 'references_document_public_id' => $documents[0]->public_id]);
    billingIssue($f, [...billingIssueProfile($f, 'NC', '2024-03-10'), 'references_document_public_id' => $documents[0]->public_id]);
    $before = $query->read($context, $command)['currencies'];
    expect($before[0]['billed_document_count'])->toBe(4)->and($before[0]['credit_note_count'])->toBe(1)
        ->and($before[0]['invoiced_gross_minor'])->toBe('45600')->and($before[0]['credit_gross_minor'])->toBe('11400')
        ->and($query->read($context, BillingSummaryCommand::fromMonth('2024-03'))['currencies'][0]['after_credits_gross_minor'])->toBe('-11400');
    expect(fn () => DB::transaction(function () use ($f) {
        billingIssue($f, billingIssueProfile($f, 'FT', '2024-02-20'));
        throw new RuntimeException('synthetic source rollback');
    }))->toThrow(RuntimeException::class);
    expect($query->read($context, $command)['currencies'])->toBe($before)->and($credit->fresh()->frozen_at)->not->toBeNull();
});

test('billing mixed currency control groups never suppress invalid data or draft accounting', function (bool $corrupt) {
    $f = billingSummaryFixture();
    billingDocument($f);
    FiscalDocument::factory()->create(['workspace_id' => $f['entity']->workspace_id, 'legal_entity_id' => $f['entity']->id,
        'establishment_id' => $f['establishment']->id, 'environment' => 'production', 'document_date' => '2024-02-28', 'currency_code' => 'XXX',
        'gross_total_minor' => PHP_INT_MAX, 'net_total_minor' => 0, 'tax_payable_minor' => 0]);
    if ($corrupt) {
        billingDocument($f, ['currency_code' => 'XXX', 'document_date' => '2024-02-29']);
    }
    $response = $this->withToken($f['secret'])->getJson($f['url'].'?month=2024-02');
    if ($corrupt) {
        $response->assertServiceUnavailable()->assertJsonMissingPath('data');
    } else {
        $response->assertOk()->assertJsonPath('data.currencies.0.invoiced_gross_minor', '10000');
    }
})->with([true, false]);

test('billing support retains real and effective attribution without ambient request disclosure', function (string $method) {
    $f = billingSummaryFixture();
    $user = User::findOrFail($f['integration']->sponsor_user_id);
    $support = User::factory()->create();
    Context::add('impersonator_id', $support->id);
    Context::add('impersonation_session', 'support-read-session');
    $context = ExecutionContext::resolve($user, $f['entity'], AgtEnvironment::Production, readOnly: true);
    $request = Request::create('/internal-read?month=2024-02&secret=PRIVATE-QUERY', $method);
    $request->headers->set('User-Agent', 'PRIVATE-UA '.$f['secret']);
    $this->app->instance('request', $request);
    $console = new ReflectionProperty(Application::class, 'isRunningInConsole');
    $prior = $console->getValue($this->app);
    $console->setValue($this->app, false);
    try {
        app(AnalyticsCapabilities::class)->billingSummary($context, BillingSummaryCommand::fromMonth('2024-02'));
    } finally {
        $console->setValue($this->app, $prior);
        Context::forget(['impersonator_id', 'impersonation_session']);
    }
    $audit = Activity::where('event', 'analytics.billing.read')->firstOrFail();
    expect($audit->causer_id)->toBe($user->id)->and($audit->properties['real_actor_id'])->toBe($support->id)
        ->and($audit->properties['effective_actor_id'])->toBe($user->id)->and($audit->properties['impersonation_session'])->toBe('support-read-session')
        ->and($audit->properties['method'])->toBe($method)->and($audit->properties['user_agent'])->toBeNull()
        ->and($audit->properties->toJson())->not->toContain('2024-02', 'PRIVATE-', $f['secret']);
})->with(['GET', 'HEAD']);

test('billing debug errors and required audit failure preserve generic GET HEAD envelopes', function (string $method, string $failure) {
    config(['app.debug' => true]);
    $f = billingSummaryFixture();
    if ($failure === 'cancelled') {
        Activity::creating(fn () => false);
    } elseif ($failure === 'throwing') {
        Activity::creating(fn () => throw new RuntimeException('PRIVATE-SQL '.$f['secret']));
    } elseif ($failure === 'cache') {
        config(['integrations.cache_store' => 'array']);
    } elseif ($failure === 'json') {
        $query = Mockery::mock(BillingSummaryQuery::class);
        $query->shouldReceive('read')->andReturn(['poison' => "\xB1\x31"]);
        app()->instance(BillingSummaryQuery::class, $query);
    } else {
        billingDocument($f, ['gross_total_minor' => -1]);
    }
    try {
        $response = $this->call($method, $f['url'].'?month=2024-02', server: ['HTTPS' => 'on', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$f['secret']]);
        $response->assertServiceUnavailable()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Request-ID');
        if ($method === 'HEAD') {
            expect($response->getContent())->toBe('');
        } else {
            $response->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE')->assertJsonMissingPath('data');
            expect($response->getContent())->not->toContain('PRIVATE-', $f['secret'], 'fiscal_documents', 'SQLSTATE', '2024-02', 'gross_total_minor');
        }
        expect($f['credential']->fresh()->last_used_at)->toBeNull()->and(Activity::where('event', 'analytics.billing.read')->count())->toBe(0);
    } finally {
        Activity::flushEventListeners();
    }
})->with(['GET', 'HEAD'])->with(['cancelled', 'throwing', 'cache', 'json', 'corrupt']);

test('billing real receipt partial full multi source withholding and overpayment effects are excluded', function () {
    $f = billingSummaryFixture();
    $first = billingIssue($f, [...billingIssueProfile($f), 'withholdings' => [['type' => 'IRT', 'rate_basis_points' => 650]]]);
    $second = billingIssue($f, billingIssueProfile($f));
    $credit = billingIssue($f, [...billingIssueProfile($f, 'NC'), 'references_document_public_id' => $second->public_id]);
    foreach ([$first, $second, $credit] as $source) {
        recordAuthoritativeAgtAcceptance($source);
    }
    $user = User::findOrFail($f['integration']->sponsor_user_id);
    $context = ExecutionContext::resolve($user, $f['entity'], AgtEnvironment::Production, readOnly: true);
    $command = BillingSummaryCommand::fromMonth('2024-02');
    $read = fn () => app(BillingSummaryQuery::class)->read($context, $command)['currencies'];
    $before = $read();
    $profile = [...billingIssueProfile($f, 'RG'), 'lines' => [], 'settlements' => [['document_public_id' => $first->public_id, 'amount_minor' => 5700]]];
    $partial = billingIssue($f, $profile);
    expect($partial->gross_total_minor)->toBe(5700)->and((int) $partial->withholdings()->sum('amount_minor'))->toBeGreaterThan(0)->and($read())->toBe($before);
    $full = billingIssue($f, [...$profile, 'settlements' => [['document_public_id' => $first->public_id, 'amount_minor' => 5700]]]);
    expect($full->gross_total_minor)->toBe(5700)->and($read())->toBe($before);
    billingIssue($f, [...$profile, 'settlements' => [['document_public_id' => $second->public_id, 'amount_minor' => 11400], ['document_public_id' => $credit->public_id, 'amount_minor' => 5700]]]);
    expect($read())->toBe($before);
    expect(fn () => billingIssue($f, [...$profile, 'settlements' => [['document_public_id' => $first->public_id, 'amount_minor' => 1]]]))->toThrow(ValidationException::class);
    expect($read())->toBe($before);
});

test('billing recorded totals survive real authoritative polling rejection and reconstruction with frozen evidence intact', function () {
    $f = billingSummaryFixture();
    $document = billingIssue($f, billingIssueProfile($f));
    $user = User::findOrFail($f['integration']->sponsor_user_id);
    $context = ExecutionContext::resolve($user, $f['entity'], AgtEnvironment::Production, readOnly: true);
    $read = fn () => app(BillingSummaryQuery::class)->read($context, BillingSummaryCommand::fromMonth('2024-02'))['currencies'];
    $before = $read();
    $frozen = $document->fresh()->getRawOriginal();
    expect(CurrentAgtState::acceptanceEvidenceSatisfied($document))->toBeFalse();
    recordAuthoritativeAgtAcceptance($document);
    expect(CurrentAgtState::acceptanceEvidenceSatisfied($document->fresh()))->toBeTrue()->and($read())->toBe($before);
    $submission = $document->submissions()->firstOrFail();
    AgtReconstruction::rebuild($submission->id);
    expect($read())->toBe($before);
    recordAuthoritativeAgtAcceptance($document, 'I');
    expect(CurrentAgtState::acceptanceEvidenceSatisfied($document->fresh()))->toBeFalse()->and($read())->toBe($before)
        ->and($document->fresh()->getRawOriginal())->toBe($frozen);
});

test('billing native quota identities survive encoded methods months and credential rotation', function () {
    $f = billingSummaryFixture();
    $user = User::findOrFail($f['integration']->sponsor_user_id);
    $request = Request::create('https://localhost/');
    $request->setUserResolver(fn () => $user);
    $request->setLaravelSession(app('session')->driver());
    $request->session()->put((string) config('work_session.started_at_key'), time());
    $request->session()->put('auth.password_confirmed_at', time());
    $management = IntegrationManagementContext::resolve($request, $f['entity']->workspace_id);
    $rotated = app(IntegrationCredentials::class)->rotate($management, $f['integration']->public_id, 1, $f['credential']->public_id, ['analytics:billing:read']);
    $secret = $rotated->revealOnce();
    $windows = [];
    foreach (range(0, 8) as $i) {
        $window = intdiv(time(), 60);
        $previous = $windows[$window] ?? 0;
        $method = $i % 2 === 0 ? 'GET' : 'HEAD';
        $url = $i % 2 === 0 ? $f['url'] : str_replace('billing-summary', 'billing%2Dsummary', $f['url']);
        $response = $this->withToken($i % 2 === 0 ? $f['secret'] : $secret)->json($method, $url.'?month='.($i % 2 === 0 ? '2024-02' : '2024-03'));
        $end = intdiv(time(), 60);
        if ($end === $window) {
            expect($response->status())->toBe($previous < 6 ? 200 : 429);
            $windows[$window] = $previous + 1;
        } else {
            expect($response->status())->toBeIn([200, 429]);
            $windows[$end] = Cache::store('database')->get('external-read:'.hash('sha256', 'billing-integration:'.$f['integration']->id).':'.$end, 0);
        }
        if ($method === 'HEAD') {
            expect($response->getContent())->toBe('');
        }
    }
    $total = 0;
    foreach (array_keys($windows) as $window) {
        $total += Cache::store('database')->get('external-read:'.hash('sha256', 'billing-integration:'.$f['integration']->id).':'.$window, 0);
    }
    expect($total)->toBe(9);
});

test('billing workspace quota is shared across distinct integrations and human identities', function () {
    $f = billingSummaryFixture();
    $contexts = [];
    foreach (range(1, 6) as $i) {
        $integration = Integration::factory()->create(['workspace_id' => $f['entity']->workspace_id, 'legal_entity_id' => $f['entity']->id, 'environment' => 'production',
            'sponsor_user_id' => $f['integration']->sponsor_user_id, 'sponsor_membership_id' => $f['integration']->sponsor_membership_id]);
        DB::table('integration_scopes')->insert(['integration_id' => $integration->id, 'scope' => 'analytics:billing:read']);
        $selector = (string) Str::ulid();
        $secret = 'fcr1.'.$selector.'.'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $credential = IntegrationCredential::factory()->create(['integration_id' => $integration->id, 'public_id' => $selector, 'secret_hash' => hash('sha256', $secret)]);
        DB::table('integration_credential_scopes')->insert(['credential_id' => $credential->id, 'scope' => 'analytics:billing:read']);
        $contexts[] = IntegrationReadContext::authenticate($secret, (string) Str::uuid());
    }
    $command = BillingSummaryCommand::fromMonth('2024-02');
    $counts = [];
    foreach (range(0, 30) as $i) {
        $window = intdiv(time(), 60);
        $prior = $counts[$window] ?? 0;
        try {
            app(AnalyticsCapabilities::class)->billingSummary($contexts[$i % 6], $command);
            $status = 200;
        } catch (HttpException $e) {
            $status = $e->getStatusCode();
        }
        if (intdiv(time(), 60) === $window) {
            expect($status)->toBe($prior < 30 ? 200 : 429);
            $counts[$window] = $prior + 1;
        } else {
            expect($status)->toBeIn([200, 429]);
            $counts[intdiv(time(), 60)] = Cache::store('database')->get('external-read:'.hash('sha256', 'billing-workspace:'.$f['entity']->workspace_id).':'.intdiv(time(), 60), 0);
        }
    }
});

test('billing Round 2 required audit failures withhold human machine and support results', function (string $principal, string $mode, string $method) {
    $f = billingSummaryFixture();
    $document = billingDocument($f);
    $frozen = $document->fresh()->getRawOriginal();
    $user = User::findOrFail($f['integration']->sponsor_user_id);
    if ($principal === 'support') {
        Context::add('impersonator_id', User::factory()->create()->id);
        Context::add('impersonation_session', 'billing-required-audit');
    }
    $context = $principal === 'machine' ? IntegrationReadContext::authenticate($f['secret'], (string) Str::uuid())
        : ExecutionContext::resolve($user, $f['entity'], AgtEnvironment::Production, readOnly: true);
    $request = Request::create('/internal-billing?month=2024-02', $method);
    $request->headers->set('User-Agent', 'PRIVATE '.$f['secret']);
    $this->app->instance('request', $request);
    $action = new class($mode) extends LogActivityAction
    {
        public function __construct(private string $mode) {}

        protected function save(Model $activity): void
        {
            if ($this->mode === 'unsaved') {
                return;
            }
            if ($this->mode === 'rollback') {
                DB::transaction(function () use ($activity): void {
                    $activity->save();
                    throw new RuntimeException('PRIVATE rolled-back audit');
                });
            }
            parent::save($activity);
        }
    };
    config(['activitylog.actions.log_activity' => $action::class]);
    app()->instance($action::class, $action);
    if ($mode === 'buffered') {
        config(['activitylog.buffer.enabled' => true]);
    } elseif ($mode === 'disabled') {
        activity()->disableLogging();
    } elseif ($mode === 'cancelled') {
        Activity::creating(fn () => false);
    } elseif ($mode === 'throwing') {
        Activity::creating(fn () => throw new RuntimeException('PRIVATE audit failure'));
    }
    try {
        expect(fn () => app(AnalyticsCapabilities::class)->billingSummary($context, BillingSummaryCommand::fromMonth('2024-02')))->toThrow(RuntimeException::class);
        expect(Activity::where('event', 'analytics.billing.read')->count())->toBe(0)
            ->and($f['credential']->fresh()->last_used_at)->toBeNull()
            ->and($document->fresh()->getRawOriginal())->toBe($frozen)
            ->and(DB::connection()->transactionLevel())->toBe(0);
        $lock = Cache::store('database')->lock('billing-query-workspace:'.$f['entity']->workspace_id, 10);
        expect($lock->get())->toBeTrue();
        $lock->release();
    } finally {
        activity()->enableLogging();
        Activity::flushEventListeners();
        Context::forget(['impersonator_id', 'impersonation_session']);
    }
})->with(['human', 'machine', 'support'])->with(['unsaved', 'rollback', 'buffered', 'disabled', 'cancelled', 'throwing'])->with(['GET', 'HEAD']);

test('billing Round 2 actual database cache outage is generic and bodyless for HEAD', function (string $method) {
    config(['app.debug' => true]);
    $f = billingSummaryFixture();
    billingDocument($f);
    Schema::drop('cache');
    $response = $this->withToken($f['secret'])->json($method, $f['url'].'?month=2024-02')->assertServiceUnavailable()
        ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Request-ID');
    expect($response->getContent())->not->toContain('SQLSTATE', 'PRIVATE', $f['secret'], 'cache', '2024-02');
    if ($method === 'HEAD') {
        expect($response->getContent())->toBe('');
    } else {
        $response->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE')->assertJsonMissingPath('data');
    }
    expect(Activity::where('event', 'analytics.billing.read')->count())->toBe(0)->and($f['credential']->fresh()->last_used_at)->toBeNull();
})->with(['GET', 'HEAD']);

test('billing Round 2 effective human quota and mixed principal workspace quota use native identities', function (bool $workspaceLimit) {
    $f = billingSummaryFixture();
    $contexts = [];
    foreach (range(1, $workspaceLimit ? 5 : 1) as $i) {
        $user = User::factory()->create(['two_factor_secret' => 'test', 'two_factor_confirmed_at' => now()]);
        WorkspaceMembership::factory()->create(['user_id' => $user->id, 'workspace_id' => $f['entity']->workspace_id, 'role' => WorkspaceRole::Accountant]);
        $contexts[] = ExecutionContext::resolve($user, $f['entity'], AgtEnvironment::Production, readOnly: true);
    }
    $machine = IntegrationReadContext::authenticate($f['secret'], (string) Str::uuid());
    $counts = [];
    $observations = [];
    $attempts = $workspaceLimit ? 31 : 7;
    foreach (range(0, $attempts - 1) as $i) {
        $context = $workspaceLimit && $i === 30 ? $machine : $contexts[$workspaceLimit ? intdiv($i, 6) : 0];
        $window = intdiv(time(), 60);
        $identity = $context instanceof ExecutionContext ? 'billing-human:'.$context->actorId.':'.$context->workspaceId() : 'billing-integration:'.$context->integrationId;
        $prior = Cache::store('database')->get('external-read:'.hash('sha256', $workspaceLimit ? 'billing-workspace:'.$context->workspaceId() : $identity).':'.$window, 0);
        try {
            app(AnalyticsCapabilities::class)->billingSummary($context, BillingSummaryCommand::fromMonth($i % 2 === 0 ? '2024-02' : '2024-03'));
            $status = 200;
        } catch (HttpException $exception) {
            $status = $exception->getStatusCode();
        }
        $end = intdiv(time(), 60);
        $observations[] = compact('window', 'end', 'prior', 'status');
        if ($window === $end) {
            expect($status)->toBe($prior < ($workspaceLimit ? 30 : 6) ? 200 : 429);
        } else {
            expect($status)->toBeIn([200, 429]);
        }
        foreach (array_unique([$window, $end]) as $observedWindow) {
            $counts[$observedWindow] = Cache::store('database')->get('external-read:'.hash('sha256', $workspaceLimit ? 'billing-workspace:'.$context->workspaceId() : $identity).':'.$observedWindow, 0);
        }
    }
    expect($observations)->toHaveCount($attempts)->and(array_sum($counts))->toBe($attempts);
})->with([true, false]);

test('billing Round 2 native fixed minute permits and records legal boundary bursts', function () {
    $f = billingSummaryFixture();
    $context = IntegrationReadContext::authenticate($f['secret'], (string) Str::uuid());
    $command = BillingSummaryCommand::fromMonth('2024-02');
    while (fmod(microtime(true), 60) < 59 || fmod(microtime(true), 60) > 59.15) {
        usleep(20000);
    }
    $window = intdiv(time(), 60);
    $statuses = [];
    foreach (range(1, 6) as $i) {
        app(AnalyticsCapabilities::class)->billingSummary($context, $command);
        $statuses[] = intdiv(time(), 60);
    }
    expect($statuses)->toBe(array_fill(0, 6, $window));
    while (intdiv(time(), 60) === $window) {
        usleep(10000);
    }
    foreach (range(1, 6) as $i) {
        app(AnalyticsCapabilities::class)->billingSummary($context, $command);
    }
    foreach ([$window, $window + 1] as $observed) {
        expect(Cache::store('database')->get('external-read:'.hash('sha256', 'billing-integration:'.$f['integration']->id).':'.$observed))->toBe(6);
    }
    expect(fn () => app(AnalyticsCapabilities::class)->billingSummary($context, $command))->toThrow(HttpException::class);
    expect(Activity::where('event', 'analytics.billing.read')->count())->toBe(12);
});

test('billing Round 2 base currency and frozen exchange rates cannot relabel native money', function () {
    $f = billingSummaryFixture();
    foreach (BillingSummaryQuery::CURRENCIES as $currency) {
        billingDocument($f, ['currency_code' => $currency, 'exchange_rate_micro' => 987654321]);
    }
    $context = IntegrationReadContext::authenticate($f['secret'], (string) Str::uuid());
    $before = app(BillingSummaryQuery::class)->read($context, BillingSummaryCommand::fromMonth('2024-02'))['currencies'];
    $f['entity']->update(['currency_code' => 'USD']);
    expect(app(BillingSummaryQuery::class)->read($context, BillingSummaryCommand::fromMonth('2024-02'))['currencies'])->toBe($before);
    foreach ($before as $bucket) {
        expect($bucket['invoiced_gross_minor'])->toBe('10000');
    }
});

test('billing Round 2 unknown partial conflicting and stale AGT evidence never changes recorded billing', function (string $classification) {
    $f = billingSummaryFixture();
    $document = billingIssue($f, billingIssueProfile($f));
    $context = IntegrationReadContext::authenticate($f['secret'], (string) Str::uuid());
    $command = BillingSummaryCommand::fromMonth('2024-02');
    $read = fn () => app(BillingSummaryQuery::class)->read($context, $command)['currencies'];
    $before = $read();
    $frozen = $document->fresh()->getRawOriginal();
    if ($classification === 'stale') {
        recordAuthoritativeAgtAcceptance($document);
        expect(AgtStatusPresentation::describe($document->fresh(), CarbonImmutable::now()->addMinutes(20))['freshness'])->toBe('stale')
            ->and(CurrentAgtState::acceptanceEvidenceSatisfied($document->fresh()))->toBeTrue();
    } else {
        $submission = $document->submissions()->firstOrFail();
        $submission->update(['request_id' => sprintf('%015d', $submission->id), 'status' => AgtSubmissionStatus::Valid,
            'attempt_count' => $classification === 'unknown' ? 0 : ($classification === 'partial' ? 1 : 2), 'next_attempt_at' => null]);
        foreach ($classification === 'unknown' ? [] : ($classification === 'partial' ? [1 => 'V'] : [1 => 'V', 2 => 'I']) as $sequence => $status) {
            $request = json_encode(['requestID' => $submission->request_id, 'schemaVersion' => '2.0',
                'taxRegistrationNumber' => $f['entity']->tax_identification_number], JSON_THROW_ON_ERROR);
            $response = json_encode(['resultCode' => $status === 'I' ? '2' : '0', 'requestErrorList' => [],
                'documentStatusList' => [['documentNo' => $document->document_no, 'documentStatus' => $status, 'errorList' => []]]], JSON_THROW_ON_ERROR);
            $submission->attempts()->create(['workspace_id' => $submission->workspace_id, 'legal_entity_id' => $submission->legal_entity_id,
                'operation' => AgtSubmissionAttemptOperation::QueryStatus, 'attempt_number' => $sequence, 'endpoint_path' => '/obterEstado',
                'request_body' => $request, 'request_body_sha256' => $classification === 'partial' ? '' : hash('sha256', $request),
                'response_body' => $response, 'response_body_sha256' => hash('sha256', $response), 'http_status' => 200,
                'safe_message' => 'PRIVATE BILLING POISON', 'started_at' => now(), 'completed_at' => now()]);
        }
        $evidence = $submission->attempts()->orderBy('attempt_number')->get()->map->getRawOriginal()->all();
        AgtReconstruction::rebuild($submission->id);
        expect($submission->fresh()->qualified_projection['classification'])->toBe($classification)
            ->and(CurrentAgtState::acceptanceEvidenceSatisfied($document->fresh()))->toBeFalse();
        $projection = $submission->fresh()->qualified_projection;
        AgtReconstruction::rebuild($submission->id);
        expect($submission->fresh()->qualified_projection)->toBe($projection)
            ->and($submission->attempts()->orderBy('attempt_number')->get()->map->getRawOriginal()->all())->toBe($evidence);
    }
    expect($read())->toBe($before)->and($document->fresh()->getRawOriginal())->toBe($frozen);
    $this->withToken($f['secret'])->getJson($f['url'].'?month=2024-02')->assertOk()->assertJsonPath('data.currencies.0.invoiced_gross_minor', '11400');
})->with(['unknown', 'partial', 'conflicting', 'stale']);

test('billing Round 2 complete error schemas retain GET HEAD redaction parity', function (int $status, string $method) {
    config(['app.debug' => true]);
    $f = billingSummaryFixture();
    $url = $f['url'].'?month=2024-02';
    $secret = $f['secret'];
    match ($status) {
        401 => $secret = 'PRIVATE_INVALID_TOKEN',
        403 => DB::table('integration_credential_scopes')->where('credential_id', $f['credential']->id)->delete(),
        404 => config(['integrations.enabled' => false]),
        422 => $url = $f['url'].'?month=2024-00',
        429 => (function () use ($f) {
            $lock = Cache::store('database')->lock('billing-query-workspace:'.$f['entity']->workspace_id, 10);
            expect($lock->get())->toBeTrue();
        })(),
        500 => (function () use ($f) {
            DB::table('integration_credential_scopes')->where('credential_id', $f['credential']->id)->delete();
            Activity::creating(fn () => false);
        })(),
        503 => billingDocument($f, ['currency_code' => 'XXX']),
    };
    try {
        $response = $this->withToken($secret)->json($method, $url)->assertStatus($status)
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Request-ID');
        billingAssertPublishedHeaders($response, $method);
        expect($response->getContent())->not->toContain('PRIVATE', $f['secret'], 'SQLSTATE', 'customer_name', '2024-02');
        if ($method === 'HEAD') {
            expect($response->getContent())->toBe('');
        } else {
            $spec = json_decode(file_get_contents(base_path('docs/openapi-external-billing-v2.json')), true, flags: JSON_THROW_ON_ERROR);
            expect(billingSchemaMatches($response->json(), $spec['components']['schemas']['Error'.$status], $spec))->toBeTrue();
            $response->assertJsonMissingPath('data');
        }
        expect(Activity::where('event', 'analytics.billing.read')->count())->toBe(0)->and($f['credential']->fresh()->last_used_at)->toBeNull();
    } finally {
        Activity::flushEventListeners();
    }
})->with([401, 403, 404, 422, 429, 500, 503])->with(['GET', 'HEAD']);

test('billing Round 2 draft deletion and issued immutability rejection preserve recorded facts', function () {
    $f = billingSummaryFixture();
    $document = billingIssue($f, billingIssueProfile($f));
    $user = User::findOrFail($f['integration']->sponsor_user_id);
    $draft = app(SaveFiscalDocumentDraft::class)->execute($f['entity'], $user, billingIssueProfile($f));
    $context = IntegrationReadContext::authenticate($f['secret'], (string) Str::uuid());
    $read = fn () => app(BillingSummaryQuery::class)->read($context, BillingSummaryCommand::fromMonth('2024-02'))['currencies'];
    $before = $read();
    $frozen = $document->fresh()->getRawOriginal();
    $draft->delete();
    expect($read())->toBe($before)->and(fn () => $document->delete())->toThrow(DomainException::class)
        ->and(fn () => DB::transaction(fn () => $document->update(['document_date' => '2024-03-01'])))->toThrow(DomainException::class);
    expect($read())->toBe($before)->and($document->fresh()->getRawOriginal())->toBe($frozen);
});

test('billing Round 2 request rejection invalidity and processing cancellation preserve fiscal billing only', function (string $outcome) {
    $f = billingSummaryFixture();
    $document = billingIssue($f, billingIssueProfile($f));
    $context = IntegrationReadContext::authenticate($f['secret'], (string) Str::uuid());
    $command = BillingSummaryCommand::fromMonth('2024-02');
    $read = fn () => app(BillingSummaryQuery::class)->read($context, $command)['currencies'];
    $before = $read();
    $frozen = $document->fresh()->getRawOriginal();
    $submission = $document->submissions()->firstOrFail();
    $submission->update(['request_id' => sprintf('%015d', $submission->id), 'status' => AgtSubmissionStatus::Received, 'next_attempt_at' => null]);
    $claim = AgtSubmissionExecution::claim($submission->id, AgtSubmissionAttemptOperation::QueryStatus);
    expect($claim)->not->toBeNull();
    $request = json_encode(['requestID' => $submission->request_id, 'schemaVersion' => '2.0',
        'taxRegistrationNumber' => $f['entity']->tax_identification_number], JSON_THROW_ON_ERROR);
    $code = $outcome === 'cancelled' ? '9' : '2';
    $response = json_encode(['resultCode' => $code, 'requestErrorList' => $outcome === 'rejected' ? ['PRIVATE REJECTION'] : [],
        'documentStatusList' => [['documentNo' => $document->document_no, 'documentStatus' => 'I', 'errorList' => []]]], JSON_THROW_ON_ERROR);
    $result = new AgtInvoiceStatusResult($outcome !== 'rejected', false, '/obterEstado', 200,
        $request, hash('sha256', $request), $response, hash('sha256', $response), $code,
        $outcome === 'rejected' ? ['PRIVATE REJECTION'] : [], [], 'PRIVATE BILLING POISON', 1);
    AgtSubmissionExecution::complete($claim, $result, fn ($locked) => $locked->update([
        'status' => AgtSubmissionStatus::from($outcome), 'next_attempt_at' => null,
    ]));
    expect(CurrentAgtState::acceptanceEvidenceSatisfied($document->fresh()))->toBeFalse()
        ->and(CurrentAgtState::projection($submission->fresh())['reported_state'])->toBe(match ($outcome) {
            'cancelled' => 'processing_cancelled', 'invalid' => 'invalid', default => null,
        })->and($read())->toBe($before)->and($document->fresh()->getRawOriginal())->toBe($frozen);
    $this->withToken($f['secret'])->getJson($f['url'].'?month=2024-02')->assertOk()->assertJsonPath('data.currencies.0.invoiced_gross_minor', '11400');
})->with(['rejected', 'invalid', 'cancelled']);
