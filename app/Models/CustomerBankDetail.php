<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerBankDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'bank_name',
        'branch_name',
        'account_number',
        'payment_method'
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
