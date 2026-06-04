<?php

namespace App\Http\Controllers\V1;

use App\Traits\ActivityLogTrait;

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
    use ActivityLogTrait;

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
            $isRegularAdmin = ($user->user_type === 'admin');
            $isAdminView = $isSuperAdmin || $isRegularAdmin;

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
            } elseif (!$isAdminView) {
                // Hierarchy logic: include self and all descendants for regular employees/managers
                $descendantIds = $user->getAllDescendantIds();
                $accessibleUserIds = array_merge([$user->id], $descendantIds);
            }

            // 2. Fetch Target Statistics
            $targetQuery = Target::query()->where('period_key', '=', $periodKey, 'and');

            if ($isBranchCoordinator) {
                $targetQuery->whereHas('user', function ($uq) use ($assignedBranchIds) {
                    $uq->whereIn('branch_id', $assignedBranchIds)
                        ->where('level_id', '=', 11, 'and'); // Target focus: Branch Managers
                });
            } elseif ($isAdminView) {
                $targetQuery->whereHas('user', function ($uq) {
                    $uq->where('level_id', '=', 1, 'and'); // Company view: GM (Level 1)
                });
            } else {
                // Hierarchy User: Show only their own aggregate target record
                $targetQuery->where('user_id', '=', $user->id, 'and');
            }

            $stats = $targetQuery->selectRaw('
                SUM(target_amount) as total_target,
                SUM(achieved_amount) as total_achieved
            ')->first();

            $totalTarget = (float) ($stats->total_target ?? 0);
            $totalAchieved = (float) ($stats->total_achieved ?? 0);

            // Use direct investment totals for real-time accuracy for high-level views
            if ($isBranchCoordinator || $isAdminView) {
                $revQuery = Investment::query()
                    ->where('status', '=', 'approved', 'and')
                    ->where('target_period_key', '=', $periodKey, 'and');
                
                if ($isBranchCoordinator) {
                    $revQuery->whereIn('branch_id', $assignedBranchIds, 'and', false);
                }

                $totalAchieved = (float) $revQuery->sum('investment_amount');
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

                $monthTargetQuery = Target::query()->where('period_key', '=', $monthKey, 'and');
                if ($isBranchCoordinator) {
                    $monthTargetQuery->whereHas('user', function ($uq) use ($assignedBranchIds) {
                        $uq->whereIn('branch_id', $assignedBranchIds)
                            ->where('level_id', '=', 11, 'and');
                    });
                } elseif ($isAdminView) {
                    $monthTargetQuery->whereHas('user', function ($uq) {
                        $uq->where('level_id', '=', 1, 'and');
                    });
                } else {
                    $monthTargetQuery->where('user_id', '=', $user->id, 'and');
                }

                $monthStats = $monthTargetQuery->selectRaw('
                    SUM(target_amount) as target,
                    SUM(achieved_amount) as revenue
                ')->first();

                $monthRevenue = (float) ($monthStats->revenue ?? 0);

                if ($isBranchCoordinator) {
                    $monthRevenue = (float) Investment::query()
                        ->whereIn('branch_id', $assignedBranchIds)
                        ->where('status', '=', 'approved', 'and')
                        ->where('target_period_key', '=', $monthKey, 'and')
                        ->sum('investment_amount');
                }

                $performanceChart[] = [
                    'month' => $monthLabel,
                    'revenue' => $monthRevenue,
                    'target' => (float) ($monthStats->target ?? 0),
                ];
            }

            // 4. Additional Quick Stats (Hierarchy/Branch Aware)
            $customerBaseQuery = Customer::query()->where('created_at', 'like', "{$periodKey}%", 'and');
            $investmentBaseQuery = Investment::query()->where('target_period_key', '=', $periodKey, 'and');
            $quotationBaseQuery = Quotation::query()->where('created_at', 'like', "{$periodKey}%", 'and');

            if ($isBranchCoordinator) {
                $customerBaseQuery->whereHas('user', fn($uq) => $uq->whereIn('branch_id', $assignedBranchIds));
                $investmentBaseQuery->whereIn('branch_id', $assignedBranchIds, 'and', false);
                $quotationBaseQuery->whereIn('branch_id', $assignedBranchIds, 'and', false);
            } elseif (!$isAdminView) {
                // Scoped view for hierarchy users
                $customerBaseQuery->whereIn('customer_id', $accessibleUserIds);
                $investmentBaseQuery->whereIn('created_by', $accessibleUserIds, 'and', false);
                $quotationBaseQuery->whereIn('created_by', $accessibleUserIds, 'and', false);
            }
            // Admins see global view (no extra filters)

            $customerCount = (clone $customerBaseQuery)->count();
            $activeCustomerCount = (clone $customerBaseQuery)->where('is_active', '=', true, 'and')->count();

            $investmentCount = (clone $investmentBaseQuery)->count();
            $approvedInvestmentCount = (clone $investmentBaseQuery)->where('status', '=', 'approved', 'and')->count();
            $pendingInvestmentCount = (clone $investmentBaseQuery)->where('status', '=', 'pending', 'and')->count();

            $quotationCount = $quotationBaseQuery->count();
            $totalInvestmentVolume = (clone $investmentBaseQuery)->where('status', '=', 'approved', 'and')->sum('investment_amount');

            $approvedCustomerCount = (clone $investmentBaseQuery)
                ->where('status', '=', 'approved', 'and')
                ->distinct()
                ->count('customer_id');

            // 5. Revenue Distribution (Sector Overview)
            $distributionData = [];
            $distributionQuery = Investment::query()
                ->where('investments.status', '=', 'approved', 'and')
                ->where('investments.target_period_key', '=', $periodKey, 'and');

            if ($isBranchCoordinator) {
                $distributionQuery->whereIn('investments.branch_id', $assignedBranchIds, 'and', false);
            } elseif (!$isAdminView) {
                $distributionQuery->whereIn('created_by', $accessibleUserIds, 'and', false);
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
                        'role' => $isSuperAdmin ? 'Super Admin' : ($isBranchCoordinator ? 'Branch Coordinator' : ($isRegularAdmin ? 'Admin' : 'Hierarchy User')),
                        'level' => $user->level?->name ?? 'N/A',
                        'descendants_count' => count($descendantIds),
                        'assigned_branches_count' => count($assignedBranchIds)
                    ]
                ]
            ], 200);
        } catch (\Throwable $th) {
            $this->logActivity('Error', 'Dashboard', 'Dashboard data retrieval failed', [
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
