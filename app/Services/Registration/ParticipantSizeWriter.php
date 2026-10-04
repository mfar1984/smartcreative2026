<?php

namespace App\Services\Registration;

use App\Models\EventAddon;
use App\Models\EventAddonVariant;
use App\Models\EventParticipant;
use App\Models\EventRegistration;
use App\Models\EventRegistrationAddon;
use App\Models\User;
use App\Services\AdminLogger;
use App\Support\ParticipantSizes;
use App\Support\PaymentFigures;
use Illuminate\Support\Facades\DB;

/**
 * Records the size each person on an entry has chosen, and nothing else.
 *
 * WHAT THIS MUST NOT DO
 *
 * Move money. Not `amount`, not `amount_paid`, not `registration_fee`, not
 * `addons_total`, not `payment_status`, not `status`. The charge on these entries was
 * corrected once already, by hand, against a live table holding real receipts, and a
 * size is not a purchase: the shirt was paid for when the entry was made. Nothing in
 * this class writes to event_registrations at all, which is the simplest way to be
 * able to promise that.
 *
 * THE THREE SHAPES IT HAS TO HANDLE
 *
 *   has a size      the recorded choice is offered back and may be changed. Changing
 *                   it moves the stock count off the old size and onto the new one.
 *   line, no size   the line already carries the charge and names no size, which is
 *                   what the totals recalculation deliberately left behind. The
 *                   variant is written onto THAT line. No second line is created, and
 *                   unit_price, quantity and line_total are not touched.
 *   no line at all  a single-person entry whose RM 40.00 still sits in
 *                   registration_fee from when the shirt was the event fee. A line is
 *                   created at 0.00 so the shirt is on the record and appears in the
 *                   size list, without the total moving by a sen.
 *
 * WHY THE NEW LINE IS 0.00, AND WHY IT MUST STAY 0.00
 *
 * These people have already paid for the shirt. Their RM 40.00 is in
 * registration_fee, not in addons_total, because they registered while the shirt WAS
 * the fee. Writing the item's price onto the new line would charge it a second time —
 * and `addons_total` is the sum of the line totals, so a priced line would also make
 * the entry's own figures disagree with themselves. The line exists to record the size
 * and the entitlement to a shirt, not to charge for one. Do not "fix" the zero.
 *
 * Every id is resolved from the registration and the event's own catalogue inside the
 * transaction. The submitted payload only ever says which variant is wanted, so it
 * cannot name another entry's person, another event's item, or its own price.
 *
 * WHO IS RECORDING IT
 *
 * Two callers: the registrant, through the signed link in their email, and a member of
 * staff correcting or taking the answer at the counter on event day. The second passes
 * the acting user, which does two things and nothing else — it names them in the trail,
 * and it turns on the refusal below. Everything about what gets written, and the
 * promise that no money moves, is identical either way.
 *
 * WHY STAFF ARE REFUSED AN OPTION THAT IS PRICED DIFFERENTLY
 *
 * An option may carry its own price, for the 5XL that genuinely costs more. A
 * registrant choosing one is making a purchase decision about their own entry. A member
 * of staff is not: they are correcting a record on somebody else's behalf, and letting
 * that press change what a registrant owes is the one thing this whole area has spent
 * its rounds closing. So when the option chosen does not cost the same as the one on
 * record, nothing is written and the refusal says to take the difference through the
 * payment route instead.
 */
class ParticipantSizeWriter
{
    /**
     * How the trail names each of the two hands that can record a choice.
     *
     * Two event names rather than one, because "who recorded this" is the question a
     * dispute over a handed-out shirt actually turns on, and reading it off a null
     * user id would make an unauthenticated write indistinguishable from a bug.
     */
    public const TRAIL_BY_STAFF = 'option.recorded-by-staff';
    public const TRAIL_BY_REGISTRANT = 'option.recorded-by-registrant';

    /**
     * Apply the submitted choices to one registration.
     *
     * $submitted is the raw input, shaped [participantId][addonId] => variantId. It is
     * never iterated: the loop runs over the people actually on this entry and the
     * items actually on its event, and reads the submitted value for each. An id that
     * belongs to neither is therefore not ignored so much as never looked at.
     *
     * $recordedBy is the member of staff doing it, or null when it is the registrant
     * through their own signed link.
     *
     * @param  array<mixed>  $submitted
     * @return array{recorded: int, unchanged: int, refused: array<string, string>}
     */
    public function apply(EventRegistration $registration, array $submitted, ?User $recordedBy = null): array
    {
        $registration->loadMissing(['event', 'participants', 'addonLines']);

        $addons = ParticipantSizes::addonsFor($registration->event);

        if ($addons->isEmpty() || $registration->participants->isEmpty()) {
            return ['recorded' => 0, 'unchanged' => 0, 'refused' => []];
        }

        $outcome = DB::transaction(function () use ($registration, $addons, $submitted, $recordedBy) {
            $recorded = 0;
            $unchanged = 0;
            $refused = [];

            /*
             | The lines, re-read under a lock rather than from the copy the page was
             | drawn with. Two tabs submitting the same answer must not both see "no
             | size yet" and both move the stock count; whichever gets here second
             | reads the row the first one wrote and finds nothing to do.
             */
            $lines = $registration->addonLines()->lockForUpdate()->get();

            // Locked for the same reason, and before any of them is read: stock_taken
            // on these rows is about to be compared and then moved.
            $variants = EventAddonVariant::query()
                ->whereIn('event_addon_id', $addons->pluck('id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // Units this submission has already claimed, so five people choosing the
            // last three shirts of one size is caught here rather than oversold.
            $claimed = [];

            foreach ($registration->participants->sortBy('id') as $participant) {
                foreach ($addons as $addon) {
                    $path = sprintf('sizes.%d.%d', $participant->id, $addon->id);

                    $choice = data_get($submitted, sprintf('%d.%d', $participant->id, $addon->id));

                    // Nothing chosen for this person. Not an error: the page is also
                    // opened to correct one person out of six, and a blank means
                    // "leave whatever is on record".
                    if (blank($choice)) {
                        continue;
                    }

                    if (! is_string($choice) && ! is_int($choice)) {
                        $refused[$path] = 'That option could not be read. Please choose a size again.';

                        continue;
                    }

                    if (! preg_match('/^\d+$/', trim((string) $choice))) {
                        $refused[$path] = 'That option could not be read. Please choose a size again.';

                        continue;
                    }

                    $variant = $variants->get((int) trim((string) $choice));

                    // A size that is not one of this item's own options, which means a
                    // stale page or an edited payload.
                    if ($variant === null || $variant->event_addon_id !== $addon->id) {
                        $refused[$path] = sprintf('That option for "%s" is no longer available. Please reload the page.', $addon->name);

                        continue;
                    }

                    $line = $this->lineFor($lines, $participant, $addon);
                    $current = $line?->event_addon_variant_id;

                    // Read before anything is written, because the trail below records
                    // what was replaced and the line itself is about to stop saying.
                    $replacedLabel = $line?->variant_label;

                    // Already on record. Nothing is written and no count moves, which
                    // is what makes a second submission of the same page a no-op.
                    if ($current !== null && (int) $current === $variant->id) {
                        $unchanged++;

                        continue;
                    }

                    /*
                     | Staff only, and deliberately a refusal rather than a re-pricing.
                     | Nothing below writes to event_registrations, so letting this
                     | through would record a 5XL against an entry still charged for an
                     | M — a figure nobody could reconcile later. See the note at the
                     | top of this class.
                     */
                    $replacing = $current !== null ? $variants->get((int) $current) : null;

                    if ($recordedBy !== null && ! $this->costsTheSame($variant, $replacing)) {
                        $refused[$path] = sprintf(
                            '%s on "%s" is priced at %s and what is on record is priced at %s, so recording it here would change what %s owes. Nothing was saved. Take the difference through the payment screens and then set it.',
                            $variant->label,
                            $addon->name,
                            PaymentFigures::money($variant->unitPrice()),
                            PaymentFigures::money($replacing?->unitPrice() ?? 0.0),
                            $registration->reference,
                        );

                        continue;
                    }

                    $left = $variant->stockLeft();

                    if ($left !== null && ($claimed[$variant->id] ?? 0) + 1 > $left) {
                        $refused[$path] = $left === 0
                            ? sprintf('%s is sold out. Please choose another size.', $variant->label)
                            : sprintf('Only %d of %s left, and more than that were chosen.', $left, $variant->label);

                        continue;
                    }

                    if ($line === null) {
                        // 0.00 on purpose. See the note at the top of this class.
                        $line = $registration->addonLines()->create([
                            'event_participant_id' => $participant->id,
                            'event_addon_id' => $addon->id,
                            'event_addon_variant_id' => $variant->id,

                            // Copied in, the way every other line on an invoice is, so
                            // the record keeps saying what was chosen even if the
                            // organiser renames the item or the size.
                            'name' => $addon->name,
                            'variant_label' => $variant->label,
                            'unit_price' => 0,
                            'quantity' => 1,
                            'line_total' => 0,
                        ]);

                        $lines->push($line);
                    } else {
                        /*
                         | The size, onto the line that is already there. Fenced to this
                         | registration and keyed on the line itself, and deliberately
                         | updating two columns only: unit_price, quantity and line_total
                         | are what the entry was charged, and they are not this page's
                         | business.
                         */
                        EventRegistrationAddon::query()
                            ->whereKey($line->id)
                            ->where('event_registration_id', $registration->id)
                            ->update([
                                'event_addon_variant_id' => $variant->id,
                                'variant_label' => $variant->label,
                            ]);

                        $line->event_addon_variant_id = $variant->id;
                        $line->variant_label = $variant->label;
                    }

                    /*
                     | The counters. stock_taken is how many of a size have gone out, so
                     | choosing one raises it and the size's remaining stock falls by one.
                     |
                     | Moved rather than added when a recorded size changes: the old size
                     | is handed back, floored at zero so a count that is already wrong
                     | cannot be driven negative. Both are single-statement updates
                     | against the locked rows, so two submissions cannot interleave into
                     | a double count.
                     */
                    EventAddonVariant::query()->whereKey($variant->id)->increment('stock_taken');

                    $claimed[$variant->id] = ($claimed[$variant->id] ?? 0) + 1;

                    if ($current !== null) {
                        EventAddonVariant::query()
                            ->whereKey($current)
                            ->where('stock_taken', '>', 0)
                            ->decrement('stock_taken');
                    }

                    $this->trail(
                        $line,
                        $recordedBy,
                        $participant,
                        $addon,
                        $current !== null ? (int) $current : null,
                        $replacedLabel,
                    );

                    $recorded++;
                }
            }

            return ['recorded' => $recorded, 'unchanged' => $unchanged, 'refused' => $refused];
        });

        // The caller is handed a registration whose lines reflect what was just
        // written, so the page it redraws cannot show the old answer.
        $registration->unsetRelation('addonLines');

        return $outcome;
    }

    /**
     * Whether swapping one option for the other leaves the amount due untouched.
     *
     * An option's price is what that option adds per unit, so a missing one and a
     * zero mean the same thing, and so does having nothing on record at all: an
     * entry with no option named is being charged no surcharge. Compared with a
     * tolerance rather than ==, because these are decimal columns read back as
     * strings and cast to float.
     */
    private function costsTheSame(EventAddonVariant $chosen, ?EventAddonVariant $replacing): bool
    {
        return abs($chosen->unitPrice() - ($replacing?->unitPrice() ?? 0.0)) < 0.01;
    }

    /**
     * Record who set this, in the audit trail the rest of the admin writes to.
     *
     * Written for both hands rather than only for staff. "No row" is not an answer
     * to "who recorded this": the whole point of the trail is that a disputed shirt
     * can be traced to a named member of staff or to the registrant's own link, and
     * one of the two leaving no trace at all would make that unanswerable.
     *
     * The actor columns come from the session the same way every other audit row's
     * do, which is nobody on the public path; the event name is what carries the
     * distinction, and the acting user is named in the values as well so the row
     * still says who even when read on its own.
     */
    private function trail(
        EventRegistrationAddon $line,
        ?User $recordedBy,
        EventParticipant $participant,
        EventAddon $addon,
        ?int $replacedId,
        ?string $replacedLabel,
    ): void {
        AdminLogger::audit(
            $line,
            $recordedBy !== null ? self::TRAIL_BY_STAFF : self::TRAIL_BY_REGISTRANT,
            [
                'event_addon_variant_id' => $replacedId,
                'variant_label' => $replacedLabel,
            ],
            [
                'event_addon_variant_id' => $line->event_addon_variant_id,
                'variant_label' => $line->variant_label,
                'event_addon_id' => $addon->id,
                'item' => $addon->name,
                'event_participant_id' => $participant->id,
                'participant' => $participant->full_name,
                'recorded_by' => $recordedBy?->logLabel() ?? 'the registrant, through their own confirmation link',
            ],
        );
    }

    /**
     * One person's line for one item, out of the locked set.
     *
     * The same preference ParticipantSizes::lineFor() applies, against the rows read
     * inside the transaction rather than the ones the page was drawn from.
     *
     * @param  \Illuminate\Support\Collection<int, EventRegistrationAddon>  $lines
     */
    private function lineFor($lines, EventParticipant $participant, EventAddon $addon): ?EventRegistrationAddon
    {
        $own = $lines
            ->where('event_participant_id', $participant->id)
            ->where('event_addon_id', $addon->id);

        return $own->firstWhere('event_addon_variant_id', '!=', null) ?? $own->first();
    }
}
