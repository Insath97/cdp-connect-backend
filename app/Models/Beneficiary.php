<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Beneficiary extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'full_name',
        'id_type',
        'id_number',
        'phone_primary',
        'relationship',
        'share_percentage'
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
