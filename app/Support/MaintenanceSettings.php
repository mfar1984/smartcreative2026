<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * The maintenance controls saved on the General Config > Maintenance tab.
 *
 * Follows the shape of SecuritySettings: one group constant, a memoised read of
 * the whole group, static readers, defaults in one place, and writing through
 * one method so the key prefix is spelled once. The defaults reproduce today's
 * behaviour exactly — switch off, no exempted address, no return time and no
 * window — so an empty settings table behaves as it did before this file
 * existed.
 *
 * THE WINDOW IS DECIDED HERE, ON EVERY REQUEST, AND NOT BY A SCHEDULED JOB.
 * That is the whole design and it must not be "improved" into a job that flips
 * the switch. A job-driven window leaves the public site down forever when cron
 * stops, the worker dies or the box is rebooted across the window, because the
 * job that would turn it back on never runs. Reading the window per request
 * means nothing has to run for it to end: it ends because the time passed, and
 * the site recovers on its own with every background process on the server
 * dead.
 *
 * The three time values are WALL-CLOCK values, not instants. An operator types
 * "09:00" meaning nine on the office clock, so they are stored as typed and
 * displayed with LocalTime::formatWallClock(), which withholds the timezone
 * shift. They are only interpreted in the display zone when they have to be
 * COMPARED against now, which is what "that time on the office clock" means.
 * Treating them as UTC instants is the bug this project has already fixed three
 * times: between local midnight and 08:00 the UTC date is still yesterday, so a
 * window set for tonight would start eight hours late.
 */
final class MaintenanceSettings
{
    /** Its own group, the one the switch has always been stored under. */
    private const GROUP = 'maintenance';

    /**
     * The holding page, named once.
     *
     * Both the middleware and the admin preview render THIS view, so the page an
     * operator checks is the page a visitor gets. A second copy of the Blade for
     * the preview would drift the first time somebody edited one of them.
     */
    public const HOLDING_PAGE_VIEW = 'pages.site-maintenance';

    /**
     * Key => the value used when nothing has been saved.
     *
     * The heading and the message here are the PAGE's fallbacks: what a visitor
     * sees when the switch was ticked without the copy being saved. They are
     * deliberately shorter than FORM_DEFAULTS, which is the suggested copy the
     * form pre-fills for an operator to edit. The two can never both be on screen:
     * saving the form stores copy, and stored copy is what renders.
     *
     * @var array<string, string>
     */
    public const DEFAULTS = [
        'enabled' => '0',
        'heading' => 'We are carrying out maintenance',
        'message' => 'The website is temporarily unavailable. Please check back shortly.',

        // Empty means nobody is let through, which is today's behaviour.
        'exempt_ips' => '',

        // Empty means the holding page says nothing about timing, as today.
        'expected_return_at' => '',
        'window_start' => '',
        'window_end' => '',
    ];

    /**
     * The copy the Maintenance form shows when nothing has been saved.
     *
     * Kept exactly as the form has always pre-filled it. See DEFAULTS for why the
     * message is longer here than the page's own fallback.
     *
     * @var array<string, string>
     */
    public const FORM_DEFAULTS = [
        'enabled' => '0',
        'heading' => 'We are carrying out maintenance',
        'message' => 'The website is temporarily unavailable while we carry out scheduled maintenance. Please check back shortly.',
    ];

    /** How a wall-clock value is stored: a bare local date and time, no zone. */
    public const STORED_FORMAT = 'Y-m-d H:i';

    /** What <input type="datetime-local"> posts and reads back. */
    public const INPUT_FORMAT = 'Y-m-d\TH:i';

    /**
     * Longest exemption list the form accepts, in characters.
     *
     * The same number as the Security tab's allowlist, and taken from it rather
     * than typed again, because they are the same kind of box.
     */
    public const MAX_EXEMPT_IPS_LENGTH = SecuritySettings::MAX_IP_ALLOWLIST_LENGTH;

    /*
     | What is in force right now. Reported on the tab so an operator never has to
     | work out whether the public site is up.
     */

    /** Switch off, no window armed: the website is live. */
    public const STATE_OFF = 'off';

    /** Switch on by hand: held until somebody clears it. */
    public const STATE_MANUAL = 'manual';

    /** Switch off, but now falls inside the saved window: held until it passes. */
    public const STATE_WINDOW_RUNNING = 'window_running';

    /** A window saved for later. The site is live until it starts. */
    public const STATE_WINDOW_PENDING = 'window_pending';

    /** A window whose end has passed. Over, and holding nothing. */
    public const STATE_WINDOW_FINISHED = 'window_finished';

    /**
     * The whole group, read once per request.
     *
     * @var array<string, string|null>|null
     */
    private static ?array $cache = null;

    /* ---------------------------------------------------------------------
     | Reading
     * ------------------------------------------------------------------ */

    /**
     * Every stored value in the group, group prefix removed from the keys.
     *
     * @return array<string, string|null>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $values = [];

        foreach (Setting::readGroup(self::GROUP) as $key => $value) {
            $values[str_replace(self::GROUP . '.', '', $key)] = $value;
        }

        return self::$cache = $values;
    }

    /** One value, or the shipped default when it is missing or blank. */
    public static function get(string $key): ?string
    {
        $value = self::all()[$key] ?? null;

        if ($value === null || trim($value) === '') {
            return self::DEFAULTS[$key] ?? null;
        }

        return $value;
    }

    /* ---------------------------------------------------------------------
     | Typed accessors — the switch and the holding page copy
     * ------------------------------------------------------------------ */

    /** The manual switch, on its own. Says nothing about the window. */
    public static function enabled(): bool
    {
        return self::get('enabled') === '1';
    }

    public static function heading(): string
    {
        return (string) self::get('heading');
    }

    public static function message(): string
    {
        return (string) self::get('message');
    }

    /* ---------------------------------------------------------------------
     | Typed accessors — who is let through
     * ------------------------------------------------------------------ */

    /**
     * Addresses that keep seeing the live public site while maintenance is on.
     *
     * NOT the Security tab's sign-in allowlist, which does the opposite job: that
     * one RESTRICTS who may sign in to the admin, this one EXEMPTS who sees the
     * live website. Only the line parsing is shared, because one box of addresses
     * should not be split into two formats.
     *
     * @return array<int, string>
     */
    public static function exemptIps(): array
    {
        return SecuritySettings::allowlistLines((string) self::get('exempt_ips'));
    }

    /**
     * Whether this visitor is exempt. An empty list exempts nobody.
     *
     * Independent of the admin bypass in PublicMaintenanceMode, which is what
     * guarantees nobody can be locked out: a mistake in this list can only ever
     * decide who sees the holding page, never who reaches the admin.
     */
    public static function exemptsIp(string $ip): bool
    {
        return IpAllowlist::matches($ip, self::exemptIps());
    }

    /* ---------------------------------------------------------------------
     | Typed accessors — the times
     * ------------------------------------------------------------------ */

    /** The expected return time as typed, or null when none was saved. */
    public static function expectedReturnAt(): ?Carbon
    {
        return self::wallClock('expected_return_at');
    }

    /**
     * The expected return time, only while it is still ahead.
     *
     * A time that has passed is HIDDEN rather than reworded: "back by 9am" at
     * half past ten tells a visitor nothing except that we are late, and the
     * operator who typed it is the only person who can correct it. The tab says
     * the time has passed, so it is visible where it can be acted on.
     */
    public static function upcomingReturnAt(): ?Carbon
    {
        $moment = self::expectedReturnAt();

        return $moment !== null && $moment->isFuture() ? $moment : null;
    }

    /** Whether a return time was saved and has already gone by. */
    public static function returnTimeHasPassed(): bool
    {
        $moment = self::expectedReturnAt();

        return $moment !== null && $moment->isPast();
    }

    /** When the scheduled window starts, as typed, or null for no window. */
    public static function windowStart(): ?Carbon
    {
        return self::wallClock('window_start');
    }

    /** When it ends, as typed. Null with a start means "until the switch is cleared". */
    public static function windowEnd(): ?Carbon
    {
        return self::wallClock('window_end');
    }

    /**
     * Whether the saved window covers this moment.
     *
     * A window with no start is not a window at all: the form refuses an end on
     * its own, and a stray stored one is ignored rather than being read as "down
     * until then", because the safe direction for a half-written value is up.
     */
    public static function windowIsRunning(): bool
    {
        $start = self::windowStart();

        if ($start === null || $start->isFuture()) {
            return false;
        }

        $end = self::windowEnd();

        return $end === null || $end->isFuture();
    }

    /* ---------------------------------------------------------------------
     | What is in force
     * ------------------------------------------------------------------ */

    /**
     * Whether the public site is being held right now.
     *
     * The manual switch, OR the window covering this moment. Read on every
     * request — see the class comment: this is what lets a window end without
     * anything running.
     */
    public static function isHoldingPublicSite(): bool
    {
        return self::enabled() || self::windowIsRunning();
    }

    /** Which of the five states the tab reports. */
    public static function state(): string
    {
        if (self::enabled()) {
            return self::STATE_MANUAL;
        }

        $start = self::windowStart();

        if ($start === null) {
            return self::STATE_OFF;
        }

        if (self::windowIsRunning()) {
            return self::STATE_WINDOW_RUNNING;
        }

        return $start->isFuture()
            ? self::STATE_WINDOW_PENDING
            : self::STATE_WINDOW_FINISHED;
    }

    /**
     * Everything the holding page needs.
     *
     * One method so the middleware's 503 and the admin's 200 preview are drawing
     * the same page from the same values, and neither can gain a variable the
     * other has not got.
     *
     * @return array<string, mixed>
     */
    public static function holdingPageData(): array
    {
        return [
            'heading' => self::heading(),
            'message' => self::message(),
            'returnAt' => self::upcomingReturnAt(),
        ];
    }

    /* ---------------------------------------------------------------------
     | Form values
     * ------------------------------------------------------------------ */

    /**
     * The values the Maintenance form shows, defaults filled in.
     *
     * The three original keys keep the exact fallback behaviour the form has
     * always had: a stored blank stays blank, only a missing row takes a default.
     * The times come back in the shape a datetime-local input reads.
     *
     * @return array<string, string>
     */
    public static function formValues(): array
    {
        $stored = self::all();
        $values = [];

        foreach (self::FORM_DEFAULTS as $key => $default) {
            $values[$key] = (string) ($stored[$key] ?? $default);
        }

        return $values + [
            'exempt_ips' => implode("\n", self::exemptIps()),
            'expected_return_at' => self::inputValue(self::expectedReturnAt()),
            'window_start' => self::inputValue(self::windowStart()),
            'window_end' => self::inputValue(self::windowEnd()),
        ];
    }

    /**
     * A typed time in the shape it is stored in, or '' for nothing.
     *
     * Accepts either the input's own format or the stored one, so the same method
     * handles a fresh post and a value already on disk.
     */
    public static function normaliseWallClock(?string $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        $moment = self::parseWallClock($value);

        return $moment === null ? '' : $moment->format(self::STORED_FORMAT);
    }

    /** Whether a posted time can be read at all. Used by the form request. */
    public static function isValidWallClock(string $value): bool
    {
        return self::parseWallClock($value) !== null;
    }

    /* ---------------------------------------------------------------------
     | Writing
     * ------------------------------------------------------------------ */

    /** Persist one key under the maintenance group. */
    public static function write(string $key, ?string $value): void
    {
        Setting::write(self::GROUP . '.' . $key, $value, self::GROUP);
    }

    /** Forget the cached group after a save, so the redirect draws the new values. */
    public static function flush(): void
    {
        self::$cache = null;
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    /**
     * A stored wall-clock value, placed on the office clock.
     *
     * The instance carries the display zone, which is what makes both jobs
     * correct at once: formatWallClock() prints the components that were typed,
     * and a comparison against now() is a comparison of real instants, so a
     * window set for local midnight starts at local midnight and not eight hours
     * later.
     */
    private static function wallClock(string $key): ?Carbon
    {
        $value = trim((string) (self::all()[$key] ?? ''));

        return $value === '' ? null : self::parseWallClock($value);
    }

    /**
     * Read a typed time, in either the input's format or the stored one.
     *
     * Returns null for anything unreadable instead of throwing. A settings row
     * can be edited by other tools, and an unreadable one must not take a page
     * down with an exception — least of all the holding page.
     */
    private static function parseWallClock(string $value): ?Carbon
    {
        $zone = LocalTime::zone();

        foreach ([self::INPUT_FORMAT, self::STORED_FORMAT, self::STORED_FORMAT . ':s'] as $format) {
            try {
                // The leading ! zeroes every field the format does not mention, so
                // "09:00" is nine o'clock exactly and not nine o'clock and whatever
                // second the request happened to arrive on.
                $moment = Carbon::createFromFormat('!' . $format, $value, $zone);
            } catch (\Throwable) {
                // Carbon throws on a value that does not fit; try the next shape.
                continue;
            }

            // Round-tripped, because createFromFormat accepts "2026-13-45" and
            // rolls it over into a date nobody typed.
            if ($moment->format($format) === $value) {
                return $moment;
            }
        }

        return null;
    }

    /** A typed time in the shape a datetime-local input reads, or '' for nothing. */
    private static function inputValue(?Carbon $moment): string
    {
        return $moment === null ? '' : LocalTime::formatWallClock($moment, self::INPUT_FORMAT, '');
    }
}
