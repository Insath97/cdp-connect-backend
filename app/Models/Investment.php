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
        'special_business_description',
        'initial_payment',
        'initial_payment_date',
        'monthly_payment_amount',
        'monthly_payment_date',
        'payment_proof',
        'business_type',
        'investment_type',
        'status',
        'created_by',
        'unit_head_id',
        'checked_by',
        'checked_at',
        'approved_by',
        'approved_at',
        'notes',
        'cancelled_at',
        'cancellation_reason',
        'refund_amount',
        'admin_cost_amount',
        'rejected_at',
        'rejection_reason',
        'terminated_at',
        'termination_reason',
        'welcome_call_status',
        'welcome_call_by',
        'welcome_call_at',
        'welcome_call_notes',
        'signature_document',
        'renewal_sms_sent_at',
        'same_day_expiry_sms_sent_at',
    ];

    protected $casts = [
        'reservation_date' => 'date',
        'initial_payment_date' => 'date',
        'monthly_payment_date' => 'date',
        'checked_at' => 'date',
        'approved_at' => 'date',
        'cancelled_at' => 'datetime',
        'terminated_at' => 'datetime',
        'welcome_call_at' => 'datetime',
        'renewal_sms_sent_at' => 'datetime',
        'same_day_expiry_sms_sent_at' => 'datetime',
        'investment_amount' => 'decimal:2',
        'initial_payment' => 'decimal:2',
        'monthly_payment_amount' => 'decimal:2',
        'admin_cost_amount' => 'decimal:2'
    ];

    public function billing()
    {
        return $this->hasOne(Billing::class);
    }

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

    public function receipts()
    {
        return $this->hasMany(Receipt::class);
    }

    public function payouts()
    {
        return $this->hasMany(InvestmentPayout::class);
    }

    public function welcomeCallUser()
    {
        return $this->belongsTo(User::class, 'welcome_call_by');
    }
}
