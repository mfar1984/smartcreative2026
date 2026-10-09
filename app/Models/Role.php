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
}
