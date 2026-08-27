<?php

namespace App\Support;

use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\EventRegistration;
use App\Models\Tournament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
 * A note on dates. `config/app.php` hardcodes UTC, so every existing screen buckets
 * days in UTC. This class does the same on purpose: a dashboard that split days at
 * a different hour from the payments report would produce two "today" figures that
 * never match. Whether the whole application should move to Asia/Kuala_Lumpur is a
 * separate decision, and it belongs in config, not here.
 */
final class DashboardMetrics
{
    private const CACHE_SECONDS = 120;

    private const CACHE_KEY = 'admin.dashboard.metrics';

    /**
     * Everything the dashboard needs, in one cached payload.
     *
     * Assembled together rather than as separate cached calls so the whole screen
     * describes one moment in time. Mixing a fresh count with a two minute old one
     * makes a total that does not add up.
     *
     * @return array<string, mixed>
     */
    public function all(int $trendDays = 30, int $barDays = 14): array
    {
        return Cache::remember(
            self::CACHE_KEY . ":{$trendDays}:{$barDays}",
            self::CACHE_SECONDS,
            fn (): array => [
                'generated_at' => now(),
                'trend_days' => $trendDays,

                'revenue' => $this->revenue($trendDays),
                'registrations' => $this->registrations($trendDays),
                'people' => $this->people(),
                'tournaments' => $this->tournaments(),

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
        // Only the shapes the controller asks for exist, so clearing those two is
        // enough rather than reaching for a full cache flush.
        foreach ([[30, 14]] as [$trend, $bars]) {
            Cache::forget(self::CACHE_KEY . ":{$trend}:{$bars}");
        }
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
            'players' => EventParticipant::where('role', ParticipantOptions::ROLE_PLAYER)->count(),
        ];
    }

    /**
     * @return array{live: int, total: int, published: int}
     */
    private function tournaments(): array
    {
        $byStatus = Tournament::query()
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
     * @return array<int, array{label: string, value: float, note: string}>
     */
    private function revenueSeries(int $days): array
    {
        $start = now()->subDays($days - 1)->startOfDay();

        $byDay = collect(PaymentFigures::dailyCollected($start->toDateString(), now()->toDateString()))
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
        $start = now()->subDays($days - 1)->startOfDay();

        $byDay = EventRegistration::query()
            ->where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

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
     | Helpers
     * ------------------------------------------------------------------ */

    /**
     * This window and the one immediately before it, as Y-m-d strings.
     *
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function windows(int $days): array
    {
        $to = now();
        $from = $to->copy()->subDays($days - 1)->startOfDay();
        $prevTo = $from->copy()->subDay();
        $prevFrom = $prevTo->copy()->subDays($days - 1)->startOfDay();

        return [
            $from->toDateString(),
            $to->toDateString(),
            $prevFrom->toDateString(),
            $prevTo->toDateString(),
        ];
    }

    private function countRegistrations(string $from, string $to): int
    {
        return EventRegistration::query()
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
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
}
