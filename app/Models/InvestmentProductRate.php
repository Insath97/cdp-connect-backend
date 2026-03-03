<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvestmentProductRate extends Model
{
    use HasFactory;

    protected $fillable = [
        'investment_product_id',
        'year',
        'roi_percentage',
    ];

    protected $casts = [
        'roi_percentage' => 'decimal:2',
    ];

    public function investmentProduct()
    {
        return $this->belongsTo(InvestmentProduct::class);
    }
}
