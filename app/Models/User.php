<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements JWTSubject, MustVerifyEmail
{
    use HasFactory, Notifiable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'employee_code',
        'password',
        'user_type',
        'level_id',
        'parent_user_id',
        'branch_id',
        'zone_id',
        'region_id',
        'province_id',
        'is_active',
        'can_login',
        'profile_image',
        'id_type',
        'id_number',
        'last_login_at',
        'last_login_ip',
        'email_verified_at',
        'email_verification_token',
        'email_verification_token_expires_at',
        'is_head_office_user',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'email_verification_token_expires_at' => 'datetime',
            'password' => 'hashed',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'can_login' => 'boolean',
            'is_head_office_user' => 'boolean',
        ];
    }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
        ];
    }

    /* Relationships */

    public function level()
    {
        return $this->belongsTo(Level::class);
    }

    public function parent()
    {
        return $this->belongsTo(User::class, 'parent_user_id');
    }

    public function children()
    {
        return $this->hasMany(User::class, 'parent_user_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

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

    public function investments()
    {
        return $this->hasMany(Investment::class, 'unit_head_id');
    }

    public function createdInvestments()
    {
        return $this->hasMany(Investment::class, 'created_by');
    }

    public function assignedBranches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_user', 'user_id', 'branch_id')->withTimestamps();
    }

    public function hierarchyChanges()
    {
        return $this->hasMany(UserHierarchyChange::class);
    }

    /* Helper Methods */

    /**
     * Check if the user is active
     */
    public function is_active(): bool
    {
        return (bool) $this->is_active;
    }

    /**
     * Check if the user is active (camelCase version)
     */
    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    public function canLogin(): bool
    {
        return $this->is_active() && $this->can_login;
    }

    public function updateLastLogin($ipAddress = null)
    {
        $this->update([
            'last_login_at' => now(),
            'last_login_ip' => $ipAddress
        ]);
    }

    /**
     * Generate a unique email verification token
     */
    public function generateEmailVerificationToken(): string
    {
        $token = bin2hex(random_bytes(32));

        $this->update([
            'email_verification_token' => $token,
            'email_verification_token_expires_at' => now()->addHours(24)
        ]);

        return $token;
    }

    /**
     * Mark the user's email as verified
     */
    public function markEmailAsVerifiedcheck(string $token)
    {
        $this->update([
            'email_verified_at' => now(),
            'email_verification_token' => $token,
            'email_verification_token_expires_at' => null
        ]);
    }

    /**
     * Mark the user's email as verified without a token
     */
    public function markEmailAsVerified()
    {
        $this->update([
            'email_verified_at' => now(),
            'email_verification_token' => null,
            'email_verification_token_expires_at' => null
        ]);
    }

    /**
     * Check if the user's email verification token is valid
     */
    public function isEmailVerificationTokenValid(string $token): bool
    {
        if ($this->email_verification_token !== $token) {
            return false;
        }

        if (!$this->email_verification_token_expires_at) {
            return false;
        }

        return now()->lessThan($this->email_verification_token_expires_at);
    }

    /**
     * Get all direct and indirect subordinate IDs (descendants).
     */
    public function getAllDescendantIds(): array
    {
        $descendants = [];
        $children = User::where('parent_user_id', $this->id)->get();

        foreach ($children as $child) {
            $descendants[] = $child->id;
            $descendants = array_merge($descendants, $child->getAllDescendantIds());
        }

        return $descendants;
    }

    /**
     * Get all descendant IDs as of a specific date (point-in-time hierarchy).
     */
    public function getAllDescendantIdsAt(string $date): array
    {
        $descendants = [];
        $children = User::where('parent_user_id', $this->id)->get();

        foreach ($children as $child) {
            // Check if this child was moved away before the date
            $moveOut = UserHierarchyChange::where('user_id', $child->id)
                ->where('old_parent_user_id', $this->id)
                ->where('changed_at', '<=', $date)
                ->orderBy('changed_at', 'desc')
                ->first();

            // If child was moved out before the date, skip
            if ($moveOut && $moveOut->new_parent_user_id !== $this->id) {
                continue;
            }

            $descendants[] = $child->id;
            $descendants = array_merge($descendants, $child->getAllDescendantIdsAt($date));
        }

        return $descendants;
    }

    /**
     * Check if the user has verified their email
     */
    public function hasVerifiedEmail(): bool
    {
        return !is_null($this->email_verified_at);
    }

    /**
     * Get ancestor user IDs as of a specific date (point-in-time hierarchy).
     */
    public function getAncestorIdsAt(string $date): array
    {
        $ancestorIds = [];
        $currentId = $this->id;
        $visited = [$currentId];

        while (true) {
            // Find the parent of current user as of the given date
            $change = UserHierarchyChange::where('user_id', $currentId)
                ->where('changed_at', '<=', $date)
                ->orderBy('changed_at', 'desc')
                ->first();

            if ($change) {
                $parentId = $change->new_parent_user_id;
            } else {
                // No change record, use current parent
                $currentUser = User::find($currentId);
                $parentId = $currentUser ? $currentUser->parent_user_id : null;
            }

            if (!$parentId || in_array($parentId, $visited)) {
                break;
            }

            $ancestorIds[] = $parentId;
            $visited[] = $parentId;
            $currentId = $parentId;
        }

        return $ancestorIds;
    }

    /**
     * Get descendant IDs as of a specific date using historical hierarchy.
     */
    public function getDescendantIdsAt(string $date): array
    {
        $descendants = [];
        $children = User::where('parent_user_id', $this->id)->get();

        foreach ($children as $child) {
            // Check if this child was moved away before the date
            $change = UserHierarchyChange::where('user_id', $child->id)
                ->where('old_parent_user_id', $this->id)
                ->where('changed_at', '<=', $date)
                ->orderBy('changed_at', 'desc')
                ->first();

            // If child was moved before the date, it's not under this parent at that date
            if ($change) {
                continue;
            }

            $descendants[] = $child->id;
            $descendants = array_merge($descendants, $child->getDescendantIdsAt($date));
        }

        // Also check users who were previously children but moved away
        $movedChildren = UserHierarchyChange::where('old_parent_user_id', $this->id)
            ->where('new_parent_user_id', $this->id)
            ->where('changed_at', '<=', $date)
            ->get();

        return $descendants;
    }

}
