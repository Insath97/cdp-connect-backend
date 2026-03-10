<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Province extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'country_id',
        'contact_info',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'contact_info' => 'array'
    ];

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function zones()
    {
        return $this->hasMany(Zone::class);
    }

    public function regions()
    {
        return $this->hasManyThrough(Region::class, Zone::class);
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
