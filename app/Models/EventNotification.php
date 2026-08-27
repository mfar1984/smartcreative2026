<?php

namespace App\Models;

use App\Support\EventTemplates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventNotification extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    public const STATUSES = [
        self::STATUS_QUEUED => 'Queued',
        self::STATUS_SENT => 'Sent',
        self::STATUS_FAILED => 'Failed',
        self::STATUS_SKIPPED => 'Not sent',
    ];

    protected $fillable = [
        'event_registration_id',
        'template_key',
        'channel',
        'recipient',
        'provider_message_id',
        'delivery_status',
        'delivery_detail',
        'delivered_at',
        'participant_ids',
        'status',
        'reason',
        'queued_at',
        'sent_at',
        'triggered_by',
    ];

    protected function casts(): array
    {
        return [
            'participant_ids' => 'array',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class, 'event_registration_id');
    }

    /** The administrator who asked for a resend, if it was not automatic. */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function templateLabel(): string
    {
        return EventTemplates::definition($this->template_key)['label'] ?? $this->template_key;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /**
     * How many people this one message spoke for.
     */
    public function coversCount(): int
    {
        return count($this->participant_ids ?? []);
    }

    /**
     * The names it covered, read back from the participant rows that still
     * exist. Someone swapped out at the counter simply drops off the list.
     *
     * @return array<int, string>
     */
    public function coveredNames(): array
    {
        $ids = $this->participant_ids ?? [];

        if ($ids === []) {
            return [];
        }

        return EventParticipant::query()
            ->whereKey($ids)
            ->orderBy('id')
            ->pluck('full_name')
            ->all();
    }

    public function wasSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }
}
