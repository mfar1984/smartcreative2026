<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One destination that appeared in a campaign body.
 *
 * Recorded before sending so a click can be resolved by id. This is the whole
 * defence against an open redirect: the tracker never reads a destination out of
 * the request, only out of this table, so the only places it can send somebody are
 * places that were in the message.
 */
class CampaignLink extends Model
{
    protected $fillable = [
        'campaign_id',
        'url',
        'click_count',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function clicks(): HasMany
    {
        return $this->hasMany(CampaignLinkClick::class);
    }

    /** How many different people clicked, as against how many clicks there were. */
    public function uniqueClicks(): int
    {
        return $this->clicks()->distinct('campaign_recipient_id')->count('campaign_recipient_id');
    }

    /** Shortened for a table, since a full URL breaks the column width. */
    public function shortUrl(int $length = 60): string
    {
        return \Illuminate\Support\Str::limit($this->url, $length);
    }
}
