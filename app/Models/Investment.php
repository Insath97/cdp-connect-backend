<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Investment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'policy_number',
        'application_number',
        'sales_code',
        'reservation_date',
        'target_period_key',
        'customer_id',
        'branch_id',
        'investment_product_id',
        'beneficiary_id',
        'customer_bank_detail_id',
        'investment_amount',
        'bank',
        'payment_type',
        'payment_description',
        'initial_payment',
        'initial_payment_date',
        'monthly_payment_amount',
        'monthly_payment_date',
        'status',
        'created_by',
        'unit_head_id',
        'checked_by',
        'checked_at',
        'approved_by',
        'approved_at',
        'notes'
    ];

    protected $casts = [
        'reservation_date' => 'date',
        'initial_payment_date' => 'date',
        'monthly_payment_date' => 'date',
        'checked_at' => 'date',
        'approved_at' => 'date',
        'investment_amount' => 'decimal:2',
        'initial_payment' => 'decimal:2',
        'monthly_payment_amount' => 'decimal:2'
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

    public function beneficiary()
    {
        return $this->belongsTo(Beneficiary::class);
    }

    public function bankDetail()
    {
        return $this->belongsTo(CustomerBankDetail::class, 'customer_bank_detail_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function unitHead()
    {
        return $this->belongsTo(User::class, 'unit_head_id');
    }

    public function checker()
    {
        return $this->belongsTo(User::class, 'checked_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
