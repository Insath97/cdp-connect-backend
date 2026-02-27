<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\Target;
use App\Models\Investment;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\InvestmentProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class DashboardController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:Dashboard View', only: ['index']),
        ];
    }

    /**
     * Get dashboard statistics based on user hierarchy.
     */
    public function index(Request $request)
    {
        try {
            $user = Auth::guard('api')->user();
            $periodKey = $request->get('period_key', Carbon::now()->format('Y-m'));

            // 1. Determine User Scope
            $isAdmin = $user->hasRole('Super Admin') || $user->user_type === 'admin';
            $descendantIds = [];

            if (!$isAdmin) {
                $descendantIds = $user->getAllDescendantIds();
            }

            // 2. Fetch Target Statistics
            $targetQuery = Target::where('period_key', $periodKey);

            if (!$isAdmin) {
                // Hierarchical logic: include self and all descendants
                $accessibleUserIds = array_merge([$user->id], $descendantIds);
                $targetQuery->whereIn('user_id', $accessibleUserIds);
            }

            $stats = $targetQuery->selectRaw('
                SUM(target_amount) as total_target,
                SUM(achieved_amount) as total_achieved
            ')->first();

            $totalTarget = (float) ($stats->total_target ?? 0);
            $totalAchieved = (float) ($stats->total_achieved ?? 0);
            $remaining = max(0, $totalTarget - $totalAchieved);

            $percentage = 0;
            if ($totalTarget > 0) {
                $percentage = ($totalAchieved / $totalTarget) * 100;
                $percentage = min($percentage, 999.99);
            } else {
                $percentage = $totalAchieved > 0 ? 100.00 : 0;
            }

            // 3. Business Performance Chart (Last 7 Months)
            $performanceChart = [];
            for ($i = 6; $i >= 0; $i--) {
                $date = Carbon::now()->subMonths($i);
                $monthKey = $date->format('Y-m');
                $monthLabel = $date->format('M');

                $monthTargetQuery = Target::where('period_key', $monthKey);
                if (!$isAdmin) {
                    $monthTargetQuery->whereIn('user_id', $accessibleUserIds);
                }

                $monthStats = $monthTargetQuery->selectRaw('
                    SUM(target_amount) as target,
                    SUM(achieved_amount) as revenue
                ')->first();

                $performanceChart[] = [
                    'month' => $monthLabel,
                    'revenue' => (float) ($monthStats->revenue ?? 0),
                    'target' => (float) ($monthStats->target ?? 0),
                ];
            }

            // 4. Additional Quick Stats (Hierarchy Aware)
            $customerCount = 0;
            $activeCustomerCount = 0;
            $approvedCustomerCount = 0;
            $quotationCount = 0;
            $investmentCount = 0;
            $approvedInvestmentCount = 0;
            $pendingInvestmentCount = 0;
            $totalInvestmentVolume = 0;

            if ($isAdmin) {
                $customerCount = Customer::count();
                $activeCustomerCount = Customer::where('is_active', true)->count();
                $investmentCount = Investment::count();
                $approvedInvestmentCount = Investment::where('status', 'approved')->count();
                $pendingInvestmentCount = Investment::where('status', 'pending')->count();
                $quotationCount = Quotation::count();
                $totalInvestmentVolume = Investment::where('status', 'approved')->sum('investment_amount');
                $approvedCustomerCount = Investment::where('status', 'approved')->distinct('customer_id')->count('customer_id');
            } else {
                // $accessibleUserIds set in step 2
                $customerCount = Customer::whereIn('customer_id', $accessibleUserIds)->count();
                $activeCustomerCount = Customer::whereIn('customer_id', $accessibleUserIds)->where('is_active', true)->count();

                $investmentCount = Investment::whereIn('created_by', $accessibleUserIds)->count();
                $approvedInvestmentCount = Investment::whereIn('created_by', $accessibleUserIds)->where('status', 'approved')->count();
                $pendingInvestmentCount = Investment::whereIn('created_by', $accessibleUserIds)
                    ->where('status', 'pending')
                    ->count();

                $quotationCount = Quotation::whereIn('created_by', $accessibleUserIds)->count();
                $totalInvestmentVolume = Investment::whereIn('created_by', $accessibleUserIds)
                    ->where('status', 'approved')
                    ->sum('investment_amount');

                $approvedCustomerCount = Investment::whereIn('created_by', $accessibleUserIds)
                    ->where('status', 'approved')
                    ->distinct('customer_id')
                    ->count('customer_id');
            }

            // 5. Revenue Distribution (Sector Overview)
            $distributionData = [];
            $distributionQuery = Investment::where('status', 'approved');

            if (!$isAdmin) {
                $distributionQuery->whereIn('created_by', $accessibleUserIds);
            }

            $productVolumes = $distributionQuery->join('investment_products', 'investments.investment_product_id', '=', 'investment_products.id')
                ->selectRaw('investment_products.name, SUM(investments.investment_amount) as volume')
                ->groupBy('investment_products.name')
                ->get();

            if ($totalInvestmentVolume > 0) {
                foreach ($productVolumes as $pv) {
                    $percentage = ($pv->volume / $totalInvestmentVolume) * 100;
                    $distributionData[] = [
                        'name' => $pv->name,
                        'value' => round($percentage, 2)
                    ];
                }
            } else {
                // Optional: Provide empty state or handle zero volume
                $distributionData = [];
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Dashboard data retrieved successfully',
                'data' => [
                    'period' => $periodKey,
                    'target_achievement' => [
                        'percentage' => round($percentage, 2),
                        'target_amount' => $totalTarget,
                        'achieved_amount' => $totalAchieved,
                        'remaining_amount' => $remaining,
                    ],
                    'performance_chart' => $performanceChart,
                    'revenue_distribution' => $distributionData,
                    'quick_stats' => [
                        'total_customers' => $customerCount,
                        'active_customers' => $activeCustomerCount,
                        'approved_customers' => $approvedCustomerCount,
                        'total_quotations' => $quotationCount,
                        'total_investments' => $investmentCount,
                        'approved_investments' => $approvedInvestmentCount,
                        'pending_approvals' => $pendingInvestmentCount,
                        'total_investment_volume' => round($totalInvestmentVolume, 2),
                    ],
                    'user_context' => [
                        'role' => $isAdmin ? 'Admin' : 'Hierarchy User',
                        'level' => $user->level?->name ?? 'N/A',
                        'descendants_count' => count($descendantIds)
                    ]
                ]
            ], 200);

        } catch (\Throwable $th) {
            Log::error('Dashboard data retrieval failed', [
                'error' => $th->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve dashboard data',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}
