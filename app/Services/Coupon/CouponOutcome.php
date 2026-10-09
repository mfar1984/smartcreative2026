<?php

namespace App\Services\Coupon;

use App\Models\CouponCode;
use App\Support\PaymentFigures;

/**
 * What came of trying to use a coupon.
 *
 * A returned value rather than an exception, because every one of these is an ordinary
 * thing for a visitor to run into: the code ran out while they were filling the form
 * in, the batch expired at midnight, they pressed submit twice. The public form has to
 * be able to fall back to the normal price without catching anything, which is also
 * why ranOut() is its own answer rather than a generic failure.
 */
readonly class CouponOutcome
{
    public const OK = 'ok';

    /** No code left in the batch. The caller charges the normal price. */
    public const RAN_OUT = 'ran_out';

    public const EXPIRED = 'expired';

    /** The code exists and somebody has already used it. */
    public const ALREADY_USED = 'already_used';

    /** Nothing in the system answers to that string. */
    public const NOT_FOUND = 'not_found';

    /** An event coupon offered against an order, or the other way round. */
    public const WRONG_KIND = 'wrong_kind';

    /** Nothing to discount, so nothing was claimed. */
    public const NOTHING_TO_DISCOUNT = 'nothing_to_discount';

    public function __construct(
        public string $status,
        public ?CouponCode $code = null,
        public float $discount = 0.0,
    ) {
    }

    public static function ok(CouponCode $code, float $discount): self
    {
        return new self(self::OK, $code, $discount);
    }

    public static function failed(string $status): self
    {
        return new self($status);
    }

    public function succeeded(): bool
    {
        return $this->status === self::OK;
    }

    /**
     * Whether the batch has nothing left to give.
     *
     * The one failure the public form treats specially: the price simply goes back to
     * normal and the Payment button comes back.
     */
    public function ranOut(): bool
    {
        return $this->status === self::RAN_OUT;
    }

    /** What to tell whoever typed the code. */
    public function message(): string
    {
        return match ($this->status) {
            self::OK => sprintf('%s off.', PaymentFigures::money($this->discount)),
            self::RAN_OUT => 'That coupon has been fully used, so the normal price applies.',
            self::EXPIRED => 'That coupon has expired, so the normal price applies.',
            self::ALREADY_USED => 'That coupon code has already been used.',
            self::WRONG_KIND => 'That coupon cannot be used here.',
            self::NOTHING_TO_DISCOUNT => 'There is nothing to discount.',
            default => 'That coupon code was not recognised.',
        };
    }
}
