<?php

use App\Exceptions\TenantAiStorageUnavailable;
use App\Fiscal\TenantAiEnvelope;
use App\Fiscal\TenantAiKeyFile;
use App\Fiscal\TenantAiStorage;
use App\Models\TenantAiCredential;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;
use Symfony\Component\VarDumper\VarDumper;

require_once __DIR__.'/../TenantAiFixtures.php';

beforeEach(function () {
    Http::preventStrayRequests();
    tenantAiRoot();
    $this->custodyPaths = [];
    $this->custodyRoot = storage_path('framework/testing/custody-'.Str::uuid());
    mkdir($this->custodyRoot, 0700, true);
    $this->custodyPaths[] = $this->custodyRoot;
    $this->custodyKey = custodySyntheticFile($this, $this->custodyRoot.'/key');
    config(['tenant_ai.kek_root' => realpath($this->custodyRoot), 'tenant_ai.kek_version' => 'custody-test-version',
        'tenant_ai.kek_files' => ['custody-test-version' => $this->custodyKey]]);
});

afterEach(function () {
    foreach (array_reverse($this->custodyPaths) as $path) {
        if (is_dir($path) && ! is_link($path)) {
            rmdir($path);
        } else {
            unlink($path);
        }
    }
    Http::assertNothingSent();
});

function custodySyntheticFile(object $test, string $path): string
{
    file_put_contents($path, random_bytes(32));
    chmod($path, 0600);
    $test->custodyPaths[] = $path;

    return $path;
}

function custodyReadPath(string $path): string
{
    config(['tenant_ai.kek_files' => ['custody-test-version' => $path]]);

    return app(TenantAiKeyFile::class)->read('custody-test-version');
}

test('actual Symfony and cached Laravel dumps redact persisted model and associated envelope bytes', function () {
    $fixture = tenantAiFixture();
    $secret = 'synthetic-'.bin2hex(random_bytes(24));
    $cloner = new VarCloner;
    $cloner->setMaxString(-1);
    $cloner->setMaxItems(-1);
    $dumper = new CliDumper;
    $dumper->setColors(false);

    app(TenantAiStorage::class)->configure($fixture['context'], $secret);
    $model = TenantAiCredential::firstOrFail();
    $raw = (array) DB::table('tenant_ai_credentials')->first();
    // Reconstruct exactly the associated persisted envelope through its private constructor, for inspection only.
    $reflection = new ReflectionClass(TenantAiEnvelope::class);
    $envelope = $reflection->newInstanceWithoutConstructor();
    $reflection->getConstructor()->invoke($envelope, $raw['secret_ciphertext'], $raw['wrapped_dek'], $raw['kek_version']);
    $kek = file_get_contents($this->custodyKey);
    $wrapped = json_decode((new Encrypter($kek, 'aes-256-gcm'))->decryptString($raw['wrapped_dek']), true, flags: JSON_THROW_ON_ERROR);
    $markers = [$secret, substr($secret, 10, 24), $kek, base64_encode($kek), $wrapped['dek'], base64_decode($wrapped['dek']),
        $raw['secret_ciphertext'], $raw['wrapped_dek'], $raw['kek_version']];
    foreach (['secret_ciphertext', 'wrapped_dek'] as $column) {
        $parts = json_decode(base64_decode($raw[$column]), true, flags: JSON_THROW_ON_ERROR);
        array_push($markers, $parts['iv'], $parts['tag'], $parts['value']);
    }
    // Positive control ensures the cloner does not truncate or silently omit the attack markers.
    $control = $dumper->dump($cloner->cloneVar([$raw['secret_ciphertext'], $raw['wrapped_dek']]), true);
    expect($control)->toContain($raw['secret_ciphertext'])->toContain($raw['wrapped_dek']);

    $outputs = [];
    foreach ([$model, $envelope, ['model' => $model, 'envelope' => $envelope]] as $value) {
        $outputs[] = $dumper->dump($cloner->cloneVar($value), true);
    }
    $outputs[] = $model->toJson();
    $outputs[] = json_encode($model->toArray());
    $outputs[] = json_encode(new JsonResource($model));
    $outputs[] = print_r($model, true);
    $outputs[] = print_r($envelope, true);

    // Exercise the already-registered Laravel handler and its cached cloner, redirecting only its output sink.
    $handler = VarDumper::setHandler(null);
    VarDumper::setHandler($handler);
    expect($handler)->toBeInstanceOf(Closure::class);
    $captured = (new ReflectionFunction($handler))->getStaticVariables();
    expect($captured['dumper'])->toBeInstanceOf(Illuminate\Foundation\Console\CliDumper::class);
    $outputProperty = new ReflectionProperty($captured['dumper'], 'output');
    $originalOutput = $outputProperty->getValue($captured['dumper']);
    $buffer = new BufferedOutput;
    try {
        $outputProperty->setValue($captured['dumper'], $buffer);
        dump($model, $envelope);
        $outputs[] = $buffer->fetch();
    } finally {
        $outputProperty->setValue($captured['dumper'], $originalOutput);
    }
    foreach ($outputs as $bytes) {
        expect($bytes)->not->toBeEmpty();
        foreach ($markers as $marker) {
            expect(str_contains($bytes, $marker))->toBeFalse();
        }
    }
    expect(fn () => serialize($model))->toThrow(TenantAiStorageUnavailable::class);
    expect(fn () => serialize($envelope))->toThrow(TenantAiStorageUnavailable::class);
    $matched = false;
    app(TenantAiStorage::class)->inspectForStorageTest($fixture['context'], $raw['id'], function ($actual) use ($secret, &$matched) {
        $matched = hash_equals($secret, $actual);
    });
    expect($matched)->toBeTrue();
});

test('assigned hidden model attributes and originals are opaque to Symfony', function () {
    $marker = 'synthetic-'.bin2hex(random_bytes(24));
    $model = new TenantAiCredential;
    $model->forceFill(['secret_ciphertext' => $marker, 'wrapped_dek' => $marker, 'kek_version' => $marker])->syncOriginal();
    $model->secret_ciphertext = $marker.'-changed';
    $model->syncChanges();
    $bytes = (new CliDumper)->dump((new VarCloner)->cloneVar($model), true);
    expect(str_contains($bytes, $marker))->toBeFalse();
});

test('approved private custody root reads the exact synthetic key', function () {
    expect(hash_equals(file_get_contents($this->custodyKey), custodyReadPath($this->custodyKey)))->toBeTrue();
});

test('webroot and served storage cannot become approved custody roots', function (string $location) {
    $root = match ($location) {
        'public' => public_path(),
        'published-storage' => storage_path('app/public'),
        'signed-served-storage' => storage_path('app/private'),
    };
    $path = custodySyntheticFile($this, $root.'/custody-'.Str::uuid());
    expect(fn () => custodyReadPath($path))->toThrow(TenantAiStorageUnavailable::class);
    config(['tenant_ai.kek_root' => realpath($root)]);
    expect(fn () => custodyReadPath($path))->toThrow(TenantAiStorageUnavailable::class);
})->with(['public', 'published-storage', 'signed-served-storage']);

test('effective public roots and configured served disks and link targets deny custody', function (string $kind) {
    if ($kind === 'effective-public') {
        app()->usePublicPath($this->custodyRoot);
    } elseif ($kind === 'published-link') {
        config(['filesystems.links' => [public_path('custom') => $this->custodyRoot]]);
    } else {
        config(['filesystems.disks.custom' => ['driver' => 'local', 'root' => $this->custodyRoot, ...match ($kind) {
            'served-disk' => ['serve' => true], 'public-disk' => ['visibility' => 'public'], 'url-disk' => ['url' => '/custom'],
        }]]);
    }
    expect(fn () => custodyReadPath($this->custodyKey))->toThrow(TenantAiStorageUnavailable::class);
})->with(['effective-public', 'published-link', 'served-disk', 'public-disk', 'url-disk']);

test('custody rejects path traversal sibling prefix symlinks and missing files', function (string $attack) {
    $path = match ($attack) {
        'traversal' => $this->custodyRoot.'/../'.basename($this->custodyRoot).'/key',
        'missing' => $this->custodyRoot.'/missing',
        default => $this->custodyRoot.'/alias',
    };
    if ($attack === 'sibling-prefix') {
        $sibling = $this->custodyRoot.'-evil';
        mkdir($sibling, 0700);
        $this->custodyPaths[] = $sibling;
        $path = custodySyntheticFile($this, $sibling.'/key');
    } elseif ($attack === 'private-to-public') {
        $target = custodySyntheticFile($this, public_path('custody-'.Str::uuid()));
        symlink($target, $path);
        $this->custodyPaths[] = $path;
    } elseif ($attack === 'public-to-private') {
        $path = public_path('custody-'.Str::uuid());
        symlink($this->custodyKey, $path);
        $this->custodyPaths[] = $path;
    } elseif ($attack === 'private-to-private') {
        symlink($this->custodyKey, $path);
        $this->custodyPaths[] = $path;
    }
    expect(fn () => custodyReadPath($path))->toThrow(TenantAiStorageUnavailable::class);
})->with(['traversal', 'missing', 'sibling-prefix', 'private-to-public', 'public-to-private', 'private-to-private']);

test('custody fails closed for missing invalid noncanonical or overly broad roots', function (string $kind) {
    $root = match ($kind) {
        'unset' => null, 'relative' => 'keys', 'missing' => $this->custodyRoot.'/missing',
        'file' => $this->custodyKey, 'ancestor-of-webroot' => base_path(), 'filesystem-root' => '/',
        'traversal' => $this->custodyRoot.'/../'.basename($this->custodyRoot), 'trailing-slash' => $this->custodyRoot.'/',
        'nul' => $this->custodyRoot."\0", 'symlink' => $this->custodyRoot.'-alias',
    };
    if ($kind === 'symlink') {
        symlink($this->custodyRoot, $root);
        $this->custodyPaths[] = $root;
    }
    config(['tenant_ai.kek_root' => $root]);
    expect(fn () => custodyReadPath($this->custodyKey))->toThrow(TenantAiStorageUnavailable::class);
})->with(['unset', 'relative', 'missing', 'file', 'ancestor-of-webroot', 'filesystem-root', 'traversal', 'trailing-slash', 'nul', 'symlink']);

test('existing permissions size regular-file and version protections remain enforced', function (string $attack) {
    $path = $this->custodyKey;
    if ($attack === 'file-mode') {
        chmod($path, 0644);
    } elseif ($attack === 'parent-mode') {
        chmod($this->custodyRoot, 0777);
    } elseif ($attack === 'short' || $attack === 'long') {
        file_put_contents($path, random_bytes($attack === 'short' ? 31 : 33));
    } elseif ($attack === 'directory') {
        $path = $this->custodyRoot.'/directory';
        mkdir($path, 0700);
        $this->custodyPaths[] = $path;
    }
    if ($attack === 'version') {
        expect(fn () => app(TenantAiKeyFile::class)->read('unknown'))->toThrow(TenantAiStorageUnavailable::class);
    } else {
        expect(fn () => custodyReadPath($path))->toThrow(TenantAiStorageUnavailable::class);
    }
})->with(['file-mode', 'parent-mode', 'short', 'long', 'directory', 'version']);

test('configured served aliases and not-yet-created served roots remain excluded', function (bool $alias) {
    $target = $this->custodyRoot.'/not-created-yet';
    if ($alias) {
        $target = $this->custodyRoot.'-served-alias';
        symlink($this->custodyRoot, $target);
        $this->custodyPaths[] = $target;
    }
    config(['filesystems.links' => [public_path('custom') => $target]]);
    expect(fn () => custodyReadPath($this->custodyKey))->toThrow(TenantAiStorageUnavailable::class);
})->with([false, true]);
