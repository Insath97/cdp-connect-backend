<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\V1\Investor\AuthController;

Route::prefix('v1/investor')->group(function () {
    Route::post('request-otp', [AuthController::class, 'requestOtp']);
    Route::post('resend-otp', [AuthController::class, 'resendOtp']);
    Route::post('verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('set-password', [AuthController::class, 'setPassword']);
    Route::post('login', [AuthController::class, 'login']);
});

Route::middleware(['auth:api', 'customer'])->prefix('v1/investor')->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);
});
