<?php

namespace App\Console\Commands;

use App\Services\AdminLogger;
use App\Services\Backup\BackupException;
use App\Services\Backup\BackupRunner;
use App\Services\Backup\BackupStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Take a backup from the command line, and from the scheduler.
 *
 *   php artisan backup:run                 a manual archive, kept for ever
 *   php artisan backup:run --type=auto     the nightly one, pruned to the last few
 *
 * The scheduler calls this with --type=auto at 03:00 on the display clock. It also
 * works over SSH with no browser and no sign in, which is the way to take an
 * archive when the admin itself cannot be reached.
 *
 * Automatic runs prune their own old archives afterwards. Manual ones never prune
 * anything: somebody took one deliberately.
 */
class RunBackup extends Command
{
    protected $signature = 'backup:run {--type=manual : auto or manual}';

    protected $description = 'Back up the database and the uploaded files into one archive';

    public function handle(BackupRunner $runner): int
    {
        $type = (string) $this->option('type');

        if (! in_array($type, BackupStore::TYPES, true)) {
            $this->components->error('--type must be auto or manual.');

            return self::FAILURE;
        }

        try {
            $result = $runner->run($type);
        } catch (BackupException $exception) {
            $this->components->error('Backup failed: ' . $exception->getMessage());

            Log::error('Backup failed.', ['type' => $type, 'reason' => $exception->getMessage()]);

            AdminLogger::activity(
                'settings.backup.failed',
                sprintf('A %s backup failed: %s', $type, $exception->getMessage()),
                null,
                'Console',
                AdminLogger::LEVEL_ERROR,
            );

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            '%s (%s, %d tables, %d files, dumped with %s)',
            $result->name,
            $result->size(),
            $result->tableCount,
            $result->fileCount,
            $result->method,
        ));

        AdminLogger::activity(
            'settings.backup.create',
            sprintf('Took a %s backup: %s (%s, %s).', $type, $result->name, $result->size(), $result->method),
            null,
            'Console',
        );

        if ($type === BackupStore::TYPE_AUTO) {
            $removed = $runner->prune();

            if ($removed !== []) {
                $this->components->info(sprintf(
                    'Pruned %d old automatic %s: %s',
                    count($removed),
                    count($removed) === 1 ? 'backup' : 'backups',
                    implode(', ', $removed),
                ));

                AdminLogger::activity(
                    'settings.backup.prune',
                    sprintf('Pruned %d old automatic backup(s): %s.', count($removed), implode(', ', $removed)),
                    null,
                    'Console',
                );
            }
        }

        return self::SUCCESS;
    }
}
