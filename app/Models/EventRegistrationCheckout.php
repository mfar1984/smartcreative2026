<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One checkout opened at the gateway for a registration.
 *
 * An attempt, not a payment. Most of these came to nothing: a payer who opened the
 * page and closed it, or one who let a QR code time out. What they are for is
 * answering "which purchases at the gateway belong to this entry", which is the
 * question that could not be answered when a second attempt overwrote the first.
 */
class EventRegistrationCheckout extends Model
{
    protected $fillable = [
        'event_registration_id',
        'purchase_id',
        'checkout_url',
        'gateway',
        'opened_at',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
        ];
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class, 'event_registration_id');
    }
}
