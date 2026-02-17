<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'address_line1',
        'address_line2',
        'city',
        'postal_code',
        'zone_id',
        'region_id',
        'province_id',
        'phone_primary',
        'phone_secondary',
        'email',
        'fax',
        'opening_date',
        'branch_type',
        'latitude',
        'longitude',
        'is_active',
        'is_head_office'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_head_office' => 'boolean',
        'opening_date' => 'date',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8'
    ];

    public function zone()
    {
        return $this->belongsTo(Zone::class);
    }

    public function region()
    {
        return $this->belongsTo(Region::class);
    }

    public function province()
    {
        return $this->belongsTo(Province::class);
    }

    // Recursive relationship to Country via Province
    public function country()
    {
        return $this->hasOneThrough(Country::class, Province::class, 'id', 'id', 'province_id', 'country_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch($query, $search)
    {
        return $query->where('name', 'like', "%{$search}%")
            ->orWhere('code', 'LIKE', "%{$search}%")
            ->orWhere('city', 'LIKE', "%{$search}%");
    }
}
