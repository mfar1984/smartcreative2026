<?php

namespace App\Services\Payment;

use App\Models\EventRegistration;
use App\Models\EventRegistrationPayment;
use App\Services\AdminLogger;
use App\Services\EventNotifier;
use App\Services\Messaging\StaffAlerts;
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
    public function __construct(
        private readonly EventNotifier $notifier,
        private readonly StaffAlerts $alerts,
    ) {
    }

    /**
     * @param  string  $status  one of EventRegistration::PAYMENT_*
     * @param  string  $source  short description for the log, e.g. the gateway event name
     * @return bool  whether anything changed
     */
    public function apply(EventRegistration $registration, string $status, string $source): bool
    {
        if (! $this->shouldApply($registration, $status)) {
            return false;
        }

        $before = [
            'payment_status' => $registration->payment_status,
            'status' => $registration->status,
        ];

        $registration->payment_status = $status;

        // Paying confirms the place; a failure leaves it pending so an
        // administrator can chase it rather than losing the entry.
        if ($status === EventRegistration::PAYMENT_PAID) {
            $registration->status = EventRegistration::STATUS_CONFIRMED;
            $this->settleLedger($registration);
        }

        $registration->save();

        AdminLogger::activity(
            'payments.status',
            sprintf('Payment for %s became %s (%s).', $registration->reference, $status, $source),
        );

        AdminLogger::audit($registration, 'payment.updated', $before, [
            'payment_status' => $registration->payment_status,
            'status' => $registration->status,
            'source' => $source,
        ]);

        // Told once, and only once: shouldApply() has already refused a repeat
        // of a status the registration is sitting on, so a reloaded return page
        // or a webhook arriving twice cannot send a second receipt.
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
            $this->apply($registration, $status, 'gateway lookup');
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
    ): EventRegistrationPayment {
        $user = Auth::user();

        $before = [
            'payment_status' => $registration->payment_status,
            'amount_paid' => (float) $registration->amount_paid,
        ];

        $payment = DB::transaction(function () use ($registration, $amount, $receivedAt, $reference, $note, $user) {
            $payment = $registration->payments()->create([
                'amount' => $amount,
                'received_at' => $receivedAt,
                'reference' => $reference,
                'note' => $note,
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
    public function markPending(EventRegistration $registration, string $gatewayReference, string $source): void
    {
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
     * Make the ledger add up to the charge, and the running total match the ledger.
     *
     * A gateway payment has no receipt row of its own: nobody typed it in. Without
     * one this table would hold only hand-recorded money, and Settlements, which
     * reconciles against a bank statement, would show a fraction of the takings.
     *
     * Written as "top up to the charge" rather than "insert the charge" so it is
     * correct in the awkward case as well: somebody transfers RM 100 of RM 250 by
     * hand, then pays the rest on the gateway. The row added is the RM 150 that
     * actually arrived, not a second RM 250.
     *
     * Does nothing when the ledger already covers the charge, which is what happens
     * when a hand-recorded receipt is the thing that completed it.
     */
    private function settleLedger(EventRegistration $registration): void
    {
        if ((float) $registration->amount <= 0) {
            return;
        }

        $recorded = (float) $registration->payments()->sum('amount');
        $shortfall = round((float) $registration->amount - $recorded, 2);

        if ($shortfall > 0.005) {
            $registration->payments()->create([
                'amount' => $shortfall,
                'received_at' => $registration->payment_synced_at ?? now(),
                'reference' => $registration->payment_reference,
                'note' => 'Taken by the payment gateway.',
                'source' => EventRegistrationPayment::SOURCE_GATEWAY,
                'recorded_by' => null,
                'actor_label' => null,
            ]);

            $recorded = round($recorded + $shortfall, 2);
        }

        $registration->amount_paid = $recorded;
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

        // A refund is an administrative decision. Nothing coming back from a
        // checkout should quietly undo it.
        if ($registration->payment_status === EventRegistration::PAYMENT_REFUNDED
            && $status !== EventRegistration::PAYMENT_PAID) {
            return false;
        }

        return true;
    }
}
