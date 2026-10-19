<?php

namespace App\Http\Controllers\External;

use App\Http\Controllers\Controller;
use App\Http\Requests\VerifyInvestmentRequest;
use App\Http\Requests\CalculatePortalReturnRequest;
use App\Models\Investment;
use App\Models\InvestmentProduct;
use App\Traits\ActivityLogTrait;
use App\Traits\InvestmentCalculationTrait;
use App\Utilities\NumberToWords;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class PortalController extends Controller
{
    use ActivityLogTrait, InvestmentCalculationTrait;

    /**
     * Verify customer identity and investment policy number,
     * then return customer details, investment info, plan info,
     * and calculated monthly maturity return amounts.
     *
     * @param VerifyInvestmentRequest $request
     * @return JsonResponse
     */
    public function verify(VerifyInvestmentRequest $request): JsonResponse
    {
        try {
            $idNumber = trim($request->input('id_number'));
            $policyNumber = trim($request->input('policy_number'));

            // Find matching investment with customer, product rates, branch, and payouts
            $investment = Investment::with([
                'customer',
                'investmentProduct.annualRates',
                'branch:id,name,code',
                'beneficiary:id,full_name,relationship',
                'payouts' => function ($q) {
                    $q->select('id', 'investment_id', 'scheduled_date', 'amount', 'status', 'paid_at')
                      ->orderBy('scheduled_date', 'asc');
                },
            ])
            ->whereRaw('LOWER(TRIM(policy_number)) = ?', [strtolower($policyNumber)])
            ->whereHas('customer', function ($q) use ($idNumber) {
                $q->whereRaw('LOWER(TRIM(id_number)) = ?', [strtolower($idNumber)]);
            })
            ->first();

            if (!$investment) {
                $this->logActivity('Failed Verification', 'Portal', "Verification failed for Policy: {$policyNumber}, ID: {$idNumber}");

                return response()->json([
                    'status' => 'error',
                    'message' => 'No investment found matching the provided Policy Number and ID Number. Please check your details and try again.'
                ], 404);
            }

            // Calculate ROI and monthly maturity returns
            $roiCalculations = [];
            $monthlyMaturityAmount = 0;
            $durationMonths = 0;
            $maturityDate = null;

            if ($investment->investmentProduct) {
                $product = $investment->investmentProduct;
                $durationMonths = (int)$product->duration_months;
                $roiCalculations = $this->calculateInvestmentROI((float)$investment->investment_amount, $product);
                $monthlyMaturityAmount = $roiCalculations['monthly_return'] ?? 0;
            }

            if ($investment->reservation_date && $durationMonths > 0) {
                $maturityDate = $investment->reservation_date->copy()->addMonths($durationMonths)->format('Y-m-d');
            }

            // Fallback for monthly maturity amount if stored explicitly on investment
            if ($monthlyMaturityAmount == 0 && (float)$investment->monthly_payment_amount > 0) {
                $monthlyMaturityAmount = (float)$investment->monthly_payment_amount;
            }

            // Summarize scheduled payouts
            $payouts = $investment->payouts ?? collect();
            $totalPayouts = $payouts->count();
            $paidPayouts = $payouts->where('status', 'paid')->count();
            $unpaidPayouts = $payouts->where('status', 'unpaid')->count();
            $totalPaidAmount = (float)$payouts->where('status', 'paid')->sum('amount');
            $nextPayout = $payouts->first(function ($p) {
                return $p->status === 'unpaid';
            });

            $customer = $investment->customer;

            // Log successful customer verification
            $this->logActivity('Customer Verification', 'Portal', "Customer {$customer->full_name} verified Policy: {$investment->policy_number}", [
                'policy_number' => $investment->policy_number,
                'customer_id' => $customer->id,
                'investment_id' => $investment->id,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Investment verified successfully',
                'data' => [
                    'customer' => [
                        'customer_code' => $customer->customer_code,
                        'full_name' => $customer->full_name,
                        'name_with_initials' => $customer->name_with_initials,
                        'id_type' => $customer->id_type,
                        'id_number' => $customer->id_number,
                        'phone_primary' => $customer->phone_primary,
                        'phone_secondary' => $customer->phone_secondary,
                        'email' => $customer->email,
                        'address' => trim(($customer->address_line_1 ?? '') . ' ' . ($customer->address_line_2 ?? '') . ' ' . ($customer->city ?? '')),
                        'city' => $customer->city,
                        'state' => $customer->state,
                        'country' => $customer->country,
                    ],
                    'investment' => [
                        'policy_number' => $investment->policy_number,
                        'application_number' => $investment->application_number,
                        'status' => $investment->status,
                        'status_badge' => ucfirst($investment->status),
                        'reservation_date' => $investment->reservation_date ? $investment->reservation_date->format('Y-m-d') : null,
                        'maturity_date' => $maturityDate,
                        'investment_amount' => (float)$investment->investment_amount,
                        'investment_amount_in_words' => NumberToWords::convert($investment->investment_amount),
                        'branch' => $investment->branch ? [
                            'name' => $investment->branch->name,
                            'code' => $investment->branch->code,
                        ] : null,
                        'beneficiary' => $investment->beneficiary ? [
                            'full_name' => $investment->beneficiary->full_name,
                            'relationship' => $investment->beneficiary->relationship,
                        ] : null,
                    ],
                    'plan' => $investment->investmentProduct ? [
                        'name' => $investment->investmentProduct->name,
                        'duration' => $investment->investmentProduct->duration_months . ' Months',
                        'duration_months' => $investment->investmentProduct->duration_months,
                    ] : null,
                    'maturity_details' => [
                        'monthly_maturity_amount' => round((float)$monthlyMaturityAmount, 2),
                        'annual_return' => round((float)($roiCalculations['annual_return'] ?? 0), 2),
                        'total_interest' => round((float)($roiCalculations['total_interest'] ?? 0), 2),
                        'maturity_amount' => round((float)($roiCalculations['maturity_amount'] ?? $investment->investment_amount), 2),
                        'yearly_breakdown' => $roiCalculations['yearly_breakdown'] ?? [],
                        'payouts_summary' => [
                            'total_installments' => $totalPayouts,
                            'paid_installments' => $paidPayouts,
                            'remaining_installments' => $unpaidPayouts,
                            'total_paid_amount' => round($totalPaidAmount, 2),
                            'next_payout_date' => $nextPayout ? Carbon::parse($nextPayout->scheduled_date)->format('Y-m-d') : null,
                            'next_payout_amount' => $nextPayout ? (float)$nextPayout->amount : null,
                        ],
                    ],
                ]
            ], 200);

        } catch (\Throwable $th) {
            Log::error('Customer verification error: ' . $th->getMessage(), [
                'exception' => $th,
                'request' => $request->only(['id_number', 'policy_number'])
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred during verification. Please try again later.',
                'error' => config('app.debug') ? $th->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get available investment products.
     *
     * @return JsonResponse
     */
    public function getAvailableInvestmentProducts(): JsonResponse
    {
        try {
            $products = InvestmentProduct::active()
                ->visibleTo()
                ->select('id', 'name', 'code', 'duration_months')
                ->orderBy('id', 'asc')
                ->get();

            return response()->json([
                'status' => 'success',
                'message' => 'Investment products retrieved successfully',
                'data' => $products,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve investment products',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * Calculate estimated investment return and maturity amount for portal display.
     *
     * @param CalculatePortalReturnRequest $request
     * @return JsonResponse
     */
    public function calculateReturn(CalculatePortalReturnRequest $request): JsonResponse
    {
        try {
            $amount = (float) $request->input('investment_amount');
            $productId = (int) $request->input('investment_product_id');

            $product = InvestmentProduct::active()
                ->with('annualRates')
                ->find($productId);

            if (!$product) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Investment product not found or is currently inactive.',
                ], 404);
            }

            $calculations = $this->calculateInvestmentROI($amount, $product);

            $duration = (int) $product->duration_months;
            $horizonLabel = "{$duration} Mo. Horizon";
            $netReturn = round((float) ($calculations['total_interest'] ?? 0), 2);
            $totalAtMaturity = round((float) ($calculations['maturity_amount'] ?? ($amount + $netReturn)), 2);

            $initialCapitalFormatted = 'LKR ' . number_format($amount, ($amount == floor($amount)) ? 0 : 2);
            $netReturnFormatted = 'LKR ' . number_format($netReturn, ($netReturn == floor($netReturn)) ? 0 : 2);
            $totalAtMaturityFormatted = 'LKR ' . number_format($totalAtMaturity, ($totalAtMaturity == floor($totalAtMaturity)) ? 0 : 2);

            $description = "Net estimated return ({$horizonLabel}) over initial capital of {$initialCapitalFormatted}";

            return response()->json([
                'status'  => 'success',
                'message' => 'Investment return estimated successfully',
                'data'    => [
                    'product_id'                     => $product->id,
                    'product_name'                   => $product->name,
                    'duration_months'                => $duration,
                    'horizon_label'                  => $horizonLabel,
                    'initial_capital'                => $amount,
                    'initial_capital_formatted'      => $initialCapitalFormatted,
                    'estimated_net_return'           => $netReturn,
                    'estimated_net_return_formatted' => $netReturnFormatted,
                    'total_at_maturity'              => $totalAtMaturity,
                    'total_at_maturity_formatted'    => $totalAtMaturityFormatted,
                    'description'                    => $description,
                ],
            ], 200);

        } catch (\Throwable $th) {
            Log::error('Portal return calculation error: ' . $th->getMessage(), [
                'exception' => $th,
                'request'   => $request->only(['investment_product_id', 'investment_amount']),
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to calculate investment return',
                'error'   => config('app.debug') ? $th->getMessage() : null,
            ], 500);
        }
    }
}
