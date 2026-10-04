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
     * A stored instant, read on the display clock.
     *
     * Returns the fallback for a missing date so the callers do not each need
     * their own ?? '—', which is how two screens end up disagreeing about what
     * an empty timestamp looks like.
     */
    public static function format(?DateTimeInterface $moment, string $format = self::DATE_TIME, string $fallback = '—'): string
    {
        if ($moment === null) {
            return $fallback;
        }

        return Carbon::instance($moment)->setTimezone(self::zone())->format($format);
    }
}
