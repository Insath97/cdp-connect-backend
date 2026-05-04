<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvestmentPayout extends Model
{
    use HasFactory;

    protected $fillable = [
        'investment_id',
        'scheduled_date',
        'amount',
        'status',
        'paid_at',
        'reference_number',
        'remarks',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'paid_at' => 'datetime',
        'amount' => 'decimal:2',
    ];

    public function investment()
    {
        return $this->belongsTo(Investment::class);
    }
}
