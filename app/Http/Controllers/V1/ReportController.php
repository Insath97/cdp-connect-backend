<?php

namespace App\Http\Controllers\V1;

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

class ReportController extends Controller implements HasMiddleware
{
    use InvestmentCalculationTrait;

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Report Index', only: ['index', 'show']),
            new Middleware('permission:Report Agent Performance', only: ['agentPerformance']),
            new Middleware('permission:Report Investor Maturity', only: ['investorMaturity']),
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

            $query = User::with(['level', 'branch'])
                ->select('users.id', 'users.name', 'users.username', 'users.level_id', 'users.branch_id', 'users.user_type')
                ->where('users.user_type', 'hierarchy');

            if (!$isAdmin) {
                $descendantIds = $user->getAllDescendantIds();
                $accessibleIds = array_merge([$user->id], $descendantIds);
                $query->whereIn('users.id', $accessibleIds);
            }

            // 2. Search
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('users.name', 'like', "%{$search}%")
                        ->orWhere('users.username', 'like', "%{$search}%");
                });
            }

            // 3. Join Targets and Commissions for the given period
            // Subquery for commission to avoid double counting if multiple targets existed (though unlikely)
            $commissionsSub = Commission::select('user_id', DB::raw('SUM(commission_amount) as total_commission'))
                ->where('period_key', $periodKey)
                ->groupBy('user_id');

            $reports = $query->leftJoinSub(
                Target::where('period_key', $periodKey),
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
            Log::error('Report generation failed', [
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
                ->select('users.id', 'users.name', 'users.username', 'users.level_id', 'users.branch_id', 'users.user_type')
                ->where('users.id', $id)
                ->where('users.user_type', 'hierarchy')
                ->leftJoinSub(
                    Target::where('period_key', $periodKey),
                    't',
                    'users.id',
                    '=',
                    't.user_id'
                )
                ->leftJoinSub(
                    Commission::select('user_id', DB::raw('SUM(commission_amount) as total_commission'))
                        ->where('period_key', $periodKey)
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
            Log::error('Detailed report failed', [
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
    public function agentPerformance(Request $request)
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

            $query = User::with(['level', 'branch'])
                ->where('user_type', 'hierarchy');

            if (!$isAdmin) {
                $descendantIds = $currentUser->getAllDescendantIds();
                $accessibleIds = array_merge([$currentUser->id], $descendantIds);
                $query->whereIn('id', $accessibleIds);
            }

            // 2. Execute Search
            $agent = $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('id_number', $search)
                  ->orWhere('username', $search);
            })->first();

            if (!$agent) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Hierarchy user not found or access denied.'
                ], 404);
            }

            // 3. Performance Metrics (Target & Commission)
            $target = Target::where('user_id', $agent->id)
                ->where('period_key', $periodKey)
                ->first();

            $totalCommission = Commission::where('user_id', $agent->id)
                ->where('period_key', $periodKey)
                ->sum('commission_amount');

            // 4. Customer Details (Investments)
            // Retrieve important datas as requested: Customer Name, Plan, Period, Amount, Maturity details
            $investments = Investment::with(['customer', 'investmentProduct.annualRates'])
                ->where('created_by', $agent->id)
                ->get()
                ->map(function ($inv) {
                    $calculations = [];
                    if ($inv->investmentProduct) {
                        $calculations = $this->calculateInvestmentROI((float)$inv->investment_amount, $inv->investmentProduct);
                    }

                    return [
                        'customer_name' => $inv->customer->full_name ?? 'N/A',
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

            return response()->json([
                'status' => 'success',
                'message' => 'Agent performance data retrieved successfully',
                'data' => [
                    'agent_info' => [
                        'id' => $agent->id,
                        'name' => $agent->name,
                        'id_number' => $agent->id_number,
                        'level' => $agent->level->name ?? 'N/A',
                        'branch' => $agent->branch->name ?? 'N/A',
                        'target_amount' => $target ? $target->target_amount : 0,
                        'achieved_amount' => $target ? $target->achieved_amount : 0,
                        'achievement_percentage' => $target ? $target->achievement_percentage : 0,
                        'total_commission' => (float) $totalCommission,
                        'period_key' => $periodKey
                    ],
                    'customer_details' => $investments
                ]
            ], 200);

        } catch (\Throwable $th) {
            Log::error('Agent performance search failed', [
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
     * Get a paginated list of all investors with their maturity schedules and payouts.
     */
    public function investorMaturity(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $user = Auth::guard('api')->user();

            $query = Investment::with(['customer', 'investmentProduct.annualRates', 'branch', 'creator']);

            // 1. Hierarchy Visibility Logic
            if (!$user->hasRole('Super Admin') && ($user->user_type !== 'admin')) {
                // Hierarchical users see their own and descendants' investments
                $descendantIds = $user->getAllDescendantIds();
                $accessibleUserIds = array_merge([$user->id], $descendantIds);
                $query->whereIn('created_by', $accessibleUserIds);
            }

            // 2. Filters
            if ($request->has('investment_product_id')) {
                $query->where('investment_product_id', $request->investment_product_id);
            }

            if ($request->has('period_key')) {
                $query->where('target_period_key', $request->period_key);
            }

            if ($request->has('branch_id')) {
                $query->where('branch_id', $request->branch_id);
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->whereHas('customer', function ($cq) use ($search) {
                        $cq->where('full_name', 'like', "%{$search}%")
                            ->orWhere('id_number', 'like', "%{$search}%")
                            ->orWhere('customer_code', 'like', "%{$search}%");
                    })->orWhere('policy_number', 'like', "%{$search}%")
                      ->orWhere('application_number', 'like', "%{$search}%");
                });
            }

            // 3. Status Filter (Default to approved for maturity analysis)
            $status = $request->get('status', 'approved');
            $query->where('status', $status);

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
                    'status' => $inv->status
                ];
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Investor maturity report retrieved successfully',
                'data' => $investments
            ], 200);

        } catch (\Throwable $th) {
            Log::error('Investor maturity report failed', [
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
}
