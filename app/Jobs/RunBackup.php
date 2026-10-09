<?php

namespace App\Jobs;

use App\Services\AdminLogger;
use App\Services\Backup\BackupException;
use App\Services\Backup\BackupRunner;
use App\Services\Backup\BackupStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The "Back up now" button, done out of the request.
 *
 * Zipping the uploads folder on shared hosting can take minutes, and a web
 * request that takes minutes is a 504 with half an archive behind it. The button
 * queues this instead; the cron worker picks it up within the minute and the
 * archive appears in the list.
 *
 * $timeout is the job's own and takes precedence over the worker's --timeout=60,
 * which would otherwise kill a large upload folder part way through. $tries is 1:
 * a dump that failed because the disk is full fails the same way three times, and
 * each attempt would be another long run against a live database.
 */
class RunBackup implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    /**
     * Set while a backup is queued or running, so the tab can say so instead of
     * looking as though the button did nothing. Short lived on purpose: if the
     * worker dies the notice clears itself rather than sticking for ever.
     */
    public const PENDING_KEY = 'backup.pending';

    public const PENDING_TTL = 900;

    public function __construct(
        public readonly ?int $userId = null,
        public readonly ?string $actorLabel = null,
    ) {
    }

    public function handle(BackupRunner $runner): void
    {
        try {
            $result = $runner->run(BackupStore::TYPE_MANUAL);

            AdminLogger::activity(
                'settings.backup.create',
                sprintf('Took a manual backup: %s (%s, %s).', $result->name, $result->size(), $result->method),
                $this->userId,
                $this->actorLabel,
            );
        } catch (BackupException $exception) {
            // Caught rather than thrown on: the reason belongs in the activity log
            // where the person who pressed the button will look for it, and a
            // BackupException has already cleaned up after itself.
            $this->report($exception->getMessage());
        } finally {
            Cache::forget(self::PENDING_KEY);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Cache::forget(self::PENDING_KEY);

        $this->report($exception?->getMessage() ?? 'Unknown failure.');
    }

    private function report(string $reason): void
    {
        Log::error('Backup failed.', ['type' => 'manual', 'reason' => $reason]);

        AdminLogger::activity(
            'settings.backup.failed',
            'A manual backup failed: ' . $reason,
            $this->userId,
            $this->actorLabel,
            AdminLogger::LEVEL_ERROR,
        );
    }
}
