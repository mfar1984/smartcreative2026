<?php

namespace App\Services\Backup;

use App\Support\LocalTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Finder\Finder;
use Throwable;
use ZipArchive;

/**
 * One backup = one archive.
 *
 *   backup-2026-10-09-030000-auto.zip
 *     database.sql.gz   the whole database, consistent, gzipped
 *     files/public/...  storage/app/public
 *     files/private/... storage/app/private, minus the backups folder itself
 *     manifest.json     what this archive is, so a restore can refuse a wrong one
 *
 * Everything is assembled under a .tmp- name and renamed into place at the very
 * end. A failure anywhere — the dump, the zip, the disk filling up — leaves the
 * temporary file deleted and the folder exactly as it was, because a half-written
 * archive is worse than no archive: it looks like a backup.
 *
 * Nothing here restores. Reading an archive back is a separate piece of work with
 * its own confirmation path, and the manifest exists so that work can check what
 * it has been handed before it touches a live database.
 */
class BackupRunner
{
    /**
     * Bumped if the layout above ever changes, so a restore written against this
     * version can refuse an archive it does not understand.
     */
    public const FORMAT = 1;

    public function __construct(
        private readonly DatabaseDumper $dumper,
        private readonly BackupStore $store,
    ) {
    }

    public function run(string $type = BackupStore::TYPE_MANUAL): BackupResult
    {
        if (! in_array($type, BackupStore::TYPES, true)) {
            throw new BackupException(sprintf('Unknown backup type "%s".', $type));
        }

        if (! class_exists(ZipArchive::class)) {
            throw new BackupException('The PHP zip extension is not installed, so no archive can be written.');
        }

        $directory = $this->store->directory();

        if (! is_writable($directory)) {
            throw new BackupException('The backups folder is not writable.');
        }

        $stamp = uniqid('', false);
        $dump = $directory . DIRECTORY_SEPARATOR . '.tmp-' . $stamp . '.sql.gz';
        $archive = $directory . DIRECTORY_SEPARATOR . '.tmp-' . $stamp . '.zip';
        $name = $this->store->nameFor($type);

        try {
            $method = $this->dumper->dump($dump);

            if (! is_file($dump)) {
                throw new BackupException('The database dump was not written.');
            }

            $files = $this->files();
            $manifest = $this->manifest($type, $method, $files);

            $this->pack($archive, $dump, $files, $manifest);

            if (! @rename($archive, $directory . DIRECTORY_SEPARATOR . $name)) {
                throw new BackupException('The archive was built but could not be moved into the backups folder.');
            }

            return new BackupResult(
                name: $name,
                bytes: (int) (@filesize($directory . DIRECTORY_SEPARATOR . $name) ?: 0),
                method: $method,
                fileCount: count($files),
                tableCount: (int) $manifest['database']['table_count'],
            );
        } finally {
            // The dump always goes: it is inside the archive by now, or the run
            // failed. The temporary archive only exists if the rename never
            // happened, which is the failure case.
            @unlink($dump);
            @unlink($archive);
        }
    }

    /**
     * Automatic archives beyond the newest few. Manual ones are never pruned.
     *
     * @return array<int, string>
     */
    public function prune(): array
    {
        return $this->store->prune((int) config('backup.keep', 7));
    }

    /**
     * Everything under the two storage folders, except the backups folder.
     *
     * Backing up the backups is how one archive becomes two, two become four and
     * a shared hosting quota is gone by the weekend.
     *
     * @return array<int, array{path: string, entry: string, bytes: int}>
     */
    private function files(): array
    {
        $roots = (array) config('backup.include', []);
        $files = [];

        foreach ($roots as $label => $root) {
            if (! is_dir((string) $root)) {
                continue;
            }

            $finder = (new Finder())
                ->files()
                ->in((string) $root)
                ->ignoreDotFiles(false)
                ->ignoreVCS(false)

                /*
                 | Never the backups folder. It is named relative to each root, so
                 | this is harmless on a root that has no such folder and is the
                 | whole point on the private one, where the archives live.
                 */
                ->exclude((string) config('backup.directory', 'backups'));

            foreach ($finder as $file) {
                $files[] = [
                    'path' => $file->getPathname(),
                    'entry' => 'files/' . $label . '/' . str_replace('\\', '/', $file->getRelativePathname()),
                    'bytes' => (int) ($file->getSize() ?: 0),
                ];
            }
        }

        return $files;
    }

    /**
     * What this archive is.
     *
     * created_at is the UTC instant, because that is what the database holds;
     * created_at_local is the same moment on the display clock, because that is
     * what the file name says and what the owner reads. Row counts per table are
     * the only thing a restore can compare against after importing, which is why
     * they are here rather than just a total.
     *
     * @param  array<int, array{path: string, entry: string, bytes: int}>  $files
     * @return array<string, mixed>
     */
    private function manifest(string $type, string $method, array $files): array
    {
        $connection = config('database.default');
        $rows = $this->rowCounts();
        $now = Carbon::now('UTC');

        return [
            'format' => self::FORMAT,
            'app' => (string) config('app.name'),
            'type' => $type,
            'created_at' => $now->toIso8601String(),
            'created_at_local' => LocalTime::format($now),
            'display_timezone' => LocalTime::zone(),
            'dump_method' => $method,
            'database' => [
                'driver' => (string) config("database.connections.{$connection}.driver"),
                'name' => (string) config("database.connections.{$connection}.database"),
                'table_count' => count($rows),
                'row_counts' => $rows,
            ],
            'files' => [
                'count' => count($files),
                'bytes' => array_sum(array_column($files, 'bytes')),
            ],
            'latest_migration' => $this->latestMigration(),
        ];
    }

    /**
     * Rows per table, named so a restore can compare like with like.
     *
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        $counts = [];

        try {
            $tables = Schema::getTableListing();
        } catch (Throwable) {
            return $counts;
        }

        foreach ($tables as $table) {
            // Schema listings can be qualified (database.table on some drivers);
            // the bare name is what a query needs.
            $table = str_contains($table, '.') ? substr(strrchr($table, '.'), 1) : $table;

            try {
                $counts[$table] = (int) DB::table($table)->count();
            } catch (Throwable) {
                // A view, or a table this user may count rows in but not read.
                continue;
            }
        }

        ksort($counts);

        return $counts;
    }

    private function latestMigration(): ?string
    {
        try {
            return DB::table('migrations')->orderByDesc('id')->value('migration');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, array{path: string, entry: string, bytes: int}>  $files
     * @param  array<string, mixed>  $manifest
     */
    private function pack(string $archive, string $dump, array $files, array $manifest): void
    {
        $zip = new ZipArchive();

        if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new BackupException('The archive could not be created in the backups folder.');
        }

        try {
            if (! $zip->addFile($dump, 'database.sql.gz')) {
                throw new BackupException('The database dump could not be added to the archive.');
            }

            foreach ($files as $file) {
                // A file deleted between the listing and here is skipped rather
                // than failing the whole run.
                if (is_file($file['path'])) {
                    $zip->addFile($file['path'], $file['entry']);
                }
            }

            $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if ($json === false || ! $zip->addFromString('manifest.json', $json)) {
                throw new BackupException('The manifest could not be added to the archive.');
            }
        } catch (Throwable $exception) {
            // Closing writes what has been added so far, so the half-built file is
            // discarded instead. run()'s finally unlinks it either way.
            $zip->unchangeAll();
            $zip->close();

            throw $exception;
        }

        if (! $zip->close()) {
            throw new BackupException('The archive could not be written. The account may be out of disk space.');
        }
    }
}
