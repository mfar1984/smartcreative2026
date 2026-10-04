<?php

namespace App\Services\Payment;

use App\Models\EventRegistration;
use App\Support\PaymentFigures;

/**
 * What one registration's gateway receipts add up to, against what the gateway says.
 *
 * Carries the rows that would go, the rows that would stay, and both figures, so the
 * preview can show the operator the arithmetic rather than a verdict.
 */
readonly class PhantomReceiptFinding
{
    /**
     * @param  array<int, PhantomReceipt>  $phantoms  rows the payload contradicts
     */
    public function __construct(
        public EventRegistration $registration,
        public string $purchaseId,
        public float $reported,
        public float $recorded,
        public array $phantoms,
    ) {
    }

    public function hasPhantoms(): bool
    {
        return $this->phantoms !== [];
    }

    /** What the takings would lose, which is money that never arrived. */
    public function phantomTotal(): float
    {
        return round(array_sum(array_map(
            fn (PhantomReceipt $receipt) => $receipt->amount(),
            $this->phantoms,
        )), 2);
    }

    public function phantomTotalLabel(): string
    {
        return PaymentFigures::money($this->phantomTotal());
    }

    /** What the row will say it has received once the phantoms are gone. */
    public function correctedAmountPaid(): float
    {
        return round((float) $this->registration->amount_paid - $this->phantomTotal(), 2);
    }

    public function correctedAmountPaidLabel(): string
    {
        return PaymentFigures::money($this->correctedAmountPaid());
    }

    public function correctedOutstanding(): float
    {
        return max(0, round((float) $this->registration->amount - $this->correctedAmountPaid(), 2));
    }

    public function correctedOutstandingLabel(): string
    {
        return PaymentFigures::money($this->correctedOutstanding());
    }

    /**
     * The badge the entry will carry afterwards.
     *
     * Derived the same way RegistrationPaymentUpdater derives it, off a copy of the
     * row holding the corrected figure, so the preview cannot promise one answer and
     * the correction write another.
     */
    public function correctedPaymentStatus(): string
    {
        $copy = new EventRegistration();
        $copy->amount = $this->registration->amount;
        $copy->amount_paid = $this->correctedAmountPaid();

        return $copy->paymentStatusFromLedger();
    }

    public function correctedPaymentStatusLabel(): string
    {
        return EventRegistration::PAYMENT_STATUSES[$this->correctedPaymentStatus()] ?? $this->correctedPaymentStatus();
    }

    public function reportedLabel(): string
    {
        return PaymentFigures::money($this->reported);
    }

    public function recordedLabel(): string
    {
        return PaymentFigures::money($this->recorded);
    }
}
