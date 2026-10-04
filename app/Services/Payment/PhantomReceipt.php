<?php

namespace App\Services\Payment;

use App\Models\EventRegistrationPayment;
use App\Support\PaymentFigures;

/**
 * One payment row the stored gateway payload contradicts.
 *
 * Not "a row that looks wrong". A row is only ever described by this class when two
 * things are both true of it: another row on the same registration carries the same
 * purchase reference, and the rows carrying that reference add up to more than the
 * gateway's own record says the purchase took. The evidence travels with the finding
 * so the screen can show it rather than asking anybody to take it on trust.
 */
readonly class PhantomReceipt
{
    public function __construct(
        public EventRegistrationPayment $payment,

        /** The purchase every row in this group names. */
        public string $purchaseId,

        /** What the stored gateway payload reports that purchase took, in ringgit. */
        public float $reported,

        /** What the ledger records against it, in ringgit, before this correction. */
        public float $recorded,
    ) {
    }

    public function amount(): float
    {
        return round((float) $this->payment->amount, 2);
    }

    public function amountLabel(): string
    {
        return PaymentFigures::money($this->amount());
    }

    public function reportedLabel(): string
    {
        return PaymentFigures::money($this->reported);
    }

    public function recordedLabel(): string
    {
        return PaymentFigures::money($this->recorded);
    }

    /** How much of the takings this row invented. */
    public function phantomLabel(): string
    {
        return PaymentFigures::money(round($this->recorded - $this->reported, 2));
    }

    /**
     * The whole row, for the trail.
     *
     * Deleting a payment record cannot be undone, so every field goes into the audit
     * entry and nothing is summarised away: the amount, when it was received, which
     * purchase it named, what it said about itself and where it came from.
     *
     * @return array<string, mixed>
     */
    public function toTrail(): array
    {
        return [
            'id' => $this->payment->id,
            'amount' => $this->amount(),
            'received_at' => $this->payment->received_at?->toDateTimeString(),
            'reference' => $this->payment->reference,
            'note' => $this->payment->note,
            'source' => $this->payment->source,
            'recorded_by' => $this->payment->recorded_by,
            'actor_label' => $this->payment->actor_label,
            'proof_path' => $this->payment->proof_path,
            'proof_name' => $this->payment->proof_name,
            'created_at' => $this->payment->created_at?->toDateTimeString(),

            // The evidence that condemned it, kept beside it.
            'gateway_reported' => $this->reported,
            'ledger_recorded' => $this->recorded,
        ];
    }
}
