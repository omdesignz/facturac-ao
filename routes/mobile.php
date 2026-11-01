<?php

use App\Http\Controllers\Api\Mobile\MobileCollectionsController;
use App\Http\Controllers\Api\Mobile\MobileCustomerController;
use App\Http\Controllers\Api\Mobile\MobileDocumentController;
use App\Http\Controllers\Api\Mobile\MobileInvoiceController;
use App\Http\Controllers\Api\Mobile\MobileItemController;
use App\Http\Controllers\Api\Mobile\MobileProfileController;
use App\Http\Controllers\Api\Mobile\MobileReminderController;
use App\Http\Controllers\Api\Mobile\MobileSessionController;
use App\Http\Controllers\Api\Mobile\MobileStockController;
use App\Http\Controllers\Api\Mobile\MobileTodayController;
use App\Http\Middleware\EnsureMobileCompany;
use Illuminate\Support\Facades\Route;

Route::name('mobile.v1.')->group(function (): void {
    Route::post('sessions', [MobileSessionController::class, 'store'])
        ->middleware('throttle:login')
        ->name('sessions.store');

    Route::middleware(['auth:sanctum', 'abilities:mobile', 'verified', 'throttle:120,1'])->group(function (): void {
        Route::get('me', MobileProfileController::class)->name('me');
        Route::get('sessions', [MobileSessionController::class, 'index'])->name('sessions.index');
        Route::delete('sessions/current', [MobileSessionController::class, 'destroyCurrent'])->name('sessions.destroy-current');
        Route::delete('sessions/{tokenId}', [MobileSessionController::class, 'destroy'])->whereNumber('tokenId')->name('sessions.destroy');

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
            Route::get('items', MobileItemController::class)->name('items.index');
            Route::get('stock', MobileStockController::class)->name('stock.index');
            Route::get('customers/{customerPublicId}/reminder', [MobileReminderController::class, 'show'])->name('reminders.show');
            Route::post('customers/{customerPublicId}/reminder', [MobileReminderController::class, 'store'])
                ->middleware('throttle:6,1')
                ->name('reminders.store');
        });
    });
});
