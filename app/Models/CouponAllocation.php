<?php

namespace App\Models;

use App\Support\CouponHolderIdentity;
use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ONE ISSUE of codes: how many, to whom, when.
 *
 * This is the unit the owner actually works in. He creates a coupon in unique mode and
 * says "a hundred codes, handled by Siti" — that is allocation one. A fortnight later
 * he says "another hundred, handled by Ahmad" — that is allocation two, with its own
 * holder, NOT a top-up of the first. A single growing quantity could not express that,
 * which is exactly why the quantity field confused this feature the first time round.
 *
 * WHAT IT BUYS BESIDES TIDINESS
 *
 * The redemption rule falls out of it for free. A typed code identifies its allocation,
 * so "the uses come from that holder's own allocation" is simply "counted inside this
 * block". A group of ten against a block with five unused codes is refused and told
 * whose block and how many are left, even when the batch as a whole has hundreds going
 * spare. That check happens inside CouponRedeemer's locked transaction, on these rows.
 *
 * Reporting follows the same line: "whose codes are finished and whose are untouched"
 * is one row per allocation, which is the NGO's whole question about its ten
 * representatives.
 *
 * Nobody tags codes one at a time. Editing the holder here moves every code in the
 * block, because they were handed out together.
 */
class CouponAllocation extends Model
{
    protected $fillable = [
        'coupon_id',
        'coupon_holder_id',
        'sponsor_user_id',
        'quantity',
        'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'issued_at' => 'datetime',
        ];
    }

    /* ---------------------------------------------------------------------
     | Relations
     * ------------------------------------------------------------------ */

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /** Whoever handles this block, or null for an unassigned one. */
    public function holder(): BelongsTo
    {
        return $this->belongsTo(CouponHolder::class, 'coupon_holder_id');
    }

    /**
     * Whoever FUNDED this block OUTRIGHT: a sponsorship account, or null.
     *
     * Not to be confused with the holder. The holder hands the codes out; the
     * sponsor paid for them, and is the one who gets a monitor-only screen showing
     * this block and nothing else. One sponsor per block, so a discount is never
     * counted on two sponsors' screens at once.
     *
     * Null here does NOT mean unsponsored. It means this block follows its batch —
     * see effectiveSponsorId() for the rule, which is the only thing that should be
     * asked "whose block is this".
     */
    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sponsor_user_id');
    }

    /** Whether this block names its own sponsorship rather than following the batch. */
    public function hasSponsor(): bool
    {
        return $this->sponsor_user_id !== null;
    }

    /* ---------------------------------------------------------------------
     | Whose block it is: the one rule, in one place
     * ------------------------------------------------------------------ */

    /**
     * THE RULE, and it is the only one.
     *
     *   THE BATCH-LEVEL SPONSORSHIP APPLIES TO EVERY BLOCK IN THE BATCH, UNLESS
     *   THAT BLOCK NAMES ITS OWN, WHICH OVERRIDES IT FOR THAT BLOCK ONLY.
     *
     * Two places that can set the same thing is exactly how the quantity field
     * confused this feature the first time round, so the precedence is stated here,
     * stated in SQL by scopeSponsoredBy() below, and stated on both screens that can
     * set it. Nothing is copied down into the blocks when the batch is tagged: a
     * block left blank resolves through its batch every time it is read, so moving
     * the batch's sponsorship moves its blocks with it and a block deliberately
     * pointed elsewhere stays pointed there.
     */
    public function effectiveSponsorId(): ?int
    {
        $own = $this->sponsor_user_id;

        return $own !== null ? (int) $own : $this->coupon?->sponsor_user_id;
    }

    /** The sponsorship account this block answers to, batch-level included. */
    public function effectiveSponsor(): ?User
    {
        return $this->sponsor ?? $this->coupon?->sponsor;
    }

    /** Whether the block's sponsorship comes from its batch rather than from itself. */
    public function inheritsSponsor(): bool
    {
        return $this->sponsor_user_id === null && $this->coupon?->sponsor_user_id !== null;
    }

    /**
     * The same rule in SQL: blocks that answer to one sponsorship account.
     *
     * Either the block names them, or the block names nobody and its batch names
     * them. A block pointed at somebody else is excluded by the first branch and not
     * rescued by the second, which is what makes the override an override.
     */
    public function scopeSponsoredBy(Builder $query, int $sponsorId): Builder
    {
        return $query->where(fn (Builder $outer) => $outer
            ->where('coupon_allocations.sponsor_user_id', $sponsorId)
            ->orWhere(fn (Builder $inherited) => $inherited
                ->whereNull('coupon_allocations.sponsor_user_id')
                ->whereHas('coupon', fn ($coupon) => $coupon->where('coupons.sponsor_user_id', $sponsorId))));
    }

    public function codes(): HasMany
    {
        return $this->hasMany(CouponIssuedCode::class)->orderBy('id');
    }

    /* ---------------------------------------------------------------------
     | Reading
     * ------------------------------------------------------------------ */

    /** Who handles it, in words. An unassigned block gets a clear label, not a blank. */
    public function holderLabel(): string
    {
        return $this->holder?->label() ?? CouponHolderIdentity::UNASSIGNED;
    }

    public function hasHolder(): bool
    {
        return $this->coupon_holder_id !== null;
    }

    /** Codes in this block that have been spent. */
    public function usedCount(): int
    {
        return $this->codes()->used()->count();
    }

    /** Codes still to be used: the balance a claim is checked against. */
    public function unusedCount(): int
    {
        return $this->codes()->unused()->count();
    }

    /** How many codes were actually minted, which is the honest denominator. */
    public function codeCount(): int
    {
        return $this->codes()->count();
    }

    public function isExhausted(): bool
    {
        return $this->unusedCount() === 0;
    }

    public function isUntouched(): bool
    {
        return $this->usedCount() === 0;
    }

    /**
     * Where the block stands, in one word, for a badge.
     *
     * The NGO's question answered in a glance: finished, started, or not touched.
     */
    public function stateLabel(): string
    {
        if ($this->isExhausted()) {
            return 'Finished';
        }

        return $this->isUntouched() ? 'Untouched' : 'In use';
    }

    public function stateTone(): string
    {
        if ($this->isExhausted()) {
            return 'amber';
        }

        return $this->isUntouched() ? 'gray' : 'green';
    }

    /** When the block went out, on the office clock. A real instant, so it is shifted. */
    public function issuedLabel(): string
    {
        return LocalTime::format($this->issued_at);
    }
}
