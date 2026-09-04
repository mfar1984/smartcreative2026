<?php

namespace App\Support;

use App\Models\EventRegistration;
use App\Models\EventRegistrationPayment;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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
    /**
     * Statuses where somebody still owes money.
     *
     * Partial belongs here: some of the charge has arrived and the rest has not, so
     * the entry is still owed money even though it is no longer untouched. What it
     * owes is the balance rather than the charge, which is why outstanding() sums a
     * difference instead of a column.
     */
    public const OWING = [
        EventRegistration::PAYMENT_UNPAID,
        EventRegistration::PAYMENT_PENDING,
        EventRegistration::PAYMENT_PARTIAL,
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

    /**
     * What is held now, after anything that has been sent back.
     *
     * Refunds are subtracted rather than the row being dropped. A partial refund
     * leaves the entry `paid`, and counting the full charge would overstate the
     * takings by whatever went back; excluding the row entirely would understate
     * them by whatever stayed.
     */
    public static function collected(?string $from = null, ?string $to = null): float
    {
        /*
         | amount_paid, not amount, and no filter on the status.
         |
         | The charge and the receipt are different figures, and until part payments
         | existed they happened to agree for every settled entry, so summing the
         | charge of everything marked paid gave the right answer. It no longer does:
         | that query would report nothing at all for an entry that has genuinely
         | transferred RM 200 of RM 250. Summing what arrived needs no status filter,
         | because an entry that has paid nothing contributes nothing.
         */
        $received = (float) self::window(self::base(), $from, $to)->sum('amount_paid');

        return round($received - self::refunded($from, $to), 2);
    }

    /** What arrived before any refund, for reconciling against the gateway. */
    public static function grossCollected(?string $from = null, ?string $to = null): float
    {
        return (float) self::window(self::base(), $from, $to)->sum('amount_paid');
    }

    /**
     * What is still expected from entries that have not been cancelled.
     *
     * The balance rather than the charge, so an entry that has paid part of its way
     * is counted for what it still owes. Summing `amount` here would report the same
     * money as both collected and outstanding at the same time.
     */
    public static function outstanding(?string $from = null, ?string $to = null): float
    {
        return round((float) self::window(self::base(), $from, $to)
            ->whereIn('payment_status', self::OWING)
            ->where('status', '!=', EventRegistration::STATUS_CANCELLED)
            ->sum(DB::raw('amount - amount_paid')), 2);
    }

    /**
     * What has been given back.
     *
     * Sums `refunded_amount`, not `amount`. Summing the charge reported a RM 10
     * refund on a RM 100 entry as RM 100 returned, and selecting on the status
     * missed partial refunds altogether, because those stay `paid`.
     */
    public static function refunded(?string $from = null, ?string $to = null): float
    {
        return (float) self::window(self::base(), $from, $to)
            ->where('refunded_amount', '>', 0)
            ->sum('refunded_amount');
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
        /*
         | Read off the receipt ledger, grouped by the day the money arrived.
         |
         | It used to group the registrations by DATE(COALESCE(payment_synced_at,
         | updated_at)), which was the best available guess when a registration could
         | only hold one payment: the sync timestamp for a gateway payment, and the
         | last time the row changed for anything else. Both were approximations of a
         | date that is now recorded, and the fallback would land a transfer somebody
         | reports on Monday on whatever day their entry happened to be edited.
         |
         | The range applies to the receipt date here rather than through window(),
         | which narrows on when the entry was created. On a reconciliation screen the
         | question is which day the money landed, not which day the entry was made.
         */
        $rows = EventRegistrationPayment::query()
            ->join('event_registrations', 'event_registrations.id', '=', 'event_registration_payments.event_registration_id')
            ->when(filled($from), fn (Builder $q) => $q->whereDate('event_registration_payments.received_at', '>=', $from))
            ->when(filled($to), fn (Builder $q) => $q->whereDate('event_registration_payments.received_at', '<=', $to))
            ->selectRaw('DATE(event_registration_payments.received_at) as day, COUNT(*) as total, SUM(event_registration_payments.amount) as amount')
            ->groupBy('day')
            ->orderByDesc('day')
            ->get();

        return $rows->map(fn ($row) => [
            'date' => (string) $row->day,
            'count' => (int) $row->total,
            'total' => (float) $row->amount,
        ])->all();
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
            /*
             | Received money and remaining balance, matching collected() and
             | outstanding(). Selecting the charge of everything marked paid credited
             | a part-paid entry with nothing, and charged it for the full fee in the
             | outstanding column at the same time.
             */
            ->selectRaw("
                events.title as title,
                COUNT(*) as entries,
                SUM(amount_paid) as collected,
                SUM(CASE WHEN payment_status IN (?, ?, ?, ?) AND event_registrations.status != ? THEN amount - amount_paid ELSE 0 END) as outstanding
            ", [
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
