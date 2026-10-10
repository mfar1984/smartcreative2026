<?php

namespace App\Services\Coupon;

use App\Models\CouponCode;
use App\Support\PaymentFigures;
use Illuminate\Support\Str;

/**
 * What came of trying to use a coupon.
 *
 * A returned value rather than an exception, because every one of these is an ordinary
 * thing for a visitor to run into: the code ran out while they were filling the form
 * in, the batch expired at midnight, they pressed submit twice. The public form has to
 * be able to fall back to the normal price without catching anything, which is also
 * why ranOut() is its own answer rather than a generic failure.
 *
 * A USE IS A PARTICIPANT, so a success is not one row any more
 *
 * On an event that charges per participant, a group of ten entering one code writes ten
 * ledger rows and charges ten uses. `codes` is every one of them and `uses` is how many
 * there were; `code` stays the FIRST row, because that is what the registration points
 * at and what every existing caller reads.
 *
 * A REFUSAL CARRIES ITS NUMBERS
 *
 * "That coupon has been fully used" is the right thing to say when nothing is left. It
 * is the wrong thing to say to a group of ten standing in front of five remaining
 * uses — they need to be told it is five, because the operator's next move is to issue
 * more or split the group. So a short refusal carries the balance, what was needed, and
 * whose block it was, and message() says so.
 *
 * Nothing is part-applied. Five discounted and five charged would leave the
 * registration holding one discount figure with no record of WHICH five it covered, so
 * a later Recheck Totals or a removed participant would have nothing to recompute
 * against — the phantom-money shape this project has already chased twice.
 */
readonly class CouponOutcome
{
    public const OK = 'ok';

    /** Not enough uses left in the batch, or in the holder's block. */
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

    /**
     * A unique-code batch reached without an individual code.
     *
     * Its name is not a code: only a code that was actually issued to somebody
     * redeems, because the code is what says whose allocation to draw from.
     */
    public const NEEDS_CODE = 'needs_code';

    /**
     * @param  array<int, CouponCode>  $codes  every ledger row written, one per participant
     * @param  int  $uses  how many uses were charged
     * @param  int|null  $remaining  on a short refusal, what was actually left
     * @param  int|null  $needed  on a short refusal, how many were wanted
     * @param  string|null  $holder  on a short refusal in unique mode, whose block it was
     */
    public function __construct(
        public string $status,
        public ?CouponCode $code = null,
        public float $discount = 0.0,
        public array $codes = [],
        public int $uses = 0,
        public ?int $remaining = null,
        public ?int $needed = null,
        public ?string $holder = null,
    ) {}

    /**
     * @param  array<int, CouponCode>  $codes
     */
    public static function ok(CouponCode $code, float $discount, array $codes = [], int $uses = 1): self
    {
        return new self(
            status: self::OK,
            code: $code,
            discount: $discount,
            codes: $codes === [] ? [$code] : $codes,
            uses: max(1, $uses),
        );
    }

    public static function failed(string $status): self
    {
        return new self($status);
    }

    /**
     * Refused for want of uses, with the numbers that explain it.
     *
     * Still RAN_OUT, deliberately: every caller that already treats that as "fall back
     * to the normal price" keeps doing the right thing without being changed.
     */
    public static function shortOfUses(int $remaining, int $needed, ?string $holder = null): self
    {
        return new self(
            status: self::RAN_OUT,
            remaining: max(0, $remaining),
            needed: max(1, $needed),
            holder: $holder,
        );
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
            self::RAN_OUT => $this->shortMessage(),
            self::EXPIRED => 'That coupon has expired, so the normal price applies.',
            self::ALREADY_USED => 'That coupon code has already been used.',
            self::WRONG_KIND => 'That coupon cannot be used here.',
            self::NOTHING_TO_DISCOUNT => 'There is nothing to discount.',
            self::NEEDS_CODE => 'That coupon is handed out as individual codes, so the code issued to you is the one to type.',
            default => 'That coupon code was not recognised.',
        };
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    /**
     * Why a claim was short, in words somebody can act on.
     *
     * Four shapes, because the useful sentence is different in each:
     *
     *   nothing left, no block    the original wording, unchanged. Every existing
     *                             caller and test reads this one.
     *   nothing left, a block     names the holder, so the office knows whose to top
     *                             up rather than hunting for it.
     *   some left, no block       names the balance AND what was needed, which is the
     *                             group-of-ten-against-five case.
     *   some left, a block        both, which is the same case in unique mode where
     *                             the batch may still have hundreds going spare.
     */
    private function shortMessage(): string
    {
        $holder = $this->holder;

        if ($this->remaining === null || $this->remaining === 0) {
            return $holder === null
                ? 'That coupon has been fully used, so the normal price applies.'
                : sprintf('The coupons held by %s have all been used, so the normal price applies.', $holder);
        }

        $left = sprintf('%d %s', $this->remaining, Str::plural('use', $this->remaining));
        $needed = (int) ($this->needed ?? 1);

        return $holder === null
            ? sprintf(
                'That coupon has only %s left and this registration needs %d, so the normal price applies.',
                $left,
                $needed,
            )
            : sprintf(
                'The coupons held by %s have only %s left and this registration needs %d, so the normal price applies.',
                $holder,
                $left,
                $needed,
            );
    }
}
