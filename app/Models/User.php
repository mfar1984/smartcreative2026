<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'is_handler',
        'is_sponsor',
        'sponsor_committed_amount',
        'password_changed_at',
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
            'is_handler' => 'boolean',
            'is_sponsor' => 'boolean',
            'sponsor_committed_amount' => 'decimal:2',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
        ];
    }

    /**
     * Stamp password_changed_at whenever the password is set or changed.
     *
     * Hooked on the model rather than in each controller so every path that sets a
     * password — user create, user update, and anything added later — records the
     * timestamp without having to remember to. The password is cast to 'hashed',
     * so isDirty('password') is true for a freshly set or a changed password and
     * false when the field was left blank on an edit. The guard lets a caller that
     * sets password_changed_at explicitly (a test, a backfill) keep its value.
     */
    protected static function booted(): void
    {
        static::saving(function (self $user): void {
            if ($user->isDirty('password') && ! $user->isDirty('password_changed_at')) {
                $user->password_changed_at = now();
            }
        });
    }

    /**
     * Whether this user's password has aged past the configured expiry window.
     *
     * Returns false when expiry is switched off (0 days) or when no change has
     * ever been recorded, so an installation that has not opted in, and a user
     * whose column is still NULL, are both treated as not expired. Enforcement is
     * deferred in Part 1 (see the brief); this reader exists so the timestamp has
     * a single interpretation when enforcement is wired up later.
     */
    public function passwordHasExpired(int $expiryDays): bool
    {
        if ($expiryDays <= 0 || $this->password_changed_at === null) {
            return false;
        }

        return $this->password_changed_at->lt(now()->subDays($expiryDays));
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Whether this user is a tournament handler.
     *
     * A handler is a users row carrying the handler role with this flag set. The
     * flag is what the post-login landing and a later Handler Management screen
     * ask, so neither has to read role logic to tell a handler apart.
     */
    public function isHandler(): bool
    {
        return $this->is_handler === true;
    }

    /**
     * Whether this user is a sponsorship account.
     *
     * A sponsor is a users row carrying the sponsor role with this flag set. The
     * flag is what the post-login landing and the Sponsorship tab ask, so neither
     * has to read role logic to tell a sponsor apart.
     */
    public function isSponsor(): bool
    {
        return $this->is_sponsor === true;
    }

    /**
     * The tournaments this user has been assigned to run.
     */
    public function handledTournaments(): BelongsToMany
    {
        return $this->belongsToMany(Tournament::class, 'tournament_handler')->withTimestamps();
    }

    /**
     * The blocks of coupon codes this sponsor funded.
     *
     * The ONE relation the sponsor's own area reads, and the reason it is safe: a
     * sponsor's whole holding is reachable from their own id, so no screen in that
     * area has to be told to narrow itself.
     */
    public function sponsoredAllocations(): HasMany
    {
        return $this->hasMany(CouponAllocation::class, 'sponsor_user_id')->orderBy('id');
    }

    /**
     * Whether this user only ever sees the tournaments assigned to them.
     *
     * True for a handler and nobody else. The super admin is never narrowed, even
     * if the flag were set on that account, because it is the way back in when an
     * assignment goes wrong.
     */
    public function isRestrictedToAssignedTournaments(): bool
    {
        return $this->isHandler() && ! (bool) $this->role?->isSuperAdmin();
    }

    /**
     * Whether this user may see and act on one tournament.
     *
     * Everyone who is not a handler runs every tournament, which is what keeps the
     * existing administrator roles exactly as they are: this answers true for them
     * without reading the pivot at all.
     */
    public function runsTournament(Tournament $tournament): bool
    {
        if (! $this->isRestrictedToAssignedTournaments()) {
            return true;
        }

        return $this->handledTournaments()
            ->where('tournaments.id', $tournament->getKey())
            ->exists();
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
