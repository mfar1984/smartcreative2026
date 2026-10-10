<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    /**
     * The role that always keeps every permission. Guarding this slug stops an
     * administrator from locking themselves out through the matrix screen.
     */
    public const SUPER_ADMIN = 'super-admin';

    /**
     * The tournament handler role. A system role, so the slug is fixed: the
     * Handler tab assigns it server side and never reads one from a form.
     */
    public const HANDLER = 'handler';

    /**
     * The sponsorship role: a monitor-and-view account that only ever sees the
     * coupon blocks it funded. A system role, so the slug is fixed and the
     * Sponsorship tab assigns it server side rather than reading one from a form.
     */
    public const SPONSOR = 'sponsor';

    protected $fillable = [
        'slug',
        'name',
        'description',
        'is_protected',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_protected' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->slug === self::SUPER_ADMIN;
    }

    /**
     * Whether this role can sign in to the admin at all.
     *
     * The super admin implicitly holds every permission, so it always can. Any other
     * role must carry admin.access on the pivot; without it, every user on the role
     * is turned away at the login screen. The Roles screens use this to warn before
     * a role is saved — or a user is assigned — into exactly that trap.
     */
    public function grantsAdminAccess(): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $this->permissions()
            ->where('slug', Permission::ADMIN_ACCESS)
            ->exists();
    }
}
