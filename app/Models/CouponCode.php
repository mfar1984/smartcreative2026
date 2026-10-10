<?php

namespace App\Models;

use App\Support\LocalTime;
use App\Support\PaymentFigures;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One use of a coupon: the redemption ledger. ONE ROW PER PARTICIPANT COVERED.
 *
 * A row here is written at the moment somebody redeems, never before. It records what
 * was typed, when, what it came to in ringgit, and which registration or order it paid
 * for — which is everything Tracking and Report read.
 *
 * A USE IS A PARTICIPANT, not a registration, on an event that charges per
 * participant. A group of ten entering one code once writes TEN rows: all ten point at
 * the same registration, all ten carry the same typed code, each one names the person
 * it covered and holds that person's share of the discount. On an event that does not
 * charge per participant, one registration is still one row.
 *
 * Three things fall out of that shape, which is why it was chosen:
 *
 *   "show me the ten people under this coupon" is a query on this table and nothing
 *   more.
 *
 *   the use cap is a row count, which is what CouponRedeemer's lock already
 *   serialises — no second counter to keep in step with the money.
 *
 *   the shares add up to the discount on the registration exactly, so Report's
 *   "given away" total is unchanged by the split.
 *
 * `code` holds a copy of the string as it was typed: the batch name for a shared
 * batch, or the individual code for a unique one. Stored rather than read off the
 * batch so the audit trail survives a rename. It stays nullable for the rows written
 * before the ledger existed.
 *
 * `participant_name` IS THE REDEEMER, AND IT IS A NAME ONLY.
 *
 * Not their IC, not their phone, not anything else on the registration. A redeemer is
 * a member of the public; a sponsor-facing view reads these rows, and what this table
 * can reach is what that view can leak. The representative who handed the code out is
 * a different person entirely and lives on CouponHolder, with contact details, because
 * the office does need to trace them.
 *
 * `buyer_name` IS THE SAME THING FOR THE SHOP SIDE, under the same rule.
 *
 * A shop redemption has no participant: the person who used the code is whoever placed
 * the order. Their name is copied here for exactly the reason the participant's is —
 * so the sponsor's screen never has to query shop_orders, which also holds a delivery
 * address, a phone number, an email and what the order came to. A NAME ONLY. What was
 * bought is read from the order's items, because a product name is catalogue
 * information and carries none of that risk.
 */
class CouponCode extends Model
{
    protected $fillable = [
        'coupon_id',
        'code',
        'redeemed_at',
        'discount_amount',
        'event_registration_id',
        'event_participant_id',
        'participant_name',
        'shop_order_id',
        'buyer_name',
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

    /**
     * The minted code this use was paid for with, or null on a shared batch.
     *
     * One ledger row is paired to at most one issued code, which is what makes
     * "whose block spent this" a single hop. A shared batch mints nothing, so the
     * answer there is genuinely null rather than missing: the name on the poster was
     * typed, and there is no block behind it.
     */
    public function issuedCode(): HasOne
    {
        return $this->hasOne(CouponIssuedCode::class, 'coupon_code_id');
    }

    /* ---------------------------------------------------------------------
     | Reading
     * ------------------------------------------------------------------ */

    public function isRedeemed(): bool
    {
        return $this->redeemed_at !== null;
    }

    /**
     * Who handed out the code this use spent, or null when nobody did.
     *
     * Null for a shared batch, where there is no block and so no representative.
     * Deliberately not a dash or a blank: the caller decides how "there is no
     * handler because there is no block" should read on its own screen.
     */
    public function holderLabel(): ?string
    {
        return $this->issuedCode?->holderLabel();
    }

    /** When it was used, on the office clock. A real instant, so it is shifted. */
    public function redeemedAtLabel(): string
    {
        return $this->redeemed_at === null ? '—' : LocalTime::format($this->redeemed_at);
    }

    /**
     * Who used this, by name, on whichever side of the system it was used.
     *
     * One method rather than two columns read in two places, because "who used it" is
     * one question and the answer is one name: the participant on an event, the buyer
     * on a shop order. Null when neither is known — a row written before the columns
     * existed, or a group use that covers no one head in particular — so the caller
     * decides how that reads on its own screen.
     */
    public function redeemerName(): ?string
    {
        return $this->participant_name ?? $this->buyer_name;
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
