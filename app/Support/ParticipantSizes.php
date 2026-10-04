<?php

namespace App\Support;

use App\Models\Event;
use App\Models\EventAddon;
use App\Models\EventParticipant;
use App\Models\EventRegistration;
use App\Models\EventRegistrationAddon;
use Illuminate\Support\Collection;

/**
 * Which items on an event are a size somebody has to choose, and who has not chosen.
 *
 * THE one definition of both questions. The public confirmation page, the writer that
 * records an answer, the Participants list column and the bulk sender all read it from
 * here: four copies of "who is missing a size" is four chances for the screen to offer
 * a link to somebody who already answered, or to leave somebody out of the shirt order.
 *
 * The rule itself is deliberately the same one the registration form uses, so a person
 * is counted as owing an answer exactly when the form would have asked them for one:
 * an active item with options, chosen one person at a time, on a radio selection.
 * Quantity items are not sizes — they are "how many" — and the per-head rule lives on
 * EventAddon::isAssignedPerParticipant().
 *
 * Nothing here writes, and nothing here knows about money. A size is a record of what
 * to print and hand over; what the entry was charged was settled when it was made.
 */
class ParticipantSizes
{
    /**
     * The items on an event that collect one choice per person.
     *
     * @return Collection<int, EventAddon>
     */
    public static function addonsFor(?Event $event): Collection
    {
        if ($event === null) {
            return collect();
        }

        // loadMissing rather than load: both callers eager load this with the rows,
        // so the common path costs no query. It is here so a single registration
        // fetched on its own still answers correctly.
        $event->loadMissing('addons.variants');

        return $event->addons
            ->filter(fn (EventAddon $addon) => $addon->is_active
                && $addon->isRadioSelection()
                && $addon->hasVariants()
                && $addon->isAssignedPerParticipant($event))
            ->values();
    }

    /** Whether this event asks anybody for a size at all. */
    public static function collectsSizes(?Event $event): bool
    {
        return self::addonsFor($event)->isNotEmpty();
    }

    /**
     * The line that holds one person's choice of one item, or null when there is none.
     *
     * Only lines naming that person. A line with no participant on it is the one
     * charge for the whole entry, not anybody's size, and reading it as a size would
     * report a squad of six as already answered because a single bulk line exists.
     *
     * Prefers a line that names a size, so an entry carrying both a recorded choice
     * and an empty one reads as answered rather than outstanding.
     */
    public static function lineFor(
        EventRegistration $registration,
        EventParticipant $participant,
        EventAddon $addon,
    ): ?EventRegistrationAddon {
        $registration->loadMissing('addonLines');

        $own = $registration->addonLines
            ->where('event_participant_id', $participant->id)
            ->where('event_addon_id', $addon->id);

        return $own->firstWhere('event_addon_variant_id', '!=', null) ?? $own->first();
    }

    /**
     * Everybody on the entry against every size they could choose, recorded or not.
     *
     * This is what the confirmation page draws: the whole roster, with whatever is
     * already on record selected, so the same page both collects a missing size and
     * corrects a wrong one.
     *
     * @return array<int, array{participant: EventParticipant, addon: EventAddon, line: EventRegistrationAddon|null, chosen: int|null}>
     */
    public static function sheetFor(EventRegistration $registration): array
    {
        $registration->loadMissing(['event', 'participants', 'addonLines']);

        $addons = self::addonsFor($registration->event);

        if ($addons->isEmpty()) {
            return [];
        }

        $rows = [];

        foreach ($registration->participants->sortBy('id') as $participant) {
            foreach ($addons as $addon) {
                $line = self::lineFor($registration, $participant, $addon);

                $rows[] = [
                    'participant' => $participant,
                    'addon' => $addon,
                    'line' => $line,
                    'chosen' => $line?->event_addon_variant_id,
                ];
            }
        }

        return $rows;
    }

    /**
     * The rows of that sheet with nothing on record yet.
     *
     * Covers both shapes the live data holds: a person whose line exists but names no
     * size, and a person with no line at all.
     *
     * @return array<int, array{participant: EventParticipant, addon: EventAddon, line: EventRegistrationAddon|null, chosen: int|null}>
     */
    public static function missingFor(EventRegistration $registration): array
    {
        return array_values(array_filter(
            self::sheetFor($registration),
            fn (array $row) => $row['chosen'] === null,
        ));
    }

    /** How many answers this entry still owes, counted one person one item at a time. */
    public static function missingCount(EventRegistration $registration): int
    {
        return count(self::missingFor($registration));
    }

    public static function needsSizes(EventRegistration $registration): bool
    {
        return self::missingCount($registration) > 0;
    }

    /**
     * The people still to answer, named, for a message an operator reads.
     *
     * @return array<int, string>
     */
    public static function missingNames(EventRegistration $registration): array
    {
        return array_values(array_unique(array_map(
            fn (array $row) => (string) $row['participant']->full_name,
            self::missingFor($registration),
        )));
    }
}
