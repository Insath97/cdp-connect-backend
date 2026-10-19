<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\External\PortalController;

/* portal & verification routes (secured by X-Portal-Key header) */
Route::prefix('v1/portal')->middleware(['portal.key', 'throttle:30,1'])->group(function () {
    Route::post('verify-investment', [PortalController::class, 'verify']);
    Route::get('investment-products', [PortalController::class, 'getAvailableInvestmentProducts']);
    Route::post('calculate-return', [PortalController::class, 'calculateReturn']);
});
