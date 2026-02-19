<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Target extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'assigned_by',
        'period_type',
        'period_key',
        'target_amount',
        'achieved_amount',
        'status',
        'achieved_at',
    ];

    protected $casts = [
        'target_amount' => 'decimal:2',
        'achieved_amount' => 'decimal:2',
        'achievement_percentage' => 'decimal:2',
        'achieved_at' => 'datetime',
    ];

    protected $appends = ['remaining_amount'];

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
    public static function syncAchievement($userId, $periodKey, $amount)
    {
        $target = self::where('user_id', $userId)
            ->where('period_key', $periodKey)
            ->first();

        if ($target) {
            $target->increment('achieved_amount', $amount);

            // Auto-achieve if amount reached? (Optional)
            if ($target->achieved_amount >= $target->target_amount) {
                $target->update(['status' => 'achieved', 'achieved_at' => now()]);
            }
        }

        // Move up the hierarchy
        $user = User::find($userId);
        if ($user && $user->parent_user_id) {
            self::syncAchievement($user->parent_user_id, $periodKey, $amount);
        }
    }
}
