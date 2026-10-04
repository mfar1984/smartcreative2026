<?php

namespace App\Services\Payment;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventRegistrationPayment;
use App\Services\AdminLogger;
use App\Support\GatewayPaymentRecord;
use App\Support\PaymentFigures;
use Illuminate\Support\Facades\DB;

/**
 * Find receipt rows the gateway's own record contradicts, and offer to remove them.
 *
 * WHY THIS EXISTS
 *
 * RegistrationPaymentUpdater::settleLedger() used to insert "whatever is left of the
 * charge" when a gateway event said paid. That is correct while a charge never moves.
 * Recheck add-on totals moves one — a group of three went from RM 40.00 to RM 120.00 —
 * and the next PAID event for the same purchase recomputed the shortfall against the
 * new figure and wrote itself a receipt for a transaction CHIP never had. Two entries
 * on one event read Paid and Confirmed while RM 120.00 of the takings had never
 * arrived. settleLedger() no longer does that; this is for the rows it already wrote,
 * on this database and on any other where more of them have appeared since.
 *
 * WHAT MAKES A ROW SUSPECT, AND IT IS EVIDENCE RATHER THAN A GUESS
 *
 * Three conditions, all of them required:
 *
 *   1. It shares a purchase reference with another row on the same registration.
 *      One receipt per purchase is the invariant; two is the symptom.
 *   2. The rows carrying that reference add up to MORE than the stored gateway
 *      payload reports the purchase took. The payload is CHIP's own answer, kept on
 *      the registration by the webhook and by every gateway lookup, and it is read
 *      here rather than re-derived: `payment.amount`, in cents.
 *   3. It is gateway-sourced, with nobody recorded against it and no proof attached.
 *
 * No payload, or a payload describing a different purchase, means no evidence, and no
 * evidence means the registration is reported as clean. This action never deletes a
 * row on the strength of arithmetic alone.
 *
 * WHAT IS NEVER TOUCHED
 *
 * Anything recorded by hand. A row with `recorded_by` set, or a source other than
 * gateway, or a transfer slip attached, is an administrator's receipt for money
 * somebody saw arrive, and it is outside this action's business whatever the gateway
 * says about its own purchases. Those rows are not even counted against the reported
 * figure: CHIP reporting RM 40.00 for its own purchase says nothing about a bank
 * transfer that happens to quote the same reference.
 *
 * TWO STEPS, NEVER ONE
 *
 * preview() reads and reports and writes nothing at all. correct() writes, re-deriving
 * every finding from the database under a lock rather than trusting the preview or
 * anything in the request, so a replayed or edited post cannot name its own rows.
 */
class GatewayReceiptAudit
{
    /**
     * What one event's gateway receipts look like against the gateway's own record.
     *
     * Read-only. Returns a finding per registration that has something wrong with it,
     * and nothing for the ones that are clean, so a quiet screen means a clean event.
     *
     * @return array<int, PhantomReceiptFinding>
     */
    public function preview(Event $event): array
    {
        $findings = [];

        $registrations = $event->registrations()
            ->with(['payments', 'participants'])
            ->orderBy('id')
            ->get();

        foreach ($registrations as $registration) {
            foreach ($this->findingsFor($registration) as $finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * Remove the rows the diagnostic identified, and bring the row back into step.
     *
     * Everything is worked out again here from the database. The preview is a screen,
     * not an instruction: an entry somebody settled, corrected or refunded in the
     * seconds since is judged on what it holds now.
     *
     * @return array<int, PhantomReceiptFinding>  what was actually corrected
     */
    public function correct(Event $event): array
    {
        $corrected = [];

        foreach ($this->preview($event) as $finding) {
            if ($this->commit($finding)) {
                $corrected[] = $finding;
            }
        }

        return $corrected;
    }

    /* ---------------------------------------------------------------------
     | Finding them
     * ------------------------------------------------------------------ */

    /**
     * Every purchase on one registration whose receipts exceed what the gateway
     * reports for it.
     *
     * @return array<int, PhantomReceiptFinding>
     */
    public function findingsFor(EventRegistration $registration): array
    {
        $reported = $this->reportedByPurchase($registration);

        if ($reported === []) {
            return [];
        }

        $findings = [];

        $groups = $registration->payments
            ->filter(fn (EventRegistrationPayment $payment) => $this->isGatewayWritten($payment))
            ->groupBy('reference');

        foreach ($groups as $reference => $rows) {
            $purchaseId = (string) $reference;

            if (! array_key_exists($purchaseId, $reported)) {
                // The gateway has told us nothing about this purchase, so there is
                // nothing to contradict it with.
                continue;
            }

            // One row for a purchase is the healthy shape. The symptom is two.
            if ($rows->count() < 2) {
                continue;
            }

            $figure = $reported[$purchaseId];
            $recorded = round((float) $rows->sum('amount'), 2);

            if ($recorded <= $figure + 0.005) {
                continue;
            }

            $phantoms = [];
            $kept = 0.0;

            /*
             | Oldest first, keeping rows while they still fit inside what the gateway
             | reports. The genuine payment is the one that arrived when the purchase
             | settled; the fabrications came later, when a charge was corrected. Any
             | row that would push the total past CHIP's figure is one of them.
             */
            foreach ($rows->sortBy('id') as $row) {
                $amount = round((float) $row->amount, 2);

                if ($kept + $amount <= $figure + 0.005) {
                    $kept = round($kept + $amount, 2);

                    continue;
                }

                $phantoms[] = new PhantomReceipt(
                    payment: $row,
                    purchaseId: $purchaseId,
                    reported: $figure,
                    recorded: $recorded,
                );
            }

            if ($phantoms === []) {
                continue;
            }

            $findings[] = new PhantomReceiptFinding(
                registration: $registration,
                purchaseId: $purchaseId,
                reported: $figure,
                recorded: $recorded,
                phantoms: $phantoms,
            );
        }

        return $findings;
    }

    /**
     * What the gateway reports, per purchase id, from the payload stored on the row.
     *
     * Keyed on the payload's own `id` so a figure can only ever be compared with the
     * purchase it describes. The registration's `payment_reference` is not used as the
     * key: it holds the most recent attempt, which is not necessarily the purchase the
     * payload is about.
     *
     * @return array<string, float>
     */
    private function reportedByPurchase(EventRegistration $registration): array
    {
        $record = GatewayPaymentRecord::make($registration->payment_details);

        if ($record === null) {
            return [];
        }

        $id = $record->id();
        $amount = $record->amount();

        if (blank($id) || $amount === null) {
            return [];
        }

        return [(string) $id => round($amount, 2)];
    }

    /**
     * Whether this row was written by the gateway path rather than by a person.
     *
     * All three tests, not just the source column. A row carrying a user, or a
     * transfer slip, is somebody's receipt for money they saw, and it is never in
     * scope however it is labelled.
     */
    private function isGatewayWritten(EventRegistrationPayment $payment): bool
    {
        return $payment->source === EventRegistrationPayment::SOURCE_GATEWAY
            && $payment->recorded_by === null
            && blank($payment->proof_path)
            && filled($payment->reference);
    }

    /* ---------------------------------------------------------------------
     | Writing it
     * ------------------------------------------------------------------ */

    /**
     * Remove one registration's phantom rows, or decline to.
     *
     * The trail is written before the delete, because afterwards there is nothing left
     * to describe. Each removed row goes into the audit entry in full, and the activity
     * entry names who pressed it.
     *
     * @return bool  whether anything was removed
     */
    private function commit(PhantomReceiptFinding $finding): bool
    {
        $before = [
            'amount' => (float) $finding->registration->amount,
            'amount_paid' => (float) $finding->registration->amount_paid,
            'payment_status' => $finding->registration->payment_status,
            'status' => $finding->registration->status,
            'outstanding' => $finding->registration->outstandingAmount(),
        ];

        $removed = array_map(fn (PhantomReceipt $receipt) => $receipt->toTrail(), $finding->phantoms);
        $ids = array_map(fn (PhantomReceipt $receipt) => $receipt->payment->id, $finding->phantoms);

        /** @var EventRegistration|null $written */
        $written = DB::transaction(function () use ($finding, $ids) {
            $registration = EventRegistration::query()
                ->whereKey($finding->registration->id)
                ->lockForUpdate()
                ->first();

            if ($registration === null) {
                return null;
            }

            /*
             | Deleted one row at a time, keyed on the row and fenced to this
             | registration, to gateway-written rows and to the amount the diagnostic
             | judged. A statement that could reach another entry's ledger, or an
             | administrator's receipt, has no business running here.
             |
             | The amount is in the WHERE clause so a row that changed underneath the
             | preview is skipped rather than removed on stale evidence.
             */
            $deleted = 0;

            foreach ($finding->phantoms as $receipt) {
                $deleted += EventRegistrationPayment::query()
                    ->whereKey($receipt->payment->id)
                    ->where('event_registration_id', $registration->id)
                    ->where('source', EventRegistrationPayment::SOURCE_GATEWAY)
                    ->whereNull('recorded_by')
                    ->whereNull('proof_path')
                    ->where('reference', $receipt->purchaseId)
                    ->where('amount', $receipt->amount())
                    ->delete();
            }

            if ($deleted === 0) {
                return null;
            }

            /*
             | Summed from what is left rather than subtracted from the column. The
             | ledger is the record and the column is the convenience, so it is rebuilt
             | from the rows every time they move.
             */
            $registration->amount_paid = (float) $registration->payments()->sum('amount');

            // The badge follows the money, through the one derivation everything else
            // uses, so this correction cannot invent a status of its own.
            $registration->payment_status = $registration->paymentStatusFromLedger();

            /*
             | The place was confirmed because the entry read as settled. It does not
             | any more, so the badge must stop saying so. A cancelled or waitlisted
             | entry keeps whatever it was: this corrects money, not attendance.
             */
            if ($registration->status === EventRegistration::STATUS_CONFIRMED
                && ! $registration->isSettledInFull()) {
                $registration->status = EventRegistration::STATUS_PENDING;
            }

            $registration->save();

            return $registration;
        });

        if ($written === null) {
            return false;
        }

        AdminLogger::audit($written, 'payment.phantom-removed', $before + [
            'payments_removed' => $removed,
        ], [
            'amount' => (float) $written->amount,
            'amount_paid' => (float) $written->amount_paid,
            'payment_status' => $written->payment_status,
            'status' => $written->status,
            'outstanding' => $written->outstandingAmount(),
            'purchase_id' => $finding->purchaseId,
            'gateway_reported' => $finding->reported,
            'ledger_recorded' => $finding->recorded,
            'removed_ids' => $ids,
            'reason' => 'Receipts exceeded what the stored gateway payload reports for this purchase.',
        ]);

        AdminLogger::activity(
            'payments.phantom',
            sprintf(
                'Removed %d gateway %s totalling %s from %s. The gateway reports %s for purchase %s. Now %s, %s outstanding.',
                count($ids),
                count($ids) === 1 ? 'receipt' : 'receipts',
                $finding->phantomTotalLabel(),
                $written->reference,
                $finding->reportedLabel(),
                $finding->purchaseId,
                $written->paymentStatusLabel(),
                $written->outstandingAmountLabel(),
            ),
        );

        return true;
    }

    /**
     * What a whole run would take out of the takings.
     *
     * @param  array<int, PhantomReceiptFinding>  $findings
     */
    public static function total(array $findings): float
    {
        return round(array_sum(array_map(
            fn (PhantomReceiptFinding $finding) => $finding->phantomTotal(),
            $findings,
        )), 2);
    }

    /**
     * @param  array<int, PhantomReceiptFinding>  $findings
     */
    public static function totalLabel(array $findings): string
    {
        return PaymentFigures::money(self::total($findings));
    }
}
