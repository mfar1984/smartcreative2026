<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change a counter made to who is playing.
 *
 * Three kinds, and the difference matters to whoever reads the audit later.
 * A swap is a squad naming somebody else for a place it already holds. A
 * transfer is a player leaving one team in this event for another, which means
 * a place elsewhere was vacated. A removal is a player who is not coming at all.
 *
 * The names and card numbers are copied in rather than read back through the
 * participant, because the same row may be changed again later and the audit has
 * to keep saying what happened on each occasion.
 */
class EventParticipantChange extends Model
{
    /** A squad put a different person into a place it already held. */
    public const TYPE_SWAP = 'swap';

    /** A player moved here from another team in the same event. */
    public const TYPE_TRANSFER = 'transfer';

    /** A player was taken off the entry and nobody replaced them. */
    public const TYPE_REMOVED = 'removed';

    public const TYPES = [
        self::TYPE_SWAP => 'Substitution',
        self::TYPE_TRANSFER => 'Transfer',
        self::TYPE_REMOVED => 'Removed',
    ];

    protected $fillable = [
        'event_id',
        'event_registration_id',
        'from_registration_id',
        'event_participant_id',
        'type',
        'previous_name',
        'previous_ic',
        'new_name',
        'new_ic',
        'details_before',
        'details_after',
        'reason',
        'changed_by',
    ];

    protected function casts(): array
    {
        return [
            'details_before' => 'array',
            'details_after' => 'array',
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

    /** Null once the participant row itself has been deleted. */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(EventParticipant::class, 'event_participant_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function changedByName(): string
    {
        return $this->changedBy?->name ?? 'Removed account';
    }

    /** Who the entry is against: the team name, or the person for a solo entry. */
    public function subject(): string
    {
        return $this->registration?->displayName() ?? '—';
    }

    /** The team a transferred player came from. Null for the other two kinds. */
    public function fromRegistration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class, 'from_registration_id');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function isTransfer(): bool
    {
        return $this->type === self::TYPE_TRANSFER;
    }

    public function isRemoval(): bool
    {
        return $this->type === self::TYPE_REMOVED;
    }

    /**
     * Where a transferred player came from, by name.
     *
     * Falls back to a dash rather than null so the audit column always reads as
     * something, including after that team's entry has since been deleted.
     */
    public function fromLabel(): string
    {
        if (! $this->isTransfer()) {
            return '—';
        }

        return $this->fromRegistration?->displayName() ?? 'An entry since deleted';
    }
}
