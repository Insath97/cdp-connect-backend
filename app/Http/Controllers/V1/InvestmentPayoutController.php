<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\InvestmentPayout;
use App\Models\Investment;
use App\Traits\InvestmentCalculationTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class InvestmentPayoutController extends Controller implements HasMiddleware
{
    use InvestmentCalculationTrait;

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Investment Payout Index', only: ['index']),
            new Middleware('permission:Investment Payout Update', only: ['updateStatus']),
            new Middleware('permission:Investment Payout Update', only: ['generateLegacyPayouts']),
        ];
    }

    /**
     * Display a listing of investment payouts with filters.
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $user = Auth::guard('api')->user();

            $query = InvestmentPayout::with([
                'investment' => function($q) {
                    $q->select('id', 'policy_number', 'customer_id', 'branch_id', 'investment_product_id', 'target_period_key', 'created_by', 'customer_bank_detail_id');
                },
                'investment.customer' => function($q) {
                    $q->select('id', 'full_name', 'customer_code', 'id_number')->with(['bankDetails' => function($bq) {
                        $bq->select('id', 'customer_id', 'bank_name', 'branch_name', 'account_number');
                    }]);
                },
                'investment.branch' => function($q) {
                    $q->select('id', 'name', 'code');
                },
                'investment.investmentProduct' => function($q) {
                    $q->select('id', 'name', 'code', 'duration_months', 'roi_percentage');
                },
                'investment.bankDetail' => function($q) {
                    $q->select('id', 'customer_id', 'bank_name', 'branch_name', 'account_number');
                }
            ]);

            // 1. Hierarchy Visibility Logic
            if ($user->hasRole('Branch Coordinator')) {
                $assignedBranchIds = $user->assignedBranches()->pluck('branches.id')->toArray();
                $query->whereHas('investment', function ($q) use ($assignedBranchIds) {
                    $q->whereIn('branch_id', $assignedBranchIds);
                });
            } elseif (!$user->hasRole('Super Admin') && ($user->user_type !== 'admin')) {
                $descendantIds = $user->getAllDescendantIds();
                $accessibleUserIds = array_merge([$user->id], $descendantIds);
                $query->whereHas('investment', function ($q) use ($accessibleUserIds) {
                    $q->whereIn('created_by', $accessibleUserIds);
                });
            }

            // 2. Filters
            if ($request->has('from_date') && $request->has('to_date')) {
                $query->whereBetween('scheduled_date', [$request->from_date, $request->to_date]);
            }

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('branch_id')) {
                $query->whereHas('investment', function ($q) use ($request) {
                    $q->where('branch_id', $request->branch_id);
                });
            }

            if ($request->has('period_key')) {
                $query->whereHas('investment', function ($q) use ($request) {
                    $q->where('target_period_key', $request->period_key);
                });
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->whereHas('investment', function ($q) use ($search) {
                    $q->where('policy_number', 'like', "%{$search}%")
                      ->orWhereHas('customer', function ($cq) use ($search) {
                          $cq->where('full_name', 'like', "%{$search}%");
                      });
                });
            }

            // 3. Execution & Pagination
            $payouts = $query->orderBy('scheduled_date', 'asc')->paginate($perPage);

            // 4. Transform for Fallback Bank Details
            $payouts->getCollection()->transform(function ($payout) {
                $investment = $payout->investment;
                if ($investment) {
                    // If specific bank detail is missing, fallback to first customer bank detail
                    if (!$investment->bankDetail && $investment->customer && $investment->customer->bankDetails->isNotEmpty()) {
                        $investment->setRelation('bankDetail', $investment->customer->bankDetails->first());
                    }
                    // Clean up: hide the redundant bankDetails collection from customer
                    if ($investment->customer) {
                        $investment->customer->unsetRelation('bankDetails');
                    }
                }
                return $payout;
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Investment payouts retrieved successfully',
                'data' => $payouts
            ], 200);

        } catch (\Throwable $th) {
            Log::error('Failed to retrieve investment payouts', [
                'error' => $th->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve investment payouts',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Update the status of a payout (Payment Verification).
     */
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:paid,unpaid,hold',
            'reference_number' => 'nullable|string',
            'remarks' => 'nullable|string',
            'paid_at' => 'nullable|date',
        ]);

        try {
            $payout = InvestmentPayout::findOrFail($id);
            
            $updateData = [
                'status' => $request->status,
                'reference_number' => $request->reference_number,
                'remarks' => $request->remarks,
            ];

            if ($request->status === 'paid') {
                $updateData['paid_at'] = $request->paid_at ?? now();
            } else {
                $updateData['paid_at'] = null;
            }

            $payout->update($updateData);

            return response()->json([
                'status' => 'success',
                'message' => 'Payout status updated successfully',
                'data' => $payout
            ], 200);

        } catch (\Throwable $th) {
            Log::error('Failed to update payout status', [
                'payout_id' => $id,
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update payout status',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Generate payout schedules for legacy approved investments via frontend button.
     */
    public function generateLegacyPayouts(Request $request)
    {
        try {
            $investments = Investment::where('status', 'approved')
                ->whereDoesntHave('payouts')
                ->with('investmentProduct.annualRates')
                ->get();

            if ($investments->isEmpty()) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'No legacy approved investments without payouts found.'
                ], 200);
            }

            $count = 0;
            foreach ($investments as $investment) {
                $this->generatePayoutSchedule($investment);
                $count++;
            }

            return response()->json([
                'status' => 'success',
                'message' => "Successfully generated payout schedules for {$count} legacy investments."
            ], 200);

        } catch (\Throwable $th) {
            Log::error('Legacy payout generation via controller failed', [
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to generate legacy payout schedules',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Helper to generate payout schedule (consistent with InvestmentController).
     */
    protected function generatePayoutSchedule(Investment $investment)
    {
        $product = $investment->investmentProduct;
        if (!$product) return;

        $calculations = $this->calculateInvestmentROI((float)$investment->investment_amount, $product);
        
        $startDate = $investment->reservation_date ?? $investment->created_at;
        $payoutDate = Carbon::parse($startDate);

        $payouts = [];
        foreach ($calculations['yearly_breakdown'] ?? [] as $yearData) {
            $monthlyPayout = $yearData['monthly_payout'];
            $monthsInYear = $yearData['duration_months'];

            for ($i = 0; $i < $monthsInYear; $i++) {
                $payoutDate->addMonth();
                $payouts[] = [
                    'investment_id' => $investment->id,
                    'scheduled_date' => $payoutDate->format('Y-m-d'),
                    'amount' => round($monthlyPayout, 2),
                    'status' => 'unpaid',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        if (!empty($payouts)) {
            InvestmentPayout::insert($payouts);
        }
    }
}
