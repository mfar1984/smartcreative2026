<?php

namespace App\Support;

use App\Models\Coupon;
use App\Models\Event;

/**
 * One place that decides what a coupon takes off a charge.
 *
 * Pure arithmetic and nothing else: no database, no transaction, no logging. That is
 * deliberate, because this is the figure the whole Payments module ends up resting on
 * and it has to be readable and testable on its own. Claiming a code is
 * CouponRedeemer's job and happens around this, never inside it.
 *
 * TWO RULES, AND BOTH OF THEM ARE THE SAFE DIRECTION
 *
 * The discount is capped at the charge. A RM50 coupon on a RM30 order takes RM30, so
 * `amount` floors at zero and can never go negative. A negative charge would be
 * subtracted from the outstanding total across a whole event and quietly reduce what
 * other people owe, which is the sort of phantom-money bug this project has already
 * been bitten by twice.
 *
 * A percentage is capped at 100 on the way in, so it cannot exceed the charge even
 * before the cap above.
 *
 * THE PER-PARTICIPANT RULE
 *
 * When an event charges add-ons per participant, a FIXED discount is owed once per
 * head: RM10 off for a group of three is RM30, because each of the three is paying for
 * their own shirt.
 *
 * A PERCENTAGE is deliberately not multiplied, and this is the one subtlety worth
 * reading twice. A percentage is already proportional to what the group is charged — a
 * tenth of a three-person total is three times a tenth of a one-person total — so
 * taking it per head and adding the three shares up gives exactly the same ringgit
 * figure as taking it once off the whole. Multiplying it again would turn a 34% coupon
 * into 102%, which the cap would then silently turn into "free", and a coupon that
 * says 34% must never hand the whole thing away.
 */
class CouponDiscount
{
    /**
     * What this batch takes off a charge.
     *
     * @param  float  $charge  what is owed before any discount
     * @param  int  $times  how many people the discount is owed for; see the class note
     */
    public static function on(Coupon $coupon, float $charge, int $times = 1): float
    {
        $charge = round(max(0.0, $charge), 2);

        if ($charge <= 0) {
            return 0.0;
        }

        $value = (float) $coupon->discount_value;

        if ($value <= 0) {
            return 0.0;
        }

        if ($coupon->isPercentage()) {
            // Capped at 100 here as well as in validation: a row written by hand or
            // by an older release must not be able to produce a negative charge.
            $percent = min(100.0, $value);

            return min($charge, round($charge * $percent / 100, 2));
        }

        $heads = max(1, $times);

        return min($charge, round($value * $heads, 2));
    }

    /**
     * How many times a fixed discount is owed on one registration.
     *
     * Mirrors the existing per-participant pricing rule rather than inventing a second
     * way of counting people: Event::chargesAddonsPerParticipant() is the same flag
     * AddonOrder reads to decide whether a shirt is charged per head, and the head
     * count is the number of participants named, which is what AddonOrder prices
     * against too.
     */
    public static function timesFor(Event $event, int $participantCount): int
    {
        return $event->chargesAddonsPerParticipant()
            ? max(1, $participantCount)
            : 1;
    }

    /**
     * The charge after the discount, floored at zero.
     *
     * Exists so the floor is written once. Every caller that reduces a stored total
     * goes through here, so none of them can forget the max().
     */
    public static function applyTo(float $charge, float $discount): float
    {
        return round(max(0.0, round($charge, 2) - round($discount, 2)), 2);
    }
}
