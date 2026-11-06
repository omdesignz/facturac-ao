<?php

use App\Http\Controllers\Api\Mobile\MobileCollectionsController;
use App\Http\Controllers\Api\Mobile\MobileCurrentCompanyController;
use App\Http\Controllers\Api\Mobile\MobileCustomerController;
use App\Http\Controllers\Api\Mobile\MobileCustomerCreateController;
use App\Http\Controllers\Api\Mobile\MobileCustomerProfileController;
use App\Http\Controllers\Api\Mobile\MobileCustomerTaxIdentificationController;
use App\Http\Controllers\Api\Mobile\MobileDocumentController;
use App\Http\Controllers\Api\Mobile\MobileInvoiceController;
use App\Http\Controllers\Api\Mobile\MobileItemController;
use App\Http\Controllers\Api\Mobile\MobileItemCreateController;
use App\Http\Controllers\Api\Mobile\MobileItemLookupController;
use App\Http\Controllers\Api\Mobile\MobileNotificationController;
use App\Http\Controllers\Api\Mobile\MobileNotificationPreferenceController;
use App\Http\Controllers\Api\Mobile\MobilePasskeyController;
use App\Http\Controllers\Api\Mobile\MobilePaymentController;
use App\Http\Controllers\Api\Mobile\MobileProfileController;
use App\Http\Controllers\Api\Mobile\MobilePushDeviceController;
use App\Http\Controllers\Api\Mobile\MobileQuoteController;
use App\Http\Controllers\Api\Mobile\MobileReminderController;
use App\Http\Controllers\Api\Mobile\MobileSessionController;
use App\Http\Controllers\Api\Mobile\MobileStockController;
use App\Http\Controllers\Api\Mobile\MobileTillController;
use App\Http\Controllers\Api\Mobile\MobileTodayController;
use App\Http\Middleware\EnsureMobileCompany;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

Route::name('mobile.v1.')->group(function (): void {
    Route::post('sessions', [MobileSessionController::class, 'store'])
        ->middleware('throttle:login')
        ->name('sessions.store');
    Route::post('sessions/passkey/options', [MobilePasskeyController::class, 'signInOptions'])
        ->middleware('throttle:20,1,mobile-passkey-options')
        ->name('sessions.passkey-options');
    Route::post('sessions/passkey', [MobilePasskeyController::class, 'signIn'])
        ->middleware('throttle:10,1,mobile-passkey-sign-in')
        ->name('sessions.passkey');

    Route::middleware(['auth:sanctum', 'abilities:mobile', 'verified', 'throttle:120,1'])->group(function (): void {
        Route::get('me', MobileProfileController::class)->name('me');
        Route::put('current-company', MobileCurrentCompanyController::class)
            ->middleware('throttle:20,1,mobile-company')
            ->name('current-company.update');
        Route::get('sessions', [MobileSessionController::class, 'index'])->name('sessions.index');
        Route::delete('sessions/current', [MobileSessionController::class, 'destroyCurrent'])->name('sessions.destroy-current');
        Route::delete('sessions/{tokenId}', [MobileSessionController::class, 'destroy'])->whereNumber('tokenId')->name('sessions.destroy');
        Route::put('push-device', [MobilePushDeviceController::class, 'update'])->name('push-device.update');
        Route::get('notification-preferences', [MobileNotificationPreferenceController::class, 'show'])->name('notification-preferences.show');
        Route::put('notification-preferences', [MobileNotificationPreferenceController::class, 'update'])->name('notification-preferences.update');
        Route::get('passkeys', [MobilePasskeyController::class, 'index'])->name('passkeys.index');
        Route::post('passkeys/options', [MobilePasskeyController::class, 'registrationOptions'])
            ->middleware('throttle:6,1,mobile-passkey-register')
            ->name('passkeys.options');
        Route::post('passkeys', [MobilePasskeyController::class, 'store'])
            ->middleware('throttle:6,1,mobile-passkey-store')
            ->name('passkeys.store');
        Route::post('passkeys/confirmation', [MobilePasskeyController::class, 'confirmationOptions'])
            ->middleware('throttle:30,1,mobile-passkey-confirm')
            ->name('passkeys.confirmation');

        Route::middleware(['workspace', EnsureMobileCompany::class])->group(function (): void {
            Route::get('today', MobileTodayController::class)->name('today');
            Route::get('collections', MobileCollectionsController::class)->name('collections');
            Route::get('documents', [MobileDocumentController::class, 'index'])->name('documents.index');
            Route::get('documents/{documentPublicId}', [MobileDocumentController::class, 'show'])->name('documents.show');
            Route::get('invoices/setup', [MobileInvoiceController::class, 'setup'])->name('invoices.setup');
            Route::post('invoices/preview', [MobileInvoiceController::class, 'preview'])->name('invoices.preview');
            Route::post('invoices', [MobileInvoiceController::class, 'store'])
                ->middleware('throttle:10,1')
                ->name('invoices.store');
            Route::get('customers', MobileCustomerController::class)->name('customers.index');
            Route::post('customers', MobileCustomerCreateController::class)
                ->middleware('throttle:20,1,mobile-customer')
                ->name('customers.store');
            Route::get('customers/{customerPublicId}', MobileCustomerProfileController::class)->name('customers.show');
            Route::get('items', MobileItemController::class)->name('items.index');
            Route::get('items/lookup', MobileItemLookupController::class)->name('items.lookup');
            Route::post('items', MobileItemCreateController::class)
                ->middleware('throttle:20,1,mobile-item')
                ->name('items.store');
            Route::get('stock', MobileStockController::class)->name('stock.index');
            Route::get('notifications', [MobileNotificationController::class, 'index'])->name('notifications.index');
            Route::get('notifications/unread', [MobileNotificationController::class, 'unread'])->name('notifications.unread');
            Route::post('notifications/read-all', [MobileNotificationController::class, 'readAll'])->name('notifications.read-all');
            Route::post('notifications/{notificationId}/read', [MobileNotificationController::class, 'read'])->name('notifications.read');
            Route::get('quotes', [MobileQuoteController::class, 'index'])->name('quotes.index');
            Route::post('quotes/preview', [MobileQuoteController::class, 'preview'])->name('quotes.preview');
            Route::post('quotes', [MobileQuoteController::class, 'store'])
                ->middleware('throttle:20,1,mobile-quote')
                ->name('quotes.store');
            Route::get('quotes/{quotePublicId}', [MobileQuoteController::class, 'show'])->name('quotes.show');
            Route::put('quotes/{quotePublicId}/status', [MobileQuoteController::class, 'status'])
                ->middleware('throttle:20,1,mobile-quote-status')
                ->name('quotes.status');
            Route::post('quotes/{quotePublicId}/invoice', [MobileQuoteController::class, 'convert'])
                ->middleware('throttle:10,1,mobile-quote-convert')
                ->name('quotes.convert');
            Route::post('quotes/{quotePublicId}/invoice/issue', [MobileQuoteController::class, 'issue'])
                ->middleware('throttle:10,1,mobile-quote-issue')
                ->name('quotes.issue');
            Route::get('till', [MobileTillController::class, 'show'])->name('till.show');
            Route::post('till/sessions', [MobileTillController::class, 'open'])
                ->middleware('throttle:10,1,mobile-till-open')
                ->name('till.sessions.store');
            Route::middleware(SubstituteBindings::class)->group(function (): void {
                Route::post('till/sessions/{posSession}/preview', [MobileTillController::class, 'preview'])->name('till.sales.preview');
                Route::post('till/sessions/{posSession}/sales', [MobileTillController::class, 'sell'])
                    ->middleware('throttle:120,1,mobile-till-sale')
                    ->name('till.sales.store');
                Route::post('till/sessions/{posSession}/movements', [MobileTillController::class, 'movement'])
                    ->middleware('throttle:20,1,mobile-till-movement')
                    ->name('till.movements.store');
                Route::post('till/sessions/{posSession}/close', [MobileTillController::class, 'close'])
                    ->middleware('throttle:10,1,mobile-till-close')
                    ->name('till.sessions.close');
            });
            Route::patch('customers/{customerPublicId}/tax-identification', MobileCustomerTaxIdentificationController::class)
                ->middleware('throttle:20,1')
                ->name('customers.tax-identification');
            Route::get('customers/{customerPublicId}/payment', [MobilePaymentController::class, 'show'])->name('payments.show');
            Route::post('customers/{customerPublicId}/payment', [MobilePaymentController::class, 'store'])
                ->middleware('throttle:10,1')
                ->name('payments.store');
            Route::get('customers/{customerPublicId}/reminder', [MobileReminderController::class, 'show'])->name('reminders.show');
            Route::post('customers/{customerPublicId}/reminder', [MobileReminderController::class, 'store'])
                ->middleware('throttle:6,1')
                ->name('reminders.store');
            Route::post('customers/{customerPublicId}/reminder/whatsapp', [MobileReminderController::class, 'whatsapp'])
                ->middleware('throttle:6,1,mobile-reminder-whatsapp')
                ->name('reminders.whatsapp');
        });
    });
});
