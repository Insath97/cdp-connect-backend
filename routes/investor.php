<?php

use App\Http\Controllers\V1\Investor\AuthController;
use App\Http\Controllers\V1\Investor\BranchController;
use App\Http\Controllers\V1\Investor\HomeController;
use App\Http\Controllers\V1\Investor\InvestmentController;
use App\Http\Controllers\V1\Investor\InvestmentProductController;
use App\Http\Controllers\V1\Investor\PayoutController;
use App\Http\Controllers\V1\Investor\QuotationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/investor')->group(function () {
    Route::post('request-otp', [AuthController::class, 'requestOtp']);
    Route::post('resend-otp', [AuthController::class, 'resendOtp']);
    Route::post('verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('set-password', [AuthController::class, 'setPassword']);
    Route::post('login', [AuthController::class, 'login']);

    // Investment plans catalog & branches catalog
    Route::get('investment-products', [InvestmentController::class, 'products']);
    Route::get('branches', [BranchController::class, 'getAvailableBranches']);
});

Route::middleware(['auth:api', 'customer'])->prefix('v1/investor')->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);
    Route::match(['post', 'patch'], 'change-password', [AuthController::class, 'changePassword']);

    // Home Dashboard Data
    Route::get('home', [HomeController::class, 'index']);

    // Investments (summary, list, details & single investment payouts)
    Route::get('investments/summary', [InvestmentController::class, 'summary']);
    Route::get('investments', [InvestmentController::class, 'index']);
    Route::get('investments/{id}', [InvestmentController::class, 'show']);
    Route::get('investments/{id}/payouts', [InvestmentController::class, 'payouts']);

    // Monthly Payouts (list & analysis)
    Route::get('payouts', [PayoutController::class, 'index']);
    Route::get('payouts/analysis', [PayoutController::class, 'analysis']);

    // Investment Products & Branches
    Route::get('investment-products', [InvestmentProductController::class, 'getAvailableInvestmentProducts']);
    Route::get('branches', [BranchController::class, 'getAvailableBranches']);

    // Quotations (calculate, create, list, view & update)
    Route::post('quotations/calculate', [QuotationController::class, 'calculate']);
    Route::get('quotations', [QuotationController::class, 'index']);
    Route::post('quotations', [QuotationController::class, 'store']);
    Route::get('quotations/{id}', [QuotationController::class, 'show']);
    Route::match(['put', 'patch'], 'quotations/{id}', [QuotationController::class, 'update']);
});

