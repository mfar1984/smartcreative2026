<?php

return [

    /*
    |--------------------------------------------------------------------------
    | mysqldump binary
    |--------------------------------------------------------------------------
    |
    | Left as a bare name so a development machine with it on PATH needs no
    | configuration. Production sets the absolute path, because cron does not run
    | with the same PATH as the owner's shell: on the current deployment
    | `command -v mysqldump` resolved to /bin/mysqldump, so .env carries
    | BACKUP_MYSQLDUMP=/bin/mysqldump and the scheduled run finds it.
    |
    */

    'mysqldump' => env('BACKUP_MYSQLDUMP', 'mysqldump'),

    /*
    |--------------------------------------------------------------------------
    | Where archives are kept
    |--------------------------------------------------------------------------
    |
    | A folder on the private disk, so it is never under public/ and cannot be
    | fetched over HTTP. Relative to storage/app/private.
    |
    */

    'directory' => 'backups',

    /*
    |--------------------------------------------------------------------------
    | Folders swept into the archive
    |--------------------------------------------------------------------------
    |
    | Label => absolute path. The label becomes the folder inside the archive, so
    | these land as files/public/... and files/private/..., which is what a
    | restore has to put back. The backups folder sits inside the private one and
    | is always excluded: backing up the backups is how one archive becomes two,
    | two become four, and a shared hosting quota is gone by the weekend.
    |
    | Configured rather than hardcoded so the tests can point them at a temporary
    | directory instead of sweeping up the real uploads folder.
    |
    */

    'include' => [
        'public' => storage_path('app/public'),
        'private' => storage_path('app/private'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Automatic backups kept — the fallback, not the source of truth
    |--------------------------------------------------------------------------
    |
    | Retention is set on the Backup & Restore tab now: a number kept, an age in
    | days and a total size in MB, any of them switched off with 0. This value is
    | what App\Support\BackupSettings::keepCount() falls back to while nothing has
    | been saved, so an untouched installation keeps the seven it always kept.
    |
    | Manual archives are never pruned by any of them: somebody took one
    | deliberately, usually right before doing something they were nervous about.
    |
    */

    'keep' => 7,

    /*
    |--------------------------------------------------------------------------
    | Time the nightly backup runs
    |--------------------------------------------------------------------------
    |
    | Read on the display clock, not UTC. config/app.php is hardcoded to UTC, so
    | a bare dailyAt('03:00') would fire at 11:00 in Malaysia, in the middle of
    | the working day.
    |
    */

    'daily_at' => '03:00',

    /*
    |--------------------------------------------------------------------------
    | How long the dump may take
    |--------------------------------------------------------------------------
    |
    | Seconds. Shared hosting is slow and the database is small, so this is a
    | ceiling on a hung process rather than a target.
    |
    */

    'timeout' => 600,

];
