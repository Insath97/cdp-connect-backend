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
use App\Models\User;
use Illuminate\Support\Facades\DB;
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
            new Middleware('permission:Dashboard Top Performance View', only: ['topPerformance']),
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
                $investmentBaseQuery->where(function ($q) use ($user) {
                    $q->where('unit_head_id', $user->id)
                      ->orWhereHas('hierarchySnapshot', function ($sq) use ($user) {
                          $sq->where('ancestor_id', $user->id);
                      });
                });
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
                $distributionQuery->where(function ($q) use ($user) {
                    $q->where('unit_head_id', $user->id)
                      ->orWhereHas('hierarchySnapshot', function ($sq) use ($user) {
                          $sq->where('ancestor_id', $user->id);
                      });
                });
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

    /**
     * Get top performance dashboard metrics.
     */
    public function topPerformance(Request $request)
    {
        try {
            $user = Auth::guard('api')->user();

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
            } elseif (!$isAdminView) {
                $descendantIds = $user->getAllDescendantIds();
                $accessibleUserIds = array_merge([$user->id], $descendantIds);
            }

            // 2. Parse Date Filters for the Current/Selected Period
            $fromDate = $request->get('from_date');
            $toDate = $request->get('to_date');
            $periodKeyInput = $request->get('period_key');
            $limit = (int) $request->get('limit', 3);
            if ($limit < 1) $limit = 3;
            if ($limit > 50) $limit = 50;

            if ($fromDate && $toDate) {
                $from = Carbon::parse($fromDate)->startOfDay();
                $to = Carbon::parse($toDate)->endOfDay();
                $periodKey = $from->format('Y-m');
            } elseif ($periodKeyInput) {
                $periodKey = $periodKeyInput;
                $from = Carbon::parse($periodKey . '-01')->startOfMonth();
                $to = Carbon::parse($periodKey . '-01')->endOfMonth();
            } else {
                $periodKey = Carbon::now()->format('Y-m');
                $from = Carbon::now()->startOfMonth();
                $to = Carbon::now()->endOfMonth();
            }

            // 3. Compute Current Performance
            $currentData = $this->getTopPerformersData(
                $from,
                $to,
                $periodKey,
                $limit,
                $isBranchCoordinator,
                $assignedBranchIds,
                $isAdminView,
                $accessibleUserIds
            );

            // 4. Compute Last 3 Months Performance History
            $last3MonthsData = [];
            // We use the start of the current $from as the base date
            for ($i = 1; $i <= 3; $i++) {
                $pastMonthDate = (clone $from)->subMonths($i);
                $pastFrom = (clone $pastMonthDate)->startOfMonth();
                $pastTo = (clone $pastMonthDate)->endOfMonth();
                $pastPeriodKey = $pastMonthDate->format('Y-m');

                $last3MonthsData[] = $this->getTopPerformersData(
                    $pastFrom,
                    $pastTo,
                    $pastPeriodKey,
                    $limit,
                    $isBranchCoordinator,
                    $assignedBranchIds,
                    $isAdminView,
                    $accessibleUserIds
                );
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Dashboard top performance data retrieved successfully',
                'data' => [
                    'current_performance' => $currentData,
                    'last_3_months' => $last3MonthsData
                ]
            ], 200);

        } catch (\Throwable $th) {
            $this->logActivity('Error', 'Dashboard', 'Dashboard top performance data retrieval failed', [
                'error' => $th->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve dashboard top performance data',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Helper to compute top performers for a specific date range and scope.
     */
    private function getTopPerformersData($from, $to, $periodKey, $limit, $isBranchCoordinator, $assignedBranchIds, $isAdminView, $accessibleUserIds)
    {
        // 1. Query Approved Investments
        $investmentQuery = Investment::query()
            ->where('status', '=', 'approved', 'and')
            ->whereBetween('reservation_date', [$from, $to]);

        if ($isBranchCoordinator) {
            $investmentQuery->whereIn('branch_id', $assignedBranchIds);
        } elseif (!$isAdminView) {
            $user = Auth::guard('api')->user();
            $investmentQuery->where(function ($q) use ($user) {
                $q->where('unit_head_id', $user->id)
                  ->orWhereHas('hierarchySnapshot', function ($sq) use ($user) {
                      $sq->where('ancestor_id', $user->id);
                  });
            });
        }

        $sales = $investmentQuery
            ->select('unit_head_id')
            ->selectRaw('SUM(investment_amount) as total_amount')
            ->selectRaw('COUNT(*) as total_count')
            ->groupBy('unit_head_id')
            ->with(['unitHead.level', 'unitHead.branch'])
            ->get();

        // 2. Compute Direct Top Performers by Amount
        $directByAmount = $sales->filter(fn($s) => !is_null($s->unitHead))
            ->sortByDesc(fn($s) => (float)$s->total_amount)
            ->take($limit)
            ->map(function ($s) {
                return [
                    'user_id' => $s->unit_head_id,
                    'name' => $s->unitHead->name,
                    'employee_code' => $s->unitHead->employee_code,
                    'level' => $s->unitHead->level->level_name ?? 'N/A',
                    'branch' => $s->unitHead->branch->name ?? 'N/A',
                    'total_amount' => (float)$s->total_amount,
                    'total_count' => (int)$s->total_count,
                ];
            })
            ->values()
            ->toArray();

        // 3. Compute Direct Top Performers by Count
        $directByCount = $sales->filter(fn($s) => !is_null($s->unitHead))
            ->sortByDesc(fn($s) => (int)$s->total_count)
            ->take($limit)
            ->map(function ($s) {
                return [
                    'user_id' => $s->unit_head_id,
                    'name' => $s->unitHead->name,
                    'employee_code' => $s->unitHead->employee_code,
                    'level' => $s->unitHead->level->level_name ?? 'N/A',
                    'branch' => $s->unitHead->branch->name ?? 'N/A',
                    'total_amount' => (float)$s->total_amount,
                    'total_count' => (int)$s->total_count,
                ];
            })
            ->values()
            ->toArray();

        // 4. Compute Team/Downline Performers (Amount-wise)
        $usersQuery = User::where('user_type', '=', 'hierarchy', 'and')
            ->where('is_active', '=', true, 'and');

        if ($isBranchCoordinator) {
            $usersQuery->whereIn('branch_id', $assignedBranchIds);
        } elseif (!$isAdminView) {
            $usersQuery->whereIn('id', $accessibleUserIds);
        }

        $users = $usersQuery->with(['level', 'branch'])->get();

        $salesMap = $sales->keyBy('unit_head_id')->map(fn($s) => [
            'amount' => (float)$s->total_amount,
            'count' => (int)$s->total_count,
        ])->toArray();

        $teamPerformance = [];
        foreach ($users as $u) {
            // Find all unit head IDs who historically made sales under this user (including themselves)
            $allTeamUserIds = Investment::whereBetween('reservation_date', [$from, $to])
                ->where('status', 'approved')
                ->where(function ($q) use ($u) {
                    $q->where('unit_head_id', $u->id)
                      ->orWhereHas('hierarchySnapshot', function ($sq) use ($u) {
                          $sq->where('ancestor_id', $u->id);
                      });
                })
                ->pluck('unit_head_id')
                ->unique()
                ->toArray();

            $teamAmount = 0.0;
            $teamCount = 0;

            foreach ($allTeamUserIds as $teamUserId) {
                if (isset($salesMap[$teamUserId])) {
                    $teamAmount += $salesMap[$teamUserId]['amount'];
                    $teamCount += $salesMap[$teamUserId]['count'];
                }
            }

            if ($teamAmount > 0) {
                $teamPerformance[] = [
                    'user_id' => $u->id,
                    'name' => $u->name,
                    'employee_code' => $u->employee_code,
                    'level' => $u->level->level_name ?? 'N/A',
                    'branch' => $u->branch->name ?? 'N/A',
                    'total_amount' => $teamAmount,
                    'total_count' => $teamCount,
                    'is_manager' => !empty($descendants),
                ];
            }
        }

        usort($teamPerformance, function ($a, $b) {
            return $b['total_amount'] <=> $a['total_amount'];
        });

        $teamList = array_slice($teamPerformance, 0, $limit);

        return [
            'period' => [
                'from_date' => $from->format('Y-m-d'),
                'to_date' => $to->format('Y-m-d'),
                'period_key' => $periodKey
            ],
            'direct_by_amount' => $directByAmount,
            'direct_by_count' => $directByCount,
            'team_by_amount' => $teamList
        ];
    }
}
