<?php

namespace App\Support;

use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\EventRegistration;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Every figure on the dashboard, worked out in one place.
 *
 * Money comes from PaymentFigures rather than being recounted here. Two classes
 * summing the same column is how a dashboard ends up disagreeing with the payments
 * screen, and then nobody trusts either.
 *
 * Cached for two minutes. Long enough that a dashboard is not a dozen aggregate
 * queries on every refresh, short enough that somebody who has just marked a
 * payment paid sees it when they go back to look.
 *
 * WHO IS LOOKING MATTERS, for one figure. A tournament handler is confined to the
 * tournaments assigned to it everywhere else in the admin area, so the tournament
 * counts here are narrowed the same way, through Tournament::visibleTo(). Every
 * other figure on this screen is money, events or registrations: none of them is
 * per tournament, and a handler holds neither payments.view nor events.view, so
 * the controller never draws them. They are left whole on purpose rather than
 * narrowed to nothing against a relationship that does not exist.
 *
 * Which means the cache key has to say what the figures COVER. Caching one payload
 * for everybody would serve an administrator's totals to a handler, or a handler's
 * narrowed totals to an administrator, depending on who refreshed first. So the key
 * carries a scope segment: one shared entry for every viewer who sees everything,
 * which is every role but a handler, and an entry per set of assigned tournaments
 * for the viewers who do not. Keyed on the assignment set and not on the user id,
 * because keying on the id would hand every administrator a private copy of an
 * identical payload.
 *
 * A note on dates. `config/app.php` still hardcodes UTC and every timestamp is still
 * STORED in UTC, which is the right way round for a database. What moved is the
 * reading: every day boundary on this screen is now the office clock's, through
 * LocalTime::today() and LocalDateRange, because the payments screens bucket that way
 * too. The two agreeing matters more than either being simple — a dashboard splitting
 * its days eight hours from the report beside it produces two "today" figures that
 * never match.
 */
final class DashboardMetrics
{
    private const CACHE_SECONDS = 120;

    private const CACHE_KEY = 'admin.dashboard.metrics';

    /**
     * The token every key carries, replaced by forget().
     *
     * A key now depends on what the figures cover, and a handler's scope cannot be
     * named from a static method that is handed nothing. So rather than guessing at
     * the list of live keys, forget() writes a new token and every one of them stops
     * being found at once.
     */
    private const CACHE_TOKEN_KEY = 'admin.dashboard.metrics.token';

    /**
     * Everything the dashboard needs, in one cached payload.
     *
     * Assembled together rather than as separate cached calls so the whole screen
     * describes one moment in time. Mixing a fresh count with a two minute old one
     * makes a total that does not add up.
     *
     * $viewer decides what the tournament figures cover. Null means every
     * tournament, which is what every role but a handler sees.
     *
     * @return array<string, mixed>
     */
    public function all(int $trendDays = 30, int $barDays = 14, ?User $viewer = null): array
    {
        return Cache::remember(
            $this->cacheKey($viewer, $trendDays, $barDays),
            self::CACHE_SECONDS,
            fn (): array => [
                'generated_at' => now(),
                'trend_days' => $trendDays,

                'revenue' => $this->revenue($trendDays),
                'registrations' => $this->registrations($trendDays),
                'people' => $this->people(),
                'tournaments' => $this->tournaments($viewer),

                'revenue_series' => $this->revenueSeries($trendDays),
                'registration_series' => $this->registrationSeries($barDays),
                'payment_breakdown' => $this->paymentBreakdown(),
                'top_events' => $this->topEvents(),
                'upcoming_events' => $this->upcomingEvents(),
            ],
        );
    }

    public static function forget(): void
    {
        Cache::forever(self::CACHE_TOKEN_KEY, (string) Str::uuid());
    }

    /* ---------------------------------------------------------------------
     | Headline figures
     |
     | Each returns the current window, the one before it, and the change
     | between them, so a card can say whether a number is moving.
     * ------------------------------------------------------------------ */

    /**
     * @return array{value: float, previous: float, change: float|null}
     */
    private function revenue(int $days): array
    {
        [$from, $to, $prevFrom, $prevTo] = $this->windows($days);

        $current = PaymentFigures::collected($from, $to);
        $previous = PaymentFigures::collected($prevFrom, $prevTo);

        return [
            'value' => $current,
            'previous' => $previous,
            'change' => $this->change($current, $previous),
            'outstanding' => PaymentFigures::outstanding(),
        ];
    }

    /**
     * @return array{value: int, previous: int, change: float|null, paid: int}
     */
    private function registrations(int $days): array
    {
        [$from, $to, $prevFrom, $prevTo] = $this->windows($days);

        $current = $this->countRegistrations($from, $to);
        $previous = $this->countRegistrations($prevFrom, $prevTo);

        return [
            'value' => $current,
            'previous' => $previous,
            'change' => $this->change($current, $previous),

            // Total across all time, not the window, because "how many entries do
            // we hold" is the question this answers.
            'total' => EventRegistration::count(),
            'paid' => EventRegistration::where('payment_status', EventRegistration::PAYMENT_PAID)->count(),
        ];
    }

    /**
     * Named people on registrations, which is not the same as the number of
     * registrations: one manager entry can carry five players.
     *
     * @return array{value: int, players: int}
     */
    private function people(): array
    {
        return [
            'value' => EventParticipant::count(),
            // playing() so a manager who also plays is counted once here, as a
            // player, rather than being left out of the figure entirely.
            'players' => EventParticipant::query()->playing()->count(),
        ];
    }

    /**
     * How the tournaments this viewer may see divide across the statuses.
     *
     * Narrowed through Tournament::visibleTo(), the same scope the tournaments
     * listing, the Matches and Standings pickers and the Hall of Fame all use. A
     * handler reading the live, published and total counts for tournaments that are
     * not theirs is the one thing on this screen that contradicted the confinement,
     * and it is fixed by asking the question that already existed rather than a
     * second one that would drift from it.
     *
     * A handler with no assignment lands on zero, which is the safe direction and
     * the same answer the tournaments listing gives them.
     *
     * @return array{live: int, total: int, published: int}
     */
    private function tournaments(?User $viewer): array
    {
        $byStatus = Tournament::query()
            ->visibleTo($viewer)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'live' => (int) ($byStatus[Tournament::STATUS_ONGOING] ?? 0),
            'published' => (int) ($byStatus[Tournament::STATUS_PUBLISHED] ?? 0),
            'total' => (int) $byStatus->sum(),
        ];
    }

    /* ---------------------------------------------------------------------
     | Series for the charts
     * ------------------------------------------------------------------ */

    /**
     * Money collected per day, oldest first and with no gaps.
     *
     * PaymentFigures::dailyCollected returns newest first and skips days on which
     * nothing was paid. A chart drawn straight from that would run backwards and
     * squeeze quiet days out of existence, making a flat week look busy. So it is
     * reversed and zero filled here.
     *
     * The bars are LOCAL days, matching what dailyCollected() now returns: money
     * taken at 04:00 in Kuala Lumpur belongs on this morning's bar, and bucketing
     * either end of this on UTC put it on yesterday's.
     *
     * @return array<int, array{label: string, value: float, note: string}>
     */
    private function revenueSeries(int $days): array
    {
        $today = $this->localToday();
        $start = $today->copy()->subDays($days - 1);

        $byDay = collect(PaymentFigures::dailyCollected($start->toDateString(), $today->toDateString()))
            ->keyBy('date');

        $series = [];

        for ($i = 0; $i < $days; $i++) {
            $day = $start->copy()->addDays($i);
            $key = $day->toDateString();
            $row = $byDay->get($key);

            $series[] = [
                'label' => $day->format('j M'),
                'value' => (float) ($row['total'] ?? 0),
                'note' => sprintf(
                    '%s on %s',
                    PaymentFigures::money((float) ($row['total'] ?? 0)),
                    $day->format('j M Y'),
                ),
            ];
        }

        return $series;
    }

    /**
     * Registrations taken per day, oldest first and zero filled.
     *
     * @return array<int, array{label: string, value: float, note: string}>
     */
    private function registrationSeries(int $days): array
    {
        $start = $this->localToday()->subDays($days - 1);

        /*
         | Bucketed by local day in PHP for the same reason dailyCollected() is:
         | DATE(created_at) is the UTC date of an instant, so an entry taken in the
         | small hours landed on the previous bar, and the portable way to group it on
         | the office clock is to not ask the database to do date arithmetic at all.
         | The window bounds the rows, so this is a loop over one chart's worth.
         */
        $byDay = [];

        $rows = EventRegistration::query()
            ->where('created_at', '>=', LocalDateRange::startsAt($start->toDateString()))
            ->select('created_at')
            ->get();

        foreach ($rows as $row) {
            // copy() first: the attribute instance is mutable.
            $day = $row->created_at->copy()->setTimezone(LocalTime::zone())->toDateString();

            $byDay[$day] = ($byDay[$day] ?? 0) + 1;
        }

        $series = [];

        for ($i = 0; $i < $days; $i++) {
            $day = $start->copy()->addDays($i);
            $count = (int) ($byDay[$day->toDateString()] ?? 0);

            $series[] = [
                'label' => $day->format('j M'),
                'value' => (float) $count,
                'note' => sprintf(
                    '%d %s on %s',
                    $count,
                    $count === 1 ? 'registration' : 'registrations',
                    $day->format('j M Y'),
                ),
            ];
        }

        return $series;
    }

    /**
     * How entries divide across the payment statuses, with a share of the whole.
     *
     * @return array<int, array{status: string, label: string, count: int, share: float, tone: string}>
     */
    private function paymentBreakdown(): array
    {
        $counts = PaymentFigures::countsByStatus();
        $total = array_sum($counts);

        $tones = [
            EventRegistration::PAYMENT_PAID => 'green',
            EventRegistration::PAYMENT_PENDING => 'amber',
            EventRegistration::PAYMENT_UNPAID => 'gray',
            EventRegistration::PAYMENT_FAILED => 'red',
            EventRegistration::PAYMENT_REFUNDED => 'purple',
        ];

        $rows = [];

        foreach ($counts as $status => $count) {
            $rows[] = [
                'status' => $status,
                'label' => EventRegistration::PAYMENT_STATUSES[$status] ?? $status,
                'count' => $count,
                'share' => $total > 0 ? round($count / $total * 100, 1) : 0.0,
                'tone' => $tones[$status] ?? 'gray',
            ];
        }

        return $rows;
    }

    /**
     * The events that brought in the most, already computed by PaymentFigures.
     *
     * @return array<int, array{event: string, count: int, collected: float, outstanding: float, share: float}>
     */
    private function topEvents(int $limit = 5): array
    {
        $rows = array_slice(PaymentFigures::byEvent(), 0, $limit);
        $highest = collect($rows)->max('collected') ?: 0;

        return collect($rows)
            ->map(fn (array $row) => $row + [
                // Share of the biggest earner, not of everything, so the bars are
                // readable when one event dwarfs the rest.
                'share' => $highest > 0 ? round($row['collected'] / $highest * 100, 1) : 0.0,
            ])
            ->all();
    }

    /**
     * What is coming, with how full it is.
     *
     * @return array<int, array<string, mixed>>
     */
    private function upcomingEvents(int $limit = 5): array
    {
        return Event::query()
            ->upcoming()
            ->withCount('registrations')
            ->orderBy('starts_at')
            ->limit($limit)
            ->get()
            ->map(fn (Event $event) => [
                'id' => $event->id,
                'title' => $event->title,
                'category' => $event->category,
                'starts_at' => $event->starts_at,
                'status' => $event->status,
                'status_label' => Event::STATUSES[$event->status] ?? $event->status,
                'registrations' => $event->registrations_count,
                'seats_total' => (int) $event->seats_total,
                'filled' => $event->filledPercent(),
            ])
            ->all();
    }

    /* ---------------------------------------------------------------------
     | Caching
     * ------------------------------------------------------------------ */

    /**
     * The key for one viewer's payload, naming what the figures cover.
     *
     * The scope segment, not the user id, is what separates entries: every viewer
     * who sees every tournament shares the one entry they share today, and a
     * restricted viewer gets an entry belonging to their set of assignments. Two
     * handlers assigned the same tournaments would read the same figures anyway, so
     * they are correct to share one.
     */
    private function cacheKey(?User $viewer, int $trendDays, int $barDays): string
    {
        return sprintf(
            '%s:%s:%s:%d:%d',
            self::CACHE_KEY,
            Cache::get(self::CACHE_TOKEN_KEY, 'first'),
            $this->scope($viewer),
            $trendDays,
            $barDays,
        );
    }

    /**
     * What this viewer's figures cover, as a short key segment.
     *
     * Hashed rather than spelled out: a handler running thirty tournaments would
     * otherwise push the key past what a cache store will accept as a key, and the
     * segment only has to tell two assignment sets apart. Sorted first so the same
     * set written in a different order is the same scope, and an empty assignment is
     * its own scope rather than being mistaken for seeing everything.
     */
    private function scope(?User $viewer): string
    {
        if ($viewer === null || ! $viewer->isRestrictedToAssignedTournaments()) {
            return 'all';
        }

        $assigned = $viewer->handledTournaments()
            ->orderBy('tournaments.id')
            ->pluck('tournaments.id')
            ->implode(',');

        return 'assigned-'.md5($assigned);
    }

    /* ---------------------------------------------------------------------
     | Helpers
     * ------------------------------------------------------------------ */

    /**
     * This window and the one immediately before it, as Y-m-d strings.
     *
     * Counted off the office clock's today, not UTC's. Between local midnight and
     * 08:00 the UTC date is still yesterday, so a window built from a bare now()
     * ended before today had started and the dashboard reported nothing for the
     * morning's entries. The strings are LOCAL days, which is what every consumer
     * of them now converts.
     *
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function windows(int $days): array
    {
        $to = $this->localToday();
        $from = $to->copy()->subDays($days - 1);
        $prevTo = $from->copy()->subDay();
        $prevFrom = $prevTo->copy()->subDays($days - 1);

        return [
            $from->toDateString(),
            $to->toDateString(),
            $prevFrom->toDateString(),
            $prevTo->toDateString(),
        ];
    }

    /**
     * Entries made inside a range of LOCAL days.
     *
     * Converted through LocalDateRange rather than compared with whereDate, for the
     * same reason PaymentFigures::window() is: created_at holds UTC instants, the
     * range names days on the office clock, and an entry taken at 04:00 in Kuala
     * Lumpur is stored on the previous UTC day. Comparing the two directly dropped
     * it out of its own day and off the end of every window.
     */
    private function countRegistrations(string $from, string $to): int
    {
        return EventRegistration::query()
            ->where('created_at', '>=', LocalDateRange::startsAt($from))
            ->where('created_at', '<=', LocalDateRange::endsAt($to))
            ->count();
    }

    /**
     * Percentage change, or null when there is nothing to compare against.
     *
     * Null rather than 100%: going from zero to one sale is not a hundred per cent
     * improvement, it is the first sale, and a card claiming +100% would be
     * inventing a trend out of a single row.
     */
    private function change(float $current, float $previous): ?float
    {
        if ($previous <= 0.0) {
            return null;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }

    /**
     * Midnight at the start of today, on the office clock.
     *
     * One place the day boundary is decided for this class, built on LocalTime::today()
     * so the dashboard splits its days at the same hour the payments screens do. A
     * dashboard bucketing on UTC beside a report bucketing locally would show two
     * "today" figures that never match, which is what the note at the top of this
     * class used to accept and no longer has to.
     */
    private function localToday(): Carbon
    {
        return Carbon::parse(LocalTime::today(), LocalTime::zone());
    }
}
