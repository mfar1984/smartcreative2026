<?php

namespace App\Services\Payment;

use App\Models\EventRegistration;
use App\Models\EventRegistrationPayment;
use App\Support\PaymentFigures;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Reconcile one registration against every purchase the gateway holds for it.
 *
 * Exists because the two sides can disagree, and when they do the money is real and
 * the record is wrong. The case it was written for: a payer pressed Pay twice, the
 * first purchase settled, the second timed out, and the registration was left
 * pointing at the second and reading "failed" while RM 248.50 sat in the account.
 *
 * Reads, decides, then reports. Nothing here guesses: a purchase is adopted only when
 * the gateway itself says it is paid, and the amount written to the ledger is the
 * amount the gateway says it took, not the amount the registration was charged. Those
 * differ when somebody pays a different figure, and recording our own would be
 * recording what we wanted rather than what happened.
 */
class RegistrationTally
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly RegistrationPaymentUpdater $updater,
    ) {
    }

    /**
     * Every purchase this registration has ever been associated with.
     *
     * The current reference, every recorded attempt, and anything typed in by hand.
     * De-duplicated, because the current reference is almost always one of the
     * attempts as well.
     *
     * @return array<int, string>
     */
    public function candidates(EventRegistration $registration, ?string $extra = null): array
    {
        return collect([
            $registration->payment_reference,
            ...$registration->checkouts()->pluck('purchase_id')->all(),
            $extra,
        ])
            ->filter(fn ($id) => is_string($id) && trim($id) !== '')
            ->map(fn (string $id) => trim($id))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Ask the gateway about each candidate.
     *
     * A purchase that cannot be read is reported rather than dropped: "the gateway
     * does not know this id" is an answer somebody needs to see, not something to hide
     * behind an empty list.
     *
     * @return Collection<int, array<string, mixed>>
     *
     * @throws PaymentGatewayException when there is no usable gateway at all
     */
    public function inspect(EventRegistration $registration, ?string $extra = null): Collection
    {
        $gateway = $this->gateways->active();

        return collect($this->candidates($registration, $extra))
            ->map(function (string $purchaseId) use ($gateway, $registration) {
                $payment = $gateway->fetchPayment($purchaseId);

                if ($payment === null) {
                    return [
                        'purchase_id' => $purchaseId,
                        'readable' => false,
                        'status' => null,
                        'means' => null,
                        'amount' => null,
                        'paid_on' => null,
                        'is_current' => $registration->payment_reference === $purchaseId,
                        'payment' => null,
                    ];
                }

                $record = \App\Support\GatewayPaymentRecord::make($payment);

                return [
                    'purchase_id' => $purchaseId,
                    'readable' => true,
                    'status' => is_string($payment['status'] ?? null) ? $payment['status'] : null,
                    'means' => $gateway->statusFromPayment($payment),
                    'amount' => $record?->amount(),
                    'paid_on' => $record?->paidOn(),
                    'is_current' => $registration->payment_reference === $purchaseId,
                    'payment' => $payment,
                ];
            });
    }

    /**
     * Adopt whichever purchase the gateway says is paid, and record the receipt.
     *
     * Returns what was done, in words, so the screen can say it rather than the
     * operator having to work out what changed.
     *
     * @return array{changed: bool, message: string, findings: Collection<int, array<string, mixed>>}
     *
     * @throws PaymentGatewayException
     */
    public function settle(EventRegistration $registration, ?string $extra = null): array
    {
        $findings = $this->inspect($registration, $extra);

        if ($findings->isEmpty()) {
            return [
                'changed' => false,
                'message' => 'There is no gateway purchase on record for this entry, so there is nothing to compare it against.',
                'findings' => $findings,
            ];
        }

        $paid = $findings->first(fn (array $row) => $row['means'] === EventRegistration::PAYMENT_PAID);

        if ($paid === null) {
            return [
                'changed' => false,
                'message' => sprintf(
                    'The gateway does not report any of these %d %s as paid, so nothing was changed. %s',
                    $findings->count(),
                    $findings->count() === 1 ? 'purchase' : 'purchases',
                    $this->summarise($findings),
                ),
                'findings' => $findings,
            ];
        }

        // Already settled and already pointing at the right purchase: say so rather
        // than writing a second receipt for the same money.
        if ($registration->isPaid() && $paid['is_current']) {
            return [
                'changed' => false,
                'message' => 'This entry is already recorded as paid against the purchase the gateway settled. Nothing needed changing.',
                'findings' => $findings,
            ];
        }

        $this->updater->adoptPurchase($registration, $paid['purchase_id'], $paid['payment']);

        $wasPaid = $registration->isPaid();

        $this->updater->apply($registration, EventRegistration::PAYMENT_PAID, 'tallied against the gateway');

        /*
         | The receipt, written only when this tally is what settled it. apply() calls
         | settleLedger(), which tops the ledger up to the charge, so a row already
         | exists by now for the amount owed. This replaces its detail with what the
         | gateway actually reported, so the ledger says the truth about the date and
         | the reference rather than "taken by the payment gateway, some time today".
         */
        if (! $wasPaid) {
            $this->describeReceipt($registration, $paid);
        }

        return [
            'changed' => true,
            'message' => sprintf(
                'Matched to purchase %s, which the gateway reports as paid%s. %s is now recorded as paid in full.',
                $paid['purchase_id'],
                $paid['paid_on'] instanceof Carbon ? ' on ' . $paid['paid_on']->format('d M Y, g:i a') : '',
                $registration->reference,
            ),
            'findings' => $findings,
        ];
    }

    /**
     * Put the gateway's own date and reference onto the receipt row it settled.
     *
     * @param  array<string, mixed>  $paid
     */
    private function describeReceipt(EventRegistration $registration, array $paid): void
    {
        $receipt = $registration->payments()
            ->where('source', EventRegistrationPayment::SOURCE_GATEWAY)
            ->orderByDesc('id')
            ->first();

        if ($receipt === null) {
            return;
        }

        $receipt->reference = $paid['purchase_id'];

        if ($paid['paid_on'] instanceof Carbon) {
            $receipt->received_at = $paid['paid_on'];
        }

        $receipt->note = 'Taken by the payment gateway, matched by hand from the Tally screen.';
        $receipt->save();
    }

    /**
     * One readable sentence about what each purchase says, for a message that has to
     * explain a refusal.
     *
     * @param  Collection<int, array<string, mixed>>  $findings
     */
    private function summarise(Collection $findings): string
    {
        return $findings
            ->map(function (array $row) {
                if (! $row['readable']) {
                    return sprintf('%s could not be read by the gateway', $row['purchase_id']);
                }

                return sprintf(
                    '%s is %s%s',
                    $row['purchase_id'],
                    $row['status'] ?? 'in an unnamed state',
                    $row['amount'] === null ? '' : ' for ' . PaymentFigures::money((float) $row['amount']),
                );
            })
            ->join('; ');
    }
}
