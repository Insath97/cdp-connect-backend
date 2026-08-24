<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserHierarchyChange extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'old_parent_user_id',
        'new_parent_user_id',
        'changed_by',
        'reason',
        'changed_at',
    ];

    protected $casts = [
        'changed_at' => 'datetime',
    ];

    /* Relationships */

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function oldParent()
    {
        return $this->belongsTo(User::class, 'old_parent_user_id');
    }

    public function newParent()
    {
        return $this->belongsTo(User::class, 'new_parent_user_id');
    }

    public function changedByUser()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
