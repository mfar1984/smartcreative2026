<?php

namespace App\Models;

use App\Support\PaymentFigures;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One use of a coupon: the redemption ledger.
 *
 * A row here is written at the moment somebody redeems, never before. It records what
 * was typed, when, what it came to in ringgit, and which registration or order it paid
 * for — which is everything Tracking and Report read.
 *
 * `code` holds a copy of the string as it was typed, which under the current model is
 * the batch name. Stored rather than read off the batch so the audit trail survives a
 * rename, and so per-person codes could be added later without this table changing
 * shape. It stays nullable for the rows written before the ledger existed.
 */
class CouponCode extends Model
{
    protected $fillable = [
        'coupon_id',
        'code',
        'redeemed_at',
        'discount_amount',
        'event_registration_id',
        'shop_order_id',
    ];

    protected function casts(): array
    {
        return [
            'redeemed_at' => 'datetime',
            'discount_amount' => 'decimal:2',
        ];
    }

    /* ---------------------------------------------------------------------
     | Relations
     * ------------------------------------------------------------------ */

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class, 'event_registration_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ShopOrder::class, 'shop_order_id');
    }

    /* ---------------------------------------------------------------------
     | Reading
     * ------------------------------------------------------------------ */

    public function isRedeemed(): bool
    {
        return $this->redeemed_at !== null;
    }

    /**
     * What somebody typed to use this.
     *
     * Falls back to the batch name, which is the code, for a row stored before this
     * column was filled in.
     */
    public function codeLabel(): string
    {
        return $this->code ?? ($this->coupon?->name ?? '—');
    }

    public function discountLabel(): string
    {
        return PaymentFigures::money((float) $this->discount_amount);
    }

    /**
     * Our own reference for whatever this discounted, or null when nothing yet.
     */
    public function usedOnReference(): ?string
    {
        return $this->registration?->reference ?? $this->order?->reference;
    }

    /**
     * What the discount was applied to, in words: the event or the order.
     */
    public function usedOnLabel(): string
    {
        if ($this->registration !== null) {
            return $this->registration->event?->title ?? 'Event registration';
        }

        if ($this->order !== null) {
            return 'Shop order';
        }

        return '—';
    }

    /* ---------------------------------------------------------------------
     | Scopes
     * ------------------------------------------------------------------ */

    public function scopeRedeemed(Builder $query): Builder
    {
        return $query->whereNotNull('redeemed_at');
    }
}
