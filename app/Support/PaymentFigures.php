<?php

namespace App\Support;

use App\Models\EventRegistration;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * One place that decides what the money figures mean.
 *
 * Overview, Settlements and Reports all answer "how much came in", and if each
 * worked it out for itself they would eventually disagree. A screen disagreeing
 * with another screen about money is worse than either being wrong, because
 * nobody can tell which to trust.
 *
 * Two rules are settled here and nowhere else:
 *
 * Collected counts only what is marked paid. A refunded entry is not collected
 * even though the money did once arrive, because the figure is meant to answer
 * "what do we hold", not "what has ever passed through".
 *
 * Outstanding excludes cancelled entries. Nobody is going to pay them, so
 * counting them would overstate what is still coming and never come down.
 */
class PaymentFigures
{
    /** Statuses where somebody still owes money. */
    public const OWING = [
        EventRegistration::PAYMENT_UNPAID,
        EventRegistration::PAYMENT_PENDING,
        EventRegistration::PAYMENT_FAILED,
    ];

    /**
     * Every registration that represents money, free entries excluded.
     *
     * A free entry has no payment to report on, so including it would pad every
     * count with rows that can never move.
     */
    public static function base(): Builder
    {
        return EventRegistration::query()->where('amount', '>', 0);
    }

    /** What is held now. */
    public static function collected(?string $from = null, ?string $to = null): float
    {
        return (float) self::window(self::base(), $from, $to)
            ->where('payment_status', EventRegistration::PAYMENT_PAID)
            ->sum('amount');
    }

    /** What is still expected from entries that have not been cancelled. */
    public static function outstanding(?string $from = null, ?string $to = null): float
    {
        return (float) self::window(self::base(), $from, $to)
            ->whereIn('payment_status', self::OWING)
            ->where('status', '!=', EventRegistration::STATUS_CANCELLED)
            ->sum('amount');
    }

    /** What has been given back. */
    public static function refunded(?string $from = null, ?string $to = null): float
    {
        return (float) self::window(self::base(), $from, $to)
            ->where('payment_status', EventRegistration::PAYMENT_REFUNDED)
            ->sum('amount');
    }

    /**
     * How many registrations sit in each payment status.
     *
     * @return array<string, int>
     */
    public static function countsByStatus(?string $from = null, ?string $to = null): array
    {
        $counts = self::window(self::base(), $from, $to)
            ->selectRaw('payment_status, COUNT(*) as total')
            ->groupBy('payment_status')
            ->pluck('total', 'payment_status')
            ->all();

        // Every status is present even at zero, so the screen does not change
        // shape depending on what happens to be in the database.
        $filled = [];

        foreach (array_keys(EventRegistration::PAYMENT_STATUSES) as $status) {
            $filled[$status] = (int) ($counts[$status] ?? 0);
        }

        return $filled;
    }

    /**
     * Checkouts that were opened and never finished.
     *
     * Pending means the payer reached the gateway and stopped. Given a grace
     * period they are not coming back on their own, which makes this the one list
     * worth chasing: the money almost arrived.
     */
    public static function abandoned(int $graceMinutes = 30): Builder
    {
        return self::base()
            ->where('payment_status', EventRegistration::PAYMENT_PENDING)
            ->where('status', '!=', EventRegistration::STATUS_CANCELLED)
            ->where('updated_at', '<=', now()->subMinutes($graceMinutes));
    }

    /** Attempts the gateway actively refused. */
    public static function failed(): Builder
    {
        return self::base()
            ->where('payment_status', EventRegistration::PAYMENT_FAILED)
            ->where('status', '!=', EventRegistration::STATUS_CANCELLED);
    }

    /**
     * Money in, grouped by the day it was recorded.
     *
     * Keyed on payment_synced_at when there is one and on updated_at otherwise:
     * the first is when the gateway told us, which is the date a bank statement
     * will agree with. Falling back matters because a payment marked by hand has
     * no sync timestamp.
     *
     * @return array<int, array{date: string, count: int, total: float}>
     */
    public static function dailyCollected(?string $from = null, ?string $to = null): array
    {
        return self::window(self::base(), $from, $to)
            ->where('payment_status', EventRegistration::PAYMENT_PAID)
            ->selectRaw('DATE(COALESCE(payment_synced_at, updated_at)) as day, COUNT(*) as total, SUM(amount) as amount')
            ->groupBy('day')
            ->orderByDesc('day')
            ->get()
            ->map(fn ($row) => [
                'date' => (string) $row->day,
                'count' => (int) $row->total,
                'total' => (float) $row->amount,
            ])
            ->all();
    }

    /**
     * Money in, grouped by event, so the office can see which one earned what.
     *
     * @return array<int, array{event: string, count: int, collected: float, outstanding: float}>
     */
    public static function byEvent(?string $from = null, ?string $to = null): array
    {
        $rows = self::window(self::base(), $from, $to)
            ->join('events', 'events.id', '=', 'event_registrations.event_id')
            ->selectRaw("
                events.title as title,
                COUNT(*) as entries,
                SUM(CASE WHEN payment_status = ? THEN amount ELSE 0 END) as collected,
                SUM(CASE WHEN payment_status IN (?, ?, ?) AND event_registrations.status != ? THEN amount ELSE 0 END) as outstanding
            ", [
                EventRegistration::PAYMENT_PAID,
                ...self::OWING,
                EventRegistration::STATUS_CANCELLED,
            ])
            ->groupBy('events.id', 'events.title')
            ->orderByDesc('collected')
            ->get();

        return $rows->map(fn ($row) => [
            'event' => (string) $row->title,
            'count' => (int) $row->entries,
            'collected' => (float) $row->collected,
            'outstanding' => (float) $row->outstanding,
        ])->all();
    }

    /**
     * Narrow a query to a date range, when one was given.
     *
     * Applied to created_at rather than to the payment date, because the ranges
     * on screen are chosen to answer "what did this period bring in", and a
     * period owns the entries that were made in it.
     */
    public static function window(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when(filled($from), fn (Builder $q) => $q->whereDate('event_registrations.created_at', '>=', $from))
            ->when(filled($to), fn (Builder $q) => $q->whereDate('event_registrations.created_at', '<=', $to));
    }

    public static function money(float $amount): string
    {
        return 'RM ' . number_format($amount, 2);
    }
}
