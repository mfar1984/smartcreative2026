<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Turning a date picker's two values into the instants they actually cover.
 *
 * The bug this exists to stop, which this project has now shipped twice: a column is
 * stored in UTC, a screen shows it on the office clock through LocalTime, and the
 * filter compares the column against the picker's bare value with whereDate. A
 * redemption at 04:00 in Kuala Lumpur is stored on the previous UTC day, so filtering
 * "today" loses it and filtering a range loses its edges.
 *
 * So the boundaries are CONVERTED rather than compared. A local day is turned into the
 * UTC instants it spans, and the query asks for a range of instants, which is the only
 * comparison that is true whatever the timezone is set to.
 *
 *     ->where('redeemed_at', '>=', LocalDateRange::startsAt($from))
 *     ->where('redeemed_at', '<=', LocalDateRange::endsAt($to))
 *
 * Only for real instants. A wall-clock column — a date somebody typed, meaning that
 * day wherever it is read — must not be shifted at all; see LocalTime::dateWallClock().
 */
final class LocalDateRange
{
    /**
     * A picker value normalised to a bare Y-m-d, or null when it is not a date.
     *
     * The day it names is a LOCAL day. Which UTC instants that covers is decided by
     * the two methods below, not here. A malformed value is dropped rather than
     * guessed at, so a bad string cannot widen a range to something nobody asked for.
     */
    public static function parse(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /** The UTC instant a local 'from' day begins at. */
    public static function startsAt(string $date): Carbon
    {
        return Carbon::parse($date, LocalTime::zone())->startOfDay()->utc();
    }

    /** The UTC instant a local 'to' day ends at, so the range is inclusive. */
    public static function endsAt(string $date): Carbon
    {
        return Carbon::parse($date, LocalTime::zone())->endOfDay()->utc();
    }
}
