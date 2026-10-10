<?php

use App\Http\Controllers\Api\V1\ExternalCatalogueReadController;
use App\Http\Controllers\Api\V1\ExternalCustomerReadController;
use App\Http\Controllers\Api\V1\ExternalDocumentReadController;
use Illuminate\Support\Facades\Route;

Route::prefix('workspaces/{workspacePublicId}/legal-entities/{entityPublicId}/environments/{environment}')->name('integrations.v1.')->group(function (): void {
    Route::get('documents', ExternalDocumentReadController::class)->name('documents.index');
    Route::get('documents/{documentPublicId}', ExternalDocumentReadController::class)->name('documents.show');
    Route::get('customers', ExternalCustomerReadController::class)->name('customers.index');
    Route::get('customers/{customerPublicId}', ExternalCustomerReadController::class)->name('customers.show');
    Route::get('catalogue-items', ExternalCatalogueReadController::class)->name('catalogue.index');
    Route::get('catalogue-items/{itemPublicId}', ExternalCatalogueReadController::class)->name('catalogue.show');
});
