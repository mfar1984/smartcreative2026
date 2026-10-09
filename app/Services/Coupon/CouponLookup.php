<?php

namespace App\Services\Coupon;

use App\Models\Coupon;
use App\Models\CouponCode;

/**
 * What a typed string turned out to be, before anything is claimed.
 *
 * The answer to "is this code worth anything here", which is a different question
 * from "give me one use of it". A lookup reads; CouponRedeemer writes. Keeping them
 * apart is what lets the public form check a code as it is typed without spending it,
 * and is why nothing in here takes a lock.
 *
 * ADVISORY ONLY
 *
 * Everything this says can be stale by the time the form is submitted: the last code
 * can go, or the batch can be edited, in the seconds between. The claim re-reads all
 * of it inside its own transaction, so a lookup is never the authority on whether a
 * discount is given — only on what to show somebody now.
 *
 * Failure statuses are CouponOutcome's own constants, and the wording comes from
 * there too, so a visitor is told the same thing whichever end refused them.
 */
readonly class CouponLookup
{
    public function __construct(
        public string $status,
        public ?Coupon $coupon = null,
        public ?CouponCode $code = null,
    ) {
    }

    /**
     * @param  CouponCode|null  $code  the minted row, or null for an unlimited batch
     *         where the batch name is the shared code and there is no row until use.
     */
    public static function found(Coupon $coupon, ?CouponCode $code = null): self
    {
        return new self(CouponOutcome::OK, $coupon, $code);
    }

    public static function failed(string $status): self
    {
        return new self($status);
    }

    public function succeeded(): bool
    {
        return $this->status === CouponOutcome::OK && $this->coupon !== null;
    }

    /** What somebody typed, or would type, to use this. */
    public function typedCode(): ?string
    {
        return $this->code?->code ?? $this->coupon?->name;
    }

    /** What to tell whoever typed it, in the words CouponOutcome already uses. */
    public function message(): string
    {
        return CouponOutcome::failed($this->status)->message();
    }
}
