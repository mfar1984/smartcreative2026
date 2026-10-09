<?php

use App\Support\GeneralSettings;
use App\Support\LocalTime;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| The clock the nightly backup is timed on, resolved here because an Event's
| ->timezone() takes a string and is evaluated when the schedule is defined, not
| when the task is due.
|
| This file is read while every single artisan command boots, including `migrate`
| on a fresh install, so the settings table cannot be assumed to exist yet: an
| unguarded read here made `php artisan migrate` fail with "no such table:
| settings" before it had a chance to create it. rescue() falls through to the
| configured display timezone in that one case, and the next boot — by which time
| the table is there — reads General Config properly.
|
| Flushed straight afterwards so this boot-time read is not left memoised for the
| life of the process. A queue worker boots once and runs for hours, and it must
| keep reading the timezone the admin actually shows.
*/
$displayTimezone = rescue(
    fn () => LocalTime::zone(),
    (string) config('app.display_timezone', 'Asia/Kuala_Lumpur'),
    report: false,
);

GeneralSettings::flush();

/*
| The nightly backup: database and uploads, into one archive, pruned to the last
| seven automatic ones by the command itself.
|
| ->timezone() is not optional here. config/app.php hardcodes the application
| timezone to UTC, deliberately, so a bare dailyAt('03:00') would fire at 11:00 in
| Malaysia — the middle of the working day, with a long read running against a live
| database while people are registering. The hour is read on the same clock the
| whole admin displays, which is General Config, Timezone.
|
| ->withoutOverlapping() because a slow run must not have the next one started on
| top of it. ->runInBackground() is deliberately absent: cron already calls
| schedule:run once a minute and this is the only task, so there is nothing to hold
| up, and a background task's output is harder to find when it fails.
|
| This needs a scheduler cron line in cPanel, which the queue worker's line does
| not provide. See DEPLOY.md, Backups. Until that line exists this entry never
| runs, and `php artisan backup:run` by hand is the only backup taken.
*/
Schedule::command('backup:run --type=auto')
    ->dailyAt((string) config('backup.daily_at', '03:00'))
    ->timezone($displayTimezone)
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/backup.log'));
