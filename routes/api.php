<?php

use App\Http\Controllers\Api\V1\CatalogueReadController;
use App\Http\Controllers\Api\V1\CustomerReadController;
use App\Http\Controllers\Api\V1\FiscalDocumentReadController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'throttle:60,1'])
    ->prefix('workspaces/{workspacePublicId}/legal-entities/{entityPublicId}/environments/{environment}')
    ->name('api.v1.')
    ->group(function (): void {
        Route::get('documents', [FiscalDocumentReadController::class, 'index'])->name('documents.index');
        Route::get('documents/{documentPublicId}', [FiscalDocumentReadController::class, 'show'])->name('documents.show');
        Route::get('customers', [CustomerReadController::class, 'index'])->name('customers.index');
        Route::get('customers/{customerPublicId}', [CustomerReadController::class, 'show'])->name('customers.show');
        Route::get('catalogue-items', [CatalogueReadController::class, 'index'])->name('catalogue.index');
        Route::get('catalogue-items/{itemPublicId}', [CatalogueReadController::class, 'show'])->name('catalogue.show');
    });
