<?php

namespace App\Models;

use App\Support\CampaignAudience;
use App\Support\EventTemplates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_SENDING => 'Sending',
        self::STATUS_SENT => 'Sent',
        self::STATUS_CANCELLED => 'Cancelled',
    ];

    protected $fillable = [
        'name',
        'channel',
        'campaign_template_id',
        'subject',
        'body',
        'audience_type',
        'audience_event_id',
        'audience_contact_ids',
        'status',
        'recipients_total',
        'sent_count',
        'failed_count',
        'opened_count',
        'clicked_count',
        'unsubscribed_count',
        'started_at',
        'finished_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'audience_contact_ids' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Whether the recipients were named one by one rather than left to a rule.
     */
    public function hasPickedRecipients(): bool
    {
        return is_array($this->audience_contact_ids) && $this->audience_contact_ids !== [];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(CampaignTemplate::class, 'campaign_template_id');
    }

    public function audienceEvent(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'audience_event_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    public function links(): HasMany
    {
        return $this->hasMany(CampaignLink::class);
    }

    public function isEmail(): bool
    {
        return $this->channel === EventTemplates::CHANNEL_EMAIL;
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /** Whether it can still be edited or sent. */
    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function channelLabel(): string
    {
        return $this->isEmail() ? 'Email' : 'SMS';
    }

    public function audienceLabel(): string
    {
        $label = CampaignAudience::label($this->audience_type, $this->audienceEvent?->title);

        // Said plainly, because "One event" would suggest everybody on it received
        // the message when in fact somebody chose a handful of them by hand.
        if ($this->hasPickedRecipients()) {
            return sprintf(
                '%d chosen from %s',
                count($this->audience_contact_ids),
                lcfirst($label),
            );
        }

        return $label;
    }

    /* ---------------------------------------------------------------------
     | Figures
     * ------------------------------------------------------------------ */

    /**
     * Percentages are worked out against what was actually sent, not against the
     * audience size. A message that failed was never open to being opened, and
     * counting it in the denominator would understate the rate.
     */
    public function openRate(): ?float
    {
        return $this->sent_count > 0
            ? round($this->opened_count / $this->sent_count * 100, 1)
            : null;
    }

    public function clickRate(): ?float
    {
        return $this->sent_count > 0
            ? round($this->clicked_count / $this->sent_count * 100, 1)
            : null;
    }

    /**
     * Clicks as a share of opens, which is the more useful of the two: it says
     * whether the message persuaded the people who actually read it.
     */
    public function clickThroughRate(): ?float
    {
        return $this->opened_count > 0
            ? round($this->clicked_count / $this->opened_count * 100, 1)
            : null;
    }

    /**
     * Open tracking cannot be trusted as a count of readers.
     *
     * It works by loading an image, and most mail clients block images by
     * default, while Apple Mail Privacy Protection loads them all whether or not
     * anybody looked. The figure is a floor with noise on top, not a headcount,
     * and the report says so rather than presenting it as fact.
     */
    public function opensAreApproximate(): bool
    {
        return $this->isEmail();
    }
}
