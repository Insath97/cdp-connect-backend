<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Zone extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'region_id',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean'
    ];

    public function region()
    {
        return $this->belongsTo(Region::class);
    }

    public function province()
    {
        return $this->hasOneThrough(Province::class, Region::class, 'id', 'id', 'region_id', 'province_id');
    }

    public function branches()
    {
        return $this->hasMany(Branch::class);
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
