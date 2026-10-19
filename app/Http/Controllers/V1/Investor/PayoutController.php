<?php

namespace App\Http\Controllers\V1\Investor;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Investment;
use App\Models\InvestmentPayout;
use App\Traits\ActivityLogTrait;
use App\Traits\ResolvesInvestorCustomer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayoutController extends Controller
{
    use ActivityLogTrait, ResolvesInvestorCustomer;

    /**
     * Get the investment IDs belonging to the authenticated customer.
     */
    protected function customerInvestmentIds(?Customer $customer = null): array
    {
        if (! $customer) {
            return [];
        }

        return Investment::where('customer_id', $customer->id)
            ->pluck('id')
            ->toArray();
    }

    /**
     * List monthly payouts for the logged-in customer with filters.
     *
     * Filters: status (paid|unpaid|hold|cancelled), month (1-12), year (YYYY),
     * investment_id, per_page.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = auth('api')->user();

            if (! $user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            $request->validate([
                'status' => 'nullable|in:paid,unpaid,hold,cancelled,all',
                'month' => 'nullable|integer|min:1|max:12',
                'year' => 'nullable|integer|min:2000|max:2100',
                'investment_id' => 'nullable|integer|exists:investments,id',
                'per_page' => 'nullable|integer|min:1|max:100',
            ]);

            $customer = $this->resolveCustomer();
            $investmentIds = $this->customerInvestmentIds($customer);

            $perPage = $request->get('per_page', 15);

            $query = InvestmentPayout::with([
                'investment:id,policy_number,investment_amount,status,investment_product_id',
                'investment.investmentProduct:id,name,code,duration_months,roi_percentage',
            ])->whereIn('investment_id', $investmentIds);

            // Status wise filter (paid / unpaid / hold / cancelled)
            if ($request->filled('status') && $request->status !== 'all') {
                $query->where('status', $request->status);
            }

            // Month filter on scheduled_date
            if ($request->filled('month')) {
                $query->whereMonth('scheduled_date', $request->month);
            }

            // Year filter on scheduled_date
            if ($request->filled('year')) {
                $query->whereYear('scheduled_date', $request->year);
            }

            // Narrow to a single investment
            if ($request->filled('investment_id')) {
                $query->where('investment_id', $request->investment_id);
            }

            $payouts = $query->orderBy('scheduled_date', 'asc')->paginate($perPage);

            $this->logActivity('View Payouts', 'Investor Portal', "Monthly payouts viewed: {$user->name}", [
                'user_id' => $user->id,
                'customer_id' => $customer ? $customer->id : null,
                'filters' => $request->only(['status', 'month', 'year', 'investment_id']),
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Payouts retrieved successfully',
                'data' => $payouts,
            ], 200);
        } catch (\Throwable $th) {
            $this->logActivity('Error', 'Investor Portal', 'Fetch payouts error: '.$th->getMessage(), [
                'exception' => $th->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch payouts',
                'error' => config('app.debug') ? $th->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Monthly payout analysis for the logged-in customer.
     *
     * Returns summary cards, month wise paid/unpaid breakdown and
     * per investment breakdown.
     */
    public function analysis(Request $request): JsonResponse
    {
        try {
            $user = auth('api')->user();

            if (! $user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            $request->validate([
                'year' => 'nullable|integer|min:2000|max:2100',
                'investment_id' => 'nullable|integer|exists:investments,id',
            ]);

            $customer = $this->resolveCustomer();
            $investmentIds = $this->customerInvestmentIds($customer);

            $year = (int) ($request->get('year') ?? now()->year);

            $baseQuery = InvestmentPayout::whereIn('investment_id', $investmentIds)
                ->whereYear('scheduled_date', $year);

            if ($request->filled('investment_id')) {
                $baseQuery->where('investment_id', $request->investment_id);
            }

            // 1. Summary (total / paid / unpaid / hold)
            $summaryRows = (clone $baseQuery)
                ->selectRaw('status, count(*) as count, COALESCE(sum(amount), 0) as total_amount')
                ->groupBy('status')
                ->get()
                ->keyBy('status');

            $summary = [
                'total' => [
                    'count' => $summaryRows->sum('count'),
                    'total_amount' => round($summaryRows->sum('total_amount'), 2),
                ],
                'paid' => [
                    'count' => $summaryRows->get('paid')->count ?? 0,
                    'total_amount' => round($summaryRows->get('paid')->total_amount ?? 0, 2),
                ],
                'unpaid' => [
                    'count' => $summaryRows->get('unpaid')->count ?? 0,
                    'total_amount' => round($summaryRows->get('unpaid')->total_amount ?? 0, 2),
                ],
                'hold' => [
                    'count' => $summaryRows->get('hold')->count ?? 0,
                    'total_amount' => round($summaryRows->get('hold')->total_amount ?? 0, 2),
                ],
                'cancelled' => [
                    'count' => $summaryRows->get('cancelled')->count ?? 0,
                    'total_amount' => round($summaryRows->get('cancelled')->total_amount ?? 0, 2),
                ],
            ];

            // 2. Month wise breakdown (always 12 keys present for the mobile app)
            $monthRows = (clone $baseQuery)
                ->selectRaw("DATE_FORMAT(scheduled_date, '%Y-%m') as month_key, status, count(*) as count, COALESCE(sum(amount), 0) as total_amount")
                ->groupBy('month_key', 'status')
                ->get();

            $monthWise = [];
            for ($m = 1; $m <= 12; $m++) {
                $key = sprintf('%d-%02d', $year, $m);
                $monthWise[$key] = [
                    'paid' => ['count' => 0, 'total_amount' => 0],
                    'unpaid' => ['count' => 0, 'total_amount' => 0],
                    'hold' => ['count' => 0, 'total_amount' => 0],
                    'cancelled' => ['count' => 0, 'total_amount' => 0],
                ];
            }

            foreach ($monthRows as $row) {
                if (! isset($monthWise[$row->month_key])) {
                    continue;
                }
                $monthWise[$row->month_key][$row->status] = [
                    'count' => (int) $row->count,
                    'total_amount' => round($row->total_amount, 2),
                ];
            }

            // 3. Per investment breakdown
            $perInvestmentRows = (clone $baseQuery)
                ->join('investments', function ($join) {
                    $join->on('investments.id', '=', 'investment_payouts.investment_id')
                        ->whereNull('investments.deleted_at');
                })
                ->leftJoin('investment_products', 'investment_products.id', '=', 'investments.investment_product_id')
                ->groupBy('investment_payouts.investment_id', 'investments.policy_number', 'investment_products.name')
                ->selectRaw("
                    investment_payouts.investment_id,
                    investments.policy_number,
                    investment_products.name as product_name,
                    count(*) as total_count,
                    COALESCE(sum(investment_payouts.amount), 0) as total_amount,
                    COALESCE(sum(CASE WHEN investment_payouts.status = 'paid' THEN 1 ELSE 0 END), 0) as paid_count,
                    COALESCE(sum(CASE WHEN investment_payouts.status = 'paid' THEN investment_payouts.amount ELSE 0 END), 0) as paid_amount,
                    COALESCE(sum(CASE WHEN investment_payouts.status = 'unpaid' THEN 1 ELSE 0 END), 0) as unpaid_count,
                    COALESCE(sum(CASE WHEN investment_payouts.status = 'unpaid' THEN investment_payouts.amount ELSE 0 END), 0) as unpaid_amount,
                    COALESCE(sum(CASE WHEN investment_payouts.status = 'hold' THEN 1 ELSE 0 END), 0) as hold_count,
                    COALESCE(sum(CASE WHEN investment_payouts.status = 'hold' THEN investment_payouts.amount ELSE 0 END), 0) as hold_amount,
                    COALESCE(sum(CASE WHEN investment_payouts.status = 'cancelled' THEN 1 ELSE 0 END), 0) as cancelled_count,
                    COALESCE(sum(CASE WHEN investment_payouts.status = 'cancelled' THEN investment_payouts.amount ELSE 0 END), 0) as cancelled_amount
                ")
                ->get()
                ->map(function ($row) {
                    return [
                        'investment_id' => $row->investment_id,
                        'policy_number' => $row->policy_number,
                        'product_name' => $row->product_name,
                        'total' => ['count' => (int) $row->total_count, 'total_amount' => round($row->total_amount, 2)],
                        'paid' => ['count' => (int) $row->paid_count, 'total_amount' => round($row->paid_amount, 2)],
                        'unpaid' => ['count' => (int) $row->unpaid_count, 'total_amount' => round($row->unpaid_amount, 2)],
                        'hold' => ['count' => (int) $row->hold_count, 'total_amount' => round($row->hold_amount, 2)],
                        'cancelled' => ['count' => (int) $row->cancelled_count, 'total_amount' => round($row->cancelled_amount, 2)],
                    ];
                });

            $this->logActivity('View Payout Analysis', 'Investor Portal', "Payout analysis viewed: {$user->name}", [
                'user_id' => $user->id,
                'customer_id' => $customer ? $customer->id : null,
                'year' => $year,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Payout analysis fetched successfully',
                'data' => [
                    'year' => $year,
                    'summary' => $summary,
                    'month_wise' => $monthWise,
                    'per_investment' => $perInvestmentRows,
                ],
            ], 200);
        } catch (\Throwable $th) {
            $this->logActivity('Error', 'Investor Portal', 'Fetch payout analysis error: '.$th->getMessage(), [
                'exception' => $th->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch payout analysis',
                'error' => config('app.debug') ? $th->getMessage() : null,
            ], 500);
        }
    }
}
