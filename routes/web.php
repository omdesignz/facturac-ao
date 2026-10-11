<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AgtConnectionCheckController;
use App\Http\Controllers\AgtConnectionController;
use App\Http\Controllers\AgtSeriesRequestController;
use App\Http\Controllers\AgtSeriesSyncController;
use App\Http\Controllers\AgtSubmissionController;
use App\Http\Controllers\AgtSubmissionRefreshController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\BillingCheckoutController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\BillingReferenceRefreshController;
use App\Http\Controllers\CatalogueItemController;
use App\Http\Controllers\CompanyLogoController;
use App\Http\Controllers\CookieConsentController;
use App\Http\Controllers\CurrentWorkspaceController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerPriceController;
use App\Http\Controllers\CustomerProfileController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DataImportCancellationController;
use App\Http\Controllers\DataImportCommitController;
use App\Http\Controllers\DataImportController;
use App\Http\Controllers\DataImportMappingController;
use App\Http\Controllers\DataImportTemplateController;
use App\Http\Controllers\DebtController;
use App\Http\Controllers\EstablishmentController;
use App\Http\Controllers\FiscalDocumentController;
use App\Http\Controllers\FiscalDocumentDeliveryController;
use App\Http\Controllers\FiscalDocumentIndexController;
use App\Http\Controllers\FiscalDocumentIssueController;
use App\Http\Controllers\FiscalDocumentPrintController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\HostedPaymentResumeController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\LegalDocumentController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NotificationPreferenceController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PlatformSettingController;
use App\Http\Controllers\PosCashMovementController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\PosRegisterController;
use App\Http\Controllers\PosSaleController;
use App\Http\Controllers\PosSaleReceiptController;
use App\Http\Controllers\PosSessionCloseController;
use App\Http\Controllers\PosSessionController;
use App\Http\Controllers\PriceListController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\RecurringInvoiceController;
use App\Http\Controllers\RenewWorkSessionController;
use App\Http\Controllers\SaftExportController;
use App\Http\Controllers\SaftIndexController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SimulateEmisPaymentController;
use App\Http\Controllers\SocialAuthenticationController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\SupportComplaintController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\TermsAcceptanceController;
use App\Http\Controllers\TransportDocumentCancellationController;
use App\Http\Controllers\TransportDocumentController;
use App\Http\Controllers\TransportDocumentIssueController;
use App\Http\Controllers\TransportDocumentPrintController;
use App\Http\Controllers\WorkSessionPreferenceController;
use App\Http\Controllers\WorkspaceSetupController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public: the first thing a visitor without an account can see.
Route::get('/', LandingController::class)->name('home');

// Public on purpose: someone deciding whether to sign up has to be able to read
// the terms before they have an account.
Route::get('/{slug}', [LegalDocumentController::class, 'show'])
    ->whereIn('slug', ['privacidade', 'termos', 'cookies'])
    ->name('legal.show');

// The customer who received the document has no account, so the signature on
// the link is what authorises it. Someone signed in reaches the same page
// through the workspace check inside the controller.
Route::get('/documentos/{fiscalDocument}', FiscalDocumentPrintController::class)
    ->middleware('throttle:60,1')
    ->name('documents.print');
Route::get('/documentos/{fiscalDocument}/pdf', [FiscalDocumentPrintController::class, 'pdf'])
    ->middleware('throttle:60,1')
    ->name('documents.pdf');

// Answerable without an account: the banner is shown before sign-in too.
Route::post('/cookie-consent', [CookieConsentController::class, 'store'])
    ->middleware('throttle:20,1')
    ->name('cookie-consent.store');

Route::middleware('guest')->group(function (): void {
    Route::get('/auth/google/redirect', [SocialAuthenticationController::class, 'redirect'])
        ->middleware('throttle:30,1')
        ->name('social.google.redirect');
    Route::get('/auth/google/callback', [SocialAuthenticationController::class, 'callback'])
        ->middleware('throttle:10,1')
        ->name('social.google.callback');
});

// Only "auth": during an impersonation this is called as the customer, and the
// way out must not depend on the state of their account.
Route::delete('/impersonation', [ImpersonationController::class, 'destroy'])
    ->middleware('auth')
    ->name('support.impersonation.destroy');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('/workspace/setup', [WorkspaceSetupController::class, 'create'])
        ->name('workspace.setup');
    Route::post('/workspace/setup', [WorkspaceSetupController::class, 'store'])
        ->name('workspace.store');

    // Outside the workspace scope on purpose: support staff troubleshoot
    // accounts in tenants they are not members of.
    Route::middleware('support')->prefix('support')->group(function (): void {
        Route::get('/', [SupportController::class, 'index'])->name('support.index');
        Route::post('/impersonation/{user}', [ImpersonationController::class, 'store'])
            ->middleware(['password.confirm', 'throttle:10,1'])
            ->name('support.impersonation.store');

        Route::get('/reclamacoes', [SupportComplaintController::class, 'index'])
            ->name('support.complaints.index');
        Route::put('/reclamacoes/{complaint}', [SupportComplaintController::class, 'update'])
            ->name('support.complaints.update');

        Route::get('/definicoes', [PlatformSettingController::class, 'edit'])
            ->name('support.settings.edit');
        Route::put('/definicoes', [PlatformSettingController::class, 'update'])
            ->middleware('password.confirm')
            ->name('support.settings.update');
    });

    Route::post('/termos/aceitar', [TermsAcceptanceController::class, 'store'])
        ->name('legal.terms.accept');

    // Outside the workspace scope: someone whose workspace setup is stuck is
    // exactly the person who most needs to reach support.
    Route::get('/ajuda', [HelpController::class, 'index'])->name('help.index');
    Route::post('/ajuda/reclamacoes', [HelpController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('help.complaints.store');

    Route::middleware('workspace')->group(function (): void {
        Route::post('/session/renew', RenewWorkSessionController::class)
            ->name('session.renew');
        Route::put('/settings/work-session', WorkSessionPreferenceController::class)
            ->name('settings.work-session.update');
        Route::put('/settings/notifications', NotificationPreferenceController::class)
            ->name('settings.notifications.update');
        Route::get('/settings/conta', [AccountController::class, 'show'])
            ->middleware('password.confirm')
            ->name('settings.account');
        Route::post('/settings/conta/exportar', [AccountController::class, 'export'])
            ->middleware(['password.confirm', 'throttle:5,10'])
            ->name('settings.account.export');
        // Irreversible for the person doing it, so it asks for the password
        // again however recently they signed in.
        Route::delete('/settings/conta', [AccountController::class, 'destroy'])
            ->middleware(['password.confirm', 'throttle:5,60'])
            ->name('settings.account.destroy');

        Route::delete('/settings/sessions/others', [SessionController::class, 'destroyOthers'])
            ->middleware('password.confirm')
            ->name('settings.sessions.destroy-others');
        Route::delete('/settings/sessions/{session}', [SessionController::class, 'destroy'])
            ->middleware('password.confirm')
            ->name('settings.sessions.destroy');

        Route::get('/dashboard', DashboardController::class)->name('dashboard');
        Route::get('/pesquisa', GlobalSearchController::class)
            ->middleware('throttle:90,1')
            ->name('search');
        Route::get('/analises', AnalyticsController::class)->name('analytics.index');
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
        Route::post('/settings/billing/payments/{payment}/resume', HostedPaymentResumeController::class)
            ->middleware(['mfa', 'password.confirm', 'throttle:6,1'])
            ->name('billing.payments.resume');
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

        Route::get('/customers', [CustomerController::class, 'index'])
            ->name('customers.index');
        Route::post('/customers', [CustomerController::class, 'store'])
            ->name('customers.store');
        Route::get('/customers/{customer}', [CustomerProfileController::class, 'show'])
            ->name('customers.show');
        Route::put('/customers/{customer}', [CustomerController::class, 'update'])
            ->name('customers.update');
        Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])
            ->name('customers.destroy');
        Route::post('/customers/{customer}/precos', [CustomerPriceController::class, 'store'])
            ->name('customers.prices.store');
        Route::delete('/customers/{customer}/precos/{price}', [CustomerPriceController::class, 'destroy'])
            ->name('customers.prices.destroy');

        Route::get('/notificacoes', [NotificationController::class, 'index'])
            ->name('notifications.index');
        Route::post('/notificacoes/lidas', [NotificationController::class, 'readAll'])
            ->name('notifications.read-all');
        Route::delete('/notificacoes/lidas', [NotificationController::class, 'destroyRead'])
            ->name('notifications.clear-read');
        Route::post('/notificacoes/{notification}', [NotificationController::class, 'read'])
            ->name('notifications.read');

        Route::get('/saft', SaftIndexController::class)
            ->middleware('cache.headers:private;no_store')
            ->name('saft.index');
        Route::get('/saft/exportar', SaftExportController::class)
            ->middleware('throttle:10,5')
            ->name('saft.export');

        Route::get('/empresa/logotipo', [CompanyLogoController::class, 'show'])
            ->name('company.logo.show');
        Route::post('/empresa/logotipo', [CompanyLogoController::class, 'store'])
            ->name('company.logo.store');
        Route::delete('/empresa/logotipo', [CompanyLogoController::class, 'destroy'])
            ->name('company.logo.destroy');

        Route::get('/estabelecimentos', [EstablishmentController::class, 'index'])
            ->name('establishments.index');
        Route::post('/estabelecimentos', [EstablishmentController::class, 'store'])
            ->name('establishments.store');
        Route::put('/estabelecimentos/{establishment}', [EstablishmentController::class, 'update'])
            ->name('establishments.update');
        Route::delete('/estabelecimentos/{establishment}', [EstablishmentController::class, 'destroy'])
            ->name('establishments.destroy');

        Route::get('/tabelas-de-precos', [PriceListController::class, 'index'])
            ->name('price-lists.index');
        Route::post('/tabelas-de-precos', [PriceListController::class, 'store'])
            ->name('price-lists.store');
        Route::put('/tabelas-de-precos/{priceList}', [PriceListController::class, 'update'])
            ->name('price-lists.update');
        Route::delete('/tabelas-de-precos/{priceList}', [PriceListController::class, 'destroy'])
            ->name('price-lists.destroy');
        Route::post('/tabelas-de-precos/{priceList}/precos', [PriceListController::class, 'storePrices'])
            ->name('price-lists.prices.store');

        Route::get('/catalogue', [CatalogueItemController::class, 'index'])
            ->name('catalogue.index');
        Route::post('/catalogue', [CatalogueItemController::class, 'store'])
            ->name('catalogue.store');
        Route::put('/catalogue/{catalogueItem}', [CatalogueItemController::class, 'update'])
            ->name('catalogue.update');
        Route::delete('/catalogue/{catalogueItem}', [CatalogueItemController::class, 'destroy'])
            ->name('catalogue.destroy');

        Route::get('/inventario', [StockController::class, 'index'])
            ->name('stock.index');
        Route::post('/inventario/movimentos', [StockController::class, 'store'])
            ->name('stock.movements.store');
        // The page was called Existências; links already sent in notifications
        // (with their ?search=) and bookmarks keep working.
        Route::get('/existencias', fn (Request $request): RedirectResponse => redirect()
            ->route('stock.index', $request->query(), 301));

        Route::get('/dividas', DebtController::class)->name('debts.index');

        Route::get('/avencas', [RecurringInvoiceController::class, 'index'])
            ->name('recurring.index');
        Route::post('/avencas', [RecurringInvoiceController::class, 'store'])
            ->name('recurring.store');
        Route::put('/avencas/{recurringInvoice}', [RecurringInvoiceController::class, 'update'])
            ->name('recurring.update');
        Route::delete('/avencas/{recurringInvoice}', [RecurringInvoiceController::class, 'destroy'])
            ->name('recurring.destroy');
        Route::post('/avencas/gerar', [RecurringInvoiceController::class, 'run'])
            ->middleware('throttle:6,1')
            ->name('recurring.run');

        Route::get('/orcamentos', [QuoteController::class, 'index'])
            ->name('quotes.index');
        Route::get('/orcamentos/novo', [QuoteController::class, 'create'])
            ->name('quotes.create');
        Route::post('/orcamentos', [QuoteController::class, 'store'])
            ->name('quotes.store');
        Route::get('/orcamentos/{quote}', [QuoteController::class, 'edit'])
            ->name('quotes.edit');
        Route::put('/orcamentos/{quote}', [QuoteController::class, 'update'])
            ->name('quotes.update');
        Route::put('/orcamentos/{quote}/estado', [QuoteController::class, 'transition'])
            ->name('quotes.transition');
        Route::post('/orcamentos/{quote}/facturar', [QuoteController::class, 'convert'])
            ->name('quotes.convert');

        Route::get('/guias', [TransportDocumentController::class, 'index'])
            ->middleware('cache.headers:private;no_store')
            ->name('transport-documents.index');
        Route::get('/guias/nova', [TransportDocumentController::class, 'create'])
            ->name('transport-documents.create');
        Route::post('/guias', [TransportDocumentController::class, 'store'])
            ->name('transport-documents.store');
        Route::get('/guias/{transportDocument}/editar', [TransportDocumentController::class, 'edit'])
            ->name('transport-documents.edit');
        Route::put('/guias/{transportDocument}', [TransportDocumentController::class, 'update'])
            ->name('transport-documents.update');
        Route::post('/guias/{transportDocument}/emitir', TransportDocumentIssueController::class)
            ->middleware(['mfa', 'password.confirm', 'throttle:10,1'])
            ->name('transport-documents.issue');
        Route::post('/guias/{transportDocument}/anular', TransportDocumentCancellationController::class)
            ->middleware(['mfa', 'password.confirm', 'throttle:10,1'])
            ->name('transport-documents.cancel');
        Route::get('/guias/{transportDocument}/imprimir', [TransportDocumentPrintController::class, 'show'])
            ->name('transport-documents.print');
        Route::get('/guias/{transportDocument}/pdf', [TransportDocumentPrintController::class, 'pdf'])
            ->name('transport-documents.pdf');

        Route::get('/documentos', FiscalDocumentIndexController::class)
            ->middleware('cache.headers:private;no_store')
            ->name('documents.index');
        Route::get('/documents/invoices/create', [FiscalDocumentController::class, 'create'])
            ->name('invoices.create');
        Route::post('/documents/invoices', [FiscalDocumentController::class, 'store'])
            ->name('invoices.store');
        Route::get('/documents/invoices/{fiscalDocument}/edit', [FiscalDocumentController::class, 'edit'])
            ->name('invoices.edit');
        Route::put('/documents/invoices/{fiscalDocument}', [FiscalDocumentController::class, 'update'])
            ->name('invoices.update');
        Route::post(
            '/documents/invoices/{fiscalDocument}/enviar',
            FiscalDocumentDeliveryController::class,
        )->middleware('throttle:20,1')->name('invoices.send');
        Route::post('/documents/invoices/{fiscalDocument}/issue', FiscalDocumentIssueController::class)
            ->middleware(['mfa', 'password.confirm', 'throttle:10,1'])
            ->name('invoices.issue');
        // Ponto de venda. Opening a shift is the moment of password
        // confirmation; an open shift is then the standing authority to sell,
        // so a sale asks for MFA but not the password again.
        Route::get('/ponto-de-venda', [PosController::class, 'show'])
            ->middleware('cache.headers:private;no_store')
            ->name('pos.show');
        Route::post('/ponto-de-venda/turnos', [PosSessionController::class, 'store'])
            ->middleware(['mfa', 'password.confirm'])
            ->name('pos.sessions.store');
        Route::get('/ponto-de-venda/turnos', [PosSessionController::class, 'index'])
            ->middleware('cache.headers:private;no_store')
            ->name('pos.sessions.index');
        Route::get('/ponto-de-venda/turnos/{posSession}', [PosSessionController::class, 'show'])
            ->middleware('cache.headers:private;no_store')
            ->name('pos.sessions.show');
        Route::post('/ponto-de-venda/turnos/{posSession}/fecho', PosSessionCloseController::class)
            ->middleware('mfa')
            ->name('pos.sessions.close');
        Route::post('/ponto-de-venda/turnos/{posSession}/movimentos', [PosCashMovementController::class, 'store'])
            ->middleware('mfa')
            ->name('pos.cash-movements.store');
        Route::post('/ponto-de-venda/turnos/{posSession}/vendas', [PosSaleController::class, 'store'])
            ->middleware(['mfa', 'throttle:120,1'])
            ->name('pos.sales.store');
        Route::get('/ponto-de-venda/vendas/{posSale}/talao', PosSaleReceiptController::class)
            ->middleware('cache.headers:private;no_store')
            ->name('pos.sales.receipt');
        Route::get('/ponto-de-venda/caixas', [PosRegisterController::class, 'index'])
            ->middleware('cache.headers:private;no_store')
            ->name('pos.registers.index');
        Route::post('/ponto-de-venda/caixas', [PosRegisterController::class, 'store'])
            ->name('pos.registers.store');
        Route::put('/ponto-de-venda/caixas/{posRegister}', [PosRegisterController::class, 'update'])
            ->name('pos.registers.update');
        Route::delete('/ponto-de-venda/caixas/{posRegister}', [PosRegisterController::class, 'destroy'])
            ->name('pos.registers.destroy');
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
        Route::post('/agt/series', AgtSeriesRequestController::class)
            ->middleware(['mfa', 'password.confirm', 'throttle:1,1'])
            ->name('agt.series.store');
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

Route::middleware(['auth', 'verified'])
    ->prefix('assistant/workspaces/{workspacePublicId}/legal-entities/{entityPublicId}/environments/{environment}')
    ->name('assistant.')
    ->group(function (): void {
        Route::get('/', [AssistantController::class, 'show'])->name('show');
        Route::post('interactions', [AssistantController::class, 'store'])->name('store');
        Route::post('acknowledgement', [AssistantController::class, 'acknowledgement'])->name('acknowledgement');
    });
