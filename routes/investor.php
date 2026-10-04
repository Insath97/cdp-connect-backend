<?php

use App\Http\Controllers\V1\Investor\AuthController;
use App\Http\Controllers\V1\Investor\HomeController;
use App\Http\Controllers\V1\Investor\PayoutController;
use Illuminate\Support\Facades\Route;

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

    // Home Dashboard Data
    Route::get('home', [HomeController::class, 'index']);

    // Monthly Payouts (list & analysis)
    Route::get('payouts', [PayoutController::class, 'index']);
    Route::get('payouts/analysis', [PayoutController::class, 'analysis']);
});
