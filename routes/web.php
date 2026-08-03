<?php

use App\Http\Controllers\AgtConnectionCheckController;
use App\Http\Controllers\AgtConnectionController;
use App\Http\Controllers\AgtSeriesSyncController;
use App\Http\Controllers\AgtSubmissionController;
use App\Http\Controllers\AgtSubmissionRefreshController;
use App\Http\Controllers\BillingCheckoutController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\BillingReferenceRefreshController;
use App\Http\Controllers\CurrentWorkspaceController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DataImportCancellationController;
use App\Http\Controllers\DataImportCommitController;
use App\Http\Controllers\DataImportController;
use App\Http\Controllers\DataImportMappingController;
use App\Http\Controllers\DataImportTemplateController;
use App\Http\Controllers\FiscalDocumentController;
use App\Http\Controllers\FiscalDocumentIssueController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\SimulateEmisPaymentController;
use App\Http\Controllers\SocialAuthenticationController;
use App\Http\Controllers\WorkspaceSetupController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('/auth/google/redirect', [SocialAuthenticationController::class, 'redirect'])
        ->middleware('throttle:30,1')
        ->name('social.google.redirect');
    Route::get('/auth/google/callback', [SocialAuthenticationController::class, 'callback'])
        ->middleware('throttle:10,1')
        ->name('social.google.callback');
});

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('/workspace/setup', [WorkspaceSetupController::class, 'create'])
        ->name('workspace.setup');
    Route::post('/workspace/setup', [WorkspaceSetupController::class, 'store'])
        ->name('workspace.store');

    Route::middleware('workspace')->group(function (): void {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');
        Route::get('/onboarding', [OnboardingController::class, 'show'])->name('onboarding');
        Route::put('/onboarding', [OnboardingController::class, 'update'])->name('onboarding.update');
        Route::put('/current-workspace/{workspace}', [CurrentWorkspaceController::class, 'update'])
            ->name('workspace.current.update');
        Route::get('/settings/security', SecurityController::class)
            ->middleware('password.confirm')
            ->name('settings.security');
        Route::get('/settings/billing', BillingController::class)
            ->middleware('cache.headers:private;no_store')
            ->name('billing.show');
        Route::post('/settings/billing/checkout', BillingCheckoutController::class)
            ->middleware(['mfa', 'password.confirm', 'throttle:6,1'])
            ->name('billing.checkout');
        Route::post(
            '/settings/billing/references/{emisPaymentReference}/refresh',
            BillingReferenceRefreshController::class,
        )->middleware(['mfa', 'password.confirm', 'throttle:12,1'])
            ->name('billing.references.refresh');
        Route::post(
            '/settings/billing/references/{emisPaymentReference}/simulate',
            SimulateEmisPaymentController::class,
        )->middleware(['mfa', 'password.confirm', 'throttle:6,1'])
            ->name('billing.references.simulate');

        Route::get('/documents/invoices/create', [FiscalDocumentController::class, 'create'])
            ->name('invoices.create');
        Route::post('/documents/invoices', [FiscalDocumentController::class, 'store'])
            ->name('invoices.store');
        Route::get('/documents/invoices/{fiscalDocument}/edit', [FiscalDocumentController::class, 'edit'])
            ->name('invoices.edit');
        Route::put('/documents/invoices/{fiscalDocument}', [FiscalDocumentController::class, 'update'])
            ->name('invoices.update');
        Route::post('/documents/invoices/{fiscalDocument}/issue', FiscalDocumentIssueController::class)
            ->middleware(['mfa', 'password.confirm', 'throttle:10,1'])
            ->name('invoices.issue');
        Route::get('/agt/connection', [AgtConnectionController::class, 'show'])
            ->middleware('cache.headers:private;no_store')
            ->name('agt.connection.show');
        Route::put('/agt/connection', [AgtConnectionController::class, 'update'])
            ->middleware(['mfa', 'password.confirm', 'throttle:10,1'])
            ->name('agt.connection.update');
        Route::post('/agt/connection/checks', [AgtConnectionCheckController::class, 'store'])
            ->middleware(['mfa', 'password.confirm', 'throttle:3,1'])
            ->name('agt.connection-checks.store');
        Route::post('/agt/series/sync', AgtSeriesSyncController::class)
            ->middleware(['mfa', 'password.confirm', 'throttle:3,1'])
            ->name('agt.series.sync');
        Route::get('/agt/submissions', [AgtSubmissionController::class, 'index'])
            ->middleware('cache.headers:private;no_store')
            ->name('agt.submissions.index');
        Route::post('/agt/submissions/refresh', AgtSubmissionRefreshController::class)
            ->middleware(['mfa', 'password.confirm', 'throttle:6,1'])
            ->name('agt.submissions.refresh');
        Route::get('/imports', [DataImportController::class, 'index'])
            ->middleware('cache.headers:private;no_store')
            ->name('imports.index');
        Route::post('/imports', [DataImportController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('imports.store');
        Route::get('/imports/templates/{type}', DataImportTemplateController::class)
            ->name('imports.templates.show');
        Route::put('/imports/{dataImport}/mapping', DataImportMappingController::class)
            ->middleware('throttle:20,1')
            ->name('imports.mapping.update');
        Route::post('/imports/{dataImport}/commit', DataImportCommitController::class)
            ->middleware(['mfa', 'password.confirm', 'throttle:6,1'])
            ->name('imports.commit');
        Route::delete('/imports/{dataImport}', DataImportCancellationController::class)
            ->middleware(['mfa', 'password.confirm', 'throttle:6,1'])
            ->name('imports.destroy');
    });
});
