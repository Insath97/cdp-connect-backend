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
     * Full recalculation of achieved amount for a specific user and period based on their branch's business.
     */
    public static function recalculateForUser($userId, $periodKey)
    {
        $user = User::find($userId);
        if (!$user) return false;

        $target = self::where('user_id', $userId)
            ->where('period_key', $periodKey)
            ->first();

        if (!$target) return false;

        // Get all approved investments in this user's branch for the period
        $descendantIds = $user->getAllDescendantIds();
        $allIds = array_merge([$userId], $descendantIds);

        $totalApproved = \App\Models\Investment::whereIn('unit_head_id', $allIds)
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
            $target->status = 'active'; // Revert to active if no longer achieved (e.g. if investments were deleted)
            $target->achieved_at = null;
        }

        return $target->save();
    }
}
