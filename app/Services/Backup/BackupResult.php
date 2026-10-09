<?php

namespace App\Services\Backup;

/**
 * What one completed backup turned out to be, for the command, the job's log
 * line and the activity entry.
 */
class BackupResult
{
    public function __construct(
        public readonly string $name,
        public readonly int $bytes,
        public readonly string $method,
        public readonly int $fileCount,
        public readonly int $tableCount,
    ) {
    }

    /** Rounded for a sentence, not for arithmetic. */
    public function size(): string
    {
        return BackupStore::humanBytes($this->bytes);
    }
}
