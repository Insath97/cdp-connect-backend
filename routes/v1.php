<?php

use App\Http\Controllers\V1\AuthController;
use App\Http\Controllers\V1\CountryController;
use App\Http\Controllers\V1\InvestmentController;
use App\Http\Controllers\V1\PermissionController;
use App\Http\Controllers\V1\ProvinceController;
use App\Http\Controllers\V1\RegionController;
use App\Http\Controllers\V1\RoleController;
use App\Http\Controllers\V1\ZoneController;
use App\Http\Controllers\V1\BranchController;
use App\Http\Controllers\V1\CustomerController;
use App\Http\Controllers\V1\UserController;
use App\Http\Controllers\V1\InvestmentProductController;
use App\Http\Controllers\V1\LevelController;
use App\Http\Controllers\V1\TargetController;
use App\Http\Controllers\V1\TargetProgressController;
use App\Http\Controllers\V1\QuotationController;
use App\Http\Controllers\V1\ReceiptController;
use App\Http\Controllers\V1\DashboardController;
use App\Http\Controllers\V1\ProfileController;
use App\Http\Controllers\V1\ReportController;
use App\Http\Controllers\V1\ImportController;
use App\Http\Controllers\V1\DatabaseController;
use App\Http\Controllers\V1\SmsController;
use App\Http\Controllers\V1\MaintenanceController;
use App\Http\Controllers\V1\InvestmentPayoutController;
use App\Http\Controllers\V1\WelcomeCallController;
use App\Http\Controllers\V1\LegalController;
use Illuminate\Support\Facades\Route;

/* public routes */

Route::prefix('v1')->group(function () {
    Route::post('login', [AuthController::class, 'login']);

    // Customer Public Details
    Route::get('customers/public-details/{customer_code?}', [CustomerController::class, 'getPublicDetails']);

});

/* protected routes */
Route::middleware(['auth:api'])->prefix('v1')->group(function () {

    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);

    // Profile Routes
    Route::get('profile', [ProfileController::class, 'show']);
    Route::patch('profile', [ProfileController::class, 'update']);
    // Alias for PUT if preferred
    Route::put('profile', [ProfileController::class, 'update']);
    Route::patch('profile/change-password', [ProfileController::class, 'changePassword']);

    Route::get('dashboard', [DashboardController::class, 'index']);

    Route::get('permissions/list', [PermissionController::class, 'getAvailablePermissions']);
    Route::apiResource('permissions', PermissionController::class);

    Route::get('roles/list/', [RoleController::class, 'getAvailableRoles']);
    Route::apiResource('roles', RoleController::class);

    Route::get('levels/list', [LevelController::class, 'getAvailableLevels']);
    Route::apiResource('levels', LevelController::class);

    Route::get('countries/list', [CountryController::class, 'getAvailableCountries']);
    Route::apiResource('countries', CountryController::class);
    Route::patch('countries/{id}/toggle-status', [CountryController::class, 'toggleStatus']);

    Route::get('provinces/list', [ProvinceController::class, 'getAvailableProvinces']);
    Route::apiResource('provinces', ProvinceController::class);
    Route::patch('provinces/{id}/toggle-status', [ProvinceController::class, 'toggleStatus']);

    Route::get('regions/list', [RegionController::class, 'getAvailableRegions']);
    Route::apiResource('regions', RegionController::class);
    Route::patch('regions/{id}/toggle-status', [RegionController::class, 'toggleStatus']);

    Route::get('zones/list', [ZoneController::class, 'getAvailableZones']);
    Route::apiResource('zones', ZoneController::class);
    Route::patch('zones/{id}/toggle-status', [ZoneController::class, 'toggleStatus']);

    Route::get('branches/list', [BranchController::class, 'getAvailableBranches']);
    Route::apiResource('branches', BranchController::class);
    Route::patch('branches/{id}/toggle-status', [BranchController::class, 'toggleStatus']);

    Route::get('users/hierarchy-list', [UserController::class, 'getHierarchyUsersByBranch']);
    Route::get('users/list', [UserController::class, 'getAvailableUsers']);
    Route::apiResource('users', UserController::class);
    Route::patch('users/{id}/toggle-status', [UserController::class, 'toggleStatus']);

    Route::get('investment-products/list', [InvestmentProductController::class, 'getAvailableInvestmentProducts']);
    Route::apiResource('investment-products', InvestmentProductController::class);
    Route::patch('investment-products/{id}/toggle-status', [InvestmentProductController::class, 'toggleStatus']);

    Route::apiResource('targets', TargetController::class);
    Route::get('my-targets', [TargetController::class, 'myTargets']);
    Route::post('targets/bulk-setup', [TargetController::class, 'bulkSetup']);
    Route::patch('targets/{id}/active', [TargetController::class, 'markActive']);
    Route::patch('targets/{id}/achieved', [TargetController::class, 'markAchieved']);
    Route::patch('targets/{id}/expired', [TargetController::class, 'markExpired']);

    Route::apiResource('customers', CustomerController::class);
    Route::patch('customers/{id}/restore', [CustomerController::class, 'restore']);
    Route::delete('customers/{id}/force', [CustomerController::class, 'forceDelete']);
    Route::patch('customers/{id}/toggle-status', [CustomerController::class, 'toggleStatus']);

    Route::apiResource('quotations', QuotationController::class);
    Route::patch('quotations/{id}/restore', [QuotationController::class, 'restore']);
    Route::delete('quotations/{id}/force', [QuotationController::class, 'forceDelete']);
    Route::patch('quotations/{id}/toggle-status', [QuotationController::class, 'toggleStatus']);

    Route::get('investments/maturity-report', [InvestmentController::class, 'investorMaturity']);
    Route::apiResource('investments', InvestmentController::class);
    Route::patch('investments/{id}/approve', [InvestmentController::class, 'approve']);
    Route::post('investments/{id}/cancel', [InvestmentController::class, 'cancel']);
    Route::post('investments/{id}/terminate', [InvestmentController::class, 'terminate']);
    Route::delete('investments/{id}/approved-delete', [InvestmentController::class, 'destroyApprovedInvestement']);
    Route::get('investments/{id}/certificate', [InvestmentController::class, 'printCertificate']);

    Route::apiResource('legals', LegalController::class);

    // Welcome Call Routes
    Route::get('welcome-calls', [WelcomeCallController::class, 'index']);
    Route::get('welcome-calls/{id}', [WelcomeCallController::class, 'show']);
    // Alias for 'upgrade' or 'update status'
    Route::patch('welcome-calls/{id}/status', [WelcomeCallController::class, 'updateStatus']);

    // Investment Payouts
    Route::get('investment-payouts', [InvestmentPayoutController::class, 'index']);
    Route::post('investment-payouts/sync-legacy', [InvestmentPayoutController::class, 'generateLegacyPayouts']);
    Route::patch('investment-payouts/{id}/status', [InvestmentPayoutController::class, 'updateStatus']);

    Route::get('target-progress', [TargetProgressController::class, 'index']);
    Route::get('target-progress/{period_key}', [TargetProgressController::class, 'show']);

    Route::apiResource('receipts', ReceiptController::class)->only(['store', 'show']);
    Route::get('investments/{investmentId}/receipts', [ReceiptController::class, 'indexByInvestment']);

    Route::get('reports/hierarchy', [ReportController::class, 'index']);
    Route::get('reports/agent-performance', [ReportController::class, 'agentPerformance']);
    Route::get('reports/hierarchy-performance', [ReportController::class, 'hierarchyPerformance']);
    Route::get('reports/hierarchy-detailed', [ReportController::class, 'hierarchyDetailedReport']);
    Route::get('reports/hierarchy-date-wise', [ReportController::class, 'hierarchyDateWiseReport']);
    Route::get('reports/investor-maturity', [ReportController::class, 'investorMaturity']);
    Route::get('reports/plan-wise-hierarchy', [ReportController::class, 'planWiseHierarchyReport']);
    Route::get('reports/hierarchy/{id}', [ReportController::class, 'show']);
    Route::get('reports/aa',[ReportController::class, 'buildPlanWiseHierarchyNode']);

    Route::get('imports/tables/list', [ImportController::class, 'listTables']);
    Route::get('imports', [ImportController::class, 'index']);
    Route::post('imports/{table}', [ImportController::class, 'import']);

    // Database Management
    Route::get('database/export', [DatabaseController::class, 'export']);
    Route::post('database/import', [DatabaseController::class, 'import']);

    // Bulk SMS Public API
    Route::post('sms/send', [SmsController::class, 'send']);
    Route::post('sms/import-send', [SmsController::class, 'importAndSend']);
    Route::post('sms/send-to-all', [SmsController::class, 'sendToAllCustomers']);

    // Maintenance Routes
    Route::post('maintenance/recalculate-targets', [MaintenanceController::class, 'recalculateTargets']);
});
