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

        $rates_per_year = [];

        if ($product->is_variable_roi) {
            $rates = $product->annualRates->pluck('roi_percentage', 'year');

            for ($year = 1; $year <= 5; $year++) {
                if ($duration >= ($year * 12)) {
                    $rate = $rates->get($year) ?? $product->roi_percentage;
                    $rates_per_year[$year] = $rate;

                    $yearlyReturn = $amount * ($rate / 100);
                    $totalInterest += $yearlyReturn;
                    $breakdowns["year_{$year}_breakdown"] = $yearlyReturn;

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

            // For fixed ROI, all years have the same rate
            for ($yr = 1; $yr <= ceil($duration / 12); $yr++) {
                $rates_per_year[$yr] = $roi;
            }
        }

        $maturityAmount = $amount + $totalInterest;

        return array_merge($breakdowns, [
            'monthly_return' => $monthlyReturn,
            'annual_return' => $annualReturn,
            'maturity_amount' => $maturityAmount,
            'total_interest' => $totalInterest,
            'monthly_breakdown' => $this->buildMonthlyBreakdown($amount, $duration, $rates_per_year),
        ]);
    }

    /**
     * Build a month-by-month breakdown of the investment.
     *
     * @param float $amount
     * @param int $duration
     * @param array $rates_per_year
     * @return array
     */
    private function buildMonthlyBreakdown(float $amount, int $duration, array $rates_per_year): array
    {
        $monthlyBreakdown = [];
        $cumulativeInterest = 0;

        for ($month = 1; $month <= $duration; $month++) {
            $year = (int) ceil($month / 12);
            $rate = $rates_per_year[$year] ?? $rates_per_year[max(array_keys($rates_per_year))];
            
            $monthlyReturn = ($amount * ($rate / 100)) / 12;
            $cumulativeInterest += $monthlyReturn;

            $monthlyBreakdown[] = [
                'month' => $month,
                'year' => $year,
                'roi_percentage' => (float) $rate,
                'monthly_return' => round($monthlyReturn, 2),
                'cumulative_interest' => round($cumulativeInterest, 2),
                'balance' => round($amount + $cumulativeInterest, 2),
            ];
        }

        return $monthlyBreakdown;
    }
}
