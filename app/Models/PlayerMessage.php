<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message written on a competitor's public profile.
 *
 * Goes to the organiser, never to the competitor. Whoever wrote it was shown a masked
 * address and nothing more, so this record is the only place the two sides meet.
 */
class PlayerMessage extends Model
{
    protected $fillable = [
        'event_participant_id',
        'name',
        'email',
        'phone',
        'message',
        'ip_address',
    ];

    public function participant(): BelongsTo
    {
        return $this->belongsTo(EventParticipant::class, 'event_participant_id');
    }
}
