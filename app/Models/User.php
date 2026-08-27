<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'role_id',
        'is_active',
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
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Whether this user may reach the admin area at all.
     *
     * A user needs an active account and a role. The super admin role always
     * passes; any other role must hold the admin.access permission.
     */
    public function canAccessAdmin(): bool
    {
        return $this->is_active
            && $this->role !== null
            && $this->role->is_active
            && $this->hasPermission('admin.access');
    }

    /**
     * Permission slugs held by this user, loaded once per request.
     *
     * @var array<int, string>|null
     */
    private ?array $permissionSlugs = null;

    /**
     * The super admin role implicitly holds every permission, so new
     * permissions never need to be granted to it after a deploy.
     */
    public function hasPermission(string $slug): bool
    {
        if ($this->role === null) {
            return false;
        }

        if ($this->role->isSuperAdmin()) {
            return true;
        }

        // Memoised because the sidebar checks several permissions on every
        // page render.
        $this->permissionSlugs ??= $this->role->permissions()->pluck('slug')->all();

        return in_array($slug, $this->permissionSlugs, true);
    }

    /**
     * Label used in log entries so history survives the user being deleted.
     */
    public function logLabel(): string
    {
        return $this->username
            ? sprintf('%s (%s)', $this->name, $this->username)
            : $this->name;
    }
}
