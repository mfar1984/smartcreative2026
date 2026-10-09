<?php

namespace App\Support;

use App\Models\Setting;

/**
 * How long automatic archives are kept, as set on the Backup & Restore tab.
 *
 * Follows the exact shape of SecuritySettings: one group constant, a memoised
 * read of the whole group, static readers, defaults in one place, and MAX
 * constants the readers clamp a stray stored value back inside.
 *
 * Three limits, each independent, 0 meaning NO LIMIT for each of them:
 *
 *   keep_count  how many automatic archives to keep
 *   keep_days   delete automatic archives older than this many days
 *   keep_mb     keep the total on disk under this many MB
 *
 * An archive goes when ANY enabled limit says so, which is what the owner asked
 * for: "berapa hari atau berapa MB, yang mana dahulu dapat target". They are not
 * redundant. The count is predictable, the days answer "how far back can I
 * restore", and the MB protects the shared hosting quota, which is the one that
 * fills up quietly — a single archive on the live server is 45.9 MB.
 *
 * The defaults preserve today's behaviour exactly: seven automatic archives, no
 * age limit, no size limit. An empty settings table therefore prunes exactly as
 * the system does now, which is the same rule the Security tab was built on.
 *
 * keep_count has no literal in DEFAULTS on purpose: its default is
 * config('backup.keep'), read at runtime, so the config key stays the fallback
 * default rather than becoming a second source of truth that can drift.
 */
final class BackupSettings
{
    /** Its own group, kept apart from the busy `general` group. */
    private const GROUP = 'backup';

    /**
     * Key => the value used when nothing has been saved.
     *
     * Both are off, because no age or size rule is applied today and first deploy
     * must change nothing about what is on disk.
     *
     * @var array<string, string>
     */
    public const DEFAULTS = [
        'keep_days' => '0',
        'keep_mb' => '0',
    ];

    /**
     * Upper bounds, so a typo cannot store an absurd value. There is no MIN for
     * any of the three: 0 switches a limit off, and anything else is floored at 1
     * by the readers, because "keep 0 archives" is a limit nobody wants and the
     * pruner would refuse to honour it anyway.
     */
    public const MAX_KEEP_COUNT = 365;

    public const MAX_KEEP_DAYS = 3650; // ten years

    public const MAX_KEEP_MB = 1048576; // one TB, expressed in MB

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
     | Typed accessors
     * ------------------------------------------------------------------ */

    /**
     * Automatic archives kept, 0 meaning however many there are.
     *
     * Defaults to config('backup.keep') so first deploy keeps the seven it keeps
     * today, and so an installation that changed the config key keeps its own
     * number until somebody saves the form.
     */
    public static function keepCount(): int
    {
        $stored = self::all()['keep_count'] ?? null;

        if ($stored === null || trim((string) $stored) === '') {
            return self::limit((int) config('backup.keep', 7), self::MAX_KEEP_COUNT);
        }

        return self::limit((int) $stored, self::MAX_KEEP_COUNT);
    }

    /** Days an automatic archive may live, 0 meaning no age limit. */
    public static function keepDays(): int
    {
        return self::limit((int) self::get('keep_days'), self::MAX_KEEP_DAYS);
    }

    /** Megabytes the backups folder may hold in total, 0 meaning no size limit. */
    public static function keepMb(): int
    {
        return self::limit((int) self::get('keep_mb'), self::MAX_KEEP_MB);
    }

    /** The size limit in bytes, or 0 when it is switched off. */
    public static function keepBytes(): int
    {
        return self::keepMb() * 1024 * 1024;
    }

    /**
     * 0 stays 0, which is how a limit is switched off. Anything else is at least
     * 1 and never above the ceiling, so a stray stored value is clamped back
     * inside the range the form accepts rather than being applied as it stands.
     */
    private static function limit(int $value, int $max): int
    {
        if ($value <= 0) {
            return 0;
        }

        return max(1, min($max, $value));
    }

    /* ---------------------------------------------------------------------
     | Form values
     * ------------------------------------------------------------------ */

    /**
     * The values the Retention form shows, defaults filled in.
     *
     * @return array<string, string>
     */
    public static function formValues(): array
    {
        return [
            'keep_count' => (string) self::keepCount(),
            'keep_days' => (string) self::keepDays(),
            'keep_mb' => (string) self::keepMb(),
        ];
    }

    /* ---------------------------------------------------------------------
     | Writing
     * ------------------------------------------------------------------ */

    /** Persist one key under the backup group. */
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
