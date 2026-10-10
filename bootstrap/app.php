<?php

use App\Exceptions\AiExecutionDisabled;
use App\Fiscal\ReadOperationAudit;
use App\Http\Middleware\AddRequestContext;
use App\Http\Middleware\AssistantBoundary;
use App\Http\Middleware\EnforceWorkSession;
use App\Http\Middleware\EnsureCurrentWorkspace;
use App\Http\Middleware\EnsureMultiFactorAuthentication;
use App\Http\Middleware\EnsureSupportStaff;
use App\Http\Middleware\ExternalCommandBoundary;
use App\Http\Middleware\ExternalIntegrationBoundary;
use App\Http\Middleware\HandleImpersonation;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ReadApiResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::prefix('api/integrations/commands/v1')->group(base_path('routes/integration-commands.php'));
            Route::prefix('api/integrations/v1')->group(base_path('routes/integrations.php'));
            Route::prefix('api/integrations/v2')->group(base_path('routes/integrations-v2.php'));
            Route::prefix('webhooks')->group(base_path('routes/webhooks.php'));
            Route::middleware('web')->withoutMiddleware([HandleInertiaRequests::class, AddLinkHeadersForPreloadedAssets::class])
                ->prefix('api/v1')->group(base_path('routes/api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Carries a consent choice, not a secret, and is deliberately readable
        // by the page: encrypting it would defeat the httpOnly:false it is set
        // with, and leave the browser unable to see its own answer.
        $middleware->encryptCookies(except: ['cookie_consent']);
        $middleware->trimStrings(except: [fn (Request $request): bool => ReadOperationAudit::isMasterPath($request) || ExternalCommandBoundary::matches($request)]);
        $middleware->prepend(ReadApiResponse::class);
        $middleware->prepend(ExternalIntegrationBoundary::class);
        $middleware->prepend(ExternalCommandBoundary::class);
        $middleware->convertEmptyStringsToNull(except: [fn (Request $request): bool => ExternalCommandBoundary::matches($request)]);
        $middleware->prepend(AssistantBoundary::class);
        $middleware->prepend(TrustProxies::class);

        $middleware->alias([
            'workspace' => EnsureCurrentWorkspace::class,
            'mfa' => EnsureMultiFactorAuthentication::class,
            'support' => EnsureSupportStaff::class,
        ]);

        $middleware->web(append: [
            AddRequestContext::class,
            EnforceWorkSession::class,
            HandleImpersonation::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash('basic_auth_password');
        $exceptions->render(function (AiExecutionDisabled $exception, Request $request): null {
            $request->attributes->set('assistant_provider_disabled', true);

            return null;
        });
        $exceptions->report(function (Throwable $exception): ?bool {
            if (AssistantBoundary::matches(request()) || ExternalCommandBoundary::matches(request()) || ReadOperationAudit::isExternalPath(request()) || ReadOperationAudit::isMasterPath(request())) {
                Log::error('External read processing failed', ['request_id' => Context::get('request_id')]);

                return false;
            }

            return null;
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'webhooks/*') || $request->expectsJson(),
        );
    })->create();
