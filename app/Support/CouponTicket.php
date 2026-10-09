<?php

namespace App\Support;

use App\Models\Coupon;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

/**
 * What a coupon looks like when it is drawn, in one object.
 *
 * Five designs show the same four facts — the discount, what it is for, the code and
 * the expiry — and differ only in appearance. This is where those four are worked
 * out, once, so a design file is layout and colour and nothing else. A new design is
 * then a key in Coupon::DESIGNS plus a component file, with no arithmetic to copy and
 * no switch statement to extend.
 *
 * NOTHING HERE DECIDES MONEY
 *
 * discountLabel() is the batch's own terms, which is what a coupon prints: "30% OFF"
 * or "RM 25.00". What it actually takes off a particular charge is CouponDiscount's
 * answer and depends on the charge, so it is never read from here.
 */
final class CouponTicket
{
    /** Drawn when a stored design names a component that is not there. */
    public const FALLBACK = 'classic';

    private function __construct(
        public readonly Coupon $coupon,
        public readonly string $subject,
        public readonly string $code,
    ) {
    }

    /**
     * @param  string|null  $subject  what the coupon is for: an event title, a product
     *         name. Falls back to the kind, which is the only honest answer when the
     *         caller has nothing more specific — a batch is created before it is
     *         ticked on anything.
     * @param  string|null  $code  the code somebody holds. Falls back to the batch
     *         name, which IS the code on an unlimited batch and the only sensible
     *         label on a preview.
     */
    public static function for(Coupon $coupon, ?string $subject = null, ?string $code = null): self
    {
        return new self(
            $coupon,
            trim((string) $subject) !== '' ? trim((string) $subject) : $coupon->kindLabel(),
            Str::upper(trim((string) $code) !== '' ? trim((string) $code) : (string) $coupon->name),
        );
    }

    /* ---------------------------------------------------------------------
     | Which design draws it
     * ------------------------------------------------------------------ */

    /**
     * The anonymous component that draws this design.
     *
     * Resolved from the stored key rather than matched against a list, which is what
     * makes the set data driven. A key with no file behind it falls back instead of
     * throwing: a coupon created on a release that had a design this one does not
     * must still be readable by the person holding it.
     */
    public function component(): string
    {
        $key = (string) $this->coupon->design;

        return $key !== '' && View::exists('components.coupon.designs.' . $key)
            ? 'coupon.designs.' . $key
            : 'coupon.designs.' . self::FALLBACK;
    }

    public function isCustom(): bool
    {
        return $this->coupon->design === Coupon::DESIGN_CUSTOM;
    }

    public function artworkUrl(): ?string
    {
        return $this->coupon->designUrl();
    }

    /* ---------------------------------------------------------------------
     | The four facts
     * ------------------------------------------------------------------ */

    /**
     * The discount, in the one slot every design keeps for it.
     *
     * A percentage batch reads "30% OFF" and a ringgit batch reads "RM 25.00" in the
     * same place, so the two are never mistaken for one another.
     */
    public function discountLabel(): string
    {
        return $this->coupon->isPercentage()
            ? $this->coupon->discountLabel() . ' OFF'
            : PaymentFigures::money((float) $this->coupon->discount_value);
    }

    /**
     * The hero figure, split from its unit so a design can stack the two.
     *
     * 'bold' sizes a block around this, which is why it is split: "30" over "PERCENT"
     * fits a column that "RM 1,250.00" on one line would not.
     */
    public function heroValue(): string
    {
        if ($this->coupon->isPercentage()) {
            return rtrim(rtrim(number_format((float) $this->coupon->discount_value, 2), '0'), '.');
        }

        return number_format((float) $this->coupon->discount_value, 2);
    }

    public function heroUnit(): string
    {
        return $this->coupon->isPercentage() ? 'PERCENT' : 'RINGGIT';
    }

    /**
     * How large the hero may be drawn.
     *
     * The block is sized for a short number, so a long one is stepped down rather
     * than left to spill out of it. This is the graceful degradation 'bold' needs:
     * "50" fills the panel, "1,250.00" shrinks to fit it.
     */
    public function heroSizeClass(): string
    {
        return match (true) {
            mb_strlen($this->heroValue()) <= 3 => 'text-5xl sm:text-6xl',
            mb_strlen($this->heroValue()) <= 5 => 'text-4xl sm:text-5xl',
            mb_strlen($this->heroValue()) <= 8 => 'text-3xl sm:text-4xl',
            default => 'text-2xl sm:text-3xl',
        };
    }

    /** The same stepping for the designs that print the whole label on one line. */
    public function discountSizeClass(): string
    {
        return match (true) {
            mb_strlen($this->discountLabel()) <= 8 => 'text-4xl sm:text-5xl',
            mb_strlen($this->discountLabel()) <= 12 => 'text-3xl sm:text-4xl',
            default => 'text-2xl sm:text-3xl',
        };
    }

    /** The eyebrow line: what kind of thing this discounts. */
    public function kindLabel(): string
    {
        return Str::upper($this->coupon->kindLabel());
    }

    /** The last day it works, as a date, with no timezone shift. See Coupon. */
    public function expiryLabel(): string
    {
        return $this->coupon->expiresLabel();
    }

    public function expiryNote(): string
    {
        return 'Valid until ' . $this->expiryLabel();
    }

    /** Spelt out for a screen reader, which cannot read a layout. */
    public function summary(): string
    {
        return sprintf(
            'Coupon %s, %s off %s, valid until %s.',
            $this->code,
            $this->coupon->discountLabel(),
            $this->subject,
            $this->expiryLabel(),
        );
    }
}
