<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person who turned up.
 *
 * The absence of a row is what absence means, so nothing here records a no
 * show. See the migration for why.
 */
class EventAttendance extends Model
{
    protected $fillable = [
        'event_id',
        'event_registration_id',
        'event_participant_id',
        'checked_in_at',
        'checked_in_by',
        'ic_verified',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'checked_in_at' => 'datetime',
            'ic_verified' => 'boolean',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class, 'event_registration_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(EventParticipant::class, 'event_participant_id');
    }

    /** The counter operator who let them in, if that account still exists. */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }

    public function recordedByName(): string
    {
        return $this->recordedBy?->name ?? 'Removed account';
    }

    /**
     * How the arrival was confirmed, for the Present list.
     */
    public function methodLabel(): string
    {
        return $this->ic_verified ? 'Identity card checked' : 'No identity card produced';
    }
}
