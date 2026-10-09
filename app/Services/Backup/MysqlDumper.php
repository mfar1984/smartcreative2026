<?php

namespace App\Services\Backup;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The database dump, taken by mysqldump and gzipped on the way past.
 *
 * Two things about this class are load-bearing and must not be "tidied".
 *
 * THE PASSWORD IS NEVER AN ARGUMENT. On shared hosting every other account on the
 * machine can read the process list, so -p<secret> on the command line hands the
 * database password to the neighbours. It goes into a temporary option file
 * instead, created at 0600 and deleted in a finally block, named by
 * --defaults-extra-file (which mysqldump insists on seeing first). command() is
 * public so a test can assert what is actually passed, and the test does.
 *
 * NOTHING IS PIPED THROUGH A SHELL. `mysqldump | gzip` would need
 * Process::fromShellCommandline, which puts the whole thing back through sh and
 * with it every quoting question we just avoided. The output callback writes each
 * chunk straight into a gzip stream, so the plain SQL never touches the disk.
 */
class MysqlDumper implements DatabaseDumper
{
    public function __construct(
        /** @var array<string, mixed> the resolved database connection config */
        private readonly array $connection,
        private readonly string $binary,
        private readonly int $timeout,
    ) {
    }

    public static function forDefaultConnection(): self
    {
        $name = config('database.default');

        return new self(
            config("database.connections.{$name}") ?? [],
            (string) config('backup.mysqldump', 'mysqldump'),
            (int) config('backup.timeout', 600),
        );
    }

    /**
     * The arguments mysqldump is given, with the option file path substituted in.
     *
     * Public so it can be asserted on. There is no credential anywhere in here:
     * the user, host, port, socket and password all live in the option file.
     */
    public function command(string $optionFile): array
    {
        return [
            $this->binary,

            // First, and mysqldump will refuse it anywhere else.
            '--defaults-extra-file=' . $optionFile,

            /*
             | A consistent snapshot of a live InnoDB database without locking
             | anybody out: the dump runs inside one transaction, so a registration
             | taken while it runs is either wholly in or wholly out.
             */
            '--single-transaction',

            // Row at a time rather than buffering a whole table in memory.
            '--quick',

            /*
             | Shared hosting MySQL 8 users are not granted PROCESS, and without
             | this mysqldump stops with "Access denied; you need the PROCESS
             | privilege" before writing a single row.
             */
            '--no-tablespaces',

            '--default-character-set=' . ($this->connection['charset'] ?? 'utf8mb4'),

            // No --routines: the schema has no procedures, functions or triggers.

            (string) ($this->connection['database'] ?? ''),
        ];
    }

    public function dump(string $target): string
    {
        $driver = $this->connection['driver'] ?? null;

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new BackupException(sprintf(
                'Backups need a MySQL or MariaDB connection; this one is "%s".',
                $driver ?? 'unknown',
            ));
        }

        if (! function_exists('proc_open')) {
            throw new BackupException(
                'PHP cannot start mysqldump because proc_open is disabled on this server.',
            );
        }

        $optionFile = $this->writeOptionFile();

        try {
            $this->stream($this->command($optionFile), $target);
        } finally {
            // Before anything else can read it, including an error handler.
            @unlink($optionFile);
        }

        return 'mysqldump';
    }

    /**
     * Run mysqldump, writing its output into a gzip stream as it arrives.
     *
     * A failure deletes the half-written file itself, so the caller never has to
     * decide whether a .gz that exists is a dump or a fragment.
     */
    private function stream(array $command, string $target): void
    {
        $gz = gzopen($target, 'wb9');

        if ($gz === false) {
            throw new BackupException(sprintf('Could not open %s to write the dump.', basename($target)));
        }

        $stderr = '';
        $bytes = 0;
        $complete = false;

        try {
            $process = new Process($command);
            $process->setTimeout($this->timeout);

            $process->run(function (string $type, string $buffer) use ($gz, &$stderr, &$bytes): void {
                if ($type === Process::ERR) {
                    $stderr .= $buffer;

                    return;
                }

                $written = gzwrite($gz, $buffer);

                if ($written === false) {
                    throw new BackupException('The dump could not be written to disk. The account may be out of space.');
                }

                $bytes += $written;
            });

            if (! $process->isSuccessful()) {
                throw new BackupException(sprintf(
                    'mysqldump exited with code %s. %s',
                    $process->getExitCode() ?? '?',
                    $this->explain(trim($stderr)),
                ));
            }

            if ($bytes === 0) {
                throw new BackupException('mysqldump produced an empty dump.');
            }

            $complete = true;
        } catch (ProcessFailedException|ProcessRuntimeException $exception) {
            throw new BackupException(sprintf(
                'mysqldump could not be started (%s). Set BACKUP_MYSQLDUMP in .env to its absolute path.',
                $exception->getMessage(),
            ), 0, $exception);
        } finally {
            gzclose($gz);

            // Only a complete dump is ever left behind. A fragment is worse than
            // nothing, because it looks like a backup.
            if (! $complete) {
                @unlink($target);
            }
        }
    }

    /**
     * mysqldump's own words, trimmed to something a settings screen can show.
     *
     * Warnings about the password on the command line cannot occur here (there is
     * none), but other hosts add their own noise, so the first real line wins.
     */
    private function explain(string $stderr): string
    {
        if ($stderr === '') {
            return 'It gave no reason. Check storage/logs for the full output.';
        }

        $lines = array_values(array_filter(array_map('trim', explode("\n", $stderr))));

        return mb_substr(implode(' ', array_slice($lines, 0, 3)), 0, 300);
    }

    /**
     * A 0600 option file carrying the credentials, for this one run.
     *
     * Written to the system temp directory rather than into the backups folder, so
     * it can never be swept into an archive even if something later goes wrong
     * between creating it and deleting it.
     */
    private function writeOptionFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'scbk');

        if ($path === false) {
            throw new BackupException('Could not create a temporary file for the database credentials.');
        }

        // Before the credentials are in it, not after.
        @chmod($path, 0600);

        $lines = ['[client]'];

        foreach ([
            'user' => $this->connection['username'] ?? null,
            'password' => $this->connection['password'] ?? null,
            'host' => $this->connection['host'] ?? null,
            'port' => $this->connection['port'] ?? null,
            'socket' => $this->connection['unix_socket'] ?? null,
        ] as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $lines[] = $key . '="' . $this->escape((string) $value) . '"';
        }

        try {
            if (file_put_contents($path, implode("\n", $lines) . "\n") === false) {
                throw new BackupException('Could not write the temporary database credentials file.');
            }
        } catch (Throwable $exception) {
            @unlink($path);

            throw $exception instanceof BackupException
                ? $exception
                : new BackupException('Could not write the temporary database credentials file.');
        }

        return $path;
    }

    /** MySQL option files take backslash escapes inside double quotes. */
    private function escape(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }
}
