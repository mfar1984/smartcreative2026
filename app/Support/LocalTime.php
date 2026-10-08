<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * One place that decides which clock a timestamp is read on.
 *
 * Everything is stored in UTC and must stay that way: config/app.php hardcodes
 * 'timezone' => 'UTC', so Laravel sets PHP's default zone to UTC in every
 * environment and Eloquent parses and writes every date in it. That is the
 * right way round for a database, and nothing here changes it. What it gets
 * wrong is only the reading: an office in Malaysia saw an entry submitted at
 * 7:33 in the morning written down as 11:33 the night before.
 *
 * So the conversion happens here, at the last possible moment, and nowhere
 * else. A Blade file that called ->setTimezone() for itself would be one more
 * place to forget, and the eight-hour shift would drift between screens.
 *
 * Carbon::instance() is load-bearing, not decoration. Carbon 3 instances are
 * MUTABLE, so $registration->created_at->setTimezone(...) would shift the very
 * object sitting in the model's $attributes array; a later save on that model
 * formats that instance in its new timezone and writes the Malaysian
 * wall-clock string into a UTC column. Cloning first closes that path by
 * construction, which is why the clone must not be "simplified" away.
 */
class LocalTime
{
    /**
     * The shape the admin screens already use.
     *
     * Kept exactly as the views spelled it, so this change moves the offset and
     * nothing else: the same words in the same order, eight hours later.
     */
    public const DATE_TIME = 'd M Y, g:i a';

    /**
     * The zone a timestamp is shown in.
     *
     * The Timezone field on the General Config screen, which is what that field
     * promises and what it previously did not do. Safe to call per rendered row:
     * GeneralSettings reads the whole group once per request and memoises the
     * resolved zone, so this is not a database read per row. Falls through to
     * config when nothing is saved, which is where it used to read from.
     */
    public static function zone(): string
    {
        return GeneralSettings::timezone();
    }

    /**
     * A sentinel meaning "no explicit format was passed, use the chosen one".
     *
     * A caller that spells out a format string must get exactly that string, so a
     * machine-readable caller (CSV columns, Y-m-d\TH:i input values) is never
     * touched by the admin's display choice. We cannot use the admin format as the
     * parameter default because it is resolved at call time, not at definition
     * time, so this sentinel marks "argument omitted" and the body swaps in the
     * chosen format. Passing self::DATE_TIME explicitly also still works and gives
     * the historical shape.
     */
    private const CHOSEN = "\0chosen";

    /**
     * The admin's chosen date + time format, in the historical order: date, then
     * time, separated by a comma and a space, matching the old 'd M Y, g:i a'.
     *
     * Read off GeneralSettings, which fetches the whole group once per request and
     * memoises it, so this is not a database read per call. Falls back to the
     * historical literals when nothing is saved, so output does not move on first
     * deploy and so the queue worker (which has no HTTP request) still resolves a
     * format rather than throwing.
     */
    public static function chosenDateTimeFormat(): string
    {
        return GeneralSettings::dateFormat() . ', ' . GeneralSettings::timeFormat();
    }

    /** The admin's chosen date-only format, or the historical 'd M Y'. */
    public static function dateFormat(): string
    {
        return GeneralSettings::dateFormat();
    }

    /** The admin's chosen time-only format, or the historical 'g:i a'. */
    public static function timeFormat(): string
    {
        return GeneralSettings::timeFormat();
    }

    /**
     * A stored instant, read on the display clock, in the chosen date+time format.
     *
     * With no explicit $format the admin's chosen date+time format is used; passing
     * a format string gives exactly that string, unchanged, so machine-readable
     * callers are never affected. Returns the fallback for a missing date so the
     * callers do not each need their own ?? '—', which is how two screens end up
     * disagreeing about what an empty timestamp looks like.
     *
     * This path IS timezone-shifted: it is for UTC instants (created_at, paid_at,
     * received_at, ...). Wall-clock values must use dateWallClock()/timeWallClock()
     * or formatWallClock() instead so they keep the time that was typed.
     */
    public static function format(?DateTimeInterface $moment, string $format = self::CHOSEN, string $fallback = '—'): string
    {
        if ($moment === null) {
            return $fallback;
        }

        return Carbon::instance($moment)->setTimezone(self::zone())->format(self::resolve($format));
    }

    /**
     * An instant as a date only, in the chosen date format, timezone-shifted.
     */
    public static function date(?DateTimeInterface $moment, string $fallback = '—'): string
    {
        if ($moment === null) {
            return $fallback;
        }

        return Carbon::instance($moment)->setTimezone(self::zone())->format(self::dateFormat());
    }

    /**
     * An instant as a time only, in the chosen time format, timezone-shifted.
     */
    public static function time(?DateTimeInterface $moment, string $fallback = '—'): string
    {
        if ($moment === null) {
            return $fallback;
        }

        return Carbon::instance($moment)->setTimezone(self::zone())->format(self::timeFormat());
    }

    /**
     * A wall-clock value in the chosen date+time format, WITHOUT a timezone shift.
     *
     * For values a human typed and we store as-is (collection_at, scheduled_at,
     * event starts_at/ends_at). The format still applies — only the eight-hour
     * shift is withheld, because these are not UTC instants and shifting them would
     * move a 9am collection to 5pm. An explicit $format is honoured exactly.
     */
    public static function formatWallClock(?DateTimeInterface $moment, string $format = self::CHOSEN, string $fallback = '—'): string
    {
        if ($moment === null) {
            return $fallback;
        }

        return Carbon::instance($moment)->format(self::resolve($format));
    }

    /** A wall-clock value as a date only, chosen format, no timezone shift. */
    public static function dateWallClock(?DateTimeInterface $moment, string $fallback = '—'): string
    {
        if ($moment === null) {
            return $fallback;
        }

        return Carbon::instance($moment)->format(self::dateFormat());
    }

    /** A wall-clock value as a time only, chosen format, no timezone shift. */
    public static function timeWallClock(?DateTimeInterface $moment, string $fallback = '—'): string
    {
        if ($moment === null) {
            return $fallback;
        }

        return Carbon::instance($moment)->format(self::timeFormat());
    }

    /**
     * Turn the sentinel into the chosen date+time format; leave any real format as
     * it is, so an explicit string reaches date() untouched.
     */
    private static function resolve(string $format): string
    {
        return $format === self::CHOSEN ? self::chosenDateTimeFormat() : $format;
    }
}
