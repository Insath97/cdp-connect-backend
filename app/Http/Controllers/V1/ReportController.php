<?php

namespace App\Http\Controllers\V1;

use App\Traits\ActivityLogTrait;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Target;
use App\Models\Commission;
use App\Models\Investment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

use App\Traits\InvestmentCalculationTrait;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller implements HasMiddleware
{
    use ActivityLogTrait;

    use InvestmentCalculationTrait;

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Report Index', only: ['index', 'show']),
            new Middleware('permission:Report Agent Performance', only: ['agentPerformance']),
            new Middleware('permission:Report Hierarchy Performance', only: ['hierarchyPerformance']),
            new Middleware('permission:Report Hierarchy Detailed', only: ['hierarchyDetailedReport']),
            new Middleware('permission:Report Hierarchy Date Wise', only: ['hierarchyDateWiseReport']),
            new Middleware('permission:Report Investor Maturity', only: ['investorMaturity']),
            new Middleware('permission:Report Plan Wise Hierarchy', only: ['planWiseHierarchyReport']),
            new Middleware('permission:Report Plan Wise Admin', only: ['planWiseAdminReport']),
            new Middleware('permission:Report Customer Investments Maturity', only: ['customerInvestmentsMaturityReport']),
            new Middleware('permission:Report Welcome Call', only: ['welcomeCallReport']),
        ];
    }

    /**
     * Get hierarchy report for all accessible users.
     */
    public function index(Request $request)
    {
        try {
            $user = Auth::guard('api')->user();
            $periodKey = $request->get('period_key', Carbon::now()->format('Y-m'));
            $perPage = $request->get('per_page', 15);

            // 1. Determine accessible user IDs
            $isAdmin = $user->hasRole('Super Admin') || ($user->user_type === 'admin');
            $isBranchCoordinator = $user->hasRole('Branch Coordinator');

            $query = User::with(['level', 'branch'])
                ->select('users.id', 'users.name', 'users.username', 'users.level_id', 'users.branch_id', 'users.user_type', 'users.id_type', 'users.id_number', 'users.is_active')
                ->where('users.user_type', '=', 'hierarchy', 'and');

            if ($isBranchCoordinator) {
                $assignedBranchIds = $user->assignedBranches()->pluck('branches.id')->toArray();
                $query->whereIn('users.branch_id', $assignedBranchIds);
            } elseif (!$isAdmin) {
                $descendantIds = $user->getAllDescendantIds();
                $accessibleIds = array_merge([$user->id], $descendantIds);
                $query->whereIn('users.id', $accessibleIds);
            }

            // 2. Search
            if ($request->has('search')) {
                $search = $request->search;
                $cleanSearch = str_replace(' ', '', $search);
                $query->where(function ($q) use ($search, $cleanSearch) {
                    $q->where('users.name', 'like', "%{$search}%", 'and')
                        ->orWhere('users.username', 'like', "%{$search}%", 'and')
                        ->orWhere('users.id_number', 'like', "%{$search}%", 'and')
                        ->orWhereRaw("REPLACE(users.id_number, ' ', '') like ?", ["%{$cleanSearch}%"]);
                });
            }

            // 3. Join Targets and Commissions for the given period
            // Subquery for commission to avoid double counting if multiple targets existed (though unlikely)
            $commissionsSub = Commission::select('user_id', DB::raw('SUM(commission_amount) as total_commission'))
                ->where('period_key', '=', $periodKey, 'and')
                ->groupBy('user_id');

            $reports = $query->leftJoinSub(
                Target::where('period_key', '=', $periodKey, 'and'),
                't',
                'users.id',
                '=',
                't.user_id'
            )
                ->leftJoinSub(
                    $commissionsSub,
                    'c',
                    'users.id',
                    '=',
                    'c.user_id'
                )
                ->addSelect([
                    't.target_amount',
                    't.achieved_amount',
                    't.achievement_percentage',
                    DB::raw('COALESCE(c.total_commission, 0) as total_commission')
                ])
                ->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'message' => 'Hierarchy report retrieved successfully',
                'data' => $reports,
                'meta' => [
                    'period_key' => $periodKey
                ]
            ], 200);
        } catch (\Throwable $th) {
            $this->logActivity('Error', 'Report', 'Report generation failed', [
                'error' => $th->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve report',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Get report for a specific user.
     */
    public function show(Request $request, $id)
    {
        try {
            $currentUser = Auth::guard('api')->user();
            $periodKey = $request->get('period_key', Carbon::now()->format('Y-m'));

            // 1. Accessibility Check
            $isAdmin = $currentUser->hasRole('Super Admin') || ($currentUser->user_type === 'admin');
            if (!$isAdmin) {
                $descendantIds = $currentUser->getAllDescendantIds();
                $accessibleIds = array_merge([$currentUser->id], $descendantIds);
                if (!in_array($id, $accessibleIds)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Unauthorized. This user is not in your hierarchy.'
                    ], 403);
                }
            }

            // 2. Fetch specific user data
            $userReport = User::with(['level', 'branch'])
                ->select('users.id', 'users.name', 'users.username', 'users.level_id', 'users.branch_id', 'users.user_type', 'users.is_active')
                ->where('users.id', '=', $id, 'and')
                ->where('users.user_type', '=', 'hierarchy', 'and')
                ->leftJoinSub(
                    Target::where('period_key', '=', $periodKey, 'and'),
                    't',
                    'users.id',
                    '=',
                    't.user_id'
                )
                ->leftJoinSub(
                    Commission::select('user_id', DB::raw('SUM(commission_amount) as total_commission'))
                        ->where('period_key', '=', $periodKey, 'and')
                        ->groupBy('user_id'),
                    'c',
                    'users.id',
                    '=',
                    'c.user_id'
                )
                ->addSelect([
                    't.target_amount',
                    't.achieved_amount',
                    't.achievement_percentage',
                    DB::raw('COALESCE(c.total_commission, 0) as total_commission')
                ])
                ->first();

            if (!$userReport) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User report not found'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'User report retrieved successfully',
                'data' => $userReport,
                'meta' => [
                    'period_key' => $periodKey
                ]
            ], 200);
        } catch (\Throwable $th) {
            $this->logActivity('Error', 'Report', 'Detailed report failed', [
                'error' => $th->getMessage(),
                'user_id' => Auth::id(),
                'target_user_id' => $id
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve user report',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Search for a hierarchy user and get their performance summary and customer details.
     */
    public function agentPerformance(Request $request): JsonResponse
    {
        try {
            $currentUser = Auth::guard('api')->user();
            $search = $request->get('search');
            $periodKey = $request->get('period_key', Carbon::now()->format('Y-m'));

            if (!$search) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Search query is required (Name or ID Number).'
                ], 422);
            }

            // 1. Accessibility & Query Setup
            $isAdmin = $currentUser->hasRole('Super Admin') || ($currentUser->user_type === 'admin');
            $isBranchCoordinator = $currentUser->hasRole('Branch Coordinator');

            $query = User::with(['level', 'branch'])
                ->where('user_type', '=', 'hierarchy', 'and');

            if ($isBranchCoordinator) {
                $assignedBranchIds = $currentUser->assignedBranches()->pluck('branches.id')->toArray();
                $query->whereIn('branch_id', $assignedBranchIds);
            } elseif (!$isAdmin) {
                $descendantIds = $currentUser->getAllDescendantIds();
                $accessibleIds = array_merge([$currentUser->id], $descendantIds);
                $query->whereIn('id', $accessibleIds);
            }

            // 2. Execute Search
            $cleanSearch = str_replace(' ', '', $search);
            $agent = $query->where(function ($q) use ($search, $cleanSearch) {
                $q->where('name', 'like', "%{$search}%", 'and')
                    ->orWhere('id_number', 'like', "%{$search}%", 'and')
                    ->orWhereRaw("REPLACE(id_number, ' ', '') like ?", ["%{$cleanSearch}%"])
                    ->orWhere('username', 'like', "%{$search}%", 'and');
            })->first();

            if (!$agent) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Hierarchy user not found or access denied.'
                ], 404);
            }

            // 3. Performance Metrics (Target & Commission)
            $target = Target::where('user_id', '=', $agent->id, 'and')
                ->where('period_key', '=', $periodKey, 'and')
                ->first();

            $totalCommission = Commission::where('user_id', '=', $agent->id, 'and')
                ->where('period_key', '=', $periodKey, 'and')
                ->sum('commission_amount');

            // 4. Customer Details (Investments)
            // Retrieve important datas as requested: Customer Name, Plan, Period, Amount, Maturity details
            $investments = Investment::with(['customer', 'investmentProduct.annualRates'])
                ->where('unit_head_id', '=', $agent->id, 'and')
                ->where('target_period_key', '=', $periodKey, 'and')
                ->get()
                ->map(function ($inv) {
                    $calculations = [];
                    if ($inv->investmentProduct) {
                        $calculations = $this->calculateInvestmentROI((float)$inv->investment_amount, $inv->investmentProduct);
                    }

                    return [
                        'customer_name' => $inv->customer->full_name ?? 'N/A',
                        'policy_number' => $inv->policy_number,
                        'invest_date' => $inv->reservation_date ? $inv->reservation_date->format('Y-m-d') : 'N/A',
                        'plan' => $inv->investmentProduct->name ?? 'N/A',
                        'period' => ($inv->investmentProduct->duration_months ?? 0) . ' Months',
                        'investment_amount' => (float)$inv->investment_amount,
                        'monthly_maturity' => round($calculations['monthly_return'] ?? 0, 2),
                        'total_maturity' => round($calculations['maturity_amount'] ?? 0, 2),
                        'monthly_breakdown' => $calculations['yearly_breakdown'] ?? [],
                        'status' => $inv->status
                    ];
                });

            // 5. Hierarchy Performance (Subordinates)
            $descendantIds = $agent->getAllDescendantIds();
            $hierarchyPerformance = [];

            if (!empty($descendantIds)) {
                $commissionsSubHierarchy = Commission::select('user_id', DB::raw('SUM(commission_amount) as total_commission'))
                    ->where('period_key', '=', $periodKey, 'and')
                    ->groupBy('user_id');

                $hierarchyPerformance = User::with(['level', 'branch'])
                    ->select('users.id', 'users.name', 'users.username', 'users.employee_code', 'users.level_id', 'users.branch_id', 'users.is_active')
                    ->whereIn('users.id', $descendantIds)
                    ->leftJoinSub(
                        Target::where('period_key', '=', $periodKey, 'and'),
                        't',
                        'users.id',
                        '=',
                        't.user_id'
                    )
                    ->leftJoinSub(
                        $commissionsSubHierarchy,
                        'c',
                        'users.id',
                        '=',
                        'c.user_id'
                    )
                    ->addSelect([
                        't.target_amount',
                        't.achieved_amount',
                        't.achievement_percentage',
                        DB::raw('COALESCE(c.total_commission, 0) as total_commission')
                    ])
                    ->get()
                    ->map(function ($u) {
                        return [
                            'id' => $u->id,
                            'name' => $u->name,
                            'username' => $u->username,
                            'level' => $u->level->level_name ?? 'N/A',
                            'branch' => $u->branch->name ?? 'N/A',
                            'target_amount' => (float)($u->target_amount ?? 0),
                            'achieved_amount' => (float)($u->achieved_amount ?? 0),
                            'achievement_percentage' => (float)($u->achievement_percentage ?? 0),
                            'total_commission' => (float)($u->total_commission ?? 0),
                            'is_active' => (bool)$u->is_active
                        ];
                    });
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Agent performance data retrieved successfully',
                'data' => [
                    'agent_info' => [
                        'id' => $agent->id,
                        'name' => $agent->name,
                        'id_number' => $agent->id_number,
                        'level' => $agent->level->level_name ?? 'N/A',
                        'branch' => $agent->branch->name ?? 'N/A',
                        'target_amount' => $target ? (float)$target->target_amount : 0,
                        'achieved_amount' => $target ? (float)$target->achieved_amount : 0,
                        'achievement_percentage' => $target ? (float)$target->achievement_percentage : 0,
                        'total_commission' => (float) $totalCommission,
                        'is_active' => (bool)$agent->is_active,
                        'period_key' => $periodKey
                    ],
                    'customer_details' => $investments,
                    'hierarchy_performance' => $hierarchyPerformance
                ]
            ], 200);
        } catch (\Throwable $th) {
            $this->logActivity('Error', 'Report', 'Agent performance search failed', [
                'error' => $th->getMessage(),
                'user_id' => Auth::id(),
                'search' => $request->get('search')
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve agent performance data',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Search for a hierarchy user and get performance metrics for them and ALL their descendants.
     */
    public function hierarchyPerformance(Request $request): JsonResponse
    {
        try {
            $currentUser = Auth::guard('api')->user();
            $search = $request->get('search');
            $periodKey = $request->get('period_key', Carbon::now()->format('Y-m'));
            $perPage = $request->get('per_page', 50);

            if (!$search) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Search query is required (Name, Username or ID Number).'
                ], 422);
            }

            // 1. Accessibility & Root User Search
            $isAdmin = $currentUser->hasRole('Super Admin') || ($currentUser->user_type === 'admin');
            $isBranchCoordinator = $currentUser->hasRole('Branch Coordinator');

            $rootQuery = User::where('user_type', '=', 'hierarchy', 'and');

            // If Branch Coordinator, restrict to assigned branches
            if ($isBranchCoordinator) {
                $assignedBranchIds = $currentUser->assignedBranches()->pluck('branches.id')->toArray();
                $rootQuery->whereIn('branch_id', $assignedBranchIds);
            } elseif (!$isAdmin) {
                // If hierarchy user, the searched user must be the current user or their descendant
                $myDescendantIds = $currentUser->getAllDescendantIds();
                $accessibleIds = array_merge([$currentUser->id], $myDescendantIds);
                $rootQuery->whereIn('id', $accessibleIds);
            }

            $cleanSearch = str_replace(' ', '', $search);
            $rootUser = $rootQuery->where(function ($q) use ($search, $cleanSearch) {
                $q->where('name', 'like', "%{$search}%", 'and')
                    ->orWhere('id_number', 'like', "%{$search}%", 'and')
                    ->orWhereRaw("REPLACE(id_number, ' ', '') like ?", ["%{$cleanSearch}%"])
                    ->orWhere('username', 'like', "%{$search}%", 'and');
            })->first();

            if (!$rootUser) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Hierarchy user not found or access denied.'
                ], 404);
            }

            // 2. Get all descendants of the root user
            $allTargetUserIds = $rootUser->getAllDescendantIds();

            if (empty($allTargetUserIds)) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Hierarchy performance report retrieved successfully (No subordinates found)',
                    'data' => [],
                    'meta' => [
                        'root_user' => [
                            'id' => $rootUser->id,
                            'name' => $rootUser->name
                        ],
                        'period_key' => $periodKey
                    ]
                ], 200);
            }

            // 3. Fetch all users in this branch with their Target and Commission
            $commissionsSub = Commission::select('user_id', DB::raw('SUM(commission_amount) as total_commission'))
                ->where('period_key', '=', $periodKey, 'and')
                ->groupBy('user_id');

            $results = User::with(['level', 'branch'])
                ->select('users.id', 'users.name', 'users.username', 'users.level_id', 'users.branch_id', 'users.parent_user_id', 'users.is_active')
                ->whereIn('users.id', $allTargetUserIds)
                ->leftJoinSub(
                    Target::where('period_key', '=', $periodKey, 'and'),
                    't',
                    'users.id',
                    '=',
                    't.user_id'
                )
                ->leftJoinSub(
                    $commissionsSub,
                    'c',
                    'users.id',
                    '=',
                    'c.user_id'
                )
                ->addSelect([
                    't.target_amount',
                    't.achieved_amount',
                    't.achievement_percentage',
                    't.period_key',
                    DB::raw('COALESCE(c.total_commission, 0) as total_commission')
                ])
                ->paginate($perPage);

            $results->getCollection()->transform(function ($u) {
                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'username' => $u->username,
                    'employee_code' => $u->employee_code,
                    'level' => $u->level->level_name ?? 'N/A',
                    'branch' => $u->branch->name ?? 'N/A',
                    'target_amount' => (float)($u->target_amount ?? 0),
                    'achieved_amount' => (float)($u->achieved_amount ?? 0),
                    'achievement_percentage' => (float)($u->achievement_percentage ?? 0),
                    'total_commission' => (float)($u->total_commission ?? 0),
                    'period_key' => $u->period_key ?? 'N/A',
                    'is_active' => (bool)$u->is_active
                ];
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Hierarchy performance report retrieved successfully',
                'data' => $results,
                'meta' => [
                    'root_user' => [
                        'id' => $rootUser->id,
                        'name' => $rootUser->name
                    ],
                    'period_key' => $periodKey
                ]
            ], 200);
        } catch (\Throwable $th) {
            $this->logActivity('Error', 'Report', 'Hierarchy performance report failed', [
                'error' => $th->getMessage(),
                'user_id' => Auth::id(),
                'search' => $request->get('search')
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve hierarchy performance report',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Search for a hierarchy user and get detailed performance metrics for them and their entire branch.
     * Includes personal metrics, total branch business, and individual subordinate metrics.
     */
    public function hierarchyDetailedReport(Request $request): JsonResponse
    {
        try {
            $currentUser = Auth::guard('api')->user();
            $search = $request->get('search');
            $periodKey = $request->get('period_key', Carbon::now()->format('Y-m'));
            $perPage = $request->get('per_page', 50);

            if (!$search) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Search query is required (Name, Username or ID Number).'
                ], 422);
            }

            // 1. Accessibility & Root User Search
            $isAdmin = $currentUser->hasRole('Super Admin') || ($currentUser->user_type === 'admin');
            $rootQuery = User::with(['level', 'branch'])->where('user_type', '=', 'hierarchy', 'and');

            if (!$isAdmin) {
                $myDescendantIds = $currentUser->getAllDescendantIds();
                $accessibleIds = array_merge([$currentUser->id], $myDescendantIds);
                $rootQuery->whereIn('id', $accessibleIds);
            }

            $cleanSearch = str_replace(' ', '', $search);
            $rootUser = $rootQuery->where(function ($q) use ($search, $cleanSearch) {
                $q->where('name', 'like', "%{$search}%", 'and')
                    ->orWhere('id_number', 'like', "%{$search}%", 'and')
                    ->orWhereRaw("REPLACE(id_number, ' ', '') like ?", ["%{$cleanSearch}%"])
                    ->orWhere('username', 'like', "%{$search}%", 'and');
            })->first();

            if (!$rootUser) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Hierarchy user not found or access denied.'
                ], 404);
            }

            // 2. Fetch Root User's Target/Achievement Data
            $rootTarget = Target::where('user_id', '=', $rootUser->id, 'and')
                ->where('period_key', '=', $periodKey, 'and')
                ->first();

            // 3. Get all descendants of the root user
            $descendantIds = $rootUser->getAllDescendantIds();

            // 4. Fetch all subordinates in this branch with their Target, Commission and Business Details
            $commissionsSub = Commission::select('user_id', DB::raw('SUM(commission_amount) as total_commission'))
                ->where('period_key', '=', $periodKey, 'and')
                ->groupBy('user_id');

            $descendantsQuery = User::with([
                'level',
                'branch',
                'investments' => function ($q) use ($periodKey) {
                    $q->where('target_period_key', '=', $periodKey, 'and')
                        ->with(['customer', 'investmentProduct']);
                }
            ])
                ->select('users.id', 'users.name', 'users.username', 'users.level_id', 'users.branch_id', 'users.parent_user_id', 'users.is_active')
                ->whereIn('users.id', $descendantIds)
                ->leftJoinSub(
                    Target::where('period_key', '=', $periodKey, 'and'),
                    't',
                    'users.id',
                    '=',
                    't.user_id'
                )
                ->leftJoinSub(
                    $commissionsSub,
                    'c',
                    'users.id',
                    '=',
                    'c.user_id'
                )
                ->addSelect([
                    't.target_amount',
                    't.achieved_amount',
                    't.achievement_percentage',
                    't.period_key',
                    DB::raw('COALESCE(c.total_commission, 0) as total_commission')
                ]);

            $descendants = $descendantsQuery->paginate($perPage);

            $descendants->getCollection()->transform(function ($u) {
                $businessDetails = $u->investments->map(function ($inv) {
                    return [
                        'customer_name' => $inv->customer->full_name ?? 'N/A',
                        'policy_number' => $inv->policy_number ?? ($inv->application_number ?? 'N/A'),
                        'investment_amount' => (float)$inv->investment_amount,
                        'plan' => $inv->investmentProduct->name ?? 'N/A',
                        'date' => $inv->reservation_date ? $inv->reservation_date->format('Y-m-d') : 'N/A',
                        'status' => $inv->status
                    ];
                });

                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'username' => $u->username,
                    'level' => $u->level->level_name ?? 'N/A',
                    'branch' => $u->branch->name ?? 'N/A',
                    'target_amount' => (float)($u->target_amount ?? 0),
                    'achieved_amount' => (float)($u->achieved_amount ?? 0),
                    'achievement_percentage' => (float)($u->achievement_percentage ?? 0),
                    'remaining_amount' => (float)max(0, ($u->target_amount ?? 0) - ($u->achieved_amount ?? 0)),
                    'total_commission' => (float)($u->total_commission ?? 0),
                    'period_key' => $u->period_key ?? 'N/A',
                    'is_active' => (bool)$u->is_active,
                    'business_details' => $businessDetails
                ];
            });

            // 5. Construct Summary
            $rootUser->load(['investments' => function ($q) use ($periodKey) {
                $q->where('target_period_key', '=', $periodKey, 'and')
                    ->with(['customer', 'investmentProduct']);
            }]);

            $rootBusinessDetails = $rootUser->investments->map(function ($inv) {
                return [
                    'customer_name' => $inv->customer->full_name ?? 'N/A',
                    'policy_number' => $inv->policy_number ?? ($inv->application_number ?? 'N/A'),
                    'investment_amount' => (float)$inv->investment_amount,
                    'plan' => $inv->investmentProduct->name ?? 'N/A',
                    'date' => $inv->reservation_date ? $inv->reservation_date->format('Y-m-d') : 'N/A',
                    'status' => $inv->status
                ];
            });

            $searchedUserSummary = [
                'id' => $rootUser->id,
                'name' => $rootUser->name,
                'username' => $rootUser->username,
                'level' => $rootUser->level->level_name ?? 'N/A',
                'branch' => $rootUser->branch->name ?? 'N/A',
                'target_amount' => $rootTarget ? (float)$rootTarget->target_amount : 0,
                'achieved_amount' => $rootTarget ? (float)$rootTarget->achieved_amount : 0,
                'achievement_percentage' => $rootTarget ? (float)$rootTarget->achievement_percentage : 0,
                'remaining_amount' => $rootTarget ? (float)$rootTarget->remaining_amount : 0,
                'is_active' => (bool)$rootUser->is_active,
                'period_key' => $periodKey,
                'business_details' => $rootBusinessDetails
            ];

            return response()->json([
                'status' => 'success',
                'message' => 'Hierarchy detailed report retrieved successfully',
                'data' => [
                    'searched_user' => $searchedUserSummary,
                    'hierarchy_summary' => [
                        'total_business' => $rootTarget ? (float)$rootTarget->achieved_amount : 0,
                        'total_subordinates' => count($descendantIds)
                    ],
                    'descendants_performance' => $descendants
                ]
            ], 200);
        } catch (\Throwable $th) {
            $this->logActivity('Error', 'Report', 'Hierarchy detailed report failed', [
                'error' => $th->getMessage(),
                'user_id' => Auth::id(),
                'search' => $request->get('search')
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve hierarchy detailed report',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Search for a hierarchy user and get detailed performance metrics for them and their entire branch.
     * Supports both date range and period key filtering.
     * If no search is provided, defaults to the current user's hierarchy.
     */
    public function hierarchyDateWiseReport(Request $request): JsonResponse
    {
        try {
            $currentUser = Auth::guard('api')->user();
            $search = $request->get('search');
            $fromDate = $request->get('from_date');
            $toDate = $request->get('to_date');
            $periodKeyInput = $request->get('period_key');
            $perPage = $request->get('per_page', 50);

            // 1. Determine Date Range & Period Key
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

            // 2. Accessibility & Roles
            $isAdmin = $currentUser->hasRole('Super Admin') || ($currentUser->user_type === 'admin');
            $isBranchCoordinator = $currentUser->hasRole('Branch Coordinator');

            // 3. Determine Root Users for the Tree
            $rootUsers = [];
            if ($search) {
                // Search for specific user
                $searchQuery = User::where('user_type', '=', 'hierarchy', 'and');
                if ($isBranchCoordinator) {
                    $assignedBranchIds = $currentUser->assignedBranches()->pluck('branches.id')->toArray();
                    $searchQuery->whereIn('branch_id', $assignedBranchIds);
                } elseif (!$isAdmin) {
                    $myDescendantIds = $currentUser->getAllDescendantIds();
                    $accessibleIds = array_merge([$currentUser->id], $myDescendantIds);
                    $searchQuery->whereIn('id', $accessibleIds);
                }

                $cleanSearch = str_replace(' ', '', $search);
                $targetUser = $searchQuery->where(function ($q) use ($search, $cleanSearch) {
                    $q->where('name', 'like', "%{$search}%", 'and')
                        ->orWhere('id_number', 'like', "%{$search}%", 'and')
                        ->orWhereRaw("REPLACE(id_number, ' ', '') like ?", ["%{$cleanSearch}%"])
                        ->orWhere('username', 'like', "%{$search}%", 'and');
                })->first();

                if (!$targetUser) {
                    return response()->json(['status' => 'error', 'message' => 'User not found.'], 404);
                }
                $rootUsers = [$targetUser];
            } else {
                // No search: Determine roots based on role
                if ($isAdmin) {
                    // Admins see all top-level hierarchy users (those without a parent)
                    $rootUsers = User::with(['level', 'branch'])
                        ->where('user_type', '=', 'hierarchy', 'and')
                        ->whereNull('parent_user_id')
                        ->join('levels', 'users.level_id', '=', 'levels.id')
                        ->orderBy('levels.tire_level', 'asc')
                        ->select('users.*')
                        ->get();

                    // If no top-level users found (orphans), just get all GMs or high level tiers
                    if ($rootUsers->isEmpty()) {
                        $rootUsers = User::with(['level', 'branch'])
                            ->where('user_type', '=', 'hierarchy', 'and')
                            ->join('levels', 'users.level_id', '=', 'levels.id')
                            ->orderBy('levels.tire_level', 'asc')
                            ->select('users.*')
                            ->limit(10)
                            ->get();
                    }
                } elseif ($isBranchCoordinator) {
                    $assignedBranchIds = $currentUser->assignedBranches()->pluck('branches.id')->toArray();
                    $rootUsers = User::with(['level', 'branch'])
                        ->where('user_type', '=', 'hierarchy', 'and')
                        ->whereIn('branch_id', $assignedBranchIds)
                        ->whereNull('parent_user_id')
                        ->get();
                } else {
                    // Hierarchy user sees themselves as root
                    $rootUsers = [User::with(['level', 'branch'])->find($currentUser->id)];
                }
            }

            // 4. Build Recursive Tree
            $tree = [];
            foreach ($rootUsers as $root) {
                $tree[] = $this->buildHierarchyNode($root, $from, $to, $periodKey);
            }

            // 5. Total Summary
            $allHierarchyIds = User::where('user_type', '=', 'hierarchy', 'and')->pluck('id')->toArray();
            $totalInvestments = Investment::whereIn('unit_head_id', $allHierarchyIds)
                ->whereBetween('reservation_date', [$from, $to])
                ->whereIn('status', ['approved', 'expired'])
                ->get();

            return response()->json([
                'status' => 'success',
                'message' => 'Hierarchy tree report retrieved successfully',
                'data' => [
                    'hierarchy_tree' => $tree,
                    'overall_summary' => [
                        'total_business' => (float)$totalInvestments->sum('investment_amount'),
                        'total_business_count' => $totalInvestments->count(),
                        'period' => [
                            'from' => $from->toDateString(),
                            'to' => $to->toDateString()
                        ]
                    ]
                ]
            ], 200);
        } catch (\Throwable $th) {
            $this->logActivity('Error', 'Report', 'Hierarchy tree report failed', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString()
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve hierarchy tree report',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Recursive helper to build a hierarchy node with metrics and children.
     */
    private function buildHierarchyNode($user, $from, $to, $periodKey)
    {
        // 1. Calculate Branch-wide metrics (includes all descendants)
        $descendantIds = $user->getAllDescendantIds();
        $allBranchIds = array_merge([$user->id], $descendantIds);

        $branchInvestments = Investment::whereIn('unit_head_id', $allBranchIds)
            ->whereBetween('reservation_date', [$from, $to])
            ->whereIn('status', ['approved', 'expired'])
            ->get();

        $branchBusinessTotal = $branchInvestments->sum('investment_amount');
        $branchBusinessCount = $branchInvestments->count();

        // 2. Personal Business Details (ONLY for this user as Unit Head)
        $personalInvestments = Investment::with(['customer', 'investmentProduct'])
            ->where('unit_head_id', $user->id)
            ->whereBetween('reservation_date', [$from, $to])
            ->whereIn('status', ['approved', 'expired'])
            ->get();

        // 3. Target and Achievement
        $target = Target::where('user_id', '=', $user->id, 'and')->where('period_key', '=', $periodKey, 'and')->first();
        $targetAmount = $target ? (float)$target->target_amount : 0;
        $achievementPercentage = $targetAmount > 0 ? ($branchBusinessTotal / $targetAmount) * 100 : ($branchBusinessTotal > 0 ? 100 : 0);

        // 4. Personal Commission
        $personalCommission = Commission::join('investments', 'commissions.investment_id', '=', 'investments.id')
            ->where('commissions.user_id', $user->id)
            ->whereBetween('investments.reservation_date', [$from, $to])
            ->sum('commission_amount');

        // 5. Recursive Children
        $children = User::with(['level', 'branch'])
            ->where('parent_user_id', '=', $user->id, 'and')
            ->get();

        $childrenNodes = [];
        foreach ($children as $child) {
            $childrenNodes[] = $this->buildHierarchyNode($child, $from, $to, $periodKey);
        }

        // 6. Return Node Data
        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'employee_code' => $user->employee_code,
            'level' => $user->level->level_name ?? 'N/A',
            'branch' => $user->branch->name ?? 'N/A',
            'is_active' => (bool)$user->is_active,
            'metrics' => [
                'target_amount' => $targetAmount,
                'achieved_branch_business' => (float)$branchBusinessTotal,
                'achievement_percentage' => (float)min($achievementPercentage, 999999.99),
                'branch_business_count' => $branchBusinessCount,
                'personal_business_count' => $personalInvestments->count(),
                'personal_commission' => (float)$personalCommission,
            ],
            'business_details' => $personalInvestments->map(function ($inv) {
                return [
                    'customer' => $inv->customer->full_name ?? 'N/A',
                    'policy' => $inv->policy_number ?? 'N/A',
                    'amount' => (float)$inv->investment_amount,
                    'plan' => $inv->investmentProduct->name ?? 'N/A',
                    'date' => $inv->reservation_date ? $inv->reservation_date->format('Y-m-d') : 'N/A',
                    'status' => $inv->status
                ];
            }),
            'subordinates' => $childrenNodes
        ];
    }

    /**
     * Get a paginated list of all investors with their maturity schedules and payouts.

     */
    public function investorMaturity(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $user = Auth::guard('api')->user();

            $query = Investment::with(['customer', 'investmentProduct.annualRates', 'branch', 'creator', 'bankDetail']);

            // 1. Hierarchy Visibility Logic
            if ($user->hasRole('Branch Coordinator')) {
                $assignedBranchIds = $user->assignedBranches()->pluck('branches.id')->toArray();
                $query->whereIn('branch_id', $assignedBranchIds);
            } elseif (!$user->hasRole('Super Admin') && ($user->user_type !== 'admin')) {
                // Hierarchical users see their own and descendants' investments
                $descendantIds = $user->getAllDescendantIds();
                $accessibleUserIds = array_merge([$user->id], $descendantIds);
                $query->whereIn('created_by', $accessibleUserIds);
            }

            // 2. Filters
            if ($request->has('investment_product_id')) {
                $query->where('investment_product_id', '=', $request->investment_product_id, 'and');
            }

            if ($request->has('period_key')) {
                $query->where('target_period_key', '=', $request->period_key, 'and');
            }

            if ($request->has('branch_id')) {
                $query->where('branch_id', '=', $request->branch_id, 'and');
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->whereHas('customer', function ($cq) use ($search) {
                        $cq->where('full_name', 'like', "%{$search}%", 'and')
                            ->orWhere('id_number', 'like', "%{$search}%", 'and')
                            ->orWhere('customer_code', 'like', "%{$search}%", 'and');
                    })->orWhere('policy_number', 'like', "%{$search}%", 'and')
                        ->orWhere('application_number', 'like', "%{$search}%", 'and');
                });
            }

            // 3. Status Filter (Default to approved for maturity analysis)
            $status = $request->get('status', 'approved');
            $query->where('status', '=', $status, 'and');

            // 4. Execution & Pagination
            $investments = $query->orderBy('created_at', 'desc')->paginate($perPage);

            // 5. Transform data to include maturity calculations
            $investments->getCollection()->transform(function ($inv) {
                $calculations = [];
                if ($inv->investmentProduct) {
                    $calculations = $this->calculateInvestmentROI((float)$inv->investment_amount, $inv->investmentProduct);
                }

                return [
                    'id' => $inv->id,
                    'policy_number' => $inv->policy_number,
                    'customer_name' => $inv->customer->full_name ?? 'N/A',
                    'id_number' => $inv->customer->id_number ?? 'N/A',
                    'plan' => $inv->investmentProduct->name ?? 'N/A',
                    'invest_date' => $inv->reservation_date ? $inv->reservation_date->format('Y-m-d') : 'N/A',
                    'investment_amount' => (float)$inv->investment_amount,
                    'duration_months' => $inv->investmentProduct->duration_months ?? 0,
                    'monthly_maturity' => round($calculations['monthly_return'] ?? 0, 2),
                    'total_maturity' => round($calculations['maturity_amount'] ?? 0, 2),
                    'total_interest' => round($calculations['total_interest'] ?? 0, 2),
                    'payout_schedule' => $calculations['yearly_breakdown'] ?? [],
                    'creator' => $inv->creator->name ?? 'N/A',
                    'branch' => $inv->branch->name ?? 'N/A',
                    'account_details' => [
                        'bank_name' => $inv->bankDetail->bank_name ?? 'N/A',
                        'branch_name' => $inv->bankDetail->branch_name ?? 'N/A',
                        'account_number' => $inv->bankDetail->account_number ?? 'N/A',
                        'payment_method' => $inv->bankDetail->payment_method ?? 'N/A',
                    ],
                    'status' => $inv->status
                ];
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Investor maturity report retrieved successfully',
                'data' => $investments
            ], 200);
        } catch (\Throwable $th) {
            $this->logActivity('Error', 'Report', 'Investor maturity report failed', [
                'error' => $th->getMessage(),
                'user_id' => Auth::id(),
                'filters' => $request->only(['investment_product_id', 'period_key', 'branch_id', 'search'])
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve investor maturity report',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Search for a hierarchy user and get detailed performance metrics for them and their entire branch.
     * Includes personal metrics, total branch business, and plan-wise breakdowns.
     * Supports both date range and period key filtering.
     */
    public function planWiseHierarchyReport(Request $request): JsonResponse
    {
        try {
            $currentUser = Auth::guard('api')->user();
            $search = $request->get('search');
            $fromDate = $request->get('from_date');
            $toDate = $request->get('to_date');
            $periodKeyInput = $request->get('period_key');

            // 1. Determine Date Range & Period Key
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

            // 2. Accessibility & Roles
            $isAdmin = $currentUser->hasRole('Super Admin') || ($currentUser->user_type === 'admin');
            $isBranchCoordinator = $currentUser->hasRole('Branch Coordinator');

            // 3. Determine Root Users for the Tree
            $rootUsers = [];
            if ($search) {
                // Search for specific user
                $searchQuery = User::where('user_type', '=', 'hierarchy', 'and');
                if ($isBranchCoordinator) {
                    $assignedBranchIds = $currentUser->assignedBranches()->pluck('branches.id')->toArray();
                    $searchQuery->whereIn('branch_id', $assignedBranchIds);
                } elseif (!$isAdmin) {
                    $myDescendantIds = $currentUser->getAllDescendantIds();
                    $accessibleIds = array_merge([$currentUser->id], $myDescendantIds);
                    $searchQuery->whereIn('id', $accessibleIds);
                }

                $cleanSearch = str_replace(' ', '', $search);
                $targetUser = $searchQuery->where(function ($q) use ($search, $cleanSearch) {
                    $q->where('name', 'like', "%{$search}%", 'and')
                        ->orWhere('id_number', 'like', "%{$search}%", 'and')
                        ->orWhereRaw("REPLACE(id_number, ' ', '') like ?", ["%{$cleanSearch}%"])
                        ->orWhere('username', 'like', "%{$search}%", 'and');
                })->first();

                if (!$targetUser) {
                    return response()->json(['status' => 'error', 'message' => 'User not found.'], 404);
                }
                $rootUsers = [$targetUser];
            } else {
                // No search: Determine roots based on role
                if ($isAdmin) {
                    // Admins see all top-level hierarchy users (those without a parent)
                    $rootUsers = User::with(['level', 'branch'])
                        ->where('user_type', '=', 'hierarchy', 'and')
                        ->whereNull('parent_user_id')
                        ->join('levels', 'users.level_id', '=', 'levels.id')
                        ->orderBy('levels.tire_level', 'asc')
                        ->select('users.*')
                        ->get();

                    // If no top-level users found (orphans), just get all GMs or high level tiers
                    if ($rootUsers->isEmpty()) {
                        $rootUsers = User::with(['level', 'branch'])
                            ->where('user_type', '=', 'hierarchy', 'and')
                            ->join('levels', 'users.level_id', '=', 'levels.id')
                            ->orderBy('levels.tire_level', 'asc')
                            ->select('users.*')
                            ->limit(10)
                            ->get();
                    }
                } elseif ($isBranchCoordinator) {
                    $assignedBranchIds = $currentUser->assignedBranches()->pluck('branches.id')->toArray();
                    $rootUsers = User::with(['level', 'branch'])
                        ->where('user_type', '=', 'hierarchy', 'and')
                        ->whereIn('branch_id', $assignedBranchIds)
                        ->whereNull('parent_user_id')
                        ->get();
                } else {
                    // Hierarchy user sees themselves as root
                    $rootUsers = [User::with(['level', 'branch'])->find($currentUser->id)];
                }
            }

            // 4. Build Recursive Tree
            $tree = [];
            foreach ($rootUsers as $root) {
                $tree[] = $this->buildPlanWiseHierarchyNode($root, $from, $to, $periodKey);
            }

            // 5. Total Summary
            $allHierarchyIds = User::where('user_type', '=', 'hierarchy', 'and')->pluck('id')->toArray();
            $totalInvestments = Investment::whereIn('unit_head_id', $allHierarchyIds)
                ->whereBetween('reservation_date', [$from, $to])
                ->whereIn('status', ['approved', 'expired'])
                ->get();

            $totalCancelled = Investment::whereIn('unit_head_id', $allHierarchyIds)
                ->whereBetween('reservation_date', [$from, $to])
                ->where('status', '=', 'cancelled', 'and')
                ->get();

            $totalRecovery = Commission::whereIn('user_id', $allHierarchyIds)
                ->whereHas('investment', function ($q) use ($from, $to) {
                    $q->whereBetween('reservation_date', [$from, $to])
                        ->where('status', 'cancelled');
                })->sum('recover_amount');

            return response()->json([
                'status' => 'success',
                'message' => 'Plan-wise hierarchy report retrieved successfully',
                'data' => [
                    'hierarchy_tree' => $tree,
                    'overall_summary' => [
                        'total_business' => (float)$totalInvestments->sum('investment_amount'),
                        'total_business_count' => $totalInvestments->count(),
                        'total_cancelled_business' => (float)$totalCancelled->sum('investment_amount'),
                        'total_cancelled_count' => $totalCancelled->count(),
                        'total_recovery_amount' => (float)$totalRecovery,
                        'period' => [
                            'from' => $from->toDateString(),
                            'to' => $to->toDateString()
                        ]
                    ]
                ]
            ], 200);
        } catch (\Throwable $th) {
            $this->logActivity('Error', 'Report', 'Plan-wise hierarchy report failed', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString()
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve plan-wise hierarchy report',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Recursive helper to build a plan-wise hierarchy node.
     */
    private function buildPlanWiseHierarchyNode($user, $from, $to, $periodKey)
    {
        $descendantIds = $user->getAllDescendantIds();
        $allBranchIds = array_merge([$user->id], $descendantIds);

        $branchInvestments = Investment::with(['investmentProduct', 'customer'])->whereIn('unit_head_id', $allBranchIds, 'and', false)
            ->whereBetween('reservation_date', [$from, $to])
            ->whereIn('status', ['approved', 'expired'])
            ->get();

        $cancelledInvestments = Investment::with(['investmentProduct', 'customer'])->whereIn('unit_head_id', $allBranchIds, 'and', false)
            ->whereBetween('reservation_date', [$from, $to])
            ->where('status', '=', 'cancelled', 'and')
            ->get();

        $branchBusinessTotal = $branchInvestments->sum('investment_amount');
        $branchBusinessCount = $branchInvestments->count();

        $cancelledBusinessTotal = $cancelledInvestments->sum('investment_amount');
        $cancelledBusinessCount = $cancelledInvestments->count();

        $userCommissions = Commission::with(['investment.customer', 'investment.investmentProduct'])
            ->where('user_id', $user->id)
            ->whereHas('investment', function ($q) use ($from, $to) {
                $q->whereBetween('reservation_date', [$from, $to])
                    ->whereIn('status', ['approved', 'expired']);
            })
            ->get();

        $personalUnitHeadCommission = $userCommissions->where('tier', 'unit_head')->sum('commission_amount');
        $personalOverrideCommission = $userCommissions->where('tier', 'parent')->sum('commission_amount');
        $personalCommission = $userCommissions->sum('commission_amount');

        // Commission Recoveries
        $userRecoveries = Commission::where('user_id', $user->id)
            ->whereHas('investment', function ($q) use ($from, $to) {
                $q->whereBetween('reservation_date', [$from, $to])
                    ->where('status', 'cancelled');
            })
            ->get();
        
        $personalRecovery = $userRecoveries->sum('recover_amount');

        $branchRecoveries = Commission::whereIn('user_id', $allBranchIds)
            ->whereHas('investment', function ($q) use ($from, $to) {
                $q->whereBetween('reservation_date', [$from, $to])
                    ->where('status', 'cancelled');
            })
            ->get();
        
        $branchRecoveryTotal = $branchRecoveries->sum('recover_amount');

        $target = Target::where('user_id', '=', $user->id, 'and')->where('period_key', '=', $periodKey, 'and')->first();
        $targetAmount = $target ? (float)$target->target_amount : 0;
        $achievementPercentage = $targetAmount > 0 ? ($branchBusinessTotal / $targetAmount) * 100 : ($branchBusinessTotal > 0 ? 100 : 0);

        $children = User::with(['level', 'branch'])
            ->where('parent_user_id', '=', $user->id, 'and')
            ->get();

        $childrenNodes = [];
        foreach ($children as $child) {
            $childrenNodes[] = $this->buildPlanWiseHierarchyNode($child, $from, $to, $periodKey);
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'employee_code' => $user->employee_code,
            'level' => $user->level->level_name ?? 'N/A',
            'branch' => $user->branch->name ?? 'N/A',
            'is_active' => (bool)$user->is_active,
            'metrics' => [
                'target_amount' => $targetAmount,
                'achieved_branch_business' => (float)$branchBusinessTotal,
                'achievement_percentage' => (float)min($achievementPercentage, 999999.99),
                'branch_business_count' => $branchBusinessCount,
                'cancelled_branch_business' => (float)$cancelledBusinessTotal,
                'cancelled_branch_count' => $cancelledBusinessCount,
                'personal_business_count' => $userCommissions->where('tier', 'unit_head')->count(),
                'personal_commission' => (float)$personalCommission,
                'personal_unit_head_commission' => (float)$personalUnitHeadCommission,
                'personal_override_commission' => (float)$personalOverrideCommission,
                'personal_recovery_amount' => (float)$personalRecovery,
                'branch_recovery_total' => (float)$branchRecoveryTotal,
                'plan_breakdown' => $branchInvestments->groupBy('investment_product_id')->map(function ($group) use ($user, $allBranchIds) {
                    $first = $group->first();
                    $planName = $first->investmentProduct->name ?? 'N/A';

                    // All commissions for these specific investments
                    $allComms = Commission::whereIn('investment_id', $group->pluck('id'))->get();

                    // 1. Current User's Earnings
                    $userUnitHead = $allComms->where('user_id', $user->id)->where('tier', 'unit_head')->sum('commission_amount');
                    $userOverride = $allComms->where('user_id', $user->id)->where('tier', 'parent')->sum('commission_amount');

                    // 2. Branch-wide Earnings (limited to users in this sub-tree)
                    $branchUnitHead = $allComms->whereIn('user_id', $allBranchIds)->where('tier', 'unit_head')->sum('commission_amount');
                    $branchOverride = $allComms->whereIn('user_id', $allBranchIds)->where('tier', 'parent')->sum('commission_amount');

                    return [
                        'plan_name' => $planName,
                        'business_count' => $group->count(),
                        'total_investment_amount' => (float)$group->sum('investment_amount'),
                        'user_unit_head_commission' => (float)$userUnitHead,
                        'user_override_commission' => (float)$userOverride,
                        'branch_unit_head_total' => (float)$branchUnitHead,
                        'branch_override_total' => (float)$branchOverride,
                        'total_commissions' => (float)$allComms->whereIn('user_id', $allBranchIds)->sum('commission_amount')
                    ];
                })->values(),
            ],
            'business_details' => $userCommissions->map(function ($comm) {
                $inv = $comm->investment;
                return [
                    'customer' => $inv->customer->full_name ?? 'N/A',
                    'policy' => $inv->policy_number ?? 'N/A',
                    'amount' => (float)$inv->investment_amount,
                    'plan' => $inv->investmentProduct->name ?? 'N/A',
                    'date' => $inv->reservation_date ? $inv->reservation_date->format('Y-m-d') : 'N/A',
                    'status' => $inv->status,
                    'earned_commission' => (float)$comm->commission_amount,
                    'commission_type' => $comm->tier === 'unit_head' ? 'Unit Head' : 'Override'
                ];
            }),
            'cancelled_business_details' => $cancelledInvestments->map(function ($inv) use ($user) {
                $comm = Commission::where('investment_id', $inv->id)->where('user_id', $user->id)->first();
                return [
                    'customer' => $inv->customer->full_name ?? 'N/A',
                    'policy' => $inv->policy_number ?? 'N/A',
                    'amount' => (float)$inv->investment_amount,
                    'plan' => $inv->investmentProduct->name ?? 'N/A',
                    'date' => $inv->reservation_date ? $inv->reservation_date->format('Y-m-d') : 'N/A',
                    'status' => $inv->status,
                    'recovery_amount' => $comm ? (float)$comm->recover_amount : 0,
                    'commission_type' => $comm ? ($comm->tier === 'unit_head' ? 'Unit Head' : 'Override') : 'N/A'
                ];
            }),
            'subordinates' => $childrenNodes
        ];
    }

    /**
     * Search for an admin user and get detailed performance metrics for them and their entire branch.
     * Includes personal metrics, total branch business, and plan-wise breakdowns.
     * Supports both date range and period key filtering.
     */
    public function planWiseAdminReport(Request $request): JsonResponse
    {
        try {
            $currentUser = Auth::guard('api')->user();
            $search = $request->get('search');
            $fromDate = $request->get('from_date');
            $toDate = $request->get('to_date');
            $periodKeyInput = $request->get('period_key');

            // 1. Determine Date Range & Period Key
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

            // 2. Accessibility & Roles
            $isAdmin = $currentUser->hasRole('Super Admin') || ($currentUser->user_type === 'admin');
            $isBranchCoordinator = $currentUser->hasRole('Branch Coordinator');

            // 3. Determine Root Users for the Tree (Admin Users)
            $rootUsers = [];
            if ($search) {
                // Search for specific admin user
                $searchQuery = User::where('user_type', '=', 'admin', 'and')
                    ->where('is_head_office_user', '=', true, 'and');
                if ($isBranchCoordinator) {
                    $assignedBranchIds = $currentUser->assignedBranches()->pluck('branches.id')->toArray();
                    $searchQuery->whereIn('branch_id', $assignedBranchIds);
                } elseif (!$isAdmin) {
                    $myDescendantIds = $currentUser->getAllDescendantIds();
                    $accessibleIds = array_merge([$currentUser->id], $myDescendantIds);
                    $searchQuery->whereIn('id', $accessibleIds);
                }

                $cleanSearch = str_replace(' ', '', $search);
                $targetUser = $searchQuery->where(function ($q) use ($search, $cleanSearch) {
                    $q->where('name', 'like', "%{$search}%", 'and')
                        ->orWhere('id_number', 'like', "%{$search}%", 'and')
                        ->orWhereRaw("REPLACE(id_number, ' ', '') like ?", ["%{$cleanSearch}%"])
                        ->orWhere('username', 'like', "%{$search}%", 'and');
                })->first();

                if (!$targetUser) {
                    return response()->json(['status' => 'error', 'message' => 'Admin user not found.'], 404);
                }
                $rootUsers = [$targetUser];
            } else {
                // No search: Determine roots based on role/type
                if ($isAdmin) {
                    // Find all admin head-office users
                    $headOfficeAdmins = User::where('user_type', '=', 'admin', 'and')
                        ->where('is_head_office_user', '=', true, 'and')
                        ->pluck('id')
                        ->toArray();

                    // 1. Try to find head-office admins with no parent (top of chain)
                    $rootUsers = User::with(['level', 'branch'])
                        ->where('user_type', '=', 'admin', 'and')
                        ->where('is_head_office_user', '=', true, 'and')
                        ->whereNull('parent_user_id')
                        ->get();

                    // 2. If none found, walk up parent chains from head-office admins
                    if ($rootUsers->isEmpty() && !empty($headOfficeAdmins)) {
                        $topAncestorIds = [];
                        foreach ($headOfficeAdmins as $adminId) {
                            $ancestorId = $this->findTopAncestor($adminId);
                            if ($ancestorId && !in_array($ancestorId, $topAncestorIds)) {
                                $topAncestorIds[] = $ancestorId;
                            }
                        }
                        if (!empty($topAncestorIds)) {
                            $rootUsers = User::with(['level', 'branch'])
                                ->whereIn('id', $topAncestorIds)
                                ->get();
                        }
                    }
                } elseif ($isBranchCoordinator) {
                    $assignedBranchIds = $currentUser->assignedBranches()->pluck('branches.id')->toArray();
                    $rootUsers = User::with(['level', 'branch'])
                        ->where('user_type', '=', 'admin', 'and')
                        ->where('is_head_office_user', '=', true, 'and')
                        ->whereIn('branch_id', $assignedBranchIds)
                        ->whereNull('parent_user_id')
                        ->get();
                } else {
                    // An admin user sees themselves as root if they are admin and head office
                    if ($currentUser->user_type === 'admin' && $currentUser->is_head_office_user) {
                        $rootUsers = [User::with(['level', 'branch'])->find($currentUser->id)];
                    } else {
                        $rootUsers = [];
                    }
                }
            }

            // 4. Build Recursive Tree
            $tree = [];
            $visited = [];
            foreach ($rootUsers as $root) {
                $rootNode = $this->buildPlanWiseAdminNode($root, $from, $to, $periodKey, $visited);
                if ($rootNode !== null) {
                    $tree[] = $rootNode;
                }
            }

            // 5. Total Summary for Admin Users
            $allAdminIds = User::where('user_type', '=', 'admin', 'and')
                ->where('is_head_office_user', '=', true, 'and')
                ->pluck('id')
                ->toArray();
            $totalInvestments = Investment::whereIn('unit_head_id', $allAdminIds)
                ->whereBetween('reservation_date', [$from, $to])
                ->whereIn('status', ['approved', 'expired'])
                ->get();

            $totalCancelled = Investment::whereIn('unit_head_id', $allAdminIds)
                ->whereBetween('reservation_date', [$from, $to])
                ->where('status', '=', 'cancelled', 'and')
                ->get();

            $totalRecovery = Commission::whereIn('user_id', $allAdminIds)
                ->whereHas('investment', function ($q) use ($from, $to) {
                    $q->whereBetween('reservation_date', [$from, $to])
                        ->where('status', 'cancelled');
                })->sum('recover_amount');

            return response()->json([
                'status' => 'success',
                'message' => 'Plan-wise admin report retrieved successfully',
                'data' => [
                    'hierarchy_tree' => $tree,
                    'overall_summary' => [
                        'total_business' => (float)$totalInvestments->sum('investment_amount'),
                        'total_business_count' => $totalInvestments->count(),
                        'total_cancelled_business' => (float)$totalCancelled->sum('investment_amount'),
                        'total_cancelled_count' => $totalCancelled->count(),
                        'total_recovery_amount' => (float)$totalRecovery,
                        'period' => [
                            'from' => $from->toDateString(),
                            'to' => $to->toDateString()
                        ]
                    ]
                ]
            ], 200);
        } catch (\Throwable $th) {
            $this->logActivity('Error', 'Report', 'Plan-wise admin report failed', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString()
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve plan-wise admin report',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Recursive helper to build a plan-wise admin node.
     */
    private function buildPlanWiseAdminNode($user, $from, $to, $periodKey, &$visited = [])
    {
        // Cycle detection: skip if this user was already visited
        if (in_array($user->id, $visited)) {
            return null;
        }
        $visited[] = $user->id;
        // 1. Personal investments (this user only)
        $personalInvestments = Investment::with(['investmentProduct', 'customer'])
            ->where('unit_head_id', $user->id)
            ->whereBetween('reservation_date', [$from, $to])
            ->whereIn('status', ['approved', 'expired'])
            ->get();

        $personalCancelledInvestments = Investment::with(['investmentProduct', 'customer'])
            ->where('unit_head_id', $user->id)
            ->whereBetween('reservation_date', [$from, $to])
            ->where('status', '=', 'cancelled', 'and')
            ->get();

        // 2. Personal commissions
        $userCommissions = Commission::with(['investment.customer', 'investment.investmentProduct'])
            ->where('user_id', $user->id)
            ->whereHas('investment', function ($q) use ($from, $to) {
                $q->whereBetween('reservation_date', [$from, $to])
                    ->whereIn('status', ['approved', 'expired']);
            })
            ->get();

        $personalUnitHeadCommission = $userCommissions->where('tier', 'unit_head')->sum('commission_amount');
        $personalOverrideCommission = $userCommissions->where('tier', 'parent')->sum('commission_amount');
        $personalCommission = $userCommissions->sum('commission_amount');
        $personalBusinessCount = $userCommissions->where('tier', 'unit_head')->count();

        // 3. Personal recovery
        $userRecoveries = Commission::where('user_id', $user->id)
            ->whereHas('investment', function ($q) use ($from, $to) {
                $q->whereBetween('reservation_date', [$from, $to])
                    ->where('status', 'cancelled');
            })
            ->get();
        $personalRecovery = $userRecoveries->sum('recover_amount');

        // 4. Target
        $target = Target::where('user_id', '=', $user->id, 'and')
            ->where('period_key', '=', $periodKey, 'and')
            ->first();
        $targetAmount = $target ? (float)$target->target_amount : 0;

        // 5. Recursively build children first
        $children = User::with(['level', 'branch'])
            ->where('parent_user_id', '=', $user->id, 'and')
            ->where('user_type', '=', 'admin', 'and')
            ->where('is_head_office_user', '=', true, 'and')
            ->get();

        $childrenNodes = [];
        foreach ($children as $child) {
            $childNode = $this->buildPlanWiseAdminNode($child, $from, $to, $periodKey, $visited);
            if ($childNode !== null) {
                $childrenNodes[] = $childNode;
            }
        }

        // 6. Aggregate team metrics from self + all children recursively
        $teamBusinessAmount = (float)$personalInvestments->sum('investment_amount');
        $teamBusinessCount = $personalInvestments->count();
        $teamCancelledAmount = (float)$personalCancelledInvestments->sum('investment_amount');
        $teamCancelledCount = $personalCancelledInvestments->count();
        $teamCommission = (float)$personalCommission;
        $teamRecovery = (float)$personalRecovery;

        foreach ($childrenNodes as $childNode) {
            $teamBusinessAmount += $childNode['metrics']['team_business_amount'];
            $teamBusinessCount += $childNode['metrics']['team_business_count'];
            $teamCancelledAmount += $childNode['metrics']['team_cancelled_amount'];
            $teamCancelledCount += $childNode['metrics']['team_cancelled_count'];
            $teamCommission += $childNode['metrics']['team_commission'];
            $teamRecovery += $childNode['metrics']['team_recovery'];
        }

        $achievementPercentage = $targetAmount > 0
            ? ($teamBusinessAmount / $targetAmount) * 100
            : ($teamBusinessAmount > 0 ? 100 : 0);

        // 7. Personal plan breakdown
        $planBreakdown = $personalInvestments->groupBy('investment_product_id')->map(function ($group) use ($user) {
            $first = $group->first();
            $planName = $first->investmentProduct->name ?? 'N/A';

            $allComms = Commission::whereIn('investment_id', $group->pluck('id'))->get();

            $userUnitHead = $allComms->where('user_id', $user->id)->where('tier', 'unit_head')->sum('commission_amount');
            $userOverride = $allComms->where('user_id', $user->id)->where('tier', 'parent')->sum('commission_amount');

            return [
                'plan_name' => $planName,
                'business_count' => $group->count(),
                'total_investment_amount' => (float)$group->sum('investment_amount'),
                'user_unit_head_commission' => (float)$userUnitHead,
                'user_override_commission' => (float)$userOverride,
                'total_commissions' => (float)$allComms->where('user_id', $user->id)->sum('commission_amount')
            ];
        })->values();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'employee_code' => $user->employee_code,
            'level' => $user->level->level_name ?? 'N/A',
            'branch' => $user->branch->name ?? 'N/A',
            'is_active' => (bool)$user->is_active,
            'metrics' => [
                'target_amount' => $targetAmount,
                'achievement_percentage' => (float)min($achievementPercentage, 999999.99),
                'personal_business_amount' => (float)$personalInvestments->sum('investment_amount'),
                'personal_business_count' => $personalBusinessCount,
                'personal_commission' => (float)$personalCommission,
                'personal_unit_head_commission' => (float)$personalUnitHeadCommission,
                'personal_override_commission' => (float)$personalOverrideCommission,
                'personal_recovery_amount' => (float)$personalRecovery,
                'team_business_amount' => (float)$teamBusinessAmount,
                'team_business_count' => $teamBusinessCount,
                'team_commission' => (float)$teamCommission,
                'team_cancelled_amount' => (float)$teamCancelledAmount,
                'team_cancelled_count' => $teamCancelledCount,
                'team_recovery' => (float)$teamRecovery,
                'plan_breakdown' => $planBreakdown,
            ],
            'business_details' => $userCommissions->map(function ($comm) {
                $inv = $comm->investment;
                return [
                    'customer' => $inv->customer->full_name ?? 'N/A',
                    'policy' => $inv->policy_number ?? 'N/A',
                    'amount' => (float)$inv->investment_amount,
                    'plan' => $inv->investmentProduct->name ?? 'N/A',
                    'date' => $inv->reservation_date ? $inv->reservation_date->format('Y-m-d') : 'N/A',
                    'status' => $inv->status,
                    'earned_commission' => (float)$comm->commission_amount,
                    'commission_type' => $comm->tier === 'unit_head' ? 'Unit Head' : 'Override'
                ];
            }),
            'cancelled_business_details' => $personalCancelledInvestments->map(function ($inv) use ($user) {
                $comm = Commission::where('investment_id', $inv->id)->where('user_id', $user->id)->first();
                return [
                    'customer' => $inv->customer->full_name ?? 'N/A',
                    'policy' => $inv->policy_number ?? 'N/A',
                    'amount' => (float)$inv->investment_amount,
                    'plan' => $inv->investmentProduct->name ?? 'N/A',
                    'date' => $inv->reservation_date ? $inv->reservation_date->format('Y-m-d') : 'N/A',
                    'status' => $inv->status,
                    'recovery_amount' => $comm ? (float)$comm->recover_amount : 0,
                    'commission_type' => $comm ? ($comm->tier === 'unit_head' ? 'Unit Head' : 'Override') : 'N/A'
                ];
            }),
            'subordinates' => $childrenNodes
        ];
    }

    /**
     * Get a paginated list of all customer investments with beneficiary, bank, agent, and calculated maturity info,
     * along with a consolidated product-wise monthly maturity summary.
     */
    public function customerInvestmentsMaturityReport(Request $request): JsonResponse
    {
        try {
            $user = Auth::guard('api')->user();
            $perPage = $request->get('per_page', 15);
            $periodKey = $request->get('period_key', Carbon::now()->format('Y-m'));
            $branchId = $request->get('branch_id');

            // 1. Core query setup with all required relationships
            $query = Investment::with([
                'customer',
                'beneficiary',
                'bankDetail',
                'unitHead.level',
                'unitHead.branch',
                'creator',
                'investmentProduct.annualRates',
                'branch'
            ]);

            // 2. Accessibility/Hierarchy Visibility Checks
            $assignedBranchIds = [];
            $accessibleUserIds = [];
            
            if ($user->hasRole('Branch Coordinator')) {
                $assignedBranchIds = $user->assignedBranches()->pluck('branches.id')->toArray();
                $query->whereIn('branch_id', $assignedBranchIds);
            } elseif (!$user->hasRole('Super Admin') && ($user->user_type !== 'admin')) {
                // Hierarchical users see their own and descendants' investments
                $descendantIds = $user->getAllDescendantIds();
                $accessibleUserIds = array_merge([$user->id], $descendantIds);
                $query->whereIn('created_by', $accessibleUserIds);
            }

            // 3. Filters
            if ($request->has('investment_product_id')) {
                $query->where('investment_product_id', '=', $request->investment_product_id);
            }

            if ($branchId) {
                $query->where('branch_id', '=', $branchId);
            }

            $status = $request->get('status', 'approved');
            $query->where('status', '=', $status);

            if ($request->has('period_key')) {
                $query->where('target_period_key', '=', $request->period_key);
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->whereHas('customer', function ($cq) use ($search) {
                        $cq->where('full_name', 'like', "%{$search}%")
                            ->orWhere('id_number', 'like', "%{$search}%")
                            ->orWhere('customer_code', 'like', "%{$search}%");
                    })
                    ->orWhere('policy_number', 'like', "%{$search}%")
                    ->orWhere('application_number', 'like', "%{$search}%");
                });
            }

            // 4. Paginate Results
            $investments = $query->orderBy('created_at', 'desc')->paginate($perPage);

            // 5. Transform Data to calculated fields
            $investments->getCollection()->transform(function ($inv) use ($periodKey) {
                $calculations = [];
                $monthlyMaturityAmount = 0;
                $effectiveRoi = 0;

                if ($inv->investmentProduct) {
                    $calculations = $this->calculateInvestmentROI((float)$inv->investment_amount, $inv->investmentProduct);
                    
                    $reservationDate = $inv->reservation_date;
                    if ($reservationDate) {
                        $targetPeriodDate = Carbon::parse($periodKey . '-01');
                        
                        $yearDiff = $targetPeriodDate->year - $reservationDate->year;
                        $monthDiff = $targetPeriodDate->month - $reservationDate->month;
                        $diffInMonths = ($yearDiff * 12) + $monthDiff;

                        // Calculate current year index (1-based)
                        $currentYearOfInvestment = (int)ceil(($diffInMonths + 1) / 12);
                        
                        $durationMonths = $inv->investmentProduct->duration_months ?? 0;
                        $maxYears = (int)ceil($durationMonths / 12);
                        
                        // clamp
                        $currentYear = max(1, min($currentYearOfInvestment, $maxYears));

                        // Find the breakdown for that year
                        $yearData = collect($calculations['yearly_breakdown'] ?? [])->firstWhere('year', $currentYear);
                        if ($yearData) {
                            $monthlyMaturityAmount = $yearData['monthly_payout'];
                            $effectiveRoi = $yearData['roi_percentage'];
                        } else {
                            $monthlyMaturityAmount = $calculations['monthly_return'] ?? 0;
                            $effectiveRoi = $inv->investmentProduct->roi_percentage ?? 0;
                        }
                    } else {
                        $monthlyMaturityAmount = $calculations['monthly_return'] ?? 0;
                        $effectiveRoi = $inv->investmentProduct->roi_percentage ?? 0;
                    }
                }

                $durationMonths = $inv->investmentProduct->duration_months ?? 0;
                $maturityDate = $inv->reservation_date 
                    ? $inv->reservation_date->copy()->addMonths($durationMonths)->format('Y-m-d') 
                    : 'N/A';

                return [
                    'id' => $inv->id,
                    'policy_number' => $inv->policy_number,
                    'application_number' => $inv->application_number,
                    'status' => $inv->status,
                    'reservation_date' => $inv->reservation_date ? $inv->reservation_date->format('Y-m-d') : 'N/A',
                    'maturity_date' => $maturityDate,
                    'investment_amount' => (float)$inv->investment_amount,
                    'percentage_of_investment' => (float)$effectiveRoi,
                    'monthly_maturity_amount' => round((float)$monthlyMaturityAmount, 2),
                    'maturity_breakdown' => $calculations['yearly_breakdown'] ?? [],
                    
                    'customer' => $inv->customer ? [
                        'id' => $inv->customer->id,
                        'full_name' => $inv->customer->full_name,
                        'customer_code' => $inv->customer->customer_code,
                        'id_type' => $inv->customer->id_type,
                        'id_number' => $inv->customer->id_number,
                        'phone_primary' => $inv->customer->phone_primary,
                        'email' => $inv->customer->email,
                        'address' => trim(($inv->customer->address_line_1 ?? '') . ' ' . ($inv->customer->address_line_2 ?? '') . ' ' . ($inv->customer->city ?? ''))
                    ] : null,

                    'beneficiary' => $inv->beneficiary ? [
                        'id' => $inv->beneficiary->id,
                        'full_name' => $inv->beneficiary->full_name,
                        'relationship' => $inv->beneficiary->relationship,
                        'share_percentage' => (float)$inv->beneficiary->share_percentage,
                        'phone_primary' => $inv->beneficiary->phone_primary
                    ] : null,

                    'bank_detail' => $inv->bankDetail ? [
                        'id' => $inv->bankDetail->id,
                        'bank_name' => $inv->bankDetail->bank_name,
                        'branch_name' => $inv->bankDetail->branch_name,
                        'account_number' => $inv->bankDetail->account_number,
                        'payment_method' => $inv->bankDetail->payment_method
                    ] : null,

                    'agent' => $inv->unitHead ? [
                        'id' => $inv->unitHead->id,
                        'name' => $inv->unitHead->name,
                        'username' => $inv->unitHead->username,
                        'employee_code' => $inv->unitHead->employee_code,
                        'level' => $inv->unitHead->level->level_name ?? 'N/A',
                        'branch' => $inv->unitHead->branch->name ?? 'N/A'
                    ] : ($inv->creator ? [
                        'id' => $inv->creator->id,
                        'name' => $inv->creator->name,
                        'username' => $inv->creator->username,
                        'employee_code' => $inv->creator->employee_code,
                        'level' => $inv->creator->level->level_name ?? 'N/A',
                        'branch' => $inv->creator->branch->name ?? 'N/A'
                    ] : null),

                    'investment_product' => $inv->investmentProduct ? [
                        'id' => $inv->investmentProduct->id,
                        'name' => $inv->investmentProduct->name,
                        'duration_months' => $inv->investmentProduct->duration_months,
                        'roi_percentage' => (float)$inv->investmentProduct->roi_percentage,
                        'is_variable_roi' => (bool)$inv->investmentProduct->is_variable_roi
                    ] : null
                ];
            });

            // 6. Calculate consolidated Product-wise Monthly Maturity Summary
            // Let's filter by the same period (using scheduled payout date check)
            $from = Carbon::parse($periodKey . '-01')->startOfMonth();
            $to = Carbon::parse($periodKey . '-01')->endOfMonth();

            $payoutsQuery = DB::table('investment_payouts')
                ->join('investments', 'investment_payouts.investment_id', '=', 'investments.id')
                ->join('investment_products', 'investments.investment_product_id', '=', 'investment_products.id')
                ->select(
                    'investment_products.id as product_id',
                    'investment_products.name as product_name',
                    'investment_products.duration_months',
                    'investment_products.roi_percentage',
                    DB::raw('COUNT(DISTINCT investments.id) as total_investments_count'),
                    DB::raw('SUM(investments.investment_amount) as total_invested_amount'),
                    DB::raw('SUM(investment_payouts.amount) as total_monthly_maturity_amount')
                )
                ->whereIn('investments.status', ['approved', 'expired'])
                ->whereNull('investments.deleted_at')
                ->whereBetween('investment_payouts.scheduled_date', [$from, $to]);

            // Apply visibility constraints
            if ($user->hasRole('Branch Coordinator')) {
                $payoutsQuery->whereIn('investments.branch_id', $assignedBranchIds);
            } elseif (!$user->hasRole('Super Admin') && ($user->user_type !== 'admin')) {
                $payoutsQuery->whereIn('investments.created_by', $accessibleUserIds);
            }

            if ($branchId) {
                $payoutsQuery->where('investments.branch_id', '=', $branchId);
            }

            $productSummary = $payoutsQuery->groupBy(
                'investment_products.id',
                'investment_products.name',
                'investment_products.duration_months',
                'investment_products.roi_percentage'
            )->get();

            $formattedSummary = $productSummary->map(function ($row) {
                return [
                    'product_id' => $row->product_id,
                    'product_name' => $row->product_name,
                    'duration_months' => $row->duration_months,
                    'roi_percentage' => (float)$row->roi_percentage,
                    'total_investments_count' => (int)$row->total_investments_count,
                    'total_invested_amount' => (float)$row->total_invested_amount,
                    'total_monthly_maturity_amount' => round((float)$row->total_monthly_maturity_amount, 2)
                ];
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Consolidated customer investments and maturity report retrieved successfully',
                'data' => $investments,
                'meta' => [
                    'period_key' => $periodKey,
                    'period_from' => $from->toDateString(),
                    'period_to' => $to->toDateString(),
                    'product_wise_maturity_summary' => $formattedSummary
                ]
            ], 200);

        } catch (\Throwable $th) {
            $this->logActivity('Error', 'Report', 'Consolidated customer investments and maturity report failed', [
                'error' => $th->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve consolidated customer investments and maturity report',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Get a listing of investments with welcome call statuses, caller details, and filters.
     */
    public function welcomeCallReport(Request $request): JsonResponse
    {
        try {
            $user = Auth::guard('api')->user();
            $perPage = $request->get('per_page', 15);
            $branchId = $request->get('branch_id');
            $welcomeCallStatus = $request->get('welcome_call_status');

            // 1. Core query setup with required relationships
            $query = Investment::with([
                'customer',
                'branch',
                'unitHead',
                'investmentProduct',
                'welcomeCallUser'
            ]);

            // 2. Accessibility/Hierarchy Visibility Checks
            $assignedBranchIds = [];
            $accessibleUserIds = [];

            if ($user->hasRole('Branch Coordinator')) {
                $assignedBranchIds = $user->assignedBranches()->pluck('branches.id')->toArray();
                $query->whereIn('branch_id', $assignedBranchIds);
            } elseif (!$user->hasRole('Super Admin') && ($user->user_type !== 'admin')) {
                // Hierarchical users see their own and descendants' investments
                $descendantIds = $user->getAllDescendantIds();
                $accessibleUserIds = array_merge([$user->id], $descendantIds);
                $query->whereIn('created_by', $accessibleUserIds);
            }

            // 3. Filters
            // Default status: approved (since welcome calls are on approved investments)
            $status = $request->get('status', 'approved');
            if ($status !== 'all') {
                $query->where('status', '=', $status);
            }

            if ($branchId) {
                $query->where('branch_id', '=', $branchId);
            }

            // Date filtering
            $fromDate = $request->get('from_date');
            $toDate = $request->get('to_date');
            $dateType = $request->get('date_type', 'approved_at'); // approved_at, welcome_call_at or reservation_date

            if (!in_array($dateType, ['approved_at', 'welcome_call_at', 'reservation_date'])) {
                $dateType = 'approved_at';
            }

            if ($fromDate && $toDate) {
                $query->whereBetween($dateType, [$fromDate, $toDate]);
            } elseif ($fromDate) {
                $query->where($dateType, '>=', $fromDate);
            } elseif ($toDate) {
                $query->where($dateType, '<=', $toDate);
            }

            // Search filter
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->whereHas('customer', function ($cq) use ($search) {
                        $cq->where('full_name', 'like', "%{$search}%")
                            ->orWhere('id_number', 'like', "%{$search}%")
                            ->orWhere('customer_code', 'like', "%{$search}%")
                            ->orWhere('phone_primary', 'like', "%{$search}%");
                    })
                    ->orWhere('policy_number', 'like', "%{$search}%")
                    ->orWhere('application_number', 'like', "%{$search}%");
                });
            }

            // Clone query to calculate summary statistics BEFORE applying welcome_call_status filter
            $summaryQuery = clone $query;

            // Apply welcome_call_status filter on the main query for the list
            if ($welcomeCallStatus) {
                $query->where('welcome_call_status', '=', $welcomeCallStatus);
            }

            // 4. Calculate Summary Statistics
            $summaryStats = $summaryQuery->select('welcome_call_status', DB::raw('count(*) as count'), DB::raw('sum(investment_amount) as total_amount'))
                ->groupBy('welcome_call_status')
                ->get()
                ->keyBy('welcome_call_status');

            $statuses = ['pending', 'completed', 'not_reachable', 'no_answer', 'others'];
            $summary = [
                'total_investments' => 0,
                'total_amount' => 0.0,
            ];

            foreach ($statuses as $st) {
                $summary[$st . '_count'] = 0;
            }

            foreach ($summaryStats as $statusKey => $data) {
                if (in_array($statusKey, $statuses)) {
                    $summary[$statusKey . '_count'] = (int)$data->count;
                }
                $summary['total_investments'] += (int)$data->count;
                $summary['total_amount'] += (float)$data->total_amount;
            }

            // 5. Paginate Results
            $investments = $query->orderBy('approved_at', 'desc')->paginate($perPage);

            // 6. Transform Data
            $investments->getCollection()->transform(function ($inv) {
                return [
                    'id' => $inv->id,
                    'policy_number' => $inv->policy_number,
                    'application_number' => $inv->application_number,
                    'investment_amount' => (float)$inv->investment_amount,
                    'status' => $inv->status,
                    'reservation_date' => $inv->reservation_date ? $inv->reservation_date->format('Y-m-d') : null,
                    'approved_at' => $inv->approved_at ? $inv->approved_at->format('Y-m-d') : null,
                    
                    'customer' => $inv->customer ? [
                        'id' => $inv->customer->id,
                        'full_name' => $inv->customer->full_name,
                        'customer_code' => $inv->customer->customer_code,
                        'phone_primary' => $inv->customer->phone_primary,
                        'email' => $inv->customer->email,
                    ] : null,

                    'branch' => $inv->branch ? [
                        'id' => $inv->branch->id,
                        'name' => $inv->branch->name,
                        'code' => $inv->branch->code,
                    ] : null,

                    'agent' => $inv->unitHead ? [
                        'id' => $inv->unitHead->id,
                        'name' => $inv->unitHead->name,
                        'employee_code' => $inv->unitHead->employee_code,
                    ] : null,

                    'plan' => $inv->investmentProduct ? [
                        'id' => $inv->investmentProduct->id,
                        'name' => $inv->investmentProduct->name,
                        'duration_months' => $inv->investmentProduct->duration_months,
                    ] : null,

                    'welcome_call' => [
                        'status' => $inv->welcome_call_status,
                        'notes' => $inv->welcome_call_notes,
                        'at' => $inv->welcome_call_at ? $inv->welcome_call_at->format('Y-m-d H:i:s') : null,
                        'by' => $inv->welcomeCallUser ? [
                            'id' => $inv->welcomeCallUser->id,
                            'name' => $inv->welcomeCallUser->name,
                        ] : null,
                    ]
                ];
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Welcome call report retrieved successfully',
                'data' => $investments,
                'meta' => [
                    'summary' => $summary,
                    'filters' => [
                        'welcome_call_status' => $welcomeCallStatus,
                        'branch_id' => $branchId,
                        'status' => $status,
                        'from_date' => $fromDate,
                        'to_date' => $toDate,
                        'date_type' => $dateType
                    ]
                ]
            ], 200);

        } catch (\Throwable $th) {
            $this->logActivity('Error', 'Report', 'Welcome call report failed', [
                'error' => $th->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve welcome call report',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Walk up the parent chain to find the topmost ancestor.
     */
    private function findTopAncestor(int $userId): ?int
    {
        $currentId = $userId;
        $visited = [$currentId];

        while (true) {
            $user = User::where('id', $currentId)->select('id', 'parent_user_id')->first();
            if (!$user || $user->parent_user_id === null) {
                return $currentId;
            }
            if (in_array($user->parent_user_id, $visited)) {
                return $currentId;
            }
            $visited[] = $user->parent_user_id;
            $currentId = $user->parent_user_id;
        }
    }
}
