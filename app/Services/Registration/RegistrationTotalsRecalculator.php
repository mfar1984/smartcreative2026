<?php

namespace App\Services\Registration;

use App\Models\Event;
use App\Models\EventAddon;
use App\Models\EventRegistration;
use App\Models\EventRegistrationAddon;
use App\Services\AdminLogger;
use App\Support\AddonOrder;
use App\Support\PaymentFigures;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
 * WHERE THE CORRECTED FIGURE COMES FROM
 *
 * The head count and the event's catalogue as it stands today. Not the lines already
 * on the entry, which is what this used to do and why it reported nothing wrong with
 * the very rows that were wrong.
 *
 * The same event has been charged three different ways as its settings changed, and
 * all three shapes are sitting in the table together:
 *
 *   A  the shirt was the event fee. registration_fee 40.00, addons_total 0.00, and
 *      no item lines at all. Thirty-two of the thirty-nine live entries look like
 *      this, and re-pricing their lines finds nothing to re-price.
 *   B  the shirt became an item charged once for the entry. A line with no person on
 *      it carries the 40.00, and each person has a free line naming their size.
 *   C  the shirt is charged per head. Each person's own line carries the 40.00.
 *
 * So the derivation is: the event's fee as it stands now, charged once, plus one unit
 * of every required per-head item for every person named. AddonOrder decides what a
 * unit costs, through perParticipantUnitPrice() and chargesGroupLine(); nothing about
 * what a thing costs is worked out twice.
 *
 * The fee is read from the event rather than from the row on purpose. Shape A rows
 * carry 40.00 in registration_fee from when that was the fee, and adding it on top of
 * the shirt would charge a one-person entry that has already paid RM 40.00 another
 * RM 40.00. The fee today is RM 0.00 and that is the fee.
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
 *   - Lines created for people who have none never name a size, so no variant's
 *     stock_taken moves. Stock was taken when the entry was made and this corrects
 *     money, not inventory.
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
     * Priced off the head count and the catalogue rather than by rebuilding the
     * submission through AddonOrder: the stock on each size was taken when the entry
     * was made, so a rebuild would judge these very units against the stock they
     * themselves consumed and report a sold out shirt.
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
         | Nobody named on the entry, so there is no head count to price it from. The
         | arithmetic below would reduce it to the event fee alone and wipe whatever it
         | was charged, which is not a correction but a loss.
         */
        if ($people === 0) {
            return TotalCorrection::blocked($registration, $people, 'Nobody is named on this entry, so there is no head count to price it from.');
        }

        $catalogue = $event->addons->keyBy('id');

        /*
         | A line naming something the event no longer sells cannot be priced again,
         | and guessing is not this action's business. Checked before any arithmetic so
         | the entry is reported as left alone rather than half corrected.
         */
        foreach ($registration->addonLines as $line) {
            if (! $catalogue->has($line->event_addon_id)) {
                return TotalCorrection::blocked(
                    $registration,
                    $people,
                    sprintf('"%s" is no longer in this event\'s items, so its price cannot be worked out again.', $line->name),
                );
            }
        }

        $byAddon = $registration->addonLines->groupBy('event_addon_id');

        $figures = [];
        $additions = [];
        $addonsTotal = 0.0;

        foreach ($catalogue as $addon) {
            /** @var EloquentCollection<int, EventRegistrationAddon> $lines */
            $lines = $byAddon->get($addon->id) ?? new EloquentCollection();

            /*
             | An item ordered for the whole entry rather than one person at a time. The
             | rule that was wrong never touched it, so every line keeps exactly what it
             | was charged.
             */
            if (! $addon->isAssignedPerParticipant($event)) {
                $addonsTotal += round((float) $lines->sum('line_total'), 2);

                continue;
            }

            $priced = $this->perHeadFigures($event, $registration, $addon, $lines);

            if ($priced['blocked'] !== null) {
                return TotalCorrection::blocked($registration, $people, $priced['blocked']);
            }

            $addonsTotal += $priced['total'];
            $figures += $priced['figures'];
            $additions = array_merge($additions, $priced['additions']);
        }

        $addonsTotal = round($addonsTotal, 2);
        $fee = round($event->registrationAmount(), 2);

        /*
         | A row claiming an item total with no line behind it and nothing in the
         | catalogue that could account for it. That is a damaged record, and quietly
         | writing a smaller charge over it is not this action's business.
         |
         | Narrower than it reads: an entry from when the shirt was the event fee also
         | has no lines, but the catalogue does account for it — the shirt is required
         | per head — so the arithmetic above gives it a real figure and it is corrected
         | rather than blocked. This only catches the case where the derivation finds
         | nothing at all to charge.
         */
        if ($registration->addonLines->isEmpty()
            && $addonsTotal <= 0.005
            && (float) $registration->addons_total > 0.005) {
            return TotalCorrection::blocked($registration, $people, 'No items on this entry, so there is nothing to re-price.');
        }

        return new TotalCorrection(
            registration: $registration,
            people: $people,
            currentAmount: (float) $registration->amount,
            correctedAmount: round($fee + $addonsTotal, 2),
            correctedAddonsTotal: $addonsTotal,
            correctedRegistrationFee: $fee,
            lines: $figures,
            additions: $additions,
        );
    }

    /**
     * One per-head item, priced for everybody named on the entry.
     *
     * Each person is taken in turn rather than each stored line, because the thing
     * being corrected is precisely that some people have no line. Somebody who has
     * lines keeps them, re-priced and with their size and quantity untouched; somebody
     * who has none and must have one gets a line created for them.
     *
     * @param  EloquentCollection<int, EventRegistrationAddon>  $lines
     * @return array{total: float, figures: array<int, array<string, float|int>>, additions: array<int, array<string, mixed>>, blocked: string|null}
     */
    private function perHeadFigures(
        Event $event,
        EventRegistration $registration,
        EventAddon $addon,
        EloquentCollection $lines,
    ): array {
        $total = 0.0;
        $figures = [];
        $additions = [];
        $units = 0;

        /*
         | Whether everybody named owes one of these whether or not a line says so.
         |
         | Required and still on sale, which is the shirt: the organiser has decided
         | every entrant takes one, so a person with nothing recorded is a gap in the
         | record rather than somebody who declined. An optional item is the opposite —
         | silence means they did not want it — so for those only what is recorded is
         | priced.
         */
        $owedByEveryone = $addon->is_active && $addon->is_required;

        foreach ($registration->participants as $person) {
            $own = $lines->where('event_participant_id', $person->id);

            if ($own->isEmpty()) {
                if (! $owedByEveryone) {
                    continue;
                }

                /*
                 | A charge owed with no size against it.
                 |
                 | Created without a variant rather than with a guessed one. The money
                 | is owed because the item is required; which size they want is a
                 | question for the counter, and inventing an answer would both misstate
                 | what to print and move stock that nobody has asked for.
                 */
                $unit = AddonOrder::perParticipantUnitPrice($event, $addon, null);

                $additions[] = [
                    'event_participant_id' => $person->id,
                    'event_addon_id' => $addon->id,
                    'event_addon_variant_id' => null,
                    'name' => $addon->name,
                    'variant_label' => null,
                    'unit_price' => $unit,
                    'quantity' => 1,
                    'line_total' => $unit,
                ];

                $total += $unit;
                $units++;

                continue;
            }

            foreach ($own as $line) {
                $variant = null;

                if ($line->event_addon_variant_id !== null) {
                    $variant = $addon->variants->firstWhere('id', $line->event_addon_variant_id);

                    if ($variant === null) {
                        return [
                            'total' => 0.0,
                            'figures' => [],
                            'additions' => [],
                            'blocked' => sprintf(
                                'The "%s" option on this entry is no longer in the catalogue, so its price cannot be worked out again.',
                                $line->variant_label ?: $line->name,
                            ),
                        ];
                    }
                }

                $quantity = max(1, (int) $line->quantity);
                $unit = AddonOrder::perParticipantUnitPrice($event, $addon, $variant);
                $lineTotal = round($unit * $quantity, 2);

                /*
                 | Recorded even when it matches what is stored. commit() writes the
                 | whole set for an entry whose total moves, so the lines on it always
                 | add up to the amount: CHIP totals the line items itself and refuses a
                 | purchase whose lines disagree with the charge.
                 */
                $figures[$line->id] = [
                    'unit_price' => $unit,
                    'line_total' => $lineTotal,
                ];

                $total += $lineTotal;
                $units += $quantity;
            }
        }

        /*
         | The one charge for the whole entry, which is shape B's line with nobody on
         | it. Still correct while the event prices the item that way; once the price
         | has moved onto each person's line it is money counted twice, so it is zeroed
         | and kept as a record of the order rather than deleted.
         |
         | The exception is an item with no sizes. A line with no person and no size on
         | one of those is an ordinary bulk purchase from before the event collected
         | choices per head, and zeroing it would wipe a real charge.
         */
        $baseLines = $lines->whereNull('event_participant_id')->values();
        $chargesGroup = AddonOrder::chargesGroupLine($event, $addon) && $units > 0;

        foreach ($baseLines as $index => $base) {
            if ($chargesGroup && $index === 0) {
                $unit = round($addon->unitPrice(), 2);

                $figures[$base->id] = [
                    'unit_price' => $unit,
                    'quantity' => 1,
                    'line_total' => $unit,
                ];

                $total += $unit;

                continue;
            }

            $unit = $addon->hasVariants() ? 0.0 : round((float) $base->unit_price, 2);
            $quantity = max(1, (int) $base->quantity);
            $lineTotal = round($unit * $quantity, 2);

            $figures[$base->id] = [
                'unit_price' => $unit,
                'line_total' => $lineTotal,
            ];

            $total += $lineTotal;
        }

        // The entry owes the one charge and has no line to carry it, which is a shape A
        // row on an event that still prices this item for the group as a whole.
        if ($chargesGroup && $baseLines->isEmpty()) {
            $unit = round($addon->unitPrice(), 2);

            $additions[] = [
                'event_participant_id' => null,
                'event_addon_id' => $addon->id,
                'event_addon_variant_id' => null,
                'name' => $addon->name,
                'variant_label' => null,
                'unit_price' => $unit,
                'quantity' => 1,
                'line_total' => $unit,
            ];

            $total += $unit;
        }

        return [
            'total' => round($total, 2),
            'figures' => $figures,
            'additions' => $additions,
            'blocked' => null,
        ];
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
            'registration_fee' => (float) $correction->registration->registration_fee,
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
             | Lines for people who had none, so the invoice itemises what is owed per
             | head instead of naming one lump nobody can check.
             |
             | Guarded on the person belonging to this entry, and on there being no line
             | for that item already: a second press must add nothing, and the row is
             | locked above but these are not.
             |
             | None of them names a variant, so no stock_taken anywhere moves.
             */
            foreach ($correction->additions as $addition) {
                $exists = EventRegistrationAddon::query()
                    ->where('event_registration_id', $registration->id)
                    ->where('event_addon_id', $addition['event_addon_id'])
                    ->when(
                        $addition['event_participant_id'] === null,
                        fn ($query) => $query->whereNull('event_participant_id'),
                        fn ($query) => $query->where('event_participant_id', $addition['event_participant_id']),
                    )
                    ->exists();

                if ($exists) {
                    continue;
                }

                if ($addition['event_participant_id'] !== null
                    && ! $registration->participants()->whereKey($addition['event_participant_id'])->exists()) {
                    continue;
                }

                $registration->addonLines()->create($addition);
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

            /*
             | The fee as the event charges it today, not the snapshot on the row.
             |
             | Entries taken while the shirt was the event fee carry RM 40.00 here with
             | no item behind it. Leaving it would charge that RM 40.00 a second time on
             | top of the shirt, and would also put a phantom "event registration" line
             | on the CHIP page beside the shirts.
             */
            $registration->registration_fee = $correction->correctedRegistrationFee;
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
            'registration_fee' => (float) $written->registration_fee,
            'addons_total' => (float) $written->addons_total,
            'payment_status' => $written->payment_status,
            'status' => $written->status,
            'people' => $correction->people,
            'difference' => $correction->difference(),
            'lines_added' => $correction->additionsCount(),
            'amount_paid' => (float) $written->amount_paid,
            'outstanding' => $written->outstandingAmount(),
            'reason' => 'Items re-priced per participant from the head count.',
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
