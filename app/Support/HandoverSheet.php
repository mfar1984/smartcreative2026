<?php

namespace App\Support;

use App\Models\Event;
use App\Models\EventAddon;
use App\Models\EventParticipant;
use App\Models\EventRegistration;
use App\Models\EventRegistrationAddon;
use Illuminate\Support\Collection;

/**
 * What there is to hand over at an event, one person and one item at a time.
 *
 * THE one definition of the collection screen's unit of work, the same way
 * ParticipantSizes is the one definition of who still owes a choice. Both questions
 * are asked by the screen, by the handover action, by the figures above the table and
 * by the CSV, and four readings of "whose shirt is this" is four chances for somebody
 * to be handed two or left out of the queue.
 *
 * WHY THE UNIT IS A PERSON AND NOT A REGISTRATION
 *
 * A grouping of six takes six shirts in up to six different sizes, and they do not
 * all turn up together. A registration-level row cannot say that four have been
 * collected and two have not, so it would have to be collected all at once or not at
 * all, and the record would then be unable to answer the only question that matters
 * later: who actually walked away with mine.
 *
 * WHICH ITEMS COUNT
 *
 * EventAddon::isHandedOver() and nothing else. An event selling a shirt and an
 * insurance line lists the shirt. Deliberately not filtered on is_active, because
 * withdrawing an add-on stops new sales without unselling the shirts already bought,
 * and those still have to reach the people who paid for them.
 *
 * WHAT IS WRITTEN FROM HERE
 *
 * Nothing. This class reads. A choice is recorded by ParticipantSizeWriter and a
 * handover by CollectionHandover, and neither of them is money: what an entry was
 * charged was settled when it was made.
 */
class HandoverSheet
{
    /**
     * The items on an event that somebody physically takes away.
     *
     * @return Collection<int, EventAddon>
     */
    public static function addonsFor(?Event $event): Collection
    {
        if ($event === null) {
            return collect();
        }

        // loadMissing rather than load: the screen eager loads this with the rows, so
        // the common path costs no query. It is here so one registration fetched on
        // its own still answers correctly.
        $event->loadMissing('addons.variants');

        return $event->addons
            ->filter(fn (EventAddon $addon) => $addon->isHandedOver())
            ->values();
    }

    /** Whether this event has anything to hand out at all. */
    public static function handsAnythingOver(?Event $event): bool
    {
        return self::addonsFor($event)->isNotEmpty();
    }

    /**
     * The ids of the items an event hands over.
     *
     * @return array<int, int>
     */
    public static function addonIdsFor(?Event $event): array
    {
        return self::addonsFor($event)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * How a row is named in a form, a URL and the markup.
     *
     * One opaque string rather than two fields, so a batch of ticked rows travels as a
     * flat list. It is never trusted: the action rebuilds the sheet for the entry it
     * was posted to and keeps only the keys that appear in it, so a key naming
     * somebody else's person or another event's item matches nothing.
     */
    public static function key(int $participantId, int $addonId): string
    {
        return $participantId . ':' . $addonId;
    }

    /**
     * Everybody on one entry against every item that entry's event hands over.
     *
     * Rows are returned whether or not a choice has been recorded and whether or not
     * the item has already gone out, because the counter needs to see all three states:
     * ready to hand over, already gone, and still owing a size. The one it must never
     * hide is the third — somebody who never answered the size link is exactly who is
     * standing at the desk.
     *
     * @return array<int, array{
     *     key: string,
     *     registration: EventRegistration,
     *     participant: EventParticipant,
     *     addon: EventAddon,
     *     line: EventRegistrationAddon|null,
     *     chosen: int|null,
     *     option: string|null,
     *     collects_choice: bool,
     *     handover: \App\Models\CollectionHandover|null,
     * }>
     */
    public static function rowsFor(EventRegistration $registration): array
    {
        $registration->loadMissing(['event.addons.variants', 'participants', 'addonLines']);

        return self::build($registration, $registration->participants->sortBy('id'));
    }

    /**
     * The same rows for one person, without hydrating the rest of their entry.
     *
     * What the list draws. The table is paginated by person, so expanding each row to
     * the whole entry would print a grouping of six once for every one of its members
     * and then print it again on the next page.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function rowsForParticipant(EventParticipant $participant): array
    {
        $participant->loadMissing(['registration.event.addons.variants', 'registration.addonLines']);

        $registration = $participant->registration;

        if ($registration === null) {
            return [];
        }

        return self::build($registration, [$participant]);
    }

    /**
     * @param  iterable<EventParticipant>  $participants
     * @return array<int, array<string, mixed>>
     */
    private static function build(EventRegistration $registration, iterable $participants): array
    {
        $addons = self::addonsFor($registration->event);

        if ($addons->isEmpty()) {
            return [];
        }

        /*
         | Which of those items the counter may also record a choice for.
         |
         | Read from ParticipantSizes rather than decided here, because that is the set
         | ParticipantSizeWriter will accept. Offering a size control for an item the
         | writer would refuse is a control that silently does nothing, and the operator
         | would find out with a queue behind them.
         */
        $choiceIds = ParticipantSizes::addonsFor($registration->event)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $rows = [];

        foreach ($participants as $participant) {
            foreach ($addons as $addon) {
                $collectsChoice = in_array((int) $addon->id, $choiceIds, true);

                $line = $collectsChoice
                    ? ParticipantSizes::lineFor($registration, $participant, $addon)
                    : self::plainLineFor($registration, $participant, $addon);

                /*
                 | The id is what says a choice exists, not the label beside it. The
                 | same reading ParticipantSizes applies, so a line carrying a stale
                 | label and no option counts as unanswered on both screens.
                 */
                $chosen = $line?->event_addon_variant_id;

                $rows[] = [
                    'key' => self::key((int) $participant->id, (int) $addon->id),
                    'registration' => $registration,
                    'participant' => $participant,
                    'addon' => $addon,
                    'line' => $line,

                    'chosen' => $chosen === null ? null : (int) $chosen,

                    // What the record says they chose. Null is not an error: it is the
                    // most common state on this screen and the reason the counter can
                    // take the answer on the spot.
                    'option' => $chosen === null ? null : $line?->variant_label,

                    'collects_choice' => $collectsChoice,
                    'handover' => $line?->handover,
                ];
            }
        }

        return $rows;
    }

    /**
     * The rows of one entry that have not been handed over yet.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function outstandingFor(EventRegistration $registration): array
    {
        return array_values(array_filter(
            self::rowsFor($registration),
            fn (array $row) => $row['handover'] === null,
        ));
    }

    /**
     * One person's line for one item, ignoring the size preference.
     *
     * Used for items that collect no choice, where there is nothing to prefer: the
     * line either names them or it does not.
     */
    private static function plainLineFor(
        EventRegistration $registration,
        EventParticipant $participant,
        EventAddon $addon,
    ): ?EventRegistrationAddon {
        $registration->loadMissing('addonLines');

        return $registration->addonLines
            ->where('event_participant_id', $participant->id)
            ->where('event_addon_id', $addon->id)
            ->first();
    }
}
