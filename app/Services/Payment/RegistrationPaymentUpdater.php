<?php

namespace App\Services\Payment;

use App\Models\EventRegistration;
use App\Models\EventRegistrationPayment;
use App\Services\AdminLogger;
use App\Services\EventNotifier;
use App\Services\Messaging\StaffAlerts;
use App\Support\GatewayPaymentRecord;
use App\Support\PaymentFigures;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The single place a registration's payment status is moved.
 *
 * Both the signed webhook and the return-from-gateway page write through here,
 * so the ordering guards and the audit trail cannot drift apart between them.
 */
class RegistrationPaymentUpdater
{
    /**
     * Purchase states that mean the gateway actually took the money.
     *
     * The same three ChipGateway::PURCHASE_STATUS_MAP translates to PAYMENT_PAID.
     * Named here as well because this class will only write a receipt against a
     * payload in one of them, and that refusal is load bearing: a payload is only
     * evidence of money when it says the purchase was paid.
     */
    private const PAID_PURCHASE_STATUSES = ['paid', 'settled', 'captured'];

    public function __construct(
        private readonly EventNotifier $notifier,
        private readonly StaffAlerts $alerts,
    ) {
    }

    /**
     * @param  string  $status  one of EventRegistration::PAYMENT_*
     * @param  string  $source  short description for the log, e.g. the gateway event name
     * @param  array<string, mixed>|null  $payment  the gateway's own record of the purchase,
     *                                              when the caller is holding one. It is the
     *                                              only thing allowed to decide how much
     *                                              money arrived.
     * @return bool  whether anything changed
     */
    public function apply(EventRegistration $registration, string $status, string $source, ?array $payment = null): bool
    {
        if (! $this->shouldApply($registration, $status)) {
            return false;
        }

        $before = [
            'payment_status' => $registration->payment_status,
            'status' => $registration->status,
            'amount_paid' => (float) $registration->amount_paid,
        ];

        if ($status === EventRegistration::PAYMENT_PAID) {
            /*
             | The ledger first, then the badge, and in that order for a reason: the
             | status is now read off the money rather than off the gateway's word.
             |
             | A gateway event says a purchase was paid. It does not say the
             | registration's whole charge was paid, and those two stopped being the
             | same thing the moment a charge could be corrected upwards after a
             | payment had already been taken.
             */
            $this->settleLedger($registration, $payment);

            $status = $registration->paymentStatusFromLedger();
        }

        // Paying confirms the place; anything less leaves it pending so an
        // administrator can chase it rather than losing the entry.
        $placeStatus = $status === EventRegistration::PAYMENT_PAID
            ? EventRegistration::STATUS_CONFIRMED
            : $registration->status;

        /*
         | Nothing moved, so nothing is written and nothing is announced.
         |
         | shouldApply() refuses a repeat of the status the row is sitting on, but it
         | cannot see a replay that would have derived the same status by a different
         | route: a second purchase.paid for a part-paid entry asks for "paid" and
         | resolves to "partial" again. Without this it would write an audit entry and
         | send a receipt on every retry CHIP makes.
         */
        if ($status === $before['payment_status']
            && $placeStatus === $before['status']
            && abs((float) $registration->amount_paid - $before['amount_paid']) <= 0.005) {
            return false;
        }

        $registration->payment_status = $status;
        $registration->status = $placeStatus;

        $registration->save();

        AdminLogger::activity(
            'payments.status',
            sprintf(
                'Payment for %s became %s (%s). %s of %s on record.',
                $registration->reference,
                $status,
                $source,
                PaymentFigures::money((float) $registration->amount_paid),
                PaymentFigures::money((float) $registration->amount),
            ),
        );

        AdminLogger::audit($registration, 'payment.updated', $before, [
            'payment_status' => $registration->payment_status,
            'status' => $registration->status,
            'amount_paid' => (float) $registration->amount_paid,
            'outstanding' => $registration->outstandingAmount(),
            'source' => $source,
        ]);

        // Told once, and only once. The guard above has already refused a repeat, so
        // a reloaded return page or a webhook arriving twice cannot send a second
        // receipt, and a part-paid entry is not told it is settled.
        if ($status === EventRegistration::PAYMENT_PAID) {
            $this->announcePayment($registration);
        }

        return true;
    }

    /**
     * Read the payment back from the gateway, keep a copy, and apply whatever
     * status it reports.
     *
     * The copy is what the admin detail screen shows, so it is stored even when
     * the status has not moved: the gateway record carries the payment method,
     * the timeline and the fees, none of which live on the registration.
     *
     * @return array<string, mixed>|null  the record, or null when unreadable
     */
    public function syncFromGateway(EventRegistration $registration, PaymentGateway $gateway): ?array
    {
        if (blank($registration->payment_reference)) {
            return null;
        }

        $payment = $gateway->fetchPayment($registration->payment_reference);

        if ($payment === null) {
            return null;
        }

        $registration->payment_details = $payment;
        $registration->payment_synced_at = now();
        $registration->save();

        $status = $gateway->statusFromPayment($payment);

        if ($status !== null) {
            // The purchase object goes with the status. It is the only thing that
            // knows how much the gateway actually took.
            $this->apply($registration, $status, 'gateway lookup', $payment);
        }

        return $payment;
    }

    /**
     * Record money that arrived outside the gateway.
     *
     * The case this exists for: somebody transferred the fee, the site failed
     * before their entry was confirmed, and the office is holding a receipt that no
     * machine has ever seen. Without this the only options were to leave a paying
     * entrant marked unpaid or to lie about a gateway reference.
     *
     * The amount decides the status, not the operator. Reaching the charge makes it
     * paid; anything less makes it partial. Letting somebody tick "paid" while
     * recording RM 100 of RM 250 is exactly the disagreement between the status and
     * the money that this whole change exists to remove.
     *
     * Written in a transaction because three things move together: the receipt, the
     * running total, and the status. A crash between them would leave a ledger that
     * does not add up to the figure the reports read.
     *
     * @param  float  $amount  what arrived, never more than the outstanding balance
     * @param  string  $receivedAt  when it arrived, as a datetime string
     */
    public function recordManualPayment(
        EventRegistration $registration,
        float $amount,
        string $receivedAt,
        ?string $reference = null,
        ?string $note = null,
        ?string $proofPath = null,
        ?string $proofName = null,
    ): EventRegistrationPayment {
        $user = Auth::user();

        $before = [
            'payment_status' => $registration->payment_status,
            'amount_paid' => (float) $registration->amount_paid,
        ];

        $payment = DB::transaction(function () use ($registration, $amount, $receivedAt, $reference, $note, $proofPath, $proofName, $user) {
            $payment = $registration->payments()->create([
                'amount' => $amount,
                'received_at' => $receivedAt,
                'reference' => $reference,
                'note' => $note,
                'proof_path' => $proofPath,
                'proof_name' => $proofName,
                'source' => EventRegistrationPayment::SOURCE_MANUAL,
                'recorded_by' => $user?->id,
                'actor_label' => $user?->logLabel(),
            ]);

            /*
             | Summed from the ledger rather than added to the running total. An
             | increment would drift the moment a row was ever inserted by anything
             | else, and the ledger is the record: the column is a convenience that
             | must always be able to prove itself.
             */
            $registration->amount_paid = (float) $registration->payments()->sum('amount');

            /*
             | A hand-recorded reference is worth searching for, so it fills the
             | registration's own reference when there is nothing there. A gateway
             | reference is never overwritten: it is the key the webhook finds this
             | entry by.
             */
            if (blank($registration->payment_reference) && filled($reference)) {
                $registration->payment_reference = $reference;
            }

            $registration->save();

            return $payment;
        });

        AdminLogger::activity(
            'payments.record',
            sprintf(
                'Recorded %s received by hand on %s. %s of %s now paid.',
                PaymentFigures::money($amount),
                $registration->reference,
                PaymentFigures::money((float) $registration->amount_paid),
                PaymentFigures::money((float) $registration->amount),
            ),
        );

        AdminLogger::audit($registration, 'payment.recorded', $before, [
            'amount' => $amount,
            'amount_paid' => (float) $registration->amount_paid,
            'outstanding' => $registration->outstandingAmount(),
            'received_at' => $receivedAt,
            'reference' => $reference,

            // Recorded so the trail shows whether the assertion came with anything
            // behind it, which is the first thing anybody asks months later.
            'proof' => $proofName,
        ]);

        /*
         | The status follows the money. apply() is a no-op when the entry is already
         | sitting on the right status, which is what happens on a second partial
         | receipt, so the receipt above is logged either way and only a real
         | transition writes a second audit entry and sends a message.
         */
        $this->apply(
            $registration,
            $registration->outstandingAmount() > 0.005
                ? EventRegistration::PAYMENT_PARTIAL
                : EventRegistration::PAYMENT_PAID,
            'recorded by hand',
        );

        return $payment;
    }

    /**
     * Point the registration at the purchase that actually settled.
     *
     * Needed because the stored reference is only the most recent attempt. When an
     * earlier purchase is the one that got paid, leaving the column alone would mean
     * every later read-back asks the gateway about the wrong purchase and gets
     * "overdue" back, and refunding would target a purchase that never took money.
     *
     * @param  array<string, mixed>|null  $payment  the gateway's record, when known
     */
    public function adoptPurchase(EventRegistration $registration, string $purchaseId, ?array $payment = null): void
    {
        if ($registration->payment_reference === $purchaseId) {
            return;
        }

        $previous = $registration->payment_reference;

        $registration->payment_reference = $purchaseId;

        if ($payment !== null) {
            $registration->payment_details = $payment;
            $registration->payment_synced_at = now();
        }

        $registration->save();

        // Recorded on both logs: it changes which purchase a refund would go to,
        // which is not a change anybody should have to guess at later.
        AdminLogger::activity('payments.reference', sprintf(
            'Pointed %s at purchase %s, which is the one that settled. It was pointing at %s.',
            $registration->reference,
            $purchaseId,
            $previous ?: 'nothing',
        ));

        AdminLogger::audit($registration, 'payment.reference-adopted', [
            'payment_reference' => $previous,
        ], [
            'payment_reference' => $purchaseId,
        ]);
    }

    /**
     * Keep the gateway's own record of a payment, without touching its status.
     *
     * Used by the webhook, where the pushed body is the purchase object itself,
     * so the admin sees fresh detail without anyone opening a page.
     *
     * @param  array<string, mixed>  $payment
     */
    public function rememberPayment(EventRegistration $registration, array $payment): void
    {
        $registration->payment_details = $payment;
        $registration->payment_synced_at = now();
        $registration->save();
    }

    /**
     * Record that a checkout has been opened, without claiming it succeeded.
     */
    public function markPending(
        EventRegistration $registration,
        string $gatewayReference,
        string $source,
        ?string $checkoutUrl = null,
    ): void {
        /*
         | Recorded before the column is overwritten, and this is the whole point.
         |
         | payment_reference holds one attempt. A payer who presses Pay twice creates
         | a second purchase, and if the first is the one that settles then its id is
         | lost the moment this method runs. That happened: a paid purchase became
         | unmatchable and its registration read "failed" while the money sat in the
         | account. The attempt is kept here so nothing can go missing again.
         */
        $registration->checkouts()->updateOrCreate(
            ['purchase_id' => $gatewayReference],
            [
                'checkout_url' => $checkoutUrl,
                'gateway' => 'chip',
                'opened_at' => now(),
            ],
        );

        /*
         | A settled entry keeps its reference. Overwriting it would point the record
         | away from the purchase that actually took the money, which is exactly the
         | fault this guard exists to prevent.
         */
        if ($registration->isPaid()) {
            AdminLogger::activity(
                'payments.checkout',
                sprintf(
                    'Opened a %s checkout for %s, which is already paid. The existing reference was kept.',
                    $source,
                    $registration->reference,
                ),
            );

            return;
        }

        $registration->payment_reference = $gatewayReference;
        $registration->payment_status = EventRegistration::PAYMENT_PENDING;

        // A new checkout means a new purchase at the gateway, so anything held
        // about the previous attempt no longer describes this one.
        $registration->payment_details = null;
        $registration->payment_synced_at = null;

        $registration->save();

        AdminLogger::activity(
            'payments.checkout',
            sprintf('Opened a %s checkout for %s.', $source, $registration->reference),
        );
    }

    /**
     * Tell the manager and the players that the money arrived.
     *
     * Wrapped because this runs on the webhook path: the payment is already
     * recorded, and answering the gateway with a failure would have it retry a
     * status change that has in fact been applied. A notification problem is
     * logged and left for the resend button.
     */
    private function announcePayment(EventRegistration $registration): void
    {
        try {
            $this->notifier->paymentReceived($registration);
        } catch (Throwable $exception) {
            Log::error('Payment was recorded but the notifications could not be raised.', [
                'registration' => $registration->reference,
                'error' => $exception->getMessage(),
            ]);
        }

        $this->alerts->paymentReceived($registration);
    }

    /**
     * Record what the GATEWAY SAYS IT TOOK, and nothing else.
     *
     * A gateway payment has no receipt row of its own: nobody typed it in. Without
     * one this table would hold only hand-recorded money, and Settlements, which
     * reconciles against a bank statement, would show a fraction of the takings.
     *
     * WHY THIS IS NOT "TOP UP TO THE CHARGE" ANY MORE
     *
     * It used to be. It inserted `amount - already recorded`, which is correct for
     * exactly as long as a charge never moves, and it invented money the day one did.
     * Recheck add-on totals raises a charge after the fact — a group of three went
     * from RM 40.00 to RM 120.00 — and the next gateway PAID event for the same
     * purchase recomputed the shortfall against the new figure and wrote itself an
     * RM 80.00 receipt for a transaction CHIP never had. Two entries read Paid and
     * Confirmed while RM 120.00 of the takings had never arrived.
     *
     * So the figure comes from one place only: the purchase object, where CHIP
     * reports `payment.amount` in cents. Subtraction against the registration's own
     * charge takes no part in it.
     *
     * The awkward case the old comment existed for still works, and works better.
     * Somebody transfers RM 100.00 of RM 250.00 by hand and pays the rest on the
     * gateway: the gateway reports the RM 150.00 it took, so RM 150.00 is what is
     * written. That was previously right by arithmetic coincidence and is now right
     * because it is what happened.
     *
     * IDEMPOTENT PER PURCHASE
     *
     * One gateway receipt per purchase reference, holding the amount CHIP reports for
     * it. A replayed webhook, a late event, a gateway lookup and somebody reloading
     * the return page all find that row already correct and add nothing.
     *
     * @param  array<string, mixed>|null  $payment  the purchase object, when the caller has one
     */
    private function settleLedger(EventRegistration $registration, ?array $payment = null): void
    {
        /*
         | Resynced from the ledger first and unconditionally. The column is a
         | denormalised convenience that must always be able to prove itself from the
         | rows, and every path out of this method leaves it agreeing with them.
         */
        $registration->amount_paid = (float) $registration->payments()->sum('amount');

        if ((float) $registration->amount <= 0) {
            return;
        }

        // The payload the caller is holding, or the last one stored against the row.
        // Both are the gateway's own words; neither is inferred from our books.
        $record = GatewayPaymentRecord::make($payment ?? $registration->payment_details);

        /*
         | The payload has to describe a purchase that was actually taken.
         |
         | Without this, recording a payment by hand would reach here through apply()
         | and read whatever purchase object was last stored — quite possibly an
         | abandoned checkout sitting at "created", whose `purchase.total` is the
         | charge. It would then write a gateway receipt for a purchase nobody ever
         | completed, which is the same fault in a new costume.
         */
        if (! in_array($record?->status(), self::PAID_PURCHASE_STATUSES, true)) {
            return;
        }

        $purchaseId = $record?->id() ?? $registration->payment_reference;
        $reported = $record?->amount();

        if (blank($purchaseId) || $reported === null || $reported <= 0.005) {
            /*
             | No figure from the gateway, so nothing is recorded. This is the branch
             | that used to fabricate, and refusing to write here is the whole fix: a
             | receipt we cannot evidence is worse than a short ledger, because the
             | short ledger is visible and the invented one is not.
             */
            Log::error('A gateway payment reported no amount, so no receipt was recorded.', [
                'registration' => $registration->reference,
                'purchase_id' => $purchaseId,
            ]);

            return;
        }

        /*
         | Receipts already on record for this very purchase, gateway-sourced only.
         |
         | A hand-recorded row carrying the same reference is deliberately left out of
         | the comparison: it is money somebody saw arrive by another route, and CHIP
         | reporting RM 40.00 for its own purchase says nothing about it.
         */
        $existing = $registration->payments()
            ->where('source', EventRegistrationPayment::SOURCE_GATEWAY)
            ->where('reference', $purchaseId)
            ->reorder('id')
            ->get();

        $recorded = round((float) $existing->sum('amount'), 2);

        // A replay. The gateway's figure is already on the ledger, to the cent.
        if (abs($recorded - $reported) <= 0.005) {
            return;
        }

        if ($recorded > $reported || $existing->count() > 1) {
            /*
             | More on record against this purchase than CHIP reports for it, or more
             | than one row claiming to be it. Both are the damage the old arithmetic
             | left behind, and neither is something to resolve in the middle of a
             | webhook: removing a payment row is irreversible, so it belongs to the
             | previewed correction on the Gateway Receipts screen.
             */
            Log::warning('The ledger disagrees with what the gateway reports for a purchase.', [
                'registration' => $registration->reference,
                'purchase_id' => $purchaseId,
                'rows' => $existing->count(),
                'recorded' => $recorded,
                'reported' => $reported,
            ]);

            return;
        }

        if ($receipt = $existing->first()) {
            /*
             | One row, holding less than CHIP now reports. Corrected to the reported
             | figure rather than joined by a second row, so the invariant above holds:
             | one gateway receipt per purchase, carrying what that purchase took.
             */
            $receipt->amount = $reported;
            $receipt->received_at = $record?->paidOn() ?? $receipt->received_at;
            $receipt->save();
        } else {
            $registration->payments()->create([
                'amount' => $reported,
                'received_at' => $record?->paidOn() ?? $registration->payment_synced_at ?? now(),
                'reference' => $purchaseId,
                'note' => 'Taken by the payment gateway.',
                'source' => EventRegistrationPayment::SOURCE_GATEWAY,
                'recorded_by' => null,
                'actor_label' => null,
            ]);
        }

        $registration->amount_paid = (float) $registration->payments()->sum('amount');
    }

    private function shouldApply(EventRegistration $registration, string $status): bool
    {
        if ($registration->payment_status === $status) {
            return false;
        }

        // A settled purchase must not be dragged back to pending by a late
        // arriving earlier event, nor by someone reloading the return page.
        if ($registration->payment_status === EventRegistration::PAYMENT_PAID
            && $status === EventRegistration::PAYMENT_PENDING) {
            return false;
        }

        /*
         | Nor back to partial. The same reasoning: a settled entry going backwards
         | because of a late message would put money that has arrived back into the
         | outstanding column.
         */
        if ($registration->payment_status === EventRegistration::PAYMENT_PAID
            && $status === EventRegistration::PAYMENT_PARTIAL) {
            return false;
        }

        /*
         | Nor to failed. A purchase that has been paid cannot later fail; it can only
         | be refunded, which is a separate status.
         |
         | This guard has teeth. Where a payer opened two checkouts and the first one
         | settled, the registration can be left pointing at the second, and reading
         | that one back reports "overdue". Without this, opening the entry in the
         | admin would quietly mark a paid registration as failed and take the money
         | out of the takings.
         */
        if ($registration->payment_status === EventRegistration::PAYMENT_PAID
            && $status === EventRegistration::PAYMENT_FAILED) {
            Log::info('Refused to fail a paid registration.', [
                'registration' => $registration->reference,
                'payment_reference' => $registration->payment_reference,
            ]);

            return false;
        }

        // A refund is an administrative decision. Nothing coming back from a
        // checkout should quietly undo it.
        if ($registration->payment_status === EventRegistration::PAYMENT_REFUNDED
            && $status !== EventRegistration::PAYMENT_PAID) {
            return false;
        }

        return true;
    }
}
