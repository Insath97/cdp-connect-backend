<?php

use App\Http\Controllers\V1\Admin\AdminUserController;
use App\Http\Controllers\V1\Admin\AuthController;
use App\Http\Controllers\V1\Admin\BrandController;
use App\Http\Controllers\V1\Admin\CategoryController;
use App\Http\Controllers\V1\Admin\CouponController;
use App\Http\Controllers\V1\Admin\CustomerController;
use App\Http\Controllers\V1\Admin\ProductController;
use App\Http\Controllers\V1\Admin\ProductVariantController;
use App\Http\Controllers\V1\PermissionController;
use App\Http\Controllers\V1\RoleController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/admin')->group(function () {

    Route::post('login', [AuthController::class, 'login']);
});

// Protected admin routes
Route::middleware(['auth:api', 'admin.auth'])->prefix('v1/admin')->group(function () {

    Route::get('me', [AuthController::class, 'me']);
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('dashboard', [\App\Http\Controllers\V1\Admin\AdminController::class, 'dashboard']);


    Route::apiResource('permissions', PermissionController::class);

    Route::get('roles/list/', [RoleController::class, 'getAvailableRoles']);
    Route::apiResource('roles', RoleController::class);

    Route::apiResource('users', AdminUserController::class);
    Route::prefix('users')->group(function () {
        Route::patch('{id}/activate', [AdminUserController::class, 'activate']);
        Route::patch('{id}/deactivate', [AdminUserController::class, 'deactivate']);
        Route::patch('{id}/profile-image', [AdminUserController::class, 'updateProfileImage']);
        Route::delete('{id}/profile-image', [AdminUserController::class, 'removeProfileImage']);
    });

    Route::apiResource('customers', CustomerController::class);
    Route::prefix('customers')->group(function () {
        Route::patch('{id}/activate', [CustomerController::class, 'activate']);
        Route::patch('{id}/deactivate', [CustomerController::class, 'deactivate']);
        Route::patch('{id}/verify', [CustomerController::class, 'verify']);
    });

    Route::apiResource('brands', BrandController::class);
    Route::prefix('brands')->group(function () {
        Route::patch('{id}/activate', [BrandController::class, 'activateBrand']);
        Route::patch('{id}/deactivate', [BrandController::class, 'deactivateBrand']);
        Route::delete('{id}/force', [BrandController::class, 'forceDestroy']);
        Route::patch('{id}/logo', [BrandController::class, 'updateLogo']);
        Route::delete('{id}/logo', [BrandController::class, 'removeLogo']);
        Route::post('{id}/restore', [BrandController::class, 'restore']);
        Route::patch('{id}/toggle-featured', [BrandController::class, 'toggleFeatured']);
    });

    Route::apiResource('categories', CategoryController::class);
    Route::prefix('categories')->group(function () {
        Route::patch('{id}/activate', [CategoryController::class, 'activate']);
        Route::patch('{id}/deactivate', [CategoryController::class, 'deactivate']);
        Route::patch('{id}/toggle-featured', [CategoryController::class, 'toggleFeatured']);
        Route::delete('{id}/force', [CategoryController::class, 'forceDestroy']);
        Route::post('{id}/restore', [CategoryController::class, 'restore']);
        Route::post('bulk-actions', [CategoryController::class, 'bulkActions']);
        Route::get('active/list', [CategoryController::class, 'activeList']);
    });

    Route::apiResource('products', ProductController::class);
    Route::prefix('products')->group(function () {
        Route::post('{id}/restore', [ProductController::class, 'restore']);
        Route::delete('{id}/force', [ProductController::class, 'forceDestroy']);
        Route::patch('{id}/activate', [ProductController::class, 'activate']);
        Route::patch('{id}/deactivate', [ProductController::class, 'deactivate']);
        Route::patch('{id}/primary-image', [ProductController::class, 'updatePrimaryImage']);
        Route::delete('{id}/primary-image', [ProductController::class, 'removePrimaryImage']);
        Route::patch('{id}/publish', [ProductController::class, 'publish']);
        Route::patch('{id}/archive', [ProductController::class, 'archive']);
        Route::patch('{id}/set-draft', [ProductController::class, 'setAsDraft']);
        Route::patch('{id}/toggle-trending', [ProductController::class, 'toggleTrending']);
        Route::patch('{id}/toggle-featured', [ProductController::class, 'toggleFeatured']);
        Route::get('get/tags', [ProductController::class, 'getTags']);
        Route::get('get/features', [ProductController::class, 'getFeatures']);
    });

    Route::apiResource('product-variants', ProductVariantController::class);
    Route::prefix('product-variants')->group(function () {
        Route::patch('{id}/activate', [ProductVariantController::class, 'activate']);
        Route::patch('{id}/deactivate', [ProductVariantController::class, 'deactivate']);
        Route::post('{id}/restore', [ProductVariantController::class, 'restore']);
        Route::delete('{id}/force', [ProductVariantController::class, 'forceDestroy']);
        Route::patch('{id}/toggle-active', [ProductVariantController::class, 'toggleActive']);
        Route::patch('{id}/toggle-featured', [ProductVariantController::class, 'toggleFeatured']);
        Route::patch('{id}/toggle-trending', [ProductVariantController::class, 'toggleTrending']);
    });

    Route::apiResource('coupons', CouponController::class);
    Route::prefix('coupons')->group(function () {
        Route::patch('{id}/activate', [CouponController::class, 'activate']);
        Route::patch('{id}/deactivate', [CouponController::class, 'deactivate']);
        Route::patch('{id}/toggle-active', [CouponController::class, 'toggleActive']);
    });
});
