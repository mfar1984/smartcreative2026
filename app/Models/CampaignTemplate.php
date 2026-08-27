<?php

namespace App\Models;

use App\Support\EventTemplates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reusable campaign wording.
 *
 * Separate from event_templates because the two answer different questions. Those
 * cover fixed moments decided by code, so the set of them never grows. These are
 * written whenever somebody has something to announce, and there can be any
 * number.
 */
class CampaignTemplate extends Model
{
    protected $fillable = [
        'name',
        'channel',
        'subject',
        'body',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isEmail(): bool
    {
        return $this->channel === EventTemplates::CHANNEL_EMAIL;
    }

    public function channelLabel(): string
    {
        return $this->isEmail() ? 'Email' : 'SMS';
    }

    /**
     * How many SMS segments this would cost, or null for email.
     *
     * Shown next to the editor because a template that quietly runs to two
     * segments doubles the bill on every campaign that uses it, and nothing else
     * on the screen would say so.
     */
    public function smsSegments(): ?int
    {
        if ($this->isEmail()) {
            return null;
        }

        $length = mb_strlen($this->body);

        // A single message holds 160 GSM characters. Once it splits, each part
        // carries a header and only 153 are left for text.
        return $length <= 160 ? 1 : (int) ceil($length / 153);
    }
}
