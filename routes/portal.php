<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\V1\CustomerVerificationController;

/* portal & verification routes (secured by X-Portal-Key header) */
Route::prefix('v1/portal')->middleware(['portal.key', 'throttle:30,1'])->group(function () {
    Route::post('verify-investment', [CustomerVerificationController::class, 'verify']);
});
