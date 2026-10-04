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
     * @param  float  $correctedAmount  the fee plus the corrected add-on lines
     * @param  array<int, array{unit_price: float, line_total: float}>  $lines
     *         add-on line id => the figures it should carry
     * @param  string|null  $blocked  why this entry cannot be re-priced, when it cannot
     */
    public function __construct(
        public EventRegistration $registration,
        public int $people,
        public float $currentAmount,
        public float $correctedAmount,
        public float $correctedAddonsTotal,
        public array $lines,
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
            lines: [],
            blocked: $reason,
        );
    }

    public function difference(): float
    {
        return round($this->correctedAmount - $this->currentAmount, 2);
    }

    /**
     * Whether applying this would change anything.
     *
     * Judged on the total alone, and that is deliberate. A single-participant entry
     * comes out of the arithmetic with the same total and a different split between
     * its lines — the shirt price moving off the group line and onto the one person's
     * line — and rewriting it would touch a row that is already charging the right
     * amount. Those are left exactly as they are, which is also what makes a second
     * run a no-op.
     */
    public function changes(): bool
    {
        return $this->blocked === null && abs($this->difference()) > 0.005;
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
        if (! $this->changes()) {
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
}
