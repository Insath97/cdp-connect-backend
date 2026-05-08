<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Commission extends Model
{
    protected $fillable = [
        'investment_id',
        'user_id',
        'investment_amount',
        'commission_amount',
        'commission_percentage',
        'tier',
        'period_key',
        'status',
        'earned_amount',
        'recover_amount',
    ];

    /**
     * Generate commission records for an approved investment based on hierarchy levels.
     */
    public static function generateForInvestment(Investment $investment)
    {
        // 1. Fetch percentages from the linked InvestmentProduct
        $product = $investment->investmentProduct;

        if (!$product) {
            \Illuminate\Support\Facades\Log::warning('Commission processing skipped: Investment product not found', [
                'investment_id' => $investment->id
            ]);
            return;
        }

        $unitHeadPct = $product->unit_head_commission_pct ?? 0;
        $parentPct = $product->parent_commission_pct ?? 0;

        $amount = (float) $investment->investment_amount;

        // Eligible Level IDs: 14=SGL, 15=GL, 16=SC, 17=C
        $eligibleLevels = [14,15,16,17]; // Added new levels based on LevelSeeder

        // 2. Unit Head Commission
        if ($investment->unit_head_id) {
            $unitHead = $investment->unitHead;

            // Ensure we have the level_id
            if ($unitHead && in_array($unitHead->level_id, $eligibleLevels)) {
                $unitHeadCommissionAmount = ($amount * $unitHeadPct) / 100;

                self::create([
                    'investment_id' => $investment->id,
                    'user_id' => $investment->unit_head_id,
                    'investment_amount' => $amount,
                    'commission_amount' => $unitHeadCommissionAmount,
                    'commission_percentage' => $unitHeadPct,
                    'tier' => 'unit_head',
                    'period_key' => $investment->target_period_key,
                    'status' => 'pending',
                ]);

                // 3. Parent Commission
                if ($unitHead->parent_user_id) {
                    // Fetch parent with their level
                    $parent = \App\Models\User::find($unitHead->parent_user_id);

                    if ($parent && in_array($parent->level_id, $eligibleLevels)) {
                        self::create([
                            'investment_id' => $investment->id,
                            'user_id' => $unitHead->parent_user_id,
                            'investment_amount' => $amount,
                            'commission_amount' => ($unitHeadCommissionAmount * $parentPct) / 100,
                            'commission_percentage' => $parentPct,
                            'tier' => 'parent',
                            'period_key' => $investment->target_period_key,
                            'status' => 'pending',
                        ]);
                    }
                }
            }
        }
    }

    public function investment()
    {
        return $this->belongsTo(Investment::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
