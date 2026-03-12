<?php

namespace App\Traits;

use App\Models\InvestmentProduct;

trait InvestmentCalculationTrait
{
    /**
     * Calculate investment ROI breakdowns and totals.
     *
     * @param float $amount
     * @param InvestmentProduct $product
     * @return array
     */
    public function calculateInvestmentROI(float $amount, InvestmentProduct $product): array
    {
        $duration = $product->duration_months;
        $monthlyReturn = 0;
        $annualReturn = 0;
        $maturityAmount = 0;
        $totalInterest = 0;

        $breakdowns = [
            'year_1_breakdown' => 0,
            'year_2_breakdown' => 0,
            'year_3_breakdown' => 0,
            'year_4_breakdown' => 0,
            'year_5_breakdown' => 0,
            'month_6_breakdown' => 0,
        ];

        $yearlyBreakdown = [];

        if ($product->is_variable_roi) {
            $rates = $product->annualRates->pluck('roi_percentage', 'year');

            for ($year = 1; $year <= 5; $year++) {
                if ($duration >= ($year * 12)) {
                    $rate = $rates->get($year) ?? $product->roi_percentage;
                    $yearlyReturn = $amount * ($rate / 100);
                    $totalInterest += $yearlyReturn;
                    $breakdowns["year_{$year}_breakdown"] = $yearlyReturn;

                    $yearlyBreakdown[] = [
                        'year' => $year,
                        'roi_percentage' => (float)$rate,
                        'monthly_payout' => round($yearlyReturn / 12, 2),
                        'yearly_total' => round($yearlyReturn, 2),
                        'duration_months' => 12
                    ];

                    if ($year === 1) {
                        $annualReturn = $yearlyReturn;
                        $monthlyReturn = $yearlyReturn / 12;
                        $breakdowns['month_6_breakdown'] = $monthlyReturn * 6;
                    }
                }
            }

            // Handle 6 months edge case if duration is exactly 6 and variable ROI is somehow enabled
            if ($duration == 6) {
                $monthlyReturn = ($amount * ($product->roi_percentage / 100)) / 12;
                $totalInterest = $monthlyReturn * 6;
                $breakdowns['month_6_breakdown'] = $totalInterest;
                $annualReturn = $monthlyReturn * 12;

                $yearlyBreakdown = [[
                    'year' => 1,
                    'roi_percentage' => (float)$product->roi_percentage,
                    'monthly_payout' => round($monthlyReturn, 2),
                    'yearly_total' => round($totalInterest, 2),
                    'duration_months' => 6
                ]];
            }

        } else {
            // Standard Fixed ROI Calculation
            $roi = (float) $product->roi_percentage;
            $monthlyReturn = ($amount * ($roi / 100)) / 12;
            $annualReturn = $amount * ($roi / 100);
            $totalInterest = $monthlyReturn * $duration;

            $breakdowns['month_6_breakdown'] = ($duration >= 6) ? $monthlyReturn * 6 : 0;
            $breakdowns['year_1_breakdown'] = ($duration >= 12) ? $monthlyReturn * 12 : 0;
            $breakdowns['year_2_breakdown'] = ($duration >= 24) ? $monthlyReturn * 24 : 0;
            $breakdowns['year_3_breakdown'] = ($duration >= 36) ? $monthlyReturn * 36 : 0;
            $breakdowns['year_4_breakdown'] = ($duration >= 48) ? $monthlyReturn * 48 : 0;
            $breakdowns['year_5_breakdown'] = ($duration >= 60) ? $monthlyReturn * 60 : 0;

            // Generate yearly breakdown for fixed ROI
            $remainingMonths = $duration;
            for ($yr = 1; $yr <= ceil($duration / 12); $yr++) {
                $monthsInThisYear = min(12, $remainingMonths);
                $yearlyBreakdown[] = [
                    'year' => $yr,
                    'roi_percentage' => $roi,
                    'monthly_payout' => round($monthlyReturn, 2),
                    'yearly_total' => round($monthlyReturn * $monthsInThisYear, 2),
                    'duration_months' => $monthsInThisYear
                ];
                $remainingMonths -= $monthsInThisYear;
            }
        }

        $maturityAmount = $amount + $totalInterest;

        return array_merge($breakdowns, [
            'monthly_return' => $monthlyReturn,
            'annual_return' => $annualReturn,
            'maturity_amount' => $maturityAmount,
            'total_interest' => $totalInterest,
            'yearly_breakdown' => $yearlyBreakdown
        ]);
    }
}
