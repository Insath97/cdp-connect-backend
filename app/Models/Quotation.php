<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quotation extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'quotation_number',
        'customer_id',
        'branch_id',
        'investment_product_id',
        'investment_amount',
        'month_6_breakdown',
        'year_1_breakdown',
        'year_2_breakdown',
        'year_3_breakdown',
        'year_4_breakdown',
        'year_5_breakdown',
        'status',
        'is_active',
        'valid_until',
        'notes',
        'created_by'
    ];

    protected $casts = [
        'investment_amount' => 'decimal:2',
        'month_6_breakdown' => 'decimal:2',
        'year_1_breakdown' => 'decimal:2',
        'year_2_breakdown' => 'decimal:2',
        'year_3_breakdown' => 'decimal:2',
        'year_4_breakdown' => 'decimal:2',
        'year_5_breakdown' => 'decimal:2',
        'is_active' => 'boolean',
        'valid_until' => 'date',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function investmentProduct()
    {
        return $this->belongsTo(InvestmentProduct::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
