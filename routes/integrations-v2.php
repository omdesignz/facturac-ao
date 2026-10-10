<?php

use App\Http\Controllers\Api\V2\ExternalBillingSummaryController;
use App\Http\Controllers\Api\V2\ExternalQualifiedAgtStatusController;
use Illuminate\Support\Facades\Route;

Route::get('workspaces/{workspacePublicId}/legal-entities/{entityPublicId}/environments/{environment}/documents/{documentPublicId}/agt-status', ExternalQualifiedAgtStatusController::class)
    ->name('integrations.v2.documents.agt-status.show');

Route::get('workspaces/{workspacePublicId}/legal-entities/{entityPublicId}/environments/{environment}/analytics/billing-summary', ExternalBillingSummaryController::class)
    ->name('integrations.v2.analytics.billing.show');
