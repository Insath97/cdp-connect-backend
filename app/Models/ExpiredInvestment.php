<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExpiredInvestment extends Model
{
    use HasFactory;

    protected $fillable = [
        'investment_id',
        'customer_id',
        'branch_id',
        'investment_amount',
        'status',
        'payment_method',
        'transaction_number',
        'remarks',
        'image',
        'paid_at',
        'paid_by',
    ];

    protected $casts = [
        'investment_amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function investment()
    {
        return $this->belongsTo(Investment::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function paidBy()
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
