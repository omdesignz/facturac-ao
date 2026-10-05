<?php

use App\Http\Controllers\WiPayCallbackController;
use Illuminate\Support\Facades\Route;

Route::post('/wipay', WiPayCallbackController::class)
    ->middleware('throttle:600,1')
    ->name('webhooks.wipay');
