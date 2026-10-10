<?php

use App\Http\Controllers\Api\ExternalCustomerCreateController;
use App\Http\Controllers\Api\ExternalServiceCreateController;
use Illuminate\Support\Facades\Route;

Route::post('workspaces/{workspacePublicId}/legal-entities/{entityPublicId}/environments/{environment}/customers', ExternalCustomerCreateController::class)
    ->name('integrations.commands.v1.customers.create');

Route::post('workspaces/{workspacePublicId}/legal-entities/{entityPublicId}/environments/{environment}/catalogue-services', ExternalServiceCreateController::class)
    ->name('integrations.commands.v1.catalogue-services.create');
