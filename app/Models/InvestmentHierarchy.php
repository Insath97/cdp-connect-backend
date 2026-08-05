<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvestmentHierarchy extends Model
{
    use HasFactory;

    protected $table = 'investment_hierarchies';

    protected $fillable = [
        'investment_id',
        'ancestor_id',
        'depth',
    ];

    public function investment()
    {
        return $this->belongsTo(Investment::class, 'investment_id');
    }

    public function ancestor()
    {
        return $this->belongsTo(User::class, 'ancestor_id');
    }
}
