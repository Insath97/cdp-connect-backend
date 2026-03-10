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
        'zone_id',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean'
    ];

    public function zone()
    {
        return $this->belongsTo(Zone::class);
    }

    public function province()
    {
        return $this->hasOneThrough(Province::class, Zone::class, 'id', 'id', 'zone_id', 'province_id');
    }

    public function country()
    {
        // This is a bit complex for a standard hasOneThrough without a deep relation package.
        // We'll go through Province which goes through Zone.
        return $this->province ? $this->province->country() : null;
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
