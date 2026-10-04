<?php

namespace App\Services\Registration;

use App\Models\Event;
use App\Models\EventAddon;
use App\Models\EventRegistration;
use App\Models\EventRegistrationAddon;
use App\Services\AdminLogger;
use App\Support\AddonOrder;
use App\Support\PaymentFigures;
use Illuminate\Support\Facades\DB;

/**
 * Re-price entries that were charged under the old add-on rule.
 *
 * An add-on's own price used to be one charge for the whole registration, which is
 * right for a group buying one thing between them and wrong for merchandise: a party
 * of six choosing a RM40 shirt each was charged RM40. AddonOrder now prices those per
 * head when the event says so, but it only prices new submissions. Everything already
 * stored still names the old figure, and a live table of them cannot be fixed by hand.
 *
 * Two steps, never one. preview() reads and reports; apply() writes, and only the rows
 * preview() said would change. The separation is the whole point: this runs against
 * production while people are registering, so the operator sees every figure before
 * anything moves.
 *
 * What makes it safe on a live table:
 *
 *   - Every figure is recomputed from the database. Nothing is read from a request.
 *   - Rows already charging the right total are not written at all, which also makes
 *     a second run a no-op.
 *   - Each row is re-read under a lock and only written if it still holds the amount
 *     the preview was built from. No mass UPDATE, no delete, no truncate.
 *   - A changed row leaves an audit entry with the old and new totals, and an
 *     activity entry naming who did it.
 */
class RegistrationTotalsRecalculator
{
    /**
     * What applying this to one event would do, entry by entry.
     *
     * Read-only. Nothing in here writes, which is what lets the operator press it as
     * often as they like before deciding.
     *
     * @return array<int, TotalCorrection>
     */
    public function preview(Event $event): array
    {
        $event->loadMissing('addons.variants');

        return $event->registrations()
            ->with(['participants', 'addonLines', 'payments'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (EventRegistration $registration) => $this->correctionFor($event, $registration))
            ->all();
    }

    /**
     * Write the corrected totals, and nothing else.
     *
     * @return array<int, TotalCorrection>  the entries that were actually changed
     */
    public function apply(Event $event): array
    {
        $applied = [];

        foreach ($this->preview($event) as $correction) {
            if (! $correction->changes()) {
                continue;
            }

            if ($this->commit($correction)) {
                $applied[] = $correction;
            }
        }

        return $applied;
    }

    /* ---------------------------------------------------------------------
     | Working out the right figure
     * ------------------------------------------------------------------ */

    /**
     * What one entry should be charged.
     *
     * Priced off the catalogue and the lines that are already on the entry, rather
     * than by rebuilding the submission through AddonOrder: the stock on each size was
     * taken when the entry was made, so a rebuild would judge these very units against
     * the stock they themselves consumed and report a sold out shirt. The rule it
     * applies is AddonOrder's own, read through the two helpers on it, so there is one
     * definition of what a unit costs.
     */
    private function correctionFor(Event $event, EventRegistration $registration): TotalCorrection
    {
        $people = $registration->participants->count();

        if ($registration->status === EventRegistration::STATUS_CANCELLED) {
            return TotalCorrection::blocked($registration, $people, 'Cancelled, so its charge is left as it stands.');
        }

        /*
         | A refund has already been settled against the figure on the row. Raising the
         | charge afterwards would reopen a balance on money that has gone back out,
         | which is a conversation rather than an arithmetic correction.
         */
        if ($registration->payment_status === EventRegistration::PAYMENT_REFUNDED
            || (float) $registration->refunded_amount > 0) {
            return TotalCorrection::blocked($registration, $people, 'Refunded, so its charge is left as it stands.');
        }

        /*
         | Nothing bought, so there is nothing here to re-price. Said as a reason rather
         | than worked out, because the arithmetic below would otherwise reduce such an
         | entry to its fee alone: an entry carrying an add-on total with no lines behind
         | it is a damaged record, and quietly writing a smaller charge over it is not
         | this action's business.
         */
        if ($registration->addonLines->isEmpty()) {
            return TotalCorrection::blocked($registration, $people, 'No items on this entry, so there is nothing to re-price.');
        }

        $catalogue = $event->addons->keyBy('id');

        $lines = [];
        $addonsTotal = 0.0;

        foreach ($registration->addonLines as $line) {
            $addon = $catalogue->get($line->event_addon_id);

            if ($addon === null) {
                return TotalCorrection::blocked(
                    $registration,
                    $people,
                    sprintf('"%s" is no longer in this event\'s items, so its price cannot be worked out again.', $line->name),
                );
            }

            /*
             | An item ordered for the whole entry rather than one person at a time. The
             | rule that was wrong never touched it, so the line keeps exactly what it
             | was charged.
             */
            if (! $addon->isAssignedPerParticipant($event)) {
                $addonsTotal += (float) $line->line_total;

                continue;
            }

            $variant = null;

            if ($line->event_addon_variant_id !== null) {
                $variant = $addon->variants->firstWhere('id', $line->event_addon_variant_id);

                if ($variant === null) {
                    return TotalCorrection::blocked(
                        $registration,
                        $people,
                        sprintf('The "%s" option on this entry is no longer in the catalogue, so its price cannot be worked out again.', $line->variant_label ?: $line->name),
                    );
                }
            }

            $unit = $this->unitPriceFor($event, $addon, $line, $variant !== null ? $variant->unitPrice() : null);
            $total = round($unit * (int) $line->quantity, 2);

            $addonsTotal += $total;

            /*
             | Recorded even when it matches what is stored. commit() writes the whole
             | set for an entry whose total moves, so the lines on it always add up to
             | the amount: CHIP totals the line items itself and refuses a purchase
             | whose lines disagree with the charge.
             */
            $lines[$line->id] = [
                'unit_price' => $unit,
                'line_total' => $total,
            ];
        }

        $addonsTotal = round($addonsTotal, 2);

        return new TotalCorrection(
            registration: $registration,
            people: $people,
            currentAmount: (float) $registration->amount,
            correctedAmount: round((float) $registration->registration_fee + $addonsTotal, 2),
            correctedAddonsTotal: $addonsTotal,
            lines: $lines,
        );
    }

    /**
     * What one unit on a per-person add-on should cost.
     *
     * Three shapes of line, and they are told apart by what they carry rather than by
     * guessing:
     *
     *   a size chosen for somebody  -> the add-on's own price per head, plus whatever
     *                                  that size adds
     *   a quantity for somebody     -> the add-on's price per unit, which is what an
     *                                  item without sizes has always charged
     *   neither                     -> the one charge for the whole entry. Zero when
     *                                  the price has moved onto each person's line,
     *                                  otherwise left exactly as it was charged.
     *
     * The last case is the careful one. A line with no person and no size on an item
     * that has no sizes is an ordinary bulk purchase from before the event collected
     * choices per head, and zeroing it would wipe a real charge.
     */
    private function unitPriceFor(Event $event, EventAddon $addon, EventRegistrationAddon $line, ?float $variantPrice): float
    {
        if ($variantPrice !== null) {
            return round(AddonOrder::perParticipantUnitBase($event, $addon) + $variantPrice, 2);
        }

        if ($line->event_participant_id !== null) {
            return round($addon->unitPrice(), 2);
        }

        return $addon->hasVariants() && ! AddonOrder::chargesGroupLine($event, $addon)
            ? 0.0
            : round((float) $line->unit_price, 2);
    }

    /* ---------------------------------------------------------------------
     | Writing it
     * ------------------------------------------------------------------ */

    /**
     * Write one corrected entry, or decline to.
     *
     * The guard inside the transaction is what makes this safe while people are
     * paying: the row is re-read under a lock and only written if it still holds the
     * amount the preview was built from. An entry somebody settled, edited or had
     * corrected in the seconds since is left untouched rather than overwritten with a
     * figure worked out against a row that no longer exists in that form.
     *
     * @return bool  whether the row was written
     */
    private function commit(TotalCorrection $correction): bool
    {
        $before = [
            'amount' => (float) $correction->registration->amount,
            'addons_total' => (float) $correction->registration->addons_total,
            'payment_status' => $correction->registration->payment_status,
            'status' => $correction->registration->status,
        ];

        /** @var EventRegistration|null $written */
        $written = DB::transaction(function () use ($correction) {
            $registration = EventRegistration::query()
                ->whereKey($correction->registration->id)
                ->lockForUpdate()
                ->first();

            if ($registration === null) {
                return null;
            }

            if (abs((float) $registration->amount - $correction->currentAmount) > 0.005) {
                return null;
            }

            foreach ($correction->lines as $lineId => $figures) {
                /*
                 | One row at a time, keyed on the line and fenced to this registration.
                 | A statement that could touch another entry's invoice has no business
                 | running on a live table.
                 */
                EventRegistrationAddon::query()
                    ->whereKey($lineId)
                    ->where('event_registration_id', $registration->id)
                    ->update($figures);
            }

            /*
             | Any checkout page still open at the gateway quotes the old figure, so it
             | must not be handed out again: reusableCheckout() offers the most recent
             | attempt back to an impatient payer, and that page would take RM 40.00
             | against a charge that is now RM 240.00 while the webhook reported the
             | whole thing settled.
             |
             | The URL is cleared rather than the attempt removed. purchase_id is how the
             | webhook recognises a payment that arrives late, and losing it would orphan
             | money. Pressing Pay now opens a fresh purchase for what is actually owed.
             */
            $registration->checkouts()
                ->whereNotNull('checkout_url')
                ->update(['checkout_url' => null]);

            $registration->addons_total = $correction->correctedAddonsTotal;
            $registration->amount = $correction->correctedAmount;

            /*
             | The badge follows the money.
             |
             | Only an entry reading "paid" is touched, and only when the receipts no
             | longer cover the corrected charge. Something has genuinely arrived, so
             | "unpaid" would hide it; all of it has not, so "paid" is a lie. Partly
             | Paid is the one honest answer, and it is also what the reminder asks for
             | when it works out what to chase.
             |
             | Written here rather than through RegistrationPaymentUpdater on purpose.
             | That class refuses to move a paid entry backwards, which is the right
             | guard for a late gateway message and the wrong one for a deliberate,
             | logged correction by an administrator.
             */
            $outstanding = round($correction->correctedAmount - (float) $registration->amount_paid, 2);

            if ($registration->payment_status === EventRegistration::PAYMENT_PAID && $outstanding > 0.005) {
                $registration->payment_status = $registration->hasMoneyReceived()
                    ? EventRegistration::PAYMENT_PARTIAL
                    : EventRegistration::PAYMENT_UNPAID;

                // The place was confirmed because the entry was settled. It is not
                // settled any more, so the badge must stop saying it is.
                if ($registration->status === EventRegistration::STATUS_CONFIRMED) {
                    $registration->status = EventRegistration::STATUS_PENDING;
                }
            }

            $registration->save();

            return $registration;
        });

        if ($written === null) {
            return false;
        }

        AdminLogger::audit($written, 'amount.recalculated', $before, [
            'amount' => (float) $written->amount,
            'addons_total' => (float) $written->addons_total,
            'payment_status' => $written->payment_status,
            'status' => $written->status,
            'people' => $correction->people,
            'difference' => $correction->difference(),
            'amount_paid' => (float) $written->amount_paid,
            'outstanding' => $written->outstandingAmount(),
            'reason' => 'Add-ons re-priced per participant.',
        ]);

        AdminLogger::activity(
            'participants.recalculate',
            sprintf(
                'Recalculated %s: %d people, %s became %s (%s). Now %s, %s outstanding.',
                $written->reference,
                $correction->people,
                PaymentFigures::money($correction->currentAmount),
                PaymentFigures::money($correction->correctedAmount),
                $correction->differenceLabel(),
                $written->paymentStatusLabel(),
                $written->outstandingAmountLabel(),
            ),
        );

        return true;
    }
}
