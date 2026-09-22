<?php

namespace App\Http\Controllers\External;

use App\Traits\ActivityLogTrait;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Target;
use App\Models\Investment;
use App\Models\Commission;
use App\Models\Customer;
use App\Traits\InvestmentCalculationTrait;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ExternalApiController extends Controller
{
    use ActivityLogTrait, InvestmentCalculationTrait;

    /**
     * Get employee metrics summary for a specific period/date range.
     */
    public function employeesSummary(Request $request): JsonResponse
    {
        try {
            $fromDate = $request->get('from_date');
            $toDate = $request->get('to_date');
            $periodKeyInput = $request->get('period_key');
            $perPage = $request->get('per_page', 50);

            // Determine Date Range & Period Key
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

            $query = User::with(['level', 'branch'])
                ->where('user_type', 'hierarchy')
                ->where('is_active', true);

            if ($request->has('search')) {
                $search = $request->get('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('employee_code', 'like', "%{$search}%")
                      ->orWhere('username', 'like', "%{$search}%");
                });
            }

            // Paginate or get all
            if ($perPage == -1) {
                $users = $query->get();
            } else {
                $users = $query->paginate($perPage);
            }

            $transformCallback = function ($user) use ($from, $to, $periodKey) {
                $descendantIds = $user->getAllDescendantIds();
                $allBranchIds = array_merge([$user->id], $descendantIds);

                // Branch approved investments for achievement amount
                $branchInvestments = Investment::whereIn('unit_head_id', $allBranchIds)
                    ->whereBetween('reservation_date', [$from, $to])
                    ->where('status', 'approved')
                    ->get();
                $branchBusinessTotal = (float)$branchInvestments->sum('investment_amount');

                // Target
                $target = Target::where('user_id', $user->id)
                    ->where('period_key', $periodKey)
                    ->first();
                $targetAmount = $target ? (float)$target->target_amount : 0.0;
                
                // Achievement percentage
                $achievementPercentage = $targetAmount > 0 
                    ? ($branchBusinessTotal / $targetAmount) * 100 
                    : ($branchBusinessTotal > 0 ? 100.0 : 0.0);
                $achievementPercentage = (float)min($achievementPercentage, 999999.99);

                // Commissions (approved)
                $userCommissions = Commission::where('user_id', $user->id)
                    ->whereHas('investment', function ($q) use ($from, $to) {
                        $q->whereBetween('reservation_date', [$from, $to])
                          ->where('status', 'approved');
                    })
                    ->get();

                $personalUnitHeadCommission = (float)$userCommissions->where('tier', 'unit_head')->sum('commission_amount');
                $personalOverrideCommission = (float)$userCommissions->where('tier', 'parent')->sum('commission_amount');
                $personalCommission = (float)$userCommissions->sum('commission_amount');

                // Recoveries (cancelled)
                $userRecoveries = Commission::where('user_id', $user->id)
                    ->whereHas('investment', function ($q) use ($from, $to) {
                        $q->whereBetween('reservation_date', [$from, $to])
                          ->where('status', 'cancelled');
                    })
                    ->get();
                $personalRecovery = (float)$userRecoveries->sum('recover_amount');

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'employee_code' => $user->employee_code,
                    'id_type' => $user->id_type,
                    'id_number' => $user->id_number,
                    'level' => $user->level->level_name ?? 'N/A',
                    'branch' => $user->branch->name ?? 'N/A',
                    'metrics' => [
                        'target_amount' => $targetAmount,
                        'achievement_amount' => $branchBusinessTotal,
                        'achievement_percentage' => $achievementPercentage,
                        'commission' => $personalUnitHeadCommission,
                        'override_commission' => $personalOverrideCommission,
                        'total_commission' => $personalCommission,
                        'recover_amount' => $personalRecovery
                    ]
                ];
            };

            if ($perPage == -1) {
                $data = $users->map($transformCallback);
            } else {
                $users->getCollection()->transform($transformCallback);
                $data = $users;
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Employees summary retrieved successfully',
                'data' => $data
            ], 200);

        } catch (\Throwable $th) {
            $this->logActivity('Error', 'ExternalApi', 'External employees summary failed', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve employees summary',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Verify customer by ID and retrieve basic details, investments, products,
     * reservation dates, expiry dates, and total amount invested.
     */
    public function customerInvestments(Request $request): JsonResponse
    {
        try {
            $idNumber = trim((string) ($request->get('id_number') ?? $request->input('id_number')));
            $idType = $request->get('id_type') ?? $request->input('id_type');
            $status = $request->get('status') ?? $request->input('status');

            if (empty($idNumber)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'The id_number field is required.'
                ], 422);
            }

            $cleanIdNumber = str_replace(' ', '', strtolower($idNumber));

            // Query Customer matching id_number and optional id_type
            $customerQuery = Customer::query();

            if (!empty($idType)) {
                $customerQuery->where('id_type', strtolower(trim($idType)));
            }

            $customer = $customerQuery->where(function ($q) use ($idNumber, $cleanIdNumber) {
                $q->whereRaw('LOWER(TRIM(id_number)) = ?', [strtolower($idNumber)])
                  ->orWhereRaw("REPLACE(LOWER(id_number), ' ', '') = ?", [$cleanIdNumber]);
            })->first();

            if (!$customer) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Customer not found matching the provided identification details.'
                ], 404);
            }

            // Load investments with products, annual rates, branch, beneficiary
            $investmentsQuery = Investment::with([
                'investmentProduct.annualRates',
                'branch:id,name,code',
                'beneficiary:id,full_name,relationship',
            ])->where('customer_id', $customer->id);

            if (!empty($status) && $status !== 'all') {
                $investmentsQuery->where('status', $status);
            }

            $investments = $investmentsQuery->orderBy('reservation_date', 'desc')->get();

            // Transform investments and compute calculations
            $transformedInvestments = [];
            $totalInvestedAll = 0.0;
            $totalInvestedApproved = 0.0;
            $approvedCount = 0;
            $expiredCount = 0;

            foreach ($investments as $inv) {
                $invAmount = (float) $inv->investment_amount;
                $totalInvestedAll += $invAmount;

                if (in_array($inv->status, ['approved', 'expired'])) {
                    $totalInvestedApproved += $invAmount;
                }
                if ($inv->status === 'approved') {
                    $approvedCount++;
                } elseif ($inv->status === 'expired') {
                    $expiredCount++;
                }

                $product = $inv->investmentProduct;
                $durationMonths = $product ? (int) $product->duration_months : 0;

                // Reservation Date
                $reservationDate = $inv->reservation_date ? Carbon::parse($inv->reservation_date) : null;
                $reservationDateFormatted = $reservationDate ? $reservationDate->format('Y-m-d') : null;

                // Expiry / Maturity Date
                $expiryDateFormatted = null;
                $isExpired = ($inv->status === 'expired');

                if ($reservationDate && $durationMonths > 0) {
                    $calculatedExpiry = $reservationDate->copy()->addMonths($durationMonths);
                    $expiryDateFormatted = $calculatedExpiry->format('Y-m-d');
                    if (!$isExpired && Carbon::now()->startOfDay()->gt($calculatedExpiry)) {
                        $isExpired = true;
                    }
                }

                // ROI Calculations
                $roiCalculations = [];
                $monthlyReturn = 0.0;
                $maturityAmount = $invAmount;
                if ($product) {
                    $roiCalculations = $this->calculateInvestmentROI($invAmount, $product);
                    $monthlyReturn = (float) ($roiCalculations['monthly_return'] ?? 0);
                    $maturityAmount = (float) ($roiCalculations['maturity_amount'] ?? $invAmount);
                }

                if ($monthlyReturn == 0 && (float) $inv->monthly_payment_amount > 0) {
                    $monthlyReturn = (float) $inv->monthly_payment_amount;
                }

                $transformedInvestments[] = [
                    'id' => $inv->id,
                    'policy_number' => $inv->policy_number,
                    'application_number' => $inv->application_number,
                    'sales_code' => $inv->sales_code,
                    'status' => $inv->status,
                    'status_badge' => ucfirst($inv->status),
                    'investment_amount' => $invAmount,
                    'reservation_date' => $reservationDateFormatted,
                    'expiry_date' => $expiryDateFormatted,
                    'maturity_date' => $expiryDateFormatted,
                    'is_expired' => $isExpired,
                    'initial_payment' => (float) $inv->initial_payment,
                    'payment_type' => $inv->payment_type,
                    'product' => $product ? [
                        'id' => $product->id,
                        'name' => $product->name,
                        'code' => $product->code,
                        'duration_months' => $product->duration_months,
                        'plan_type' => $product->plan_type,
                        'roi_percentage' => (float) $product->roi_percentage,
                        'is_variable_roi' => (bool) $product->is_variable_roi,
                    ] : null,
                    'branch' => $inv->branch ? [
                        'id' => $inv->branch->id,
                        'name' => $inv->branch->name,
                        'code' => $inv->branch->code,
                    ] : null,
                    'beneficiary' => $inv->beneficiary ? [
                        'id' => $inv->beneficiary->id,
                        'full_name' => $inv->beneficiary->full_name,
                        'relationship' => $inv->beneficiary->relationship,
                    ] : null,
                    'maturity_details' => [
                        'monthly_return' => round($monthlyReturn, 2),
                        'annual_return' => round((float)($roiCalculations['annual_return'] ?? 0), 2),
                        'total_interest' => round((float)($roiCalculations['total_interest'] ?? 0), 2),
                        'maturity_amount' => round($maturityAmount, 2),
                    ],
                    'created_at' => $inv->created_at?->toDateTimeString(),
                    'approved_at' => $inv->approved_at ? Carbon::parse($inv->approved_at)->toDateTimeString() : null,
                ];
            }

            $this->logActivity('External Customer Search', 'External API', "Retrieved details for Customer ID {$customer->id} ({$customer->id_number})", [
                'customer_id' => $customer->id,
                'id_type' => $customer->id_type,
                'id_number' => $customer->id_number,
                'investments_count' => count($transformedInvestments)
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Customer investment details retrieved successfully',
                'data' => [
                    'customer' => [
                        'id' => $customer->id,
                        'customer_code' => $customer->customer_code,
                        'full_name' => $customer->full_name,
                        'name_with_initials' => $customer->name_with_initials,
                        'id_type' => $customer->id_type,
                        'id_number' => $customer->id_number,
                        'date_of_birth' => $customer->date_of_birth ? Carbon::parse($customer->date_of_birth)->format('Y-m-d') : null,
                        'email' => $customer->email,
                        'phone_primary' => $customer->phone_primary,
                        'phone_secondary' => $customer->phone_secondary,
                        'address' => trim(($customer->address_line_1 ?? '') . ' ' . ($customer->address_line_2 ?? '')),
                        'city' => $customer->city,
                        'state' => $customer->state,
                        'country' => $customer->country,
                        'postal_code' => $customer->postal_code,
                        'preferred_language' => $customer->preferred_language,
                        'is_active' => (bool) $customer->is_active,
                        'created_at' => $customer->created_at?->toDateTimeString(),
                    ],
                    'summary' => [
                        'total_invested_amount' => round($totalInvestedAll, 2),
                        'active_invested_amount' => round($totalInvestedApproved, 2),
                        'total_investments_count' => count($transformedInvestments),
                        'approved_investments_count' => $approvedCount,
                        'expired_investments_count' => $expiredCount,
                    ],
                    'investments' => $transformedInvestments
                ]
            ], 200);

        } catch (\Throwable $th) {
            $this->logActivity('Error', 'ExternalApi', 'External customer investments search failed', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString(),
                'request' => $request->all()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve customer investment details',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}
