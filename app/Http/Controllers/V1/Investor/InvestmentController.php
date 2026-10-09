<?php

namespace App\Http\Controllers\V1\Investor;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Investment;
use App\Models\InvestmentPayout;
use App\Models\InvestmentProduct;
use App\Traits\ActivityLogTrait;
use App\Traits\InvestmentCalculationTrait;
use App\Traits\ResolvesInvestorCustomer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InvestmentController extends Controller
{
    use ActivityLogTrait, InvestmentCalculationTrait, ResolvesInvestorCustomer;

    /**
     * Format an investment record with the required fields and optional branch / agent.
     *
     * @param Investment|mixed $inv
     * @param Request $request
     * @return array
     */
    protected function formatInvestment($inv, Request $request): array
    {
        $durationMonths = (int) ($inv->investmentProduct->duration_months ?? 0);
        $amount = (float) $inv->investment_amount;

        // Calculate expired / maturity date
        $expiredDate = null;
        if ($inv->reservation_date && $durationMonths > 0) {
            $expiredDate = $inv->reservation_date->copy()->addMonths($durationMonths)->format('Y-m-d');
        }

        // Calculate total maturity amount
        $totalMaturityAmount = $amount;
        if ($inv->investmentProduct) {
            $calc = $this->calculateInvestmentROI($amount, $inv->investmentProduct);
            $totalMaturityAmount = (float) ($calc['maturity_amount'] ?? $amount);
        }

        $data = [
            'id'                    => $inv->id,
            'policy_number'         => $inv->policy_number,
            'application_number'    => $inv->application_number,
            'status'                => $inv->status,
            'plan_name'             => $inv->investmentProduct->name ?? 'N/A',
            'reservation_date'      => $inv->reservation_date?->format('Y-m-d'),
            'amount'                => $amount,
            'expired_date'          => $expiredDate,
            'total_maturity_amount' => $totalMaturityAmount,
        ];

        // Conditional Branch
        if ($request->boolean('branch') || $request->boolean('include_branch')) {
            $data['branch'] = $inv->branch ? [
                'id'   => $inv->branch->id,
                'name' => $inv->branch->name,
                'code' => $inv->branch->code,
            ] : null;
        }

        // Conditional Agent
        if ($request->boolean('agent') || $request->boolean('include_agent')) {
            $data['agent'] = $inv->creator ? [
                'id'            => $inv->creator->id,
                'name'          => $inv->creator->name,
                'employee_code' => $inv->creator->employee_code,
            ] : null;
        }

        return $data;
    }

    /**
     * List all investments for the logged-in customer.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = auth('api')->user();

            if (!$user) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            $request->validate([
                'status'          => 'nullable|string|in:all,approved,pending,active,expired,cancelled,rejected,terminated',
                'search'          => 'nullable|string|max:100',
                'from_date'       => 'nullable|date',
                'to_date'         => 'nullable|date',
                'per_page'        => 'nullable|integer|min:1|max:100',
                'all'             => 'nullable',
                'branch'          => 'nullable',
                'include_branch'  => 'nullable',
                'agent'           => 'nullable',
                'include_agent'   => 'nullable',
                'order_by'        => 'nullable|string|in:reservation_date,investment_amount,policy_number,id',
                'order_direction' => 'nullable|string|in:asc,desc',
            ]);

            $customer = $this->resolveCustomer();

            if (!$customer) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Customer profile not found.',
                ], 404);
            }

            $query = Investment::where('customer_id', $customer->id)
                ->with(['investmentProduct']);

            // Eager load branch if requested
            if ($request->boolean('branch') || $request->boolean('include_branch')) {
                $query->with('branch');
            }

            // Eager load agent if requested
            if ($request->boolean('agent') || $request->boolean('include_agent')) {
                $query->with('creator');
            }

            // Filter by status if provided
            if ($request->filled('status') && $request->status !== 'all') {
                $query->where('status', $request->status);
            }

            // Search by policy number, application number, sales code or plan name
            if ($request->filled('search')) {
                $search = trim($request->search);
                $query->where(function ($q) use ($search) {
                    $q->where('policy_number', 'like', "%{$search}%")
                        ->orWhere('application_number', 'like', "%{$search}%")
                        ->orWhere('sales_code', 'like', "%{$search}%")
                        ->orWhereHas('investmentProduct', function ($pq) use ($search) {
                            $pq->where('name', 'like', "%{$search}%");
                        });
                });
            }

            // Date filtering
            if ($request->filled('from_date')) {
                $query->whereDate('reservation_date', '>=', $request->from_date);
            }
            if ($request->filled('to_date')) {
                $query->whereDate('reservation_date', '<=', $request->to_date);
            }

            $orderBy = $request->get('order_by', 'reservation_date');
            $orderDir = $request->get('order_direction', 'desc');
            $query->orderBy($orderBy, $orderDir);

            if ($request->boolean('all')) {
                $investments = $query->get()->map(fn($inv) => $this->formatInvestment($inv, $request));
            } else {
                $perPage = (int) $request->get('per_page', 15);
                $paginated = $query->paginate($perPage);
                $investments = $paginated->getCollection()->map(fn($inv) => $this->formatInvestment($inv, $request));
                $paginated->setCollection($investments);
                $investments = $paginated;
            }

            $this->logActivity('View Investments', 'Investor Portal', "Investments listed for: {$user->name}", [
                'user_id'     => $user->id,
                'customer_id' => $customer->id,
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'Investments fetched successfully',
                'data'    => $investments,
            ], 200);

        } catch (\Throwable $th) {
            Log::error('Investor investments list error: ' . $th->getMessage(), ['exception' => $th]);
            $this->logActivity('Error', 'Investor Portal', 'Fetch investments error: ' . $th->getMessage(), [
                'exception' => $th->getMessage(),
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to fetch investments',
                'error'   => config('app.debug') ? $th->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get single investment details by ID for the logged-in customer.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $user = auth('api')->user();

            if (!$user) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            $customer = $this->resolveCustomer();

            if (!$customer) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Customer profile not found.',
                ], 404);
            }

            $query = Investment::where('customer_id', $customer->id)
                ->with(['investmentProduct', 'beneficiary', 'bankDetail']);

            if ($request->boolean('branch') || $request->boolean('include_branch')) {
                $query->with('branch');
            }

            if ($request->boolean('agent') || $request->boolean('include_agent')) {
                $query->with('creator');
            }

            if ($request->boolean('include_payouts')) {
                $query->with(['payouts' => fn($q) => $q->orderBy('scheduled_date', 'asc')]);
            }

            $inv = $query->find($id);

            if (!$inv) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Investment not found.',
                ], 404);
            }

            $data = $this->formatInvestment($inv, $request);

            // Append additional detail fields for show view
            $data['sales_code']   = $inv->sales_code;
            $data['product']      = $inv->investmentProduct;
            $data['beneficiary']  = $inv->beneficiary;
            $data['bank_detail']  = $inv->bankDetail;

            if ($inv->relationLoaded('payouts')) {
                $data['payouts'] = $inv->payouts;
            }

            $this->logActivity('View Investment Detail', 'Investor Portal', "Investment {$inv->policy_number} viewed by: {$user->name}", [
                'user_id'       => $user->id,
                'customer_id'   => $customer->id,
                'investment_id' => $inv->id,
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'Investment details fetched successfully',
                'data'    => $data,
            ], 200);

        } catch (\Throwable $th) {
            Log::error('Investor investment detail error: ' . $th->getMessage(), ['exception' => $th]);
            $this->logActivity('Error', 'Investor Portal', 'Fetch investment detail error: ' . $th->getMessage(), [
                'exception' => $th->getMessage(),
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to fetch investment details',
                'error'   => config('app.debug') ? $th->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get portfolio summary metrics for the logged-in customer.
     */
    public function summary(Request $request): JsonResponse
    {
        try {
            $user = auth('api')->user();

            if (!$user) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            $customer = $this->resolveCustomer();

            if (!$customer) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Customer profile not found.',
                ], 404);
            }

            $investments = Investment::where('customer_id', $customer->id)
                ->with(['investmentProduct'])
                ->get();

            $investmentIds = $investments->pluck('id')->toArray();

            $totalInvested = (float) $investments->sum('investment_amount');
            $activeInvestments = $investments->whereIn('status', ['approved', 'active']);
            $activeInvested = (float) $activeInvestments->sum('investment_amount');

            // Status counts
            $statusCounts = [
                'total'     => $investments->count(),
                'active'    => $activeInvestments->count(),
                'pending'   => $investments->where('status', 'pending')->count(),
                'expired'   => $investments->where('status', 'expired')->count(),
                'cancelled' => $investments->where('status', 'cancelled')->count(),
            ];

            // Payouts metrics for customer investments
            $payouts = InvestmentPayout::whereIn('investment_id', $investmentIds)->get();
            $totalReturnsPaid = (float) $payouts->where('status', 'paid')->sum('amount');
            $totalReturnsUpcoming = (float) $payouts->where('status', 'unpaid')->sum('amount');

            // Next upcoming payout
            $nextPayout = InvestmentPayout::whereIn('investment_id', $investmentIds)
                ->where('status', 'unpaid')
                ->whereDate('scheduled_date', '>=', now()->toDateString())
                ->orderBy('scheduled_date', 'asc')
                ->with(['investment:id,policy_number'])
                ->first();

            $nextPayoutData = null;
            if ($nextPayout) {
                $nextPayoutData = [
                    'id'             => $nextPayout->id,
                    'investment_id'  => $nextPayout->investment_id,
                    'policy_number'  => $nextPayout->investment->policy_number ?? null,
                    'scheduled_date' => $nextPayout->scheduled_date?->format('Y-m-d'),
                    'amount'         => (float) $nextPayout->amount,
                ];
            }

            // Calculate total projected maturity return
            $totalMaturityProjected = 0.0;
            foreach ($investments as $inv) {
                $amount = (float) $inv->investment_amount;
                if ($inv->investmentProduct) {
                    $calc = $this->calculateInvestmentROI($amount, $inv->investmentProduct);
                    $totalMaturityProjected += (float) ($calc['maturity_amount'] ?? $amount);
                } else {
                    $totalMaturityProjected += $amount;
                }
            }

            $summary = [
                'total_invested_amount'     => round($totalInvested, 2),
                'active_invested_amount'    => round($activeInvested, 2),
                'total_projected_maturity'  => round($totalMaturityProjected, 2),
                'total_returns_paid'        => round($totalReturnsPaid, 2),
                'total_returns_upcoming'    => round($totalReturnsUpcoming, 2),
                'counts'                    => $statusCounts,
                'next_upcoming_payout'      => $nextPayoutData,
            ];

            $this->logActivity('View Investment Summary', 'Investor Portal', "Investment summary viewed: {$user->name}", [
                'user_id'     => $user->id,
                'customer_id' => $customer->id,
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'Investment summary fetched successfully',
                'data'    => $summary,
            ], 200);

        } catch (\Throwable $th) {
            Log::error('Investor investment summary error: ' . $th->getMessage(), ['exception' => $th]);
            $this->logActivity('Error', 'Investor Portal', 'Fetch investment summary error: ' . $th->getMessage(), [
                'exception' => $th->getMessage(),
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to fetch investment summary',
                'error'   => config('app.debug') ? $th->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get payouts schedule specifically for an investment.
     */
    public function payouts(Request $request, string $id): JsonResponse
    {
        try {
            $user = auth('api')->user();

            if (!$user) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            $request->validate([
                'status'   => 'nullable|string|in:all,paid,unpaid,hold,cancelled',
                'per_page' => 'nullable|integer|min:1|max:100',
            ]);

            $customer = $this->resolveCustomer();

            if (!$customer) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Customer profile not found.',
                ], 404);
            }

            // Ensure this investment belongs to the customer
            $inv = Investment::where('customer_id', $customer->id)
                ->where('id', $id)
                ->first();

            if (!$inv) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Investment not found.',
                ], 404);
            }

            $query = InvestmentPayout::where('investment_id', $inv->id)
                ->orderBy('scheduled_date', 'asc');

            if ($request->filled('status') && $request->status !== 'all') {
                $query->where('status', $request->status);
            }

            $payouts = $query->get();

            $payoutSummary = [
                'total_count'   => $payouts->count(),
                'total_amount'  => round($payouts->sum('amount'), 2),
                'paid_count'    => $payouts->where('status', 'paid')->count(),
                'paid_amount'   => round($payouts->where('status', 'paid')->sum('amount'), 2),
                'unpaid_count'  => $payouts->where('status', 'unpaid')->count(),
                'unpaid_amount' => round($payouts->where('status', 'unpaid')->sum('amount'), 2),
            ];

            return response()->json([
                'status'  => 'success',
                'message' => 'Investment payouts fetched successfully',
                'data'    => [
                    'investment_id' => $inv->id,
                    'policy_number' => $inv->policy_number,
                    'summary'       => $payoutSummary,
                    'payouts'       => $payouts,
                ],
            ], 200);

        } catch (\Throwable $th) {
            Log::error('Investor investment payouts error: ' . $th->getMessage(), ['exception' => $th]);
            $this->logActivity('Error', 'Investor Portal', 'Fetch investment payouts error: ' . $th->getMessage(), [
                'exception' => $th->getMessage(),
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to fetch investment payouts',
                'error'   => config('app.debug') ? $th->getMessage() : null,
            ], 500);
        }
    }

    /**
     * List active investment products / plans for the investor mobile app.
     */
    public function products(): JsonResponse
    {
        try {
            $products = InvestmentProduct::where('is_active', true)
                ->with(['annualRates'])
                ->orderBy('duration_months', 'asc')
                ->get()
                ->map(function ($product) {
                    return [
                        'id'              => $product->id,
                        'name'            => $product->name,
                        'code'            => $product->code,
                        'duration_months' => $product->duration_months,
                        'roi_percentage'  => (float) $product->roi_percentage,
                        'is_variable_roi' => (bool) $product->is_variable_roi,
                        'plan_type'       => $product->plan_type,
                        'annual_rates'    => $product->annualRates,
                    ];
                });

            return response()->json([
                'status'  => 'success',
                'message' => 'Investment products fetched successfully',
                'data'    => $products,
            ], 200);

        } catch (\Throwable $th) {
            Log::error('Investor investment products error: ' . $th->getMessage(), ['exception' => $th]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to fetch investment products',
                'error'   => config('app.debug') ? $th->getMessage() : null,
            ], 500);
        }
    }
}
