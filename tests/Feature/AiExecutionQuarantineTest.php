<?php

use App\Exceptions\AiExecutionDisabled;
use App\Fiscal\AssistantPlanner;
use App\Fiscal\UnavailableAssistantPlanner;
use App\Providers\AppServiceProvider;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\AiManager;
use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\Audio;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Image;
use Laravel\Ai\Jobs\InvokeAgent;
use Laravel\Ai\Reranking;
use Laravel\Ai\Stores;
use Laravel\Ai\Transcription;
use Symfony\Component\Process\Process;

use function Laravel\Ai\agent;

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
});

test('SDK inactive configuration discovery macros storage and reporting stay quarantined', function () {
    expect(require base_path('config/ai.php'))->toBe([
        'default' => null, 'default_for_images' => null, 'default_for_audio' => null,
        'default_for_transcription' => null, 'default_for_embeddings' => null, 'default_for_reranking' => null,
        'providers' => [], 'caching' => ['embeddings' => ['cache' => false]], 'conversations' => ['generate_title' => false],
    ]);
    $manifest = app(PackageManifest::class);
    expect($manifest->providers())->not->toContain(AiServiceProvider::class);
    expect(app()->getLoadedProviders())->not->toHaveKey(AiServiceProvider::class);
    expect(app()->bound(ConversationStore::class))->toBeFalse();
    expect(Str::hasMacro('summarize'))->toBeFalse()->and(Str::hasMacro('toEmbeddings'))->toBeFalse()
        ->and(Collection::hasMacro('rerank'))->toBeFalse();
    expect(app(AssistantPlanner::class))->toBeInstanceOf(UnavailableAssistantPlanner::class);
    expect(app(ExceptionHandler::class)->shouldReport(new AiExecutionDisabled))->toBeFalse();
    expect((new AiExecutionDisabled)->getPrevious())->toBeNull();
    expect(glob(database_path('migrations/*agent_conversation*')))->toBe([]);
});

test('SDK ordinary entry paths refuse execution before transport regardless of populated configuration', function (string $path, bool $enabled) {
    config(['assistant.enabled' => $enabled, 'ai.default' => 'openai', 'ai.default_for_images' => 'openai',
        'ai.default_for_audio' => 'openai', 'ai.default_for_transcription' => 'openai', 'ai.default_for_embeddings' => 'openai',
        'ai.default_for_reranking' => 'cohere', 'ai.providers' => [
            'openai' => ['driver' => 'openai', 'key' => 'synthetic-not-a-secret', 'url' => 'https://inference.invalid'],
            'bedrock' => ['driver' => 'bedrock', 'region' => 'synthetic', 'use_default_credential_provider' => true],
        ]]);
    $autoload = function (string $class): void {
        if (str_starts_with($class, 'Aws\\') || (str_starts_with($class, 'Laravel\\Ai\\Providers\\') && $class !== 'Laravel\\Ai\\Providers\\Provider') || (str_starts_with($class, 'Laravel\\Ai\\Gateway\\') && $class !== 'Laravel\\Ai\\Gateway\\ParentInvocation')) {
            throw new RuntimeException('Transport or credential class reached before denial: '.$class);
        }
    };
    spl_autoload_register($autoload, true, true);
    try {
        expect(fn () => match ($path) {
            'container' => app(AiManager::class),
            'facade' => Ai::textProvider('openai'),
            'on-demand' => Ai::textProvider(['driver' => 'openai', 'key' => 'synthetic-not-a-secret']),
            'bedrock' => Ai::textProvider('bedrock'),
            'text' => agent()->prompt('synthetic', provider: 'openai', model: 'synthetic'),
            'provider-array' => agent()->prompt('synthetic', provider: ['openai' => 'synthetic', 'bedrock' => 'synthetic']),
            'stream' => agent()->stream('synthetic', provider: 'openai', model: 'synthetic'),
            'image' => Image::of('synthetic')->generate('openai', 'synthetic'),
            'audio' => Audio::of('synthetic')->generate('openai', 'synthetic'),
            'transcription' => Transcription::fromBase64('c3ludGhldGlj')->generate('openai', 'synthetic'),
            'embedding' => Embeddings::for(['synthetic'])->generate('openai', 'synthetic'),
            'reranking' => Reranking::of(['synthetic'])->rerank('synthetic', provider: 'cohere', model: 'synthetic'),
            'file' => Document::fromString('synthetic')->put('openai'),
            'store' => Stores::create('synthetic', provider: 'openai'),
            'queue' => agent()->queue('synthetic', provider: 'openai', model: 'synthetic'),
            'worker' => (new InvokeAgent(agent(), 'synthetic', provider: 'openai', model: 'synthetic'))->handle(),
        })->toThrow(AiExecutionDisabled::class, 'AI execution is disabled.');
    } finally {
        spl_autoload_unregister($autoload);
    }
    Http::assertNothingSent();
    Queue::assertNothingPushed();
})->with(['container', 'facade', 'on-demand', 'bedrock', 'text', 'provider-array', 'stream', 'image', 'audio', 'transcription', 'embedding', 'reranking', 'file', 'store', 'queue', 'worker'])->with([false, true]);

test('SDK cached facade and container instances are discarded when application binding registers', function () {
    Ai::swap(new stdClass);
    (new AppServiceProvider(app()))->register();
    expect(fn () => Ai::textProvider('openai'))->toThrow(AiExecutionDisabled::class);
    expect(fn () => app(AiManager::class))->toThrow(AiExecutionDisabled::class);
});

test('SDK fresh console and worker bootstrap denies execution with configuration cache', function (bool $cached) {
    $cache = sys_get_temp_dir().'/phase6-ai-config-'.bin2hex(random_bytes(8)).'.php';
    $environment = ['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $cache];
    try {
        if ($cached) {
            $build = new Process([PHP_BINARY, 'artisan', 'config:cache', '--no-interaction'], base_path(), $environment);
            $build->mustRun();
            expect(is_file($cache))->toBeTrue();
        }
        $script = <<<'CODE'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if ($app->configurationIsCached() !== (getenv('EXPECT_CACHE') === '1')) { exit(3); }
$app['config']->set('assistant.enabled', true);
$app['config']->set('ai.providers.openai', ['driver' => 'openai', 'key' => 'synthetic']);
Illuminate\Support\Facades\Http::preventStrayRequests();
foreach ([fn () => $app->make(Laravel\Ai\AiManager::class), fn () => Laravel\Ai\Ai::textProvider('openai'), fn () => (new Laravel\Ai\Jobs\InvokeAgent(Laravel\Ai\agent(), 'synthetic', provider: 'openai', model: 'synthetic'))->handle()] as $operation) {
    try { $operation(); exit(4); } catch (App\Exceptions\AiExecutionDisabled $e) { if ($e->getMessage() !== 'AI execution is disabled.') { exit(5); } }
}
Illuminate\Support\Facades\Http::assertNothingSent();
echo 'denied';
CODE;
        $process = new Process([PHP_BINARY, '-r', $script], base_path(), [...$environment, 'EXPECT_CACHE' => $cached ? '1' : '0']);
        $process->mustRun();
        expect($process->getOutput())->toBe('denied');
    } finally {
        @unlink($cache);
    }
})->with([false, true]);

/** SDK references outside the one unconditional binding are forbidden in runtime source. */
function phaseSixSdkReferences(string $source): bool
{
    return preg_match('/Laravel\\\\+Ai(?:\\\\+|\b)/i', $source) === 1;
}

test('SDK runtime architecture cannot bypass manager denial through imports helpers direct clients or registration', function () {
    $violations = [];
    foreach (['app', 'routes', 'resources', 'bootstrap', 'scripts'] as $directory) {
        if (! is_dir(base_path($directory))) {
            continue;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory))) as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), ['php', 'js', 'ts', 'vue', 'mjs', 'sh'], true) || str_contains($file->getPathname(), '/bootstrap/cache/')) {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            if ($file->getPathname() === app_path('Providers/AppServiceProvider.php')) {
                $source = str_replace('use Laravel\\Ai\\AiManager;', '', $source);
                expect(substr_count($source, 'AiManager::class'))->toBe(2);
                expect($source)->toContain('throw new AiExecutionDisabled;');
                $source = str_replace('Facade::clearResolvedInstance(AiManager::class);', '', $source);
                $source = preg_replace('/\$this->app->bind\(AiManager::class, function \(\): never \{\s*throw new AiExecutionDisabled;\s*\}\);/', '', $source);
                expect($source)->not->toContain('AiManager', 'AiServiceProvider');
            }
            if ($file->getPathname() === app_path('Fiscal/AssistantIntentGateway.php')) {
                foreach (['Gateway\\Anthropic\\AnthropicGateway', 'Gateway\\StepContext', 'Gateway\\TextGenerationOptions',
                    'Messages\\Message', 'Providers\\AnthropicProvider', 'Providers\\Provider'] as $symbol) {
                    $import = 'use Laravel\\Ai\\'.$symbol.';';
                    expect(substr_count($source, $import))->toBe(1);
                    $source = str_replace($import, '', $source);
                }
                expect(substr_count($source, '->generateTextStep('))->toBe(1);
                expect($source)->not->toContain('Promptable', 'TextGenerationLoop', '->prompt(', '->stream(', 'AiManager', 'AiServiceProvider');
            }
            if (phaseSixSdkReferences($source)) {
                $violations[] = $file->getPathname();
            }
        }
    }
    expect($violations)->toBe([]);
});

test('SDK architecture detector rejects every prohibited SDK namespace form', function (string $source) {
    expect(phaseSixSdkReferences($source))->toBeTrue();
})->with(['use Laravel\\Ai\\AiManager;', 'new \\Laravel\\Ai\\Providers\\OpenAiProvider;', 'use function Laravel\\Ai\\agent;', 'app("Laravel\\\\Ai\\\\AiManager")', 'register(Laravel\\Ai\\AiServiceProvider::class)']);
