<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Level extends Model
{
    use HasFactory;

    protected $fillable = [
        'level_name',
        'slug',
        'code',
        'tire_level',
        'category',
        'isActive',
        'is_single_user'
    ];

    protected $casts = [
        'isActive' => 'boolean',
        'is_single_user' => 'boolean'
    ];

    public function scopeActive($query)
    {
        return $query->where('isActive', true);
    }

    public function scopeSearch($query, $search)
    {
        return $query->where('name', 'like', "%{$search}%")->orWhere('slug', 'LIKE', "%{$search}%");
    }
}
