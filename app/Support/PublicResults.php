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

    private static ?bool $archive = null;

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
     * Whether any tournament has finished with a table behind it.
     *
     * Two conditions, and both are needed. Finished, because an archive of things
     * still being played is not an archive. And drawn, because a tournament closed
     * without a draw has no fixtures and no standings, so its entry would open onto
     * nothing.
     *
     * Deliberately not the same test as hasHallOfFame(). A finished tournament whose
     * podium was never announced belongs in the archive and not on the podium page,
     * which is the whole reason the two pages are separate.
     */
    public static function hasArchive(): bool
    {
        return self::$archive ??= Tournament::query()
            ->whereIn('status', [Tournament::STATUS_COMPLETED, Tournament::STATUS_PUBLISHED])
            ->whereHas('stages', fn ($query) => $query->whereNotNull('drawn_at'))
            ->whereHas('event')
            ->exists();
    }

    /**
     * Nothing to show, so the menu item is left out altogether.
     */
    public static function isEmpty(): bool
    {
        return ! self::anyLive() && ! self::hasHallOfFame() && ! self::hasArchive();
    }

    /**
     * Where the Results menu itself should lead.
     *
     * The heading is a link as well as a hover target, because on a touch screen the
     * hover never happens. It has to land on a page with something on it, so it walks
     * the same order the menu is listed in and takes the first one that is populated.
     */
    public static function menuUrl(): string
    {
        if (self::anyLive()) {
            return route('events.ranking', self::live()->first()->event->slug);
        }

        if (self::hasHallOfFame()) {
            return route('hall-of-fame');
        }

        return route('archive');
    }

    /**
     * Forget what was read, for tests that change the data mid-request.
     */
    public static function flush(): void
    {
        self::$live = null;
        self::$champions = null;
        self::$archive = null;
    }
}
