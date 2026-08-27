<?php

namespace App\Models;

use App\Support\ParticipantOptions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class EventParticipant extends Model
{
    protected $fillable = [
        'event_registration_id',
        'role',
        'full_name',
        'ic_number',
        'ign_player_id',
        'ign_server_id',
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

    public function roleLabel(): string
    {
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
        return filled($this->ign_player_id) || filled($this->ign_server_id);
    }

    /**
     * Game account as one readable line, for example "12345678 on Asia".
     *
     * Either part may be missing: an event can be switched to require these
     * after people have registered, so an older row legitimately has neither.
     */
    public function ignLabel(): string
    {
        if (! $this->hasIgn()) {
            return '—';
        }

        if (blank($this->ign_server_id)) {
            return (string) $this->ign_player_id;
        }

        if (blank($this->ign_player_id)) {
            return 'Server ' . $this->ign_server_id;
        }

        return $this->ign_player_id . ' on ' . $this->ign_server_id;
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
