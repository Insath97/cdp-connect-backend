<?php

use App\Http\Controllers\V1\AuthController;
use App\Http\Controllers\V1\LevelController;
use Illuminate\Support\Facades\Route;


/* public routes */

Route::prefix('v1')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
});

/* protected routes */
Route::middleware(['auth:api'])->prefix('v1')->group(function () {

    /* levels routes */
    Route::apiResource('levels', LevelController::class);
});

