<?php

namespace App\Support;

use App\Models\Tournament;
use App\Models\TournamentChampion;
use Illuminate\Support\Collection;

/**
 * What the public menu needs to know about results.
 *
 * Read from the header, which is drawn on every page of the website, so it is
 * memoised for the request: the desktop menu and the mobile menu both ask, and one
 * visit should cost one query rather than four.
 *
 * Follows the rule the basket link already set in that header: a menu item is only
 * offered once there is something behind it. A permanent "Results" link leading to a
 * page that says "nothing yet" is worse than no link.
 */
final class PublicResults
{
    /** @var Collection<int, Tournament>|null */
    private static ?Collection $live = null;

    private static ?bool $champions = null;

    /**
     * Events with a tournament being played right now, one entry per event.
     *
     * Three things have to be true before an event is offered, because each of them
     * on its own would produce a link to an empty table:
     *
     * The tournament is ongoing.
     *
     * A stage has been drawn. Until then there are no fixtures and no standings.
     *
     * The organiser has not turned live rankings off for it. That setting exists
     * precisely so a half-played table is not quoted back at them, and linking to it
     * from the menu would go behind their decision.
     *
     * @return Collection<int, Tournament>
     */
    public static function live(): Collection
    {
        if (self::$live !== null) {
            return self::$live;
        }

        return self::$live = Tournament::query()
            ->where('status', Tournament::STATUS_ONGOING)
            ->whereHas('stages', fn ($query) => $query->whereNotNull('drawn_at'))
            ->whereHas('event')
            ->with('event:id,slug,title')
            ->orderBy('id')
            ->get()
            ->filter(fn (Tournament $tournament) => (bool) $tournament->setting('public_rankings_live', true))
            // One link per event. Two tournaments on the same event share one ranking
            // page, so offering it twice would be offering the same page twice.
            ->unique('event_id')
            ->values();
    }

    public static function anyLive(): bool
    {
        return self::live()->isNotEmpty();
    }

    /**
     * Whether a podium has ever been announced.
     */
    public static function hasHallOfFame(): bool
    {
        return self::$champions ??= TournamentChampion::query()
            ->whereNotNull('published_at')
            ->exists();
    }

    /**
     * Nothing to show, so the menu item is left out altogether.
     */
    public static function isEmpty(): bool
    {
        return ! self::anyLive() && ! self::hasHallOfFame();
    }

    /**
     * Forget what was read, for tests that change the data mid-request.
     */
    public static function flush(): void
    {
        self::$live = null;
        self::$champions = null;
    }
}
