<?php

namespace App\Support;

use App\Models\Setting;

/**
 * The security controls saved on the General Config > Security tab.
 *
 * Follows the exact shape of GeneralSettings: one group constant, a memoised
 * read of the whole group, static readers, and defaults in one place. The
 * defaults are chosen so that on first deploy — an empty settings table — the
 * effective behaviour is identical to what the system did before this tab
 * existed:
 *
 *   - password rule equals Password::min(10)->letters()->numbers()->symbols()
 *     (min 10, a letter is required, a number is required, a symbol is required;
 *     mixed case is NOT required, because today's ->letters() does not force it)
 *   - no password expiry (0 = never)
 *   - inactivity auto-logout set to the current session lifetime (config value),
 *     so nobody is logged out sooner than they are today
 *   - one active session per user is OFF
 *   - the sessions row IS destroyed on logout — this is the one deliberate change
 *     Part 1 ships, because the owner asked for it and the old behaviour (leaving
 *     a stale user_id NULL row behind) is the bug being fixed
 *
 * Part 1 populates the password-policy and session keys. Part 2 adds the
 * sign-in ban, the two rate limits and the IP allowlist. Their defaults keep
 * today's behaviour for the limits (10 sign-in attempts per minute, no admin
 * request limit, no allowlist); the ban itself is new by design, with generous
 * defaults of ten failures inside fifteen minutes.
 */
final class SecuritySettings
{
    /** Its own group, kept apart from the busy `general` group. */
    private const GROUP = 'security';

    /**
     * Key => the value used when nothing has been saved.
     *
     * Password policy keys reproduce today's hardcoded rule. 'require_upper' is
     * OFF because today's ->letters() does not enforce case. Session keys keep
     * today's effective behaviour, except destroy_session_on_logout which is the
     * fix the owner asked for.
     *
     * session_timeout_minutes has no literal here on purpose: its default is the
     * configured session lifetime, read at runtime, so the two can never drift.
     *
     * @var array<string, string>
     */
    public const DEFAULTS = [
        // Password policy
        'password_min' => '10',
        'password_require_upper' => '0',
        'password_require_number' => '1',
        'password_require_symbol' => '1',
        'password_expiry_days' => '0',

        // Session controls
        'single_session' => '0',
        'destroy_session_on_logout' => '1',

        // Failed sign-in bans (admin sign in only, never the public site)
        'ban_enabled' => '1',
        'ban_after_failures' => '10',
        'ban_window_minutes' => '15',
        'ban_duration_minutes' => '30',

        // Rate limits. 10 per minute is exactly the old throttle:10,1 on the
        // sign-in POST; 0 means the admin area has no request limit, as today.
        'login_attempts_per_minute' => '10',
        'admin_requests_per_minute' => '0',

        // Empty means no restriction, which is today's behaviour.
        'ip_allowlist' => '',
    ];

    /**
     * Floor applied to the inactivity timeout.
     *
     * A value this small would start logging people out almost immediately, which
     * is exactly the lockout risk the brief warns against, so the form rejects
     * anything lower and the reader clamps a stray stored value up to it.
     */
    public const MIN_SESSION_TIMEOUT_MINUTES = 5;

    /** Upper bound the form accepts, so a typo cannot store an absurd value. */
    public const MAX_SESSION_TIMEOUT_MINUTES = 10080; // one week

    /** Floor on password length, matching Laravel's own minimum. */
    public const MIN_PASSWORD_MIN = 8;

    public const MAX_PASSWORD_MIN = 128;

    public const MAX_PASSWORD_EXPIRY_DAYS = 3650; // ten years

    /*
     | Bounds on the Part 2 numbers. The floors are the lockout guard: below them
     | a single mistyped password, or a burst of ordinary clicks, would start
     | refusing people. The form rejects anything outside these and the readers
     | clamp a stray stored value back inside them.
     */
    public const MIN_BAN_AFTER_FAILURES = 3;

    public const MAX_BAN_AFTER_FAILURES = 100;

    public const MIN_BAN_WINDOW_MINUTES = 1;

    public const MAX_BAN_WINDOW_MINUTES = 1440; // one day

    public const MIN_BAN_DURATION_MINUTES = 1;

    public const MAX_BAN_DURATION_MINUTES = 1440; // one day

    public const MIN_LOGIN_ATTEMPTS_PER_MINUTE = 3;

    public const MAX_LOGIN_ATTEMPTS_PER_MINUTE = 600;

    /** 0 switches the admin request limit off; any other value is at least this. */
    public const MIN_ADMIN_REQUESTS_PER_MINUTE = 60;

    public const MAX_ADMIN_REQUESTS_PER_MINUTE = 10000;

    /** Longest allowlist the form accepts, in characters. */
    public const MAX_IP_ALLOWLIST_LENGTH = 5000;

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

    /**
     * One value, or the shipped default when it is missing or blank.
     */
    public static function get(string $key): ?string
    {
        $value = self::all()[$key] ?? null;

        if ($value === null || trim($value) === '') {
            return self::DEFAULTS[$key] ?? null;
        }

        return $value;
    }

    private static function int(string $key): int
    {
        return (int) self::get($key);
    }

    private static function bool(string $key): bool
    {
        return self::get($key) === '1';
    }

    /* ---------------------------------------------------------------------
     | Typed accessors — password policy
     * ------------------------------------------------------------------ */

    /** Minimum password length, floored so a stray value cannot weaken it below Laravel's own minimum. */
    public static function passwordMin(): int
    {
        return max(self::MIN_PASSWORD_MIN, self::int('password_min'));
    }

    public static function passwordRequireUpper(): bool
    {
        return self::bool('password_require_upper');
    }

    public static function passwordRequireNumber(): bool
    {
        return self::bool('password_require_number');
    }

    public static function passwordRequireSymbol(): bool
    {
        return self::bool('password_require_symbol');
    }

    /** Days a password stays valid, 0 meaning never expires. */
    public static function passwordExpiryDays(): int
    {
        return max(0, self::int('password_expiry_days'));
    }

    /* ---------------------------------------------------------------------
     | Typed accessors — session controls
     * ------------------------------------------------------------------ */

    /**
     * Minutes of inactivity before the admin is logged out.
     *
     * Defaults to the configured session lifetime so first deploy matches today's
     * effective lifetime, and is clamped to the floor so no stored value can start
     * logging people out aggressively.
     */
    public static function sessionTimeoutMinutes(): int
    {
        $stored = self::all()['session_timeout_minutes'] ?? null;

        if ($stored === null || trim((string) $stored) === '') {
            return (int) config('session.lifetime', 120);
        }

        return max(self::MIN_SESSION_TIMEOUT_MINUTES, (int) $stored);
    }

    public static function singleSession(): bool
    {
        return self::bool('single_session');
    }

    public static function destroySessionOnLogout(): bool
    {
        return self::bool('destroy_session_on_logout');
    }

    /* ---------------------------------------------------------------------
     | Typed accessors — failed sign-in bans
     * ------------------------------------------------------------------ */

    public static function banEnabled(): bool
    {
        return self::bool('ban_enabled');
    }

    /** Failed sign ins from one IP that trigger a ban, never fewer than three. */
    public static function banAfterFailures(): int
    {
        return self::clamp(self::int('ban_after_failures'), self::MIN_BAN_AFTER_FAILURES, self::MAX_BAN_AFTER_FAILURES);
    }

    /** The window, in minutes, those failures are counted in. */
    public static function banWindowMinutes(): int
    {
        return self::clamp(self::int('ban_window_minutes'), self::MIN_BAN_WINDOW_MINUTES, self::MAX_BAN_WINDOW_MINUTES);
    }

    /** How long a ban lasts, in minutes, before it lifts on its own. */
    public static function banDurationMinutes(): int
    {
        return self::clamp(self::int('ban_duration_minutes'), self::MIN_BAN_DURATION_MINUTES, self::MAX_BAN_DURATION_MINUTES);
    }

    /* ---------------------------------------------------------------------
     | Typed accessors — rate limits
     * ------------------------------------------------------------------ */

    /** Sign-in POSTs allowed from one IP per minute. */
    public static function loginAttemptsPerMinute(): int
    {
        return self::clamp(self::int('login_attempts_per_minute'), self::MIN_LOGIN_ATTEMPTS_PER_MINUTE, self::MAX_LOGIN_ATTEMPTS_PER_MINUTE);
    }

    /**
     * Requests one signed-in user may make per minute across the admin area.
     *
     * 0 means no limit. Anything else is floored at 60, so a typo such as 6
     * cannot stall the scoring desk in the middle of an event.
     */
    public static function adminRequestsPerMinute(): int
    {
        $value = self::int('admin_requests_per_minute');

        if ($value <= 0) {
            return 0;
        }

        return self::clamp($value, self::MIN_ADMIN_REQUESTS_PER_MINUTE, self::MAX_ADMIN_REQUESTS_PER_MINUTE);
    }

    /* ---------------------------------------------------------------------
     | Typed accessors — IP allowlist
     * ------------------------------------------------------------------ */

    /**
     * The allowlist entries, one IP or CIDR range each. Empty means no restriction.
     *
     * @return array<int, string>
     */
    public static function ipAllowlist(): array
    {
        return self::allowlistLines((string) self::get('ip_allowlist'));
    }

    /**
     * Split allowlist text into its trimmed, non-empty lines.
     *
     * @return array<int, string>
     */
    public static function allowlistLines(string $text): array
    {
        $lines = preg_split('/\R/', $text) ?: [];

        return array_values(array_filter(
            array_map('trim', $lines),
            fn (string $line) => $line !== '',
        ));
    }

    private static function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }

    /* ---------------------------------------------------------------------
     | Form values
     * ------------------------------------------------------------------ */

    /**
     * The values the Security form shows, defaults filled in.
     *
     * @return array<string, string>
     */
    public static function formValues(): array
    {
        return [
            'password_min' => (string) self::passwordMin(),
            'password_require_upper' => self::passwordRequireUpper() ? '1' : '0',
            'password_require_number' => self::passwordRequireNumber() ? '1' : '0',
            'password_require_symbol' => self::passwordRequireSymbol() ? '1' : '0',
            'password_expiry_days' => (string) self::passwordExpiryDays(),
            'session_timeout_minutes' => (string) self::sessionTimeoutMinutes(),
            'single_session' => self::singleSession() ? '1' : '0',
            'destroy_session_on_logout' => self::destroySessionOnLogout() ? '1' : '0',
            'ban_enabled' => self::banEnabled() ? '1' : '0',
            'ban_after_failures' => (string) self::banAfterFailures(),
            'ban_window_minutes' => (string) self::banWindowMinutes(),
            'ban_duration_minutes' => (string) self::banDurationMinutes(),
            'login_attempts_per_minute' => (string) self::loginAttemptsPerMinute(),
            'admin_requests_per_minute' => (string) self::adminRequestsPerMinute(),
            'ip_allowlist' => implode("\n", self::ipAllowlist()),
        ];
    }

    /* ---------------------------------------------------------------------
     | Writing
     * ------------------------------------------------------------------ */

    /** Persist one key under the security group. */
    public static function write(string $key, ?string $value): void
    {
        Setting::write(self::GROUP . '.' . $key, $value, self::GROUP);
    }

    /** Forget the cached group after a save, so the redirect draws the new values. */
    public static function flush(): void
    {
        self::$cache = null;
    }
}
