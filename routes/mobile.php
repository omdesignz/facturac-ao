<?php

use App\Http\Controllers\Api\Mobile\MobileCollectionsController;
use App\Http\Controllers\Api\Mobile\MobileProfileController;
use App\Http\Controllers\Api\Mobile\MobileSessionController;
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
        });
    });
});
