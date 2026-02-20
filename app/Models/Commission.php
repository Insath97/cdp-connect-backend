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
    ];

    public function investment()
    {
        return $this->belongsTo(Investment::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
