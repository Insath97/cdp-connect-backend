<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\External\ExternalApiController;

Route::prefix('v1/external')->middleware('external.key')->group(function () {
    Route::get('employees-summary', [ExternalApiController::class, 'employeesSummary']);
});
