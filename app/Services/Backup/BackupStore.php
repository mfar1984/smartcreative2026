<?php

namespace App\Services\Backup;

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
     * Drop automatic archives beyond the newest $keep. Manual ones are untouched.
     *
     * @return array<int, string> the names removed
     */
    public function prune(int $keep): array
    {
        $auto = array_values(array_filter(
            $this->all(),
            fn (array $row) => $row['type'] === self::TYPE_AUTO,
        ));

        $removed = [];

        foreach (array_slice($auto, max($keep, 0)) as $row) {
            if ($this->delete($row['name'])) {
                $removed[] = $row['name'];
            }
        }

        return $removed;
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
