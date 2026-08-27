<?php

namespace App\Models;

use App\Support\ParticipantOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Event extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSING_SOON = 'closing_soon';
    public const STATUS_FULL = 'full';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Status slug => label shown in the admin and on the public card.
     */
    public const STATUSES = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_OPEN => 'Open for Registration',
        self::STATUS_CLOSING_SOON => 'Closing Soon',
        self::STATUS_FULL => 'Fully Booked',
        self::STATUS_CLOSED => 'Registration Closed',
        self::STATUS_CANCELLED => 'Cancelled',
    ];

    /**
     * Statuses a visitor is allowed to register against.
     */
    public const REGISTERABLE = [
        self::STATUS_OPEN,
        self::STATUS_CLOSING_SOON,
    ];

    public const MODE_INDIVIDUAL = 'individual';
    public const MODE_MANAGER = 'manager';

    public const MODES = [
        self::MODE_INDIVIDUAL => 'Individual — one person, one registration',
        self::MODE_MANAGER => 'Manager — a manager registers a squad of players',
    ];

    protected $fillable = [
        'slug',
        'title',
        'category',
        'description',
        'starts_at',
        'ends_at',
        'time',
        'location',
        'address',
        'rules',
        'fee',
        'seats_total',
        'seats_taken',
        'requires_ign',
        'requires_logo',
        'status',
        'registration_mode',
        'min_players',
        'max_players',
        'registration_opens_at',
        'registration_closes_at',
        'image',
        'poster_path',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'date',
            'ends_at' => 'date',
            'registration_opens_at' => 'date',
            'registration_closes_at' => 'date',
            'fee' => 'decimal:2',
            'seats_total' => 'integer',
            'seats_taken' => 'integer',
            'min_players' => 'integer',
            'max_players' => 'integer',
            'requires_ign' => 'boolean',
            'requires_logo' => 'boolean',
        ];
    }

    /* ---------------------------------------------------------------------
     | Relations
     * ------------------------------------------------------------------ */

    public function registrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class);
    }

    public function participants()
    {
        return $this->hasManyThrough(EventParticipant::class, EventRegistration::class);
    }

    /** Everything extra a registrant may pay for, in display order. */
    public function addons(): HasMany
    {
        return $this->hasMany(EventAddon::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /** Everyone who has turned up. */
    public function attendances(): HasMany
    {
        return $this->hasMany(EventAttendance::class);
    }

    /** Players substituted at the counter for this event. */
    public function participantChanges(): HasMany
    {
        return $this->hasMany(EventParticipantChange::class);
    }

    /* ---------------------------------------------------------------------
     | Presentation helpers
     * ------------------------------------------------------------------ */

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function modeLabel(): string
    {
        return self::MODES[$this->registration_mode] ?? $this->registration_mode;
    }

    public function isManagerMode(): bool
    {
        return $this->registration_mode === self::MODE_MANAGER;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isFree(): bool
    {
        return $this->fee === null || (float) $this->fee <= 0;
    }

    public function feeLabel(): string
    {
        return $this->isFree() ? 'Free' : 'RM ' . number_format((float) $this->fee, 2);
    }

    /**
     * What one registration costs.
     *
     * The fee is charged per registration, not per head. A manager entering a
     * squad of any size pays the same as a single entrant, so the party size is
     * deliberately not a parameter here.
     */
    public function registrationAmount(): float
    {
        return $this->isFree() ? 0.0 : (float) $this->fee;
    }

    /**
     * How the fee should be described next to the amount.
     */
    public function feeBasisLabel(): string
    {
        return $this->isManagerMode() ? 'per team registration' : 'per registration';
    }

    /* ---------------------------------------------------------------------
     | In game identifiers
     * ------------------------------------------------------------------ */

    /**
     * Whether every person on a registration must give their game account.
     *
     * Applies to both modes: a squad needs one per player, and a solo entry
     * needs one for that person.
     */
    public function requiresIgn(): bool
    {
        return (bool) $this->requires_ign;
    }

    /* ---------------------------------------------------------------------
     | Rules
     * ------------------------------------------------------------------ */

    public function hasRules(): bool
    {
        return filled($this->rules);
    }

    /**
     * The rules split into lines, with blanks dropped.
     *
     * Returned as lines rather than one block so the view can render a list,
     * which is how a rule set reads. Escaping still happens in the view: this is
     * plain text from an administrator, never markup.
     *
     * @return array<int, string>
     */
    public function ruleLines(): array
    {
        if (! $this->hasRules()) {
            return [];
        }

        return collect(preg_split('/\r\n|\r|\n/', (string) $this->rules))
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Whether the person registering must upload a logo.
     *
     * One per entry, not one per person: a squad has a single crest, and a solo
     * entry a single image.
     */
    public function requiresLogo(): bool
    {
        return (bool) $this->requires_logo;
    }

    /**
     * What the logo is called on screen, which differs by mode.
     */
    public function logoLabel(): string
    {
        return $this->isManagerMode() ? 'Team Logo' : 'Logo';
    }

    /* ---------------------------------------------------------------------
     | Add-ons
     * ------------------------------------------------------------------ */

    /**
     * Add-ons that should be offered on the public form.
     *
     * Filtered in PHP rather than by a query so an already loaded relation is
     * reused, which matters on the registration modal where the same list is
     * walked several times.
     */
    public function purchasableAddons()
    {
        return $this->addons->filter(fn (EventAddon $addon) => $addon->isPurchasable())->values();
    }

    public function hasAddons(): bool
    {
        return $this->purchasableAddons()->isNotEmpty();
    }

    public function seatsLeft(): int
    {
        return max(0, $this->seats_total - $this->seats_taken);
    }

    public function filledPercent(): int
    {
        if ($this->seats_total <= 0) {
            return 0;
        }

        return min(100, (int) round($this->seats_taken / $this->seats_total * 100));
    }

    public function posterUrl(): ?string
    {
        return $this->poster_path ? Storage::disk('public')->url($this->poster_path) : null;
    }

    /**
     * Where the event sits in its lifecycle, worked out from the dates rather
     * than stored, so it can never drift out of sync with them.
     *
     * cancelled | completed | ongoing | upcoming
     */
    public function lifecycle(): string
    {
        if ($this->isCancelled()) {
            return 'cancelled';
        }

        $today = now()->startOfDay();

        if ($this->ends_at->lt($today)) {
            return 'completed';
        }

        if ($this->starts_at->lte($today)) {
            return 'ongoing';
        }

        return 'upcoming';
    }

    /* ---------------------------------------------------------------------
     | Registration gate
     * ------------------------------------------------------------------ */

    /**
     * Whether the status alone allows registration. Used for the card badge.
     */
    public function canRegister(): bool
    {
        return in_array($this->status, self::REGISTERABLE, true);
    }

    /**
     * Why registration is not possible, or null when it is.
     *
     * Every reason a submission could be rejected lives here, so the button on
     * the card and the server side check can never disagree.
     */
    public function registrationBlockedReason(): ?string
    {
        if ($this->isCancelled()) {
            return 'This event has been cancelled.';
        }

        if (! $this->canRegister()) {
            return 'Registration for this event is not open.';
        }

        if ($this->lifecycle() === 'completed') {
            return 'This event has already finished.';
        }

        $today = now()->startOfDay();

        if ($this->registration_opens_at && $this->registration_opens_at->gt($today)) {
            return 'Registration opens on ' . $this->registration_opens_at->format('d M Y') . '.';
        }

        if ($this->registration_closes_at && $this->registration_closes_at->lt($today)) {
            return 'Registration closed on ' . $this->registration_closes_at->format('d M Y') . '.';
        }

        if ($this->seats_total > 0 && $this->seatsLeft() <= 0) {
            return 'This event is fully booked.';
        }

        return null;
    }

    public function isRegistrationOpen(): bool
    {
        return $this->registrationBlockedReason() === null;
    }

    /**
     * How many players a manager may enter, as [min, max]. Max is null when
     * unlimited.
     *
     * @return array{0: int, 1: int|null}
     */
    public function playerBounds(): array
    {
        return [max(1, (int) ($this->min_players ?? 1)), $this->max_players];
    }

    /**
     * Roles a person on this event's registration may hold.
     *
     * The mode decides this outright, so the public form never asks the
     * visitor to pick. An individual entry is simply a participant, which
     * suits a course or conference as well as a match.
     *
     * @return array<string, string>
     */
    public function allowedParticipantRoles(): array
    {
        return $this->isManagerMode()
            ? ParticipantOptions::TEAM_ROLES
            : ParticipantOptions::INDIVIDUAL_ROLES;
    }

    /* ---------------------------------------------------------------------
     | Scopes used by the admin Registration tabs
     * ------------------------------------------------------------------ */

    /** Not cancelled and not finished: the working list of events. */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query
            ->where('status', '!=', self::STATUS_CANCELLED)
            ->whereDate('ends_at', '>=', now()->toDateString());
    }

    /** Running right now. */
    public function scopeOngoing(Builder $query): Builder
    {
        return $query
            ->where('status', '!=', self::STATUS_CANCELLED)
            ->whereDate('starts_at', '<=', now()->toDateString())
            ->whereDate('ends_at', '>=', now()->toDateString());
    }

    /** Finished, and was not cancelled. */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query
            ->where('status', '!=', self::STATUS_CANCELLED)
            ->whereDate('ends_at', '<', now()->toDateString());
    }

    public function scopeCancelled(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CANCELLED);
    }

    /**
     * Events a visitor is allowed to see at all.
     *
     * Drafts are not published and cancelled events are withdrawn, so neither
     * ever reaches the public site. Nothing is filtered by date here, because
     * the public page has a Past Events tab.
     */
    public function scopePubliclyListed(Builder $query): Builder
    {
        return $query->whereNotIn('status', [self::STATUS_DRAFT, self::STATUS_CANCELLED]);
    }

    /**
     * Has not begun yet.
     *
     * Deliberately narrower than scopeUpcoming(), which also covers events
     * already running. This one lines up with lifecycle() returning 'upcoming',
     * so a tab built on it can never disagree with the badge on the card.
     */
    public function scopeNotStarted(Builder $query): Builder
    {
        return $query
            ->where('status', '!=', self::STATUS_CANCELLED)
            ->whereDate('starts_at', '>', now()->toDateString());
    }
}
