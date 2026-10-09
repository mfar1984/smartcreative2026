<?php

namespace App\Services\Backup;

use App\Services\AdminLogger;
use App\Support\LocalTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;
use ZipArchive;

/**
 * The backups folder: what is in it, and the only way to name a file in it.
 *
 * Every route that takes a file name goes through resolve(), which is the single
 * check standing between a query string and the filesystem. It does not sanitise
 * and carry on; it compares the basename against the folder listing and returns
 * null for anything that is not an archive sitting there. "../../.env", an
 * absolute path and a real file that is not a backup all come back null, and the
 * controllers turn null into a 404.
 *
 * The folder is on the private disk (storage/app/private), which is outside
 * public/ and has no route serving it, so an archive is never fetchable over
 * HTTP. storage/app/private/.gitignore ignores everything but itself, so an
 * archive can never be committed either.
 */
class BackupStore
{
    /**
     * backup-2026-10-09-030000-auto.zip
     *
     * The date and time are on the display clock, deliberately: somebody reading
     * this folder over FTP at three in the morning should see 030000, not the UTC
     * 190000 of the day before. The manifest inside carries both.
     */
    private const PATTERN = '/^backup-(\d{4}-\d{2}-\d{2})-(\d{6})-(auto|manual)\.zip$/';

    public const TYPE_AUTO = 'auto';

    public const TYPE_MANUAL = 'manual';

    public const TYPES = [self::TYPE_AUTO, self::TYPE_MANUAL];

    public const RULE_COUNT = 'count';

    public const RULE_AGE = 'age';

    public const RULE_SIZE = 'size';

    /**
     * Why an archive was removed, in the words the log line and the command both
     * use. One map, so the record and the console cannot describe the same
     * deletion differently.
     */
    public const RULES = [
        self::RULE_COUNT => 'beyond the number of automatic backups kept',
        self::RULE_AGE => 'older than the age limit',
        self::RULE_SIZE => 'over the total size limit',
    ];

    /**
     * A byte count as a sentence. Rounded for reading, not for arithmetic.
     *
     * Here rather than in the view so the table rows and the total below them
     * cannot end up rounding differently.
     */
    public static function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) max($bytes, 0);
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return ($unit === 0 ? (string) (int) $size : number_format($size, 1)) . ' ' . $units[$unit];
    }

    /** Absolute path of the backups folder, created if it is not there yet. */
    public function directory(): string
    {
        $disk = Storage::disk('local');
        $relative = (string) config('backup.directory', 'backups');

        if (! $disk->exists($relative)) {
            $disk->makeDirectory($relative);
        }

        return $disk->path($relative);
    }

    /**
     * Every archive, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $rows = [];

        foreach ($this->names() as $name) {
            preg_match(self::PATTERN, $name, $matches);

            $path = $this->directory() . DIRECTORY_SEPARATOR . $name;

            $rows[] = [
                'name' => $name,
                'type' => $matches[3],
                'created_at' => $this->momentFromName($matches[1], $matches[2]),
                'bytes' => (int) (@filesize($path) ?: 0),
                'method' => $this->methodOf($path),
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['name'] <=> $a['name']);

        return $rows;
    }

    public function totalBytes(): int
    {
        return array_sum(array_column($this->all(), 'bytes'));
    }

    /**
     * The absolute path of an archive named by a request, or null.
     *
     * Refused, not repaired. basename() on "../backup-....zip" would quietly hand
     * back the real archive, which happens to be harmless here but teaches the
     * wrong habit: the moment the pattern loosens, a normalising resolver is a
     * traversal. So a name carrying a separator is rejected outright, then the name
     * has to match the archive pattern, then it has to actually be in the folder.
     * All three, every time, for download and for delete.
     */
    public function resolve(string $name): ?string
    {
        if ($name === '' || preg_match('#[/\\\\]#', $name) === 1) {
            return null;
        }

        if (preg_match(self::PATTERN, $name) !== 1) {
            return null;
        }

        $path = $this->directory() . DIRECTORY_SEPARATOR . $name;

        return is_file($path) ? $path : null;
    }

    public function delete(string $name): bool
    {
        $path = $this->resolve($name);

        return $path !== null && @unlink($path);
    }

    /**
     * Apply the three retention limits. Manual archives are never touched.
     *
     * Each limit is independent and 0 switches it off, so an archive goes when
     * ANY enabled limit says so — whichever target is reached first, which is
     * what the owner asked for.
     *
     * THE FOLDER CAN NEVER BE EMPTIED BY THIS METHOD, and that is true by
     * construction rather than by a check further down: the newest automatic
     * archive is taken out of the candidate list on the line below, before any
     * rule is consulted, so there is no rule, no ordering and no stored value
     * that can reach it. A 1 MB budget against a 46 MB archive therefore leaves
     * that archive alone instead of leaving the owner with no backup at all.
     * retentionWarnings() is what tells the operator the budget could not be
     * honoured, because a size rule that silently gave up looks exactly like one
     * that worked, right up until a restore is needed.
     *
     * Every removal is recorded here rather than by the caller, for the same
     * reason: an archive that disappears with no log line is indistinguishable
     * from one that was stolen.
     *
     * @return array<int, array{name: string, rule: string, bytes: int}> newest removed first
     */
    public function prune(int $keepCount = 0, int $keepDays = 0, int $keepMb = 0): array
    {
        $rows = $this->all();

        $auto = array_values(array_filter($rows, fn (array $row) => $row['type'] === self::TYPE_AUTO));

        // The guard. Everything after this line works on candidates only, and the
        // newest automatic archive is not one of them.
        $candidates = array_slice($auto, 1);

        /*
         | The age cutoff is built on the OFFICE clock, not on a bare now().
         |
         | The archive's name carries the local wall-clock date (see PATTERN), and
         | "older than seven days" means seven days on the calendar the owner
         | reads. Between local midnight and 08:00 the UTC date is still yesterday,
         | so a UTC startOfDay would move this cutoff by a whole day and prune an
         | archive a day early, or keep one a day too long. That bug has been fixed
         | three times in this project already.
         */
        $cutoff = $keepDays > 0
            ? Carbon::now(LocalTime::zone())->startOfDay()->subDays($keepDays)
            : null;

        $budget = $keepMb > 0 ? $keepMb * 1024 * 1024 : 0;

        /*
         | What is kept no matter what: every manual archive, plus the newest
         | automatic one. Manual archives count towards the budget because they do
         | occupy the quota, but they are never deleted to make room.
         */
        $held = array_sum(array_column(
            array_filter($rows, fn (array $row) => $row['type'] === self::TYPE_MANUAL),
            'bytes',
        )) + (int) ($auto[0]['bytes'] ?? 0);

        $removed = [];
        $overBudget = false;

        foreach ($candidates as $index => $row) {
            // Position among the automatic archives, newest first. The newest is 0
            // and is not in this loop, so the first candidate is already 1.
            $position = $index + 1;

            $rule = match (true) {
                $keepCount > 0 && $position >= $keepCount => self::RULE_COUNT,
                $cutoff !== null && $row['created_at']->lessThan($cutoff) => self::RULE_AGE,

                /*
                 | Once the budget is full it stays full: everything older goes too,
                 | rather than skipping one large archive and keeping a smaller older
                 | one behind it. "The newest few that fit" is a list somebody can
                 | reason about; a folder with gaps in it is not.
                 */
                $overBudget => self::RULE_SIZE,
                $budget > 0 && $held + $row['bytes'] > $budget => self::RULE_SIZE,
                default => null,
            };

            if ($rule === null) {
                $held += $row['bytes'];

                continue;
            }

            if ($rule === self::RULE_SIZE) {
                $overBudget = true;
            }

            if (! $this->delete($row['name'])) {
                // A file that could not be deleted still occupies the quota, so it
                // is counted rather than wished away.
                $held += $row['bytes'];

                continue;
            }

            $removed[] = ['name' => $row['name'], 'rule' => $rule, 'bytes' => $row['bytes']];

            AdminLogger::activity(
                'settings.backup.prune',
                sprintf('Retention removed %s: %s.', $row['name'], self::RULES[$rule]),
                null,
                'Retention',
            );
        }

        return $removed;
    }

    /**
     * What the size limit could not do, in words, for the Backup & Restore tab.
     *
     * Both cases are the pruner refusing to do harm, and both would otherwise be
     * invisible: the folder simply stays over the limit and nothing says why.
     *
     * @return array<int, string>
     */
    public function retentionWarnings(int $keepMb): array
    {
        if ($keepMb <= 0) {
            return [];
        }

        $rows = $this->all();
        $budget = $keepMb * 1024 * 1024;
        $total = array_sum(array_column($rows, 'bytes'));

        if ($total <= $budget) {
            return [];
        }

        $warnings = [];

        $auto = array_values(array_filter($rows, fn (array $row) => $row['type'] === self::TYPE_AUTO));
        $manual = array_values(array_filter($rows, fn (array $row) => $row['type'] === self::TYPE_MANUAL));

        // One archive bigger than the whole budget. It is kept, so the size limit
        // cannot be honoured and saying nothing would hide that.
        if (isset($auto[0]) && $auto[0]['bytes'] > $budget) {
            $warnings[] = sprintf(
                'The total size limit is %d MB, but the newest automatic archive on its own is %s. It is kept anyway — the newest automatic backup is never deleted — so the size limit cannot be honoured. Raise the limit to at least %d MB.',
                $keepMb,
                self::humanBytes($auto[0]['bytes']),
                (int) ceil($auto[0]['bytes'] / 1024 / 1024),
            );
        }

        // Nothing left to prune and still over: the manual archives are what is
        // filling the folder, and those are never removed automatically.
        if (count($auto) <= 1 && $manual !== []) {
            $warnings[] = sprintf(
                'The backups folder is %s, over the %d MB limit, and %d manual %s (%s) %s what is holding it there. Manual backups are never removed automatically — delete one by hand if the space is needed.',
                self::humanBytes($total),
                $keepMb,
                count($manual),
                count($manual) === 1 ? 'archive' : 'archives',
                self::humanBytes((int) array_sum(array_column($manual, 'bytes'))),
                count($manual) === 1 ? 'is' : 'are',
            );
        }

        return $warnings;
    }

    /**
     * A free file name for a backup taken now, on the display clock.
     *
     * Walks the clock forward a second at a time rather than appending a counter,
     * so the name shape never varies and the pattern above stays the only thing
     * that has to understand it.
     */
    public function nameFor(string $type): string
    {
        $moment = Carbon::now(LocalTime::zone());
        $directory = $this->directory();

        for ($i = 0; $i < 60; $i++) {
            $name = sprintf('backup-%s-%s.zip', $moment->format('Y-m-d-His'), $type);

            if (! file_exists($directory . DIRECTORY_SEPARATOR . $name)) {
                return $name;
            }

            $moment->addSecond();
        }

        throw new BackupException('Could not find a free backup file name. Delete an archive and try again.');
    }

    /** @return array<int, string> */
    private function names(): array
    {
        $found = @scandir($this->directory()) ?: [];

        return array_values(array_filter(
            $found,
            fn (string $name) => preg_match(self::PATTERN, $name) === 1,
        ));
    }

    /** The name's wall clock reading, converted back to the UTC instant it was. */
    private function momentFromName(string $date, string $time): Carbon
    {
        return Carbon::createFromFormat('Y-m-d His', $date . ' ' . $time, LocalTime::zone())
            ->setTimezone('UTC');
    }

    /**
     * The dump method recorded in the archive's own manifest.
     *
     * Read from inside the zip rather than kept in a side table, so the archive
     * stays the only thing that has to be copied. Seven files on one screen, one
     * small read each; an unreadable archive reports a dash rather than breaking
     * the page it is listed on.
     */
    private function methodOf(string $path): ?string
    {
        if (! class_exists(ZipArchive::class)) {
            return null;
        }

        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            return null;
        }

        try {
            $raw = $zip->getFromName('manifest.json');

            if ($raw === false) {
                return null;
            }

            $manifest = json_decode($raw, true);

            return is_array($manifest) ? ($manifest['dump_method'] ?? null) : null;
        } catch (Throwable) {
            return null;
        } finally {
            $zip->close();
        }
    }
}
