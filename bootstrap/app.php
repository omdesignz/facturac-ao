<?php

use App\Http\Middleware\AddRequestContext;
use App\Http\Middleware\EnforceWorkSession;
use App\Http\Middleware\EnsureCurrentWorkspace;
use App\Http\Middleware\EnsureMultiFactorAuthentication;
use App\Http\Middleware\EnsureSupportStaff;
use App\Http\Middleware\HandleImpersonation;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::prefix('webhooks')->group(base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Carries a consent choice, not a secret, and is deliberately readable
        // by the page: encrypting it would defeat the httpOnly:false it is set
        // with, and leave the browser unable to see its own answer.
        $middleware->encryptCookies(except: ['cookie_consent']);

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

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'webhooks/*') || $request->expectsJson(),
        );
    })->create();
