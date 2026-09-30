<?php

namespace App\Http\Controllers\V1\Investor;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Investment;
use App\Models\InvestmentPayout;
use App\Traits\ActivityLogTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class HomeController extends Controller
{
    use ActivityLogTrait;

    /**
     * Get home screen dashboard data for the investor.
     * Supports boolean parameters to selectively include data blocks.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = auth('api')->user();

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            // Resolve customer profile linked to this user
            $customer = Customer::where('customer_id', $user->id)
                ->orWhereRaw('LOWER(TRIM(id_number)) = ?', [strtolower($user->id_number)])
                ->first();

            $data = [];

            // 1. My Investments Stats (Count & Total Amount)
            if ($request->boolean('include_my_stats')) {
                if ($customer) {
                    $myInvestments = Investment::where('customer_id', $customer->id);

                    // You may want to filter by active investments only depending on your business logic
                    // $myInvestments = $myInvestments->where('status', 'approved');

                    $data['my_stats'] = [
                        'invest_count' => $myInvestments->count(),
                        'total_investment_amount' => $myInvestments->sum('investment_amount'),
                    ];
                } else {
                    $data['my_stats'] = [
                        'invest_count' => 0,
                        'total_investment_amount' => 0,
                    ];
                }
            }

            // 2. Global Investments Total (Total of ALL investments across the platform)
            if ($request->boolean('include_global_total')) {
                // If you just want the sum of ALL investments on the platform:
                $data['global_total_investment'] = Investment::sum('investment_amount');
            }

            // 3. My Investments grouped by status (pending, active, expired, cancelled)
            if ($request->boolean('include_status_stats')) {
                if ($customer) {
                    $statusCounts = Investment::where('customer_id', $customer->id)
                        ->selectRaw('status, count(*) as count, sum(investment_amount) as total_amount')
                        ->groupBy('status')
                        ->get()
                        ->keyBy('status');

                    // Default structure ensuring all 4 statuses are always present for the mobile app
                    $data['status_stats'] = [
                        'pending' => [
                            'count' => $statusCounts->get('pending')->count ?? 0,
                            'total_amount' => $statusCounts->get('pending')->total_amount ?? 0,
                        ],
                        'active' => [
                            'count' => $statusCounts->get('active')->count ?? 0,
                            'total_amount' => $statusCounts->get('active')->total_amount ?? 0,
                        ],
                        'expired' => [
                            'count' => $statusCounts->get('expired')->count ?? 0,
                            'total_amount' => $statusCounts->get('expired')->total_amount ?? 0,
                        ],
                        'cancelled' => [
                            'count' => $statusCounts->get('cancelled')->count ?? 0,
                            'total_amount' => $statusCounts->get('cancelled')->total_amount ?? 0,
                        ],
                    ];
                } else {
                    $data['status_stats'] = [
                        'pending' => ['count' => 0, 'total_amount' => 0],
                        'active' => ['count' => 0, 'total_amount' => 0],
                        'expired' => ['count' => 0, 'total_amount' => 0],
                        'cancelled' => ['count' => 0, 'total_amount' => 0],
                    ];
                }
            }

            // 4. Most Recent Unpaid Payout for this customer
            if ($request->boolean('include_upcoming_payout')) {
                if ($customer) {
                    // Get all investment IDs belonging to this customer
                    $investmentIds = Investment::where('customer_id', $customer->id)
                        ->pluck('id');

                    // Fetch the single most recent UNPAID payout (status != paid)
                    $upcomingPayout = InvestmentPayout::whereIn('investment_id', $investmentIds)
                        ->where('status', '!=', 'paid')
                        ->orderBy('scheduled_date', 'desc')
                        ->with(['investment:id,policy_number,investment_amount,status'])
                        ->first();

                    $data['upcoming_payout'] = $upcomingPayout ? [
                        'id'               => $upcomingPayout->id,
                        'investment_id'    => $upcomingPayout->investment_id,
                        'policy_number'    => $upcomingPayout->investment->policy_number ?? null,
                        'scheduled_date'   => $upcomingPayout->scheduled_date,
                        'amount'           => $upcomingPayout->amount,
                        'status'           => $upcomingPayout->status,
                        'reference_number' => $upcomingPayout->reference_number,
                        'remarks'          => $upcomingPayout->remarks,
                    ] : null;
                } else {
                    $data['upcoming_payout'] = null;
                }
            }

            $this->logActivity('View Dashboard', 'Investor Portal', "Dashboard viewed: {$user->name}", [
                'user_id' => $user->id,
                'customer_id' => $customer ? $customer->id : null,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Dashboard data fetched successfully',
                'data' => $data,
            ], 200);

        } catch (\Throwable $th) {
            Log::error('Investor home dashboard fetch error: ' . $th->getMessage(), [
                'exception' => $th,
            ]);

            $this->logActivity('Error', 'Investor Portal', 'Fetch dashboard error: ' . $th->getMessage(), [
                'exception' => $th->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch dashboard data',
                'error' => config('app.debug') ? $th->getMessage() : null,
            ], 500);
        }
    }
}
