<?php

namespace App\Services\Backup;

use RuntimeException;

/**
 * A backup that could not be taken.
 *
 * Its message is written to the log and to the activity entry, and is shown to
 * whoever pressed the button, so it must never carry a credential. The dumper
 * builds its messages from mysqldump's stderr, which the option file keeps the
 * password out of; nothing here adds it back.
 */
class BackupException extends RuntimeException
{
}
