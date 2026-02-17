<?php

use App\Http\Controllers\V1\AuthController;
use App\Http\Controllers\V1\CountryController;
use App\Http\Controllers\V1\PermissionController;
use App\Http\Controllers\V1\ProvinceController;
use App\Http\Controllers\V1\RegionController;
use App\Http\Controllers\V1\RoleController;
use App\Http\Controllers\V1\ZoneController;
use App\Http\Controllers\V1\BranchController;
use App\Http\Controllers\V1\CustomerController;
use App\Http\Controllers\V1\UserController;
use App\Http\Controllers\V1\InvestmentProductController;
use App\Http\Controllers\V1\TargetController;
use App\Http\Controllers\V1\LevelController;
use Illuminate\Support\Facades\Route;

/* public routes */
Route::prefix('v1')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
});

/* protected routes */
Route::middleware(['auth:api'])->prefix('v1')->group(function () {

    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);

    Route::apiResource('permissions', PermissionController::class);

    Route::get('roles/list/', [RoleController::class, 'getAvailableRoles']);
    Route::apiResource('roles', RoleController::class);

    Route::apiResource('levels', LevelController::class);

    Route::apiResource('countries', CountryController::class);
    Route::patch('countries/{id}/toggle-status', [CountryController::class, 'toggleStatus']);

    Route::apiResource('provinces', ProvinceController::class);
    Route::patch('provinces/{id}/toggle-status', [ProvinceController::class, 'toggleStatus']);

    Route::apiResource('regions', RegionController::class);
    Route::patch('regions/{id}/toggle-status', [RegionController::class, 'toggleStatus']);

    Route::apiResource('zones', ZoneController::class);
    Route::patch('zones/{id}/toggle-status', [ZoneController::class, 'toggleStatus']);

    Route::apiResource('branches', BranchController::class);
    Route::patch('branches/{id}/toggle-status', [BranchController::class, 'toggleStatus']);

    Route::apiResource('users', UserController::class);
    Route::patch('users/{id}/toggle-status', [UserController::class, 'toggleStatus']);

    Route::apiResource('investment-products', InvestmentProductController::class);
    Route::patch('investment-products/{id}/toggle-status', [InvestmentProductController::class, 'toggleStatus']);

    Route::apiResource('targets', TargetController::class);
    Route::get('my-targets', [TargetController::class, 'myTargets']);
    Route::patch('targets/{id}/active', [TargetController::class, 'markActive']);
    Route::patch('targets/{id}/achieved', [TargetController::class, 'markAchieved']);
    Route::patch('targets/{id}/expired', [TargetController::class, 'markExpired']);

    // Customer Routes
    Route::apiResource('customers', CustomerController::class);
    Route::patch('customers/{id}/restore', [CustomerController::class, 'restore']);
    Route::delete('customers/{id}/force', [CustomerController::class, 'forceDelete']);
    Route::patch('customers/{id}/toggle-status', [CustomerController::class, 'toggleStatus']);
});
