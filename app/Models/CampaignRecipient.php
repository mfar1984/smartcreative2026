<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One campaign, sent to one address.
 *
 * The token on this row is what a tracking pixel, a click and an unsubscribe link
 * all carry, which is why it is per send rather than per contact: a report has to
 * be able to say which message was opened, not merely that somebody once opened
 * something.
 */
class CampaignRecipient extends Model
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
        'campaign_id',
        'campaign_contact_id',
        'address',
        'is_test',
        'provider_message_id',
        'delivery_status',
        'delivery_detail',
        'delivered_at',
        'status',
        'reason',
        'token',
        'sent_at',
        'opened_at',
        'open_count',
        'clicked_at',
        'click_count',
        'unsubscribed_at',
    ];

    protected function casts(): array
    {
        return [
            'is_test' => 'boolean',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'opened_at' => 'datetime',
            'clicked_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }

    /**
     * Whether the gateway confirmed it reached a handset.
     *
     * Distinct from wasSent(): that one only means we handed it over. Null when no
     * report has arrived, which for a fresh send is the normal state.
     */
    public function wasDelivered(): bool
    {
        return $this->delivered_at !== null;
    }

    /**
     * What is actually known about where this message got to.
     */
    public function deliveryLabel(): string
    {
        if ($this->delivered_at !== null) {
            return 'Delivered';
        }

        return match ($this->delivery_status) {
            null => $this->status === self::STATUS_SENT ? 'Handed over, no report yet' : '—',
            'PENDING' => 'On its way',
            'UNDELIVERABLE' => 'Could not be delivered',
            'EXPIRED' => 'Gave up trying',
            'REJECTED' => 'Refused by the network',
            default => $this->delivery_status,
        };
    }

    protected static function booted(): void
    {
        static::creating(function (self $recipient) {
            $recipient->token ??= Str::random(48);
        });
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(CampaignContact::class, 'campaign_contact_id');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function wasOpened(): bool
    {
        return $this->opened_at !== null;
    }

    public function wasClicked(): bool
    {
        return $this->clicked_at !== null;
    }
}
