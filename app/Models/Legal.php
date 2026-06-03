<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Legal extends Model
{
    protected $fillable = [
        'branch_id',
        'customer_id',
        'investment_product_id',
        'investment_id',
        'language',
        'legal_number',
        'date_of_agreement',
        'full_name',
        'name_with_initials',
        'id_type',
        'id_number',
        'email',
        'address_line_1',
        'address_line_2',
        'landmark',
        'city',
        'state',
        'country',
        'postal_code',
        'year_in_words',
        'year',
        'witness_01_name',
        'witness_01_nic',
        'witness_01_address',
        'witness_02_name',
        'witness_02_nic',
        'witness_02_address',
        'created_by',
        'bank_name',
        'branch_name',
        'account_number',
        'beneficiary_full_name',
        'beneficiary_id_type',
        'beneficiary_id_number',
        'beneficiary_phone_primary',
        'beneficiary_relationship',
        'beneficiary_share_percentage',
        'monthly_return',
        'annual_return',
        'maturity_amount',
        'month_6_breakdown',
        'year_1_breakdown',
        'year_2_breakdown',
        'year_3_breakdown',
        'year_4_breakdown',
        'year_5_breakdown',
        'yearly_breakdown'
    ];

    protected $casts = [
        'date_of_agreement' => 'date',
        'yearly_breakdown' => 'array',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function investmentProduct()
    {
        return $this->belongsTo(InvestmentProduct::class);
    }

    public function investment()
    {
        return $this->belongsTo(Investment::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
