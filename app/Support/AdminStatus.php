<?php

namespace App\Support;

use App\Models\EventRegistration;
use App\Models\Tournament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The three counters on the right of the admin footer.
 *
 * Gathered in a single query and cached, because the footer is drawn on every
 * admin page. Three separate counts would mean three round trips on every click,
 * and that is the kind of slowness nobody later manages to trace back to a footer.
 *
 * Sixty seconds is deliberately short. These numbers are the reason somebody looks
 * at the footer at all, so a stale count is worse than a cheap query.
 */
final class AdminStatus
{
    private const CACHE_KEY = 'admin.status.counts';

    private const CACHE_SECONDS = 60;

    /**
     * @return array{unpaid: int, failed: int, live: int}
     */
    public function counts(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, function (): array {
            $row = DB::selectOne(
                'SELECT
                    (SELECT COUNT(*) FROM event_registrations WHERE payment_status <> ?) AS unpaid,
                    (SELECT COUNT(*) FROM failed_jobs)                                   AS failed,
                    (SELECT COUNT(*) FROM tournaments WHERE status = ?)                  AS live',
                [EventRegistration::PAYMENT_PAID, Tournament::STATUS_ONGOING],
            );

            return [
                'unpaid' => (int) ($row->unpaid ?? 0),
                'failed' => (int) ($row->failed ?? 0),
                'live' => (int) ($row->live ?? 0),
            ];
        });
    }

    /**
     * Drop the cache so the next page shows current numbers.
     *
     * Worth calling after anything that moves one of these three, so a payment
     * marked paid does not leave a stale count sitting there for a minute.
     */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
