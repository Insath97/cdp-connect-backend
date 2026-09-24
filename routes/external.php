<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\External\ExternalApiController;

// Existing external integrations (e.g. HRMS employees summary)
Route::prefix('v1/external')->middleware('external.key')->group(function () {
    Route::get('employees-summary', [ExternalApiController::class, 'employeesSummary']);
});

// Credix application integration (Customer & Investment Verification)
Route::prefix('v1/external')->middleware('credix.key')->group(function () {
    Route::match(['get', 'post'], 'customer-investments', [ExternalApiController::class, 'customerInvestments']);
});
