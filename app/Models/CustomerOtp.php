<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerOtp extends Model
{
    use HasFactory;

    protected $table = 'customer_otps';

    protected $fillable = [
        'customer_id',
        'id_number',
        'otp',
        'expires_at',
        'attempts',
        'verified_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'verified_at' => 'datetime',
        'attempts' => 'integer',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'otp',
    ];

    /* Relationships */

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /* Scopes */

    /**
     * Scope a query to only include active (unverified and not expired) OTPs.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('verified_at')
                     ->where('expires_at', '>', Carbon::now());
    }

    /**
     * Scope a query to filter by ID number.
     */
    public function scopeForIdNumber(Builder $query, string $idNumber): Builder
    {
        return $query->where('id_number', $idNumber);
    }

    /* Helpers */

    /**
     * Determine if the OTP has expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at ? $this->expires_at->isPast() : true;
    }

    /**
     * Determine if the OTP has been verified.
     */
    public function isVerified(): bool
    {
        return !is_null($this->verified_at);
    }

    /**
     * Check if OTP is still valid (not expired, not verified, within max attempts).
     */
    public function isValid(int $maxAttempts = 5): bool
    {
        return !$this->isExpired() && !$this->isVerified() && $this->attempts < $maxAttempts;
    }

    /**
     * Determine if maximum attempts have been reached.
     */
    public function hasMaxAttemptsReached(int $maxAttempts = 5): bool
    {
        return $this->attempts >= $maxAttempts;
    }

    /**
     * Increment attempts count and save.
     */
    public function incrementAttempts(): self
    {
        $this->increment('attempts');
        return $this;
    }

    /**
     * Mark the OTP as verified.
     */
    public function markAsVerified(): self
    {
        $this->update(['verified_at' => Carbon::now()]);
        return $this;
    }
}
