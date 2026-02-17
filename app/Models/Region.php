<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Region extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'province_id',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean'
    ];

    public function province()
    {
        return $this->belongsTo(Province::class);
    }

    public function country()
    {
        return $this->hasOneThrough(Country::class, Province::class, 'id', 'id', 'province_id', 'country_id');
    }

    public function zones()
    {
        return $this->hasMany(Zone::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch($query, $search)
    {
        return $query->where('name', 'like', "%{$search}%")->orWhere('code', 'LIKE', "%{$search}%");
    }
}
