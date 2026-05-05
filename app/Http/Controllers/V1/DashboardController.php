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
            $isSuperAdmin = $user->hasRole('Super Admin');
            $isBranchCoordinator = $user->hasRole('Branch Coordinator');
            $descendantIds = [];
            $assignedBranchIds = [];
            $accessibleUserIds = [];

            if ($isBranchCoordinator) {
                $assignedBranchIds = $user->assignedBranches()->pluck('branches.id')->toArray();

                // If a specific branch is requested, validate and restrict scope
                $requestBranchId = $request->get('branch_id');
                if ($requestBranchId) {
                    if (in_array($requestBranchId, $assignedBranchIds)) {
                        $assignedBranchIds = [$requestBranchId];
                    } else {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'Unauthorized access to this branch data.'
                        ], 403);
                    }
                }
            } elseif (!$isSuperAdmin) {
                // Hierarchy logic: include self and all descendants for everyone else
                $descendantIds = $user->getAllDescendantIds();
                $accessibleUserIds = array_merge([$user->id], $descendantIds);
            }

            // 2. Fetch Target Statistics
            $targetQuery = Target::query()->where('period_key', $periodKey);

            if ($isBranchCoordinator) {
                $targetQuery->whereHas('user', function ($uq) use ($assignedBranchIds) {
                    $uq->whereIn('branch_id', $assignedBranchIds)
                        ->where('level_id', 6); // Target focus: Branch Managers
                });
            } elseif (!$isSuperAdmin) {
                $targetQuery->whereIn('user_id', $accessibleUserIds);
            }

            $stats = $targetQuery->selectRaw('
                SUM(target_amount) as total_target,
                SUM(achieved_amount) as total_achieved
            ')->first();

            $totalTarget = (float) ($stats->total_target ?? 0);
            $totalAchieved = (float) ($stats->total_achieved ?? 0);

            // For Branch Coordinators, use direct investment totals for real-time accuracy
            if ($isBranchCoordinator) {
                $totalAchieved = (float) Investment::query()
                    ->whereIn('branch_id', $assignedBranchIds)
                    ->where('status', 'approved')
                    ->where('target_period_key', $periodKey)
                    ->sum('investment_amount');
            }

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

                $monthTargetQuery = Target::query()->where('period_key', $monthKey);
                if ($isBranchCoordinator) {
                    $monthTargetQuery->whereHas('user', function ($uq) use ($assignedBranchIds) {
                        $uq->whereIn('branch_id', $assignedBranchIds)
                            ->where('level_id', 6);
                    });
                } elseif (!$isSuperAdmin) {
                    $monthTargetQuery->whereIn('user_id', $accessibleUserIds);
                }

                $monthStats = $monthTargetQuery->selectRaw('
                    SUM(target_amount) as target,
                    SUM(achieved_amount) as revenue
                ')->first();

                $monthRevenue = (float) ($monthStats->revenue ?? 0);

                if ($isBranchCoordinator) {
                    $monthRevenue = (float) Investment::query()
                        ->whereIn('branch_id', $assignedBranchIds)
                        ->where('status', 'approved')
                        ->where('target_period_key', $monthKey)
                        ->sum('investment_amount');
                }

                $performanceChart[] = [
                    'month' => $monthLabel,
                    'revenue' => $monthRevenue,
                    'target' => (float) ($monthStats->target ?? 0),
                ];
            }

            // 4. Additional Quick Stats (Hierarchy/Branch Aware)
            $customerBaseQuery = Customer::query()->where('created_at', 'like', "{$periodKey}%");
            $investmentBaseQuery = Investment::query()->where('target_period_key', $periodKey);
            $quotationBaseQuery = Quotation::query()->where('created_at', 'like', "{$periodKey}%");

            if ($isBranchCoordinator) {
                $customerBaseQuery->whereHas('user', fn($uq) => $uq->whereIn('branch_id', $assignedBranchIds));
                $investmentBaseQuery->whereIn('branch_id', $assignedBranchIds);
                $quotationBaseQuery->whereIn('branch_id', $assignedBranchIds);
            } elseif (!$isSuperAdmin) {
                $customerBaseQuery->whereIn('customer_id', $accessibleUserIds);
                $investmentBaseQuery->whereIn('created_by', $accessibleUserIds);
                $quotationBaseQuery->whereIn('created_by', $accessibleUserIds);
            }

            $customerCount = (clone $customerBaseQuery)->count();
            $activeCustomerCount = (clone $customerBaseQuery)->where('is_active', true)->count();
            
            $investmentCount = (clone $investmentBaseQuery)->count();
            $approvedInvestmentCount = (clone $investmentBaseQuery)->where('status', 'approved')->count();
            $pendingInvestmentCount = (clone $investmentBaseQuery)->where('status', 'pending')->count();
            
            $quotationCount = $quotationBaseQuery->count();
            $totalInvestmentVolume = (clone $investmentBaseQuery)->where('status', 'approved')->sum('investment_amount');
            
            $approvedCustomerCount = (clone $investmentBaseQuery)
                ->where('status', 'approved')
                ->distinct()
                ->count('customer_id');

            // 5. Revenue Distribution (Sector Overview)
            $distributionData = [];
            $distributionQuery = Investment::query()
                ->where('investments.status', 'approved')
                ->where('investments.target_period_key', $periodKey);

            if ($isBranchCoordinator) {
                $distributionQuery->whereIn('investments.branch_id', $assignedBranchIds);
            } elseif (!$isSuperAdmin) {
                $distributionQuery->whereIn('created_by', $accessibleUserIds);
            }

            $productVolumes = $distributionQuery->join('investment_products', 'investments.investment_product_id', '=', 'investment_products.id')
                ->selectRaw('investment_products.name, SUM(investments.investment_amount) as volume')
                ->groupBy('investment_products.name')
                ->get();

            if ($totalInvestmentVolume > 0) {
                foreach ($productVolumes as $pv) {
                    $itemPercentage = ($pv->volume / $totalInvestmentVolume) * 100;
                    $distributionData[] = [
                        'name' => $pv->name,
                        'value' => round($itemPercentage, 2)
                    ];
                }
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
                        'role' => $isSuperAdmin ? 'Super Admin' : ($isBranchCoordinator ? 'Branch Coordinator' : 'Hierarchy User'),
                        'level' => $user->level?->name ?? 'N/A',
                        'descendants_count' => count($descendantIds),
                        'assigned_branches_count' => count($assignedBranchIds)
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
