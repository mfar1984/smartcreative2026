<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One question an organiser added to their own registration form.
 *
 * A terms agreement is this with is_required true; an optional survey question is
 * the same thing with it false. Nothing else distinguishes them, which is why
 * there is one table rather than two.
 */
class EventQuestion extends Model
{
    protected $fillable = [
        'event_id',
        'title',
        'body',
        'is_required',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(EventParticipantAnswer::class);
    }

    /**
     * The values to copy onto an answer, so the record keeps the wording that was
     * actually shown rather than whatever the question says later.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'event_question_id' => $this->id,
            'question_title' => $this->title,
            'question_body' => $this->body,
            'was_required' => $this->is_required,
        ];
    }

    public function requirementLabel(): string
    {
        return $this->is_required ? 'Compulsory' : 'Optional';
    }
}
