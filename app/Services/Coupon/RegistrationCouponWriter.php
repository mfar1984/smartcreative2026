<?php

namespace App\Services\Coupon;

use App\Models\Coupon;
use App\Models\EventRegistration;
use App\Support\CouponDiscount;
use Illuminate\Support\Facades\DB;

/**
 * Putting a claimed coupon onto a registration's money.
 *
 * The one place that writes `discount_amount` and re-derives `amount`, so the figure
 * on screen, the figure in the books and the figure the gateway is asked for all come
 * from the same arithmetic. Part B calls this from the public form; it lives here
 * rather than in a controller precisely so that a second caller cannot reach a
 * different total.
 *
 * WHAT IT WRITES, AND WHAT IT DELIBERATELY DOES NOT
 *
 *   amount          fee + items - discount, floored at zero.
 *   discount_amount a record of what was taken off. Nothing subtracts it again.
 *   payment_status  through paymentStatusFromLedger(), the existing rule, which
 *                   already answers PAID for a free entry. A 100% coupon therefore
 *                   needs no new status concept, and none is invented here.
 *   status          confirmed when nothing is left to pay, matching what the public
 *                   controller already does for a free entry: there is nothing to wait
 *                   for, so leaving it pending would put an amber badge on an entry
 *                   that owes nothing.
 *
 * registration_fee and addons_total are not touched. They are what the entry was
 * charged for, and a discount does not change what was bought.
 */
class RegistrationCouponWriter
{
    public function __construct(private readonly CouponRedeemer $redeemer)
    {
    }

    /**
     * Claim one use of a batch and reduce this registration by it.
     *
     * Returns the outcome untouched, so a caller that loses the race can fall back to
     * the normal price without catching anything. Nothing is written unless the claim
     * succeeded.
     */
    public function apply(EventRegistration $registration, Coupon $coupon): CouponOutcome
    {
        if (! $coupon->isForEvents()) {
            return CouponOutcome::failed(CouponOutcome::WRONG_KIND);
        }

        $registration->loadMissing(['event', 'participants']);

        // Already discounted, so a second coupon would stack. One per entry.
        if ($registration->hasDiscount()) {
            return CouponOutcome::failed(CouponOutcome::ALREADY_USED);
        }

        $charge = $this->chargeBefore($registration);

        $outcome = $this->redeemer->claim(
            coupon: $coupon,
            charge: $charge,
            times: CouponDiscount::timesFor($registration->event, $registration->participants->count()),
            registration: $registration,
        );

        if (! $outcome->succeeded()) {
            return $outcome;
        }

        $this->write($registration, $outcome->discount, $outcome->code?->id);

        return $outcome;
    }

    /**
     * The same, by the code somebody typed.
     *
     * A separate entry point rather than a resolved Coupon handed to apply(), because
     * the lookup has its own failure answers — not found, wrong kind, already used —
     * and the public form has to tell them apart.
     */
    public function applyCode(EventRegistration $registration, string $typed): CouponOutcome
    {
        $registration->loadMissing(['event', 'participants']);

        if ($registration->hasDiscount()) {
            return CouponOutcome::failed(CouponOutcome::ALREADY_USED);
        }

        $outcome = $this->redeemer->claimByCode(
            typed: $typed,
            kind: Coupon::KIND_EVENT,
            charge: $this->chargeBefore($registration),
            times: CouponDiscount::timesFor($registration->event, $registration->participants->count()),
            registration: $registration,
        );

        if ($outcome->succeeded()) {
            $this->write($registration, $outcome->discount, $outcome->code?->id);
        }

        return $outcome;
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    /**
     * What the entry is charged before any discount.
     *
     * The two columns that say what was bought, never `amount`: asking `amount` would
     * compound a discount already on the row, and this is also what makes the
     * arithmetic match RegistrationTotalsRecalculator, which derives the same figure.
     */
    private function chargeBefore(EventRegistration $registration): float
    {
        return round(
            (float) $registration->registration_fee + (float) $registration->addons_total,
            2,
        );
    }

    private function write(EventRegistration $registration, float $discount, ?int $codeId): void
    {
        DB::transaction(function () use ($registration, $discount, $codeId) {
            $registration->discount_amount = $discount;
            $registration->coupon_code_id = $codeId;
            $registration->amount = CouponDiscount::applyTo($this->chargeBefore($registration), $discount);

            /*
             | The existing rule, asked rather than reimplemented. It returns PAID for
             | isFree(), which is how a 100% coupon settles itself without a single
             | new branch anywhere in the payments module.
             */
            $registration->payment_status = $registration->paymentStatusFromLedger();

            // Nothing to wait for, so the place is confirmed, the same way the public
            // controller already confirms a free entry on arrival.
            if ($registration->isFree()) {
                $registration->status = EventRegistration::STATUS_CONFIRMED;
            }

            $registration->save();
        });
    }
}
