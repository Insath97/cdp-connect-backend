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
            // 1. Increment total achieved amount for this person
            $target->achieved_amount += $amount;

            // 2. Reduce remaining target level for this person (Robust calculation)
            // Use target_amount - achieved_amount to ensure it's always in sync with progress
            $target->current_amount = max(0, $target->target_amount - $target->achieved_amount);

            // 3. Calculate achievement percentage relative to THEIR own target
            if ($target->target_amount > 0) {
                $percentage = ($target->achieved_amount / $target->target_amount) * 100;
                $target->achievement_percentage = min($percentage, 999.99);
            } else {
                $target->achievement_percentage = $target->achieved_amount > 0 ? 100.00 : 0;
            }

            $target->save();

            Log::info("Target updated in hierarchy", [
                'target_id' => $target->id,
                'user_id' => $target->user_id,
                'target_amount' => $target->target_amount,
                'achieved_amount' => $target->achieved_amount,
                'current_amount' => $target->current_amount,
                'percentage' => $target->achievement_percentage,
                'is_deep' => $isDeep
            ]);

            // Check if achieved
            if ($target->current_amount <= 0 || $target->achieved_amount >= $target->target_amount) {
                $target->update(['status' => 'achieved', 'achieved_at' => now()]);
            }
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
}
