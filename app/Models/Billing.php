<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Billing extends Model
{
    use HasFactory;

    protected $fillable = [
        'billing_number',
        'customer_id',
        'investment_id',
        'investment_product_id',
        'investment_amount',
        'branch_id',
        'status',
    ];

    protected $casts = [
        'investment_amount' => 'decimal:2',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function investment()
    {
        return $this->belongsTo(Investment::class);
    }

    public function investmentProduct()
    {
        return $this->belongsTo(InvestmentProduct::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
