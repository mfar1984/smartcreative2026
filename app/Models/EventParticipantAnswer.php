<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one person answered to one question, and what they were asked.
 *
 * The wording is copied here rather than read back through the question, so an
 * organiser editing their terms cannot change what somebody already agreed to.
 * The question itself may even be deleted; this row still reads as itself.
 */
class EventParticipantAnswer extends Model
{
    protected $fillable = [
        'event_participant_id',
        'event_question_id',
        'question_title',
        'question_body',
        'was_required',
        'answered',
        'answered_at',
    ];

    protected function casts(): array
    {
        return [
            'was_required' => 'boolean',
            'answered' => 'boolean',
            'answered_at' => 'datetime',
        ];
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(EventParticipant::class, 'event_participant_id');
    }

    /**
     * The question this came from, or null once it has been deleted. Callers must
     * read the snapshot rather than this relation for anything they display.
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(EventQuestion::class, 'event_question_id');
    }

    public function answerLabel(): string
    {
        return $this->answered ? 'Yes' : 'No';
    }
}
