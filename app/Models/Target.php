<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class Target extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'assigned_by',
        'period_type',
        'period_key',
        'target_amount',
        'current_amount',
        'achieved_amount',
        'achievement_percentage',
        'status',
        'achieved_at',
    ];

    protected $casts = [
        'target_amount' => 'decimal:2',
        'current_amount' => 'decimal:2',
        'achieved_amount' => 'decimal:2',
        'achievement_percentage' => 'decimal:2',
        'achieved_at' => 'datetime',
    ];

    protected $appends = ['remaining_amount'];

    // Removed getAchievementPercentageAttribute accessor as it is now a database column


    public function getRemainingAmountAttribute()
    {
        return max(0, $this->target_amount - $this->achieved_amount);
    }

    /* Relationships */

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function assigner()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /* Scopes */

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForPeriod($query, $type, $key)
    {
        return $query->where('period_type', $type)->where('period_key', $key);
    }

    /**
     * Recursively update achieved_amount for a user and their superiors.
     */
    /**
     * Recursively update achieved_amount for a user and their superiors.
     */
    /**
     * Recursively update achieved_amount for a user and their superiors.
     */
    public static function syncAchievement($userId, $periodKey, $amount, $isDeep = false)
    {
        Log::info("Syncing target achievement", [
            'user_id' => $userId,
            'period_key' => $periodKey,
            'amount' => $amount,
            'is_deep' => $isDeep
        ]);

        $target = self::where('user_id', $userId)
            ->where('period_key', $periodKey)
            ->first();

        if ($target) {
            // 1. Increment total achieved amount atomically in the database
            $target->increment('achieved_amount', $amount);
            
            // Refresh model to get the updated amount and recalculate related fields
            $target->refresh();

            // 2. Update remaining target level
            $target->current_amount = max(0, $target->target_amount - $target->achieved_amount);

            // 3. Calculate achievement percentage relative to THEIR own target
            if ($target->target_amount > 0) {
                $percentage = ($target->achieved_amount / $target->target_amount) * 100;
                $target->achievement_percentage = min($percentage, 999.99);
            } else {
                $target->achievement_percentage = $target->achieved_amount > 0 ? 100.00 : 0;
            }

            // Check if achieved status needs to be updated
            if ($target->current_amount <= 0 || $target->achieved_amount >= $target->target_amount) {
                $target->status = 'achieved';
                $target->achieved_at = $target->achieved_at ?? now();
            }

            $target->save();

            Log::info("Target updated in hierarchy", [
                'target_id' => $target->id,
                'user_id' => $target->user_id,
                'achieved_amount' => $target->achieved_amount,
                'is_deep' => $isDeep
            ]);
        } else {
            Log::warning("Target NOT FOUND for sync. This user's amounts will NOT be updated, but moving up the hierarchy.", [
                'user_id' => $userId,
                'period_key' => $periodKey,
                'is_deep' => $isDeep
            ]);
        }

        // Move up the hierarchy
        $user = User::find($userId);
        if ($user && $user->parent_user_id) {
            self::syncAchievement($user->parent_user_id, $periodKey, $amount, true);
        }
    }

    /**
     * Sync target achievements for an approved investment using its hierarchy snapshot.
     */
    public static function syncInvestmentAchievement(Investment $investment)
    {
        $userIds = array_merge(
            [$investment->unit_head_id],
            $investment->hierarchySnapshot()->pluck('ancestor_id')->toArray()
        );

        foreach ($userIds as $userId) {
            Log::info("Syncing target achievement via investment snapshot", [
                'user_id' => $userId,
                'period_key' => $investment->target_period_key,
                'amount' => $investment->investment_amount
            ]);

            $target = self::where('user_id', $userId)
                ->where('period_key', $investment->target_period_key)
                ->first();

            if ($target) {
                $target->increment('achieved_amount', $investment->investment_amount);
                $target->refresh();
                $target->current_amount = max(0, $target->target_amount - $target->achieved_amount);

                if ($target->target_amount > 0) {
                    $percentage = ($target->achieved_amount / $target->target_amount) * 100;
                    $target->achievement_percentage = min($percentage, 999.99);
                } else {
                    $target->achievement_percentage = $target->achieved_amount > 0 ? 100.00 : 0;
                }

                if ($target->current_amount <= 0 || $target->achieved_amount >= $target->target_amount) {
                    $target->status = 'achieved';
                    $target->achieved_at = $target->achieved_at ?? now();
                }

                $target->save();
            }
        }
    }

    /**
     * Full recalculation of achieved amount for a specific user and period based on hierarchy snapshot.
     */
    public static function recalculateForUser($userId, $periodKey)
    {
        $user = User::find($userId);
        if (!$user) return false;

        $target = self::where('user_id', $userId)
            ->where('period_key', $periodKey)
            ->first();

        if (!$target) return false;

        // Sum approved investments where user is unit_head OR user is in the hierarchy snapshot
        $totalApproved = \App\Models\Investment::where(function ($query) use ($userId) {
                $query->where('unit_head_id', $userId)
                    ->orWhereHas('hierarchySnapshot', function ($q) use ($userId) {
                        $q->where('ancestor_id', $userId);
                    });
            })
            ->where('target_period_key', $periodKey)
            ->where('status', 'approved')
            ->sum('investment_amount');

        // Update target fields
        $target->achieved_amount = $totalApproved;
        $target->current_amount = max(0, $target->target_amount - $totalApproved);

        if ($target->target_amount > 0) {
            $percentage = ($totalApproved / $target->target_amount) * 100;
            $target->achievement_percentage = min($percentage, 999.99);
        } else {
            $target->achievement_percentage = $totalApproved > 0 ? 100.00 : 0;
        }

        if ($target->current_amount <= 0 || $totalApproved >= $target->target_amount) {
            $target->status = 'achieved';
            $target->achieved_at = $target->achieved_at ?? now();
        } else {
            $target->status = 'active';
            $target->achieved_at = null;
        }

        return $target->save();
    }

    /**
     * Recursively recalculate targets for a user and all their superiors.
     */
    public static function recalculateHierarchyTargets($userId, $periodKey)
    {
        self::recalculateForUser($userId, $periodKey);

        $user = User::find($userId);
        if ($user && $user->parent_user_id) {
            self::recalculateHierarchyTargets($user->parent_user_id, $periodKey);
        }
    }

    /**
     * Recalculate target achievements for an investment's snapshotted hierarchy (the unit head and all historical ancestors).
     */
    public static function recalculateHierarchyTargetsForInvestment(Investment $investment)
    {
        $userIds = array_merge(
            [$investment->unit_head_id],
            $investment->hierarchySnapshot()->pluck('ancestor_id')->toArray()
        );

        foreach ($userIds as $userId) {
            self::recalculateForUser($userId, $investment->target_period_key);
        }
    }
}
