<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvestmentProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'duration_months',
        'roi_percentage',
        'is_variable_roi',
        'minimum_amount',
        'maximum_amount',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_variable_roi' => 'boolean',
        'duration_months' => 'integer',
        'roi_percentage' => 'decimal:2',
        'minimum_amount' => 'decimal:2',
        'maximum_amount' => 'decimal:2',
    ];

    public function annualRates()
    {
        return $this->hasMany(InvestmentProductRate::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch($query, $search)
    {
        return $query->where('name', 'like', "%{$search}%")
            ->orWhere('code', 'active', "%{$search}%");
    }
}
