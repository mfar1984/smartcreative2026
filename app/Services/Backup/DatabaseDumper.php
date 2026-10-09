<?php

namespace App\Services\Backup;

/**
 * Writes the whole database to one gzipped SQL file.
 *
 * An interface with a single implementation, for one reason: the test database is
 * sqlite in memory and mysqldump cannot read it, so the tests bind a fake that
 * writes a known file instead. Production resolves MysqlDumper.
 */
interface DatabaseDumper
{
    /**
     * Dump to $target, which is an absolute path ending in .sql.gz.
     *
     * @return string the method used, recorded in the manifest ('mysqldump')
     *
     * @throws BackupException when the dump could not be completed
     */
    public function dump(string $target): string;
}
