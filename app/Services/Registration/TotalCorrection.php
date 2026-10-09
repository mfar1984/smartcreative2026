<?php

namespace App\Services\Registration;

use App\Models\EventRegistration;
use App\Support\PaymentFigures;

/**
 * What one registration is charged now, and what it should be charged.
 *
 * Carries the arithmetic rather than performing it, so the preview screen and the
 * apply step read the same figures from the same object. Nothing here writes
 * anything: a correction is a proposal until RegistrationTotalsRecalculator commits
 * it.
 */
readonly class TotalCorrection
{
    /**
     * @param  int  $people  how many are named on the entry
     * @param  float  $currentAmount  what the row says today, read from the database
     * @param  float  $correctedAmount  the event's fee now, plus the corrected items
     * @param  float  $correctedRegistrationFee  the event's fee as it stands today.
     *         Written over the snapshot on the row, because entries taken while the
     *         shirt was the event fee carry that RM 40.00 here and charging it again
     *         on top of the shirt would bill the same money twice.
     * @param  array<int, array<string, float|int>>  $lines
     *         add-on line id => the figures it should carry
     * @param  array<int, array<string, mixed>>  $additions
     *         lines that have to be created, ready for EventRegistrationAddon. Never
     *         carry a variant: see RegistrationTotalsRecalculator on stock.
     * @param  float  $discount  what a coupon already took off this entry.
     *         Carried through rather than recomputed, and part of correctedAmount:
     *         the corrected charge is fee + items - discount, floored at zero. A
     *         recalculation that left this out would silently wipe the discount and
     *         reopen a balance on an entry that was settled, which is exactly the
     *         phantom-money shape this class already exists to clean up.
     * @param  string|null  $blocked  why this entry cannot be re-priced, when it cannot
     */
    public function __construct(
        public EventRegistration $registration,
        public int $people,
        public float $currentAmount,
        public float $correctedAmount,
        public float $correctedAddonsTotal,
        public float $correctedRegistrationFee,
        public array $lines,
        public array $additions = [],
        public float $discount = 0.0,
        public ?string $blocked = null,
    ) {
    }

    /**
     * An entry left alone, with the reason why.
     */
    public static function blocked(EventRegistration $registration, int $people, string $reason): self
    {
        $amount = (float) $registration->amount;

        return new self(
            registration: $registration,
            people: $people,
            currentAmount: $amount,
            correctedAmount: $amount,
            correctedAddonsTotal: (float) $registration->addons_total,
            correctedRegistrationFee: (float) $registration->registration_fee,
            lines: [],
            additions: [],
            discount: (float) $registration->discount_amount,
            blocked: $reason,
        );
    }

    public function difference(): float
    {
        return round($this->correctedAmount - $this->currentAmount, 2);
    }

    /** What the row says it was charged as a registration fee today. */
    public function currentRegistrationFee(): float
    {
        return round((float) $this->registration->registration_fee, 2);
    }

    /** What the row says its items add up to today. */
    public function currentAddonsTotal(): float
    {
        return round((float) $this->registration->addons_total, 2);
    }

    /** Whether a coupon took anything off this entry. */
    public function hasDiscount(): bool
    {
        return $this->discount > 0.005;
    }

    public function discountLabel(): string
    {
        return PaymentFigures::money($this->discount);
    }

    /**
     * The code that was used, for the preview to name.
     *
     * The operator's question about a discounted row is "is my discount still there
     * after this press", and a figure with no code beside it does not answer it.
     */
    public function couponLabel(): ?string
    {
        return $this->registration->couponCode?->codeLabel();
    }

    /**
     * Whether applying this would move money.
     *
     * The total alone: this is the part that changes what somebody owes, and the only
     * part that may touch a payment status or reopen a balance.
     */
    public function movesMoney(): bool
    {
        return $this->blocked === null && abs($this->difference()) > 0.005;
    }

    /**
     * Whether the total is right and the itemisation does not describe it.
     *
     * The case this was added for. An entry taken while the shirt was the event fee
     * carries RM 40.00 in registration_fee with no item line behind it, and on an
     * event whose fee is now RM 0.00 that single figure is already the right total —
     * so the arithmetic above finds nothing to move and the row was skipped. The
     * money was never wrong; what it is called is. The shirt list is built from item
     * lines, so twenty-two people who have paid for a shirt do not appear on it, and
     * RM 880.00 of shirt income reads as registration fees on an event that charges
     * none.
     *
     * Judged on the two columns that name the charge rather than on the lines, which
     * is what keeps it narrow: a row whose fee and item total already agree with the
     * event's catalogue is left alone even if its lines split that total differently
     * from how this would write them. Those were sold that way and are not wrong.
     */
    public function reshapes(): bool
    {
        if ($this->blocked !== null || $this->movesMoney()) {
            return false;
        }

        return abs($this->currentRegistrationFee() - $this->correctedRegistrationFee) > 0.005
            || abs($this->currentAddonsTotal() - $this->correctedAddonsTotal) > 0.005;
    }

    /**
     * Whether applying this would change anything.
     *
     * Either the total is wrong, or the total is right and the items do not describe
     * it. Both are corrections and both are written by the same confirmed press; the
     * preview keeps them in separate tables because only the first one moves money.
     *
     * A row where neither holds is not written at all, which is what makes a second
     * run a no-op.
     */
    public function changes(): bool
    {
        return $this->movesMoney() || $this->reshapes();
    }

    /** What is still owed once the corrected charge is in place. */
    public function correctedOutstanding(): float
    {
        return max(0, round($this->correctedAmount - $this->registration->amountPaid(), 2));
    }

    /**
     * The payment status this entry will read after the correction.
     *
     * The same rule the recalculator applies, so the preview cannot promise one
     * outcome and the apply step produce another.
     */
    public function correctedPaymentStatus(): string
    {
        /*
         | Nothing that leaves the total where it is may touch the badge. A shape
         | correction re-describes a charge that has already been settled or is still
         | being chased, and the amount it is judged against does not move by a sen,
         | so whatever the row reads now is still the truth.
         */
        if (! $this->movesMoney()) {
            return $this->registration->payment_status;
        }

        if ($this->registration->payment_status !== EventRegistration::PAYMENT_PAID) {
            return $this->registration->payment_status;
        }

        if ($this->correctedOutstanding() <= 0.005) {
            return EventRegistration::PAYMENT_PAID;
        }

        return $this->registration->hasMoneyReceived()
            ? EventRegistration::PAYMENT_PARTIAL
            : EventRegistration::PAYMENT_UNPAID;
    }

    public function correctedPaymentStatusLabel(): string
    {
        $status = $this->correctedPaymentStatus();

        return EventRegistration::PAYMENT_STATUSES[$status] ?? $status;
    }

    public function currentAmountLabel(): string
    {
        return PaymentFigures::money($this->currentAmount);
    }

    public function correctedAmountLabel(): string
    {
        return PaymentFigures::money($this->correctedAmount);
    }

    /** Signed, because a correction that lowers a charge has to look different. */
    public function differenceLabel(): string
    {
        $difference = $this->difference();

        return ($difference > 0 ? '+' : ($difference < 0 ? '-' : '')) . PaymentFigures::money(abs($difference));
    }

    public function correctedOutstandingLabel(): string
    {
        return PaymentFigures::money($this->correctedOutstanding());
    }

    /* ---------------------------------------------------------------------
     | The shape, before and after
     |
     | Four figures the preview puts side by side, so the operator can read along the
     | row and see that the two totals are the same number while the two columns
     | describing it swap over.
     * ------------------------------------------------------------------ */

    public function currentRegistrationFeeLabel(): string
    {
        return PaymentFigures::money($this->currentRegistrationFee());
    }

    public function correctedRegistrationFeeLabel(): string
    {
        return PaymentFigures::money($this->correctedRegistrationFee);
    }

    public function currentAddonsTotalLabel(): string
    {
        return PaymentFigures::money($this->currentAddonsTotal());
    }

    public function correctedAddonsTotalLabel(): string
    {
        return PaymentFigures::money($this->correctedAddonsTotal);
    }

    /**
     * How many lines would be written for people who have none on record.
     *
     * Reported on the preview because it is the one part of the correction that adds
     * rows rather than changing figures, and the operator should know a size was
     * never captured for those people before the invoice itemises them.
     */
    public function additionsCount(): int
    {
        return count($this->additions);
    }
}
