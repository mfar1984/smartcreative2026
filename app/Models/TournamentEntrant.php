<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One competitor in one tournament.
 *
 * Wraps an event registration rather than duplicating it. A team event's
 * registration is the squad, with its name, logo and players already recorded; an
 * individual event's registration is the person.
 */
class TournamentEntrant extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_ELIMINATED = 'eliminated';
    public const STATUS_DISQUALIFIED = 'disqualified';
    public const STATUS_WITHDRAWN = 'withdrawn';

    /** @var array<string, string> */
    public const STATUSES = [
        self::STATUS_ACTIVE => 'Active',
        self::STATUS_ELIMINATED => 'Eliminated',
        self::STATUS_DISQUALIFIED => 'Disqualified',
        self::STATUS_WITHDRAWN => 'Withdrawn',
    ];

    protected $fillable = [
        'tournament_id',
        'event_registration_id',
        'seed',
        'status',
        'added_by_hand',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'seed' => 'integer',
            'added_by_hand' => 'boolean',
        ];
    }

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class, 'event_registration_id');
    }

    /**
     * What to call this competitor on a bracket, a table or a public page.
     *
     * Falls through team name, then the first person on the entry, then the
     * reference, because an individual entry has no team name and a table with a
     * blank row in it is worse than one showing a reference.
     */
    public function displayName(): string
    {
        $registration = $this->registration;

        if ($registration === null) {
            return 'Removed entry';
        }

        if (filled($registration->team_name)) {
            return $registration->team_name;
        }

        $first = $registration->participants->sortBy('id')->first();

        return $first?->full_name ?: $registration->reference;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Whether this competitor may still be given a fixture.
     *
     * Disqualified and withdrawn entrants stay on the table for the record, but
     * their remaining matches are walkovers for the opponent.
     */
    public function isOut(): bool
    {
        return in_array($this->status, [
            self::STATUS_DISQUALIFIED,
            self::STATUS_WITHDRAWN,
        ], true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
