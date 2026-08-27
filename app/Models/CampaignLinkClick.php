<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One press of one link.
 *
 * Kept per press rather than only as a counter so a report can say whether ten
 * clicks were ten people or one person going back and forth.
 */
class CampaignLinkClick extends Model
{
    protected $fillable = [
        'campaign_link_id',
        'campaign_recipient_id',
        'clicked_at',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'clicked_at' => 'datetime',
        ];
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(CampaignLink::class, 'campaign_link_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(CampaignRecipient::class, 'campaign_recipient_id');
    }
}
