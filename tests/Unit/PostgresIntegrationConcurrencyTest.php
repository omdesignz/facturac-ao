<?php

use App\AgtEnvironment;
use App\Fiscal\CatalogueCapabilities;
use App\Fiscal\CatalogueListCommand;
use App\Fiscal\CustomerCapabilities;
use App\Fiscal\CustomerListCommand;
use App\Fiscal\DocumentCapabilities;
use App\Fiscal\DocumentReadContext;
use App\Fiscal\IntegrationCredentials;
use App\Fiscal\IntegrationManagementContext;
use App\Fiscal\IntegrationRateLimiter;
use App\Fiscal\IntegrationReadContext;
use App\Fiscal\IssuedIntegrationCredential;
use App\Models\CatalogueItem;
use App\Models\Customer;
use App\Models\FiscalDocument;
use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class)->group('postgresql');

beforeEach(function () {
    if (getenv('FISCAL_PG_GATE') !== '1') {
        $this->markTestSkipped('Mandatory separate PostgreSQL integration gate.');
    }
    expect(app()->environment())->toBe('testing');
    expect(DB::getDriverName())->toBe('pgsql');
    expect(str_starts_with(DB::connection()->getDatabaseName(), 'facturac_test_'))->toBeTrue();
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    config(['integrations.enabled' => true]);
});

/** @return array{context: IntegrationManagementContext, issued: IssuedIntegrationCredential, secret: string, integration: Integration} */
function pgIntegrationFixture(AgtEnvironment $environment = AgtEnvironment::Homologation, array $scopes = ['documents:read']): array
{
    $user = User::factory()->withWorkspace()->create();
    $user->forceFill(['two_factor_secret' => 'test', 'two_factor_confirmed_at' => now()])->save();
    $entity = LegalEntity::factory()->configured()->create(['workspace_id' => $user->current_workspace_id]);
    $request = Request::create('https://localhost/');
    $request->setUserResolver(fn () => $user);
    $request->setLaravelSession(app('session')->driver());
    $request->session()->put((string) config('work_session.started_at_key'), time());
    $request->session()->put('auth.password_confirmed_at', time());
    $context = IntegrationManagementContext::resolve($request, $entity->workspace_id);
    $issued = app(IntegrationCredentials::class)->create($context, $entity->public_id, $environment, 'Concurrency', $scopes);
    $secret = $issued->revealOnce();
    $integration = Integration::where('public_id', $issued->integrationPublicId)->firstOrFail();

    return compact('context', 'issued', 'secret', 'integration');
}

/** @return list<array<string, mixed>> */
function pgIntegrationContend(Closure $operation, int $workers = 3): array
{
    $directory = sys_get_temp_dir().'/facturac-contention-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    DB::purge();
    $pids = [];
    try {
        for ($i = 0; $i < $workers; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Could not fork test worker');
            }
            if ($pid === 0) {
                DB::purge();
                file_put_contents("$directory/ready-$i", 'ready');
                $deadline = microtime(true) + 20;
                while (! file_exists("$directory/start")) {
                    if (microtime(true) > $deadline) {
                        exit(2);
                    }
                    usleep(1000);
                }
                try {
                    $result = ['ok' => true, 'value' => $operation($i)];
                } catch (Throwable $exception) {
                    $result = ['ok' => false, 'error' => $exception::class, 'message' => $exception->getMessage()];
                }
                file_put_contents("$directory/result-$i", json_encode($result, JSON_THROW_ON_ERROR));
                exit(0);
            }
            $pids[] = $pid;
        }
        $deadline = microtime(true) + 30;
        while (count(glob("$directory/ready-*")) !== $workers) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Workers did not reach barrier');
            }
            usleep(1000);
        }
        file_put_contents("$directory/start", 'start');
        foreach ($pids as $pid) {
            while (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Worker timed out');
                }
                usleep(1000);
            }
            expect(pcntl_wexitstatus($status))->toBe(0);
        }

        return array_map(fn (int $i): array => json_decode(file_get_contents("$directory/result-$i"), true, flags: JSON_THROW_ON_ERROR), range(0, $workers - 1));
    } finally {
        foreach ($pids as $pid) {
            if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                posix_kill($pid, SIGTERM);
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

test('postgres concurrent rotation commits one revision and immutable credential creator', function () {
    $f = pgIntegrationFixture();
    $results = pgIntegrationContend(fn () => app(IntegrationCredentials::class)->rotate($f['context'], $f['issued']->integrationPublicId, 1, $f['issued']->credentialPublicId)->credentialPublicId);
    expect(count(array_filter($results, fn (array $result): bool => $result['ok'])))->toBe(1);
    expect(IntegrationCredential::count())->toBe(2)->and($f['integration']->fresh()->revision)->toBe(2)
        ->and(Activity::where('event', 'integration.rotated')->count())->toBe(1);
});

test('postgres rotation and revocation serialize and stale writes never restore authority', function () {
    $f = pgIntegrationFixture();
    $results = pgIntegrationContend(function (int $i) use ($f): string {
        if ($i === 0) {
            app(IntegrationCredentials::class)->rotate($f['context'], $f['issued']->integrationPublicId, 1, $f['issued']->credentialPublicId);
        } else {
            app(IntegrationCredentials::class)->revoke($f['context'], $f['issued']->integrationPublicId, 1);
        }

        return 'committed';
    }, 2);
    expect(count(array_filter($results, fn (array $result): bool => $result['ok'])))->toBe(1)->and($f['integration']->fresh()->revision)->toBe(2);
    app(IntegrationCredentials::class)->revoke($f['context'], $f['issued']->integrationPublicId, 2);
    expect(fn () => IntegrationReadContext::authenticate($f['secret'], (string) Str::uuid()))->toThrow(HttpException::class);
});

test('postgres committed revocation denies final authorization while a preceding authorized read may finish', function () {
    $f = pgIntegrationFixture();
    $document = FiscalDocument::factory()->create(['workspace_id' => $f['integration']->workspace_id, 'legal_entity_id' => $f['integration']->legal_entity_id]);
    $context = IntegrationReadContext::authenticate($f['secret'], (string) Str::uuid());
    $results = pgIntegrationContend(fn () => app(IntegrationCredentials::class)->revoke($f['context'], $f['issued']->integrationPublicId, 1), 1);
    expect($results[0]['ok'])->toBeTrue();
    expect(fn () => app(DocumentCapabilities::class)->read($context, $document->public_id))->toThrow(HttpException::class);

    $f = pgIntegrationFixture();
    $document = FiscalDocument::factory()->create(['workspace_id' => $f['integration']->workspace_id, 'legal_entity_id' => $f['integration']->legal_entity_id]);
    $inner = IntegrationReadContext::authenticate($f['secret'], (string) Str::uuid());
    $afterSnapshot = fn () => pgIntegrationContend(fn () => app(IntegrationCredentials::class)->revoke($f['context'], $f['issued']->integrationPublicId, 1), 1);
    $context = new class($inner, $afterSnapshot) implements DocumentReadContext
    {
        public function __construct(private IntegrationReadContext $inner, private Closure $afterSnapshot) {}

        public function authorize(string $permission): void
        {
            $this->inner->authorize($permission);
            ($this->afterSnapshot)();
        }

        public function workspaceId(): int
        {
            return $this->inner->workspaceId();
        }

        public function legalEntityId(): int
        {
            return $this->inner->legalEntityId();
        }

        public function environment(): AgtEnvironment
        {
            return $this->inner->environment();
        }

        public function correlationId(): string
        {
            return $this->inner->correlationId();
        }

        public function audit(): array
        {
            return $this->inner->audit();
        }

        public function auditCauser(): Model
        {
            return $this->inner->auditCauser();
        }

        public function recordSuccessfulUse(): void
        {
            $this->inner->recordSuccessfulUse();
        }
    };
    $read = app(DocumentCapabilities::class)->read($context, $document->public_id);
    expect($read['public_id'])->toBe($document->public_id)->and($f['integration']->fresh()->revoked_at)->not->toBeNull();
    expect(fn () => $inner->authorize('documents.read'))->toThrow(HttpException::class);
});

test('postgres monotonic last use cannot undo concurrent credential revocation', function () {
    $f = pgIntegrationFixture();
    $context = IntegrationReadContext::authenticate($f['secret'], (string) Str::uuid());
    $results = pgIntegrationContend(function (int $i) use ($f, $context): void {
        if ($i === 0) {
            app(IntegrationCredentials::class)->revoke($f['context'], $f['issued']->integrationPublicId, 1, 'compromise', $f['issued']->credentialPublicId);
        } else {
            $context->recordSuccessfulUse();
        }
    });
    expect(array_column($results, 'ok'))->toBe([true, true, true]);
    $credential = IntegrationCredential::firstOrFail();
    expect($credential->revoked_at)->not->toBeNull()->and($credential->last_used_at)->not->toBeNull();
    expect(fn () => $context->authorize('documents.read'))->toThrow(HttpException::class);
});

test('postgres atomic shared quotas count all independent workers and workspace buckets', function () {
    $results = pgIntegrationContend(function (): int {
        $accepted = 0;
        for ($i = 0; $i < 25; $i++) {
            try {
                app(IntegrationRateLimiter::class)->consume(['integration:one' => 60, 'workspace:one' => 300]);
                $accepted++;
            } catch (HttpException $e) {
                if ($e->getStatusCode() !== 429) {
                    throw $e;
                }
            }
        }

        return $accepted;
    });
    expect(array_column($results, 'ok'))->toBe([true, true, true]);
    expect(array_sum(array_column($results, 'value')))->toBe(60);
});

test('postgres composite ownership and replacement constraints reject foreign relationships', function () {
    $f = pgIntegrationFixture();
    $other = pgIntegrationFixture();
    expect(fn () => DB::transaction(fn () => DB::table('integrations')->where('id', $f['integration']->id)->update(['legal_entity_id' => $other['integration']->legal_entity_id])))
        ->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('integration_credentials')->where('integration_id', $f['integration']->id)->update(['replaces_credential_id' => IntegrationCredential::where('integration_id', $other['integration']->id)->value('id')])))
        ->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('integrations')->where('id', $f['integration']->id)->update(['sponsor_user_id' => $other['integration']->sponsor_user_id])))
        ->toThrow(QueryException::class);
});

test('postgres shared workspace quota spans independent integration identities', function () {
    $results = pgIntegrationContend(function (int $worker): int {
        $accepted = 0;
        for ($i = 0; $i < 110; $i++) {
            try {
                app(IntegrationRateLimiter::class)->consume(['integration:'.$worker => 1000, 'workspace:shared' => 300]);
                $accepted++;
            } catch (HttpException $e) {
                if ($e->getStatusCode() !== 429) {
                    throw $e;
                }
            }
        }

        return $accepted;
    });
    expect(array_column($results, 'ok'))->toBe([true, true, true])->and(array_sum(array_column($results, 'value')))->toBe(300);
});

test('postgres inserts reject foreign composite ownership and cross-integration replacement', function () {
    $f = pgIntegrationFixture();
    $other = pgIntegrationFixture();
    expect(fn () => DB::transaction(fn () => Integration::factory()->create(['workspace_id' => $f['integration']->workspace_id, 'legal_entity_id' => $other['integration']->legal_entity_id])))
        ->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => IntegrationCredential::factory()->create(['integration_id' => $f['integration']->id, 'replaces_credential_id' => IntegrationCredential::where('integration_id', $other['integration']->id)->value('id')])))
        ->toThrow(QueryException::class);
});

test('postgres repeated concurrent revocations commit exactly one terminal transition', function () {
    $f = pgIntegrationFixture();
    $results = pgIntegrationContend(fn () => app(IntegrationCredentials::class)->revoke($f['context'], $f['issued']->integrationPublicId, 1, 'compromise'));
    expect(array_column($results, 'ok'))->toBe([true, true, true])
        ->and($f['integration']->fresh()->revision)->toBe(2)
        ->and(Activity::where('event', 'integration.revoked')->count())->toBe(1);
    expect(fn () => IntegrationReadContext::authenticate($f['secret'], (string) Str::uuid()))->toThrow(HttpException::class);
});

test('postgres master authority sees independently committed withdrawal before final snapshot', function (string $change, string $kind) {
    $f = pgIntegrationFixture(AgtEnvironment::Production, ['customers:read', 'catalogue:read']);
    $context = IntegrationReadContext::authenticate($f['secret'], (string) Str::uuid());
    $results = pgIntegrationContend(function () use ($f, $change): void {
        if ($change === 'revocation') {
            app(IntegrationCredentials::class)->revoke($f['context'], $f['integration']->public_id, 1);
        } elseif ($change === 'grant') {
            app(IntegrationCredentials::class)->reduceGrant($f['context'], $f['integration']->public_id, 1);
        } else {
            WorkspaceMembership::whereKey($f['integration']->sponsor_membership_id)->update(['is_active' => false]);
        }
    }, 1);
    expect($results[0]['ok'])->toBeTrue();
    expect(fn () => $kind === 'customers'
        ? app(CustomerCapabilities::class)->listCustomers($context, CustomerListCommand::fromInput([]))
        : app(CatalogueCapabilities::class)->listItems($context, CatalogueListCommand::fromInput([])))->toThrow(HttpException::class);
    expect(Activity::whereIn('event', ['customers.list', 'catalogue.list'])->count())->toBe(0);
})->with(['revocation', 'grant', 'sponsor'])->with(['customers', 'catalogue']);

test('postgres master read already authorized can finish while subsequent checks deny', function (string $kind) {
    $f = pgIntegrationFixture(AgtEnvironment::Production, ['customers:read', 'catalogue:read']);
    $inner = IntegrationReadContext::authenticate($f['secret'], (string) Str::uuid());
    $context = new class($inner, $f) implements DocumentReadContext
    {
        public function __construct(private IntegrationReadContext $inner, private array $fixture) {}

        public function authorize(string $permission): void
        {
            $this->inner->authorize($permission);
            $f = $this->fixture;
            $result = pgIntegrationContend(fn () => app(IntegrationCredentials::class)->revoke($f['context'], $f['integration']->public_id, 1), 1);
            expect($result[0]['ok'])->toBeTrue();
        }

        public function workspaceId(): int
        {
            return $this->inner->workspaceId();
        }

        public function legalEntityId(): int
        {
            return $this->inner->legalEntityId();
        }

        public function environment(): AgtEnvironment
        {
            return $this->inner->environment();
        }

        public function correlationId(): string
        {
            return $this->inner->correlationId();
        }

        public function audit(): array
        {
            return $this->inner->audit();
        }

        public function auditCauser(): Model
        {
            return $this->inner->auditCauser();
        }

        public function recordSuccessfulUse(): void
        {
            $this->inner->recordSuccessfulUse();
        }
    };
    $page = $kind === 'customers' ? app(CustomerCapabilities::class)->listCustomers($context, CustomerListCommand::fromInput([]))
        : app(CatalogueCapabilities::class)->listItems($context, CatalogueListCommand::fromInput([]));
    expect($page->count())->toBe(0)->and($f['integration']->fresh()->revoked_at)->not->toBeNull();
    expect(fn () => $inner->authorize($kind === 'customers' ? 'customers.read' : 'catalogue.read'))->toThrow(HttpException::class);
})->with(['customers', 'catalogue']);

// Both resource families share the same process-safe limiter rather than per-route buckets.
test('postgres alternating resource workers share integration quota after rotation', function () {
    $this->freezeTime();
    $f = pgIntegrationFixture(AgtEnvironment::Production, ['documents:read', 'customers:read', 'catalogue:read']);
    $next = app(IntegrationCredentials::class)->rotate($f['context'], $f['integration']->public_id, 1, $f['issued']->credentialPublicId, ['documents:read', 'customers:read', 'catalogue:read']);
    $secrets = [$f['secret'], $next->revealOnce()];
    $entity = LegalEntity::findOrFail($f['integration']->legal_entity_id);
    $base = '/api/integrations/v1/workspaces/'.$entity->workspace->public_id.'/legal-entities/'.$entity->public_id.'/environments/production/';
    $results = pgIntegrationContend(function (int $worker) use ($base, $secrets): int {
        $accepted = 0;
        $kernel = app(Kernel::class);
        for ($i = 0; $i < 25; $i++) {
            $request = Request::create('https://localhost'.$base.['documents', 'customers', 'catalogue-items'][($i + $worker) % 3], $i % 2 === 0 ? 'GET' : 'HEAD');
            $request->headers->set('Authorization', 'Bearer '.$secrets[$worker % 2]);
            $response = $kernel->handle($request);
            if ($response->getStatusCode() === 200) {
                $accepted++;
            } else {
                expect($response->getStatusCode())->toBe(429);
            }
        }

        return $accepted;
    });
    expect(array_column($results, 'ok'))->toBe([true, true, true])->and(array_sum(array_column($results, 'value')))->toBe(60);
});

test('postgres master query plans are measured against scoped representative rows', function () {
    $f = pgIntegrationFixture(AgtEnvironment::Production, ['customers:read', 'catalogue:read']);
    $sibling = LegalEntity::factory()->create(['workspace_id' => $f['integration']->workspace_id]);
    $rows = [];
    $itemRows = [];
    for ($i = 0; $i < 20000; $i++) {
        $entityId = $i < 1000 ? $f['integration']->legal_entity_id : $sibling->id;
        $common = ['public_id' => strtolower((string) Str::ulid()), 'workspace_id' => $f['integration']->workspace_id, 'legal_entity_id' => $entityId, 'name' => 'Plano '.$i];
        $rows[] = [...$common, 'tax_identification_number' => 'PLAN-'.$i];
        $itemRows[] = [...$common, 'public_id' => strtolower((string) Str::ulid()), 'code' => 'PLAN-'.$i, 'type' => 'service'];
        if (count($rows) === 200) {
            DB::table('customers')->insert($rows);
            DB::table('catalogue_items')->insert($itemRows);
            $rows = [];
            $itemRows = [];
        }
    }
    $plans = [];
    foreach (['customers', 'catalogue_items'] as $table) {
        DB::statement("ANALYZE $table");
        foreach (['list' => "SELECT id, public_id, name FROM $table WHERE workspace_id = ? AND legal_entity_id = ? AND is_active = true ORDER BY id DESC LIMIT 25",
            'count' => "SELECT count(*) FROM $table WHERE workspace_id = ? AND legal_entity_id = ? AND is_active = true",
            'search' => "SELECT id, public_id, name FROM $table WHERE workspace_id = ? AND legal_entity_id = ? AND is_active = true AND strpos(name, ?) > 0 ORDER BY id DESC LIMIT 25"] as $operation => $sql) {
            $bindings = [$f['integration']->workspace_id, $f['integration']->legal_entity_id, ...($operation === 'search' ? ['Plano'] : [])];
            $plan = json_decode(DB::select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$sql, $bindings)[0]->{'QUERY PLAN'}, true, flags: JSON_THROW_ON_ERROR)[0];
            $plans[$table][$operation] = $plan;
            expect((int) $plan['Plan']['Actual Rows'])->toBe($operation === 'count' ? 1 : 25);
        }
    }
    $captured = [];
    $observe = true;
    DB::connection()->beforeExecuting(function ($sql, $bindings) use (&$captured, &$observe): void {
        if ($observe && preg_match('/from "(customers|catalogue_items)"/i', $sql, $matches)) {
            $captured[$matches[1]][] = ['sql' => $sql, 'bindings' => $bindings];
        }
    });
    try {
        $context = IntegrationReadContext::authenticate($f['secret'], (string) Str::uuid());
        foreach ([[], ['q' => 'Plano']] as $input) {
            expect(app(CustomerCapabilities::class)->listCustomers($context, CustomerListCommand::fromInput($input))->total())->toBe(1000);
            expect(app(CatalogueCapabilities::class)->listItems($context, CatalogueListCommand::fromInput($input))->total())->toBe(1000);
        }
    } finally {
        $observe = false;
    }
    foreach ($captured as $table => $queries) {
        expect($queries)->toHaveCount(4);
        foreach ($queries as $index => $query) {
            $operation = ['actual_count', 'actual_list', 'actual_search_count', 'actual_search'][$index];
            $plan = json_decode(DB::select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$query['sql'], $query['bindings'])[0]->{'QUERY PLAN'}, true, flags: JSON_THROW_ON_ERROR)[0];
            expect((int) $plan['Plan']['Actual Rows'])->toBe($index % 2 === 0 ? 1 : 25);
            $plans[$table][$operation] = ['query' => $query['sql'], ...$plan];
        }
    }
    expect($captured['catalogue_items'][3]['sql'])->toContain('(strpos(name, ?) > 0 or strpos(code, ?) > 0)');
    file_put_contents(sys_get_temp_dir().'/facturac-phase4a-query-plans.json', json_encode($plans, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    expect(Customer::count())->toBe(20000)->and(CatalogueItem::count())->toBe(20000);
});
