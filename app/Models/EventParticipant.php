<?php

namespace App\Models;

use App\Support\ParticipantOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class EventParticipant extends Model
{
    protected $fillable = [
        'event_registration_id',
        'role',
        'also_plays',
        'full_name',
        'ic_number',
        'ign_player_id',
        'ign_server_id',
        'ign_name',
        'marketing_consent',
        'consent_recorded_at',
        'consent_ip',
        'address_line_1',
        'address_line_2',
        'postcode',
        'city',
        'state',
        'country',
        'phone',
        'email',
        'gender',
        'race',
        'date_of_birth',
        'emergency_contact_name',
        'emergency_contact_phone',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'marketing_consent' => 'boolean',
            'also_plays' => 'boolean',
            'consent_recorded_at' => 'datetime',
        ];
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class, 'event_registration_id');
    }

    /** Their arrival, or null while they have not turned up. */
    public function attendance(): HasOne
    {
        return $this->hasOne(EventAttendance::class, 'event_participant_id');
    }

    /** Every time this slot was swapped to a different person. */
    public function changes(): HasMany
    {
        return $this->hasMany(EventParticipantChange::class, 'event_participant_id')->latest();
    }

    public function hasCheckedIn(): bool
    {
        return $this->attendance !== null;
    }

    /**
     * Whether a counter may substitute someone else into this slot.
     *
     * Only players on a squad entry: the squad paid and its players are
     * interchangeable, whereas an individual entry belongs to the one person who
     * booked and paid for it. Once someone has been checked in the slot is
     * settled, so it is closed to changes.
     */
    public function isSwappable(): bool
    {
        return $this->role === ParticipantOptions::ROLE_PLAYER
            && ! $this->hasCheckedIn();
    }

    /**
     * Why a swap is not offered, or null when it is.
     */
    public function swapBlockedReason(): ?string
    {
        if ($this->hasCheckedIn()) {
            return 'Already checked in, so this place can no longer be changed.';
        }

        if ($this->role === ParticipantOptions::ROLE_MANAGER) {
            return 'The manager registered and paid for this entry, so they cannot be substituted here.';
        }

        if ($this->role !== ParticipantOptions::ROLE_PLAYER) {
            return 'This entry is for one named person, so it cannot be handed to someone else.';
        }

        return null;
    }

    /* ---------------------------------------------------------------------
     | Removal at the counter
     * ------------------------------------------------------------------ */

    /**
     * Whether a counter may take this person off the entry entirely.
     *
     * Same shape as a swap, and for the same reasons, with one addition: the
     * entry cannot be emptied. A registration with nobody on it would still hold
     * a paid place while describing no one, and there would be nothing left on
     * screen to undo it from.
     */
    public function isRemovable(): bool
    {
        return $this->removalBlockedReason() === null;
    }

    /**
     * How many people are on the entry this person belongs to.
     *
     * Reads a loaded relation when there is one, so the counter panel does not
     * fire a count query for every player in a squad.
     */
    private function entrySize(): int
    {
        $registration = $this->registration;

        if ($registration === null) {
            return 0;
        }

        return $registration->relationLoaded('participants')
            ? $registration->participants->count()
            : $registration->participants()->count();
    }

    /**
     * Why this person cannot be removed, or null when they can.
     */
    public function removalBlockedReason(): ?string
    {
        if ($this->hasCheckedIn()) {
            return 'Already checked in. Undo the check-in first if they are being taken off.';
        }

        if ($this->role === ParticipantOptions::ROLE_MANAGER) {
            return 'The manager registered and paid for this entry, so they cannot be removed here.';
        }

        if ($this->role !== ParticipantOptions::ROLE_PLAYER) {
            return 'This entry is for one named person, so removing them would leave nothing behind.';
        }

        if ($this->entrySize() <= 1) {
            return 'The last person on an entry cannot be removed. Delete the whole registration instead.';
        }

        return null;
    }

    public function isManager(): bool
    {
        return $this->role === ParticipantOptions::ROLE_MANAGER;
    }

    /* ---------------------------------------------------------------------
     | Who is on the roster
     * ------------------------------------------------------------------ */

    /**
     * Whether this person occupies a playing place.
     *
     * The single definition of that question. A manager who ticked "and Player"
     * is on the roster too, and before this flag existed the only way to say so
     * was to enter them a second time under their own identity card, which the
     * duplicate check refused.
     *
     * Every count of players, and every line-up, goes through here or through the
     * matching scope. Asking role === 'player' directly is what would leave a
     * playing manager out of a tournament draw.
     */
    public function isPlaying(): bool
    {
        return $this->role === ParticipantOptions::ROLE_PLAYER
            || ($this->isManager() && (bool) $this->also_plays);
    }

    /**
     * The same rule in SQL, for queries that cannot load the models first.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePlaying(Builder $query): Builder
    {
        return $query->where(function (Builder $inner) {
            $inner->where('role', ParticipantOptions::ROLE_PLAYER)
                ->orWhere(fn (Builder $manager) => $manager
                    ->where('role', ParticipantOptions::ROLE_MANAGER)
                    ->where('also_plays', true));
        });
    }

    /**
     * How this person's position reads on screen.
     *
     * A manager who also plays says so, because every list that shows this is read
     * by somebody deciding who is on the pitch. Calling them "Manager" alone would
     * hide the fact that they occupy a playing place.
     */
    public function roleLabel(): string
    {
        if ($this->isManager() && (bool) $this->also_plays) {
            return ParticipantOptions::LABEL_MANAGER_PLAYER;
        }

        return ParticipantOptions::ROLES[$this->role] ?? $this->role;
    }

    public function isParticipant(): bool
    {
        return $this->role === ParticipantOptions::ROLE_PARTICIPANT;
    }

    /**
     * These two can be blank on someone substituted in at a counter, where only
     * the identity card and a phone number are collected, so they fall back to a
     * dash rather than rendering as nothing.
     */
    public function genderLabel(): string
    {
        if (blank($this->gender)) {
            return '—';
        }

        return ParticipantOptions::GENDERS[$this->gender] ?? $this->gender;
    }

    public function raceLabel(): string
    {
        if (blank($this->race)) {
            return '—';
        }

        return ParticipantOptions::RACES[$this->race] ?? $this->race;
    }

    public function age(): ?int
    {
        return $this->date_of_birth?->age;
    }

    /* ---------------------------------------------------------------------
     | In game identifiers
     * ------------------------------------------------------------------ */

    public function hasIgn(): bool
    {
        return filled($this->ign_player_id)
            || filled($this->ign_server_id)
            || filled($this->ign_name);
    }

    /**
     * Game account as one readable line, for example "ShadowX (12345678) on Asia".
     *
     * Assembled from whichever parts are present rather than a fixed shape. Each
     * of the three fields is asked for independently, and an event can start
     * asking for one after people have already registered, so any combination is
     * legitimate, including none.
     */
    public function ignLabel(): string
    {
        if (! $this->hasIgn()) {
            return '—';
        }

        // The in-game name leads when there is one: it is what an organiser reads
        // off a scoreboard, while the id is what they use to look the account up.
        $head = filled($this->ign_name)
            ? $this->ign_name . (filled($this->ign_player_id) ? ' (' . $this->ign_player_id . ')' : '')
            : (string) $this->ign_player_id;

        if (blank($this->ign_server_id)) {
            return $head !== '' ? $head : '—';
        }

        return $head !== ''
            ? $head . ' on ' . $this->ign_server_id
            : 'Server ' . $this->ign_server_id;
    }

    /**
     * Address as a single readable line.
     */
    public function addressLine(): string
    {
        return collect([
            $this->address_line_1,
            $this->address_line_2,
            trim($this->postcode . ' ' . $this->city),
            $this->state,
            $this->country,
        ])->filter()->implode(', ');
    }
}
