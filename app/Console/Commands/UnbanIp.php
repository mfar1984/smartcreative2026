<?php

namespace App\Console\Commands;

use App\Models\BannedIp;
use App\Services\AdminLogger;
use App\Services\Security\LoginBanService;
use App\Support\LocalTime;
use Illuminate\Console\Command;

/**
 * Lift admin sign-in bans from SSH.
 *
 * The way back in when the admin itself cannot be reached: it needs no browser, no
 * session and no sign in, only a shell on the server (cPanel Terminal or SSH).
 *
 *   php artisan security:unban 203.0.113.10   one address, and its failure count
 *   php artisan security:unban --all          every ban
 *
 * It only deletes rows from banned_ips and clears failure counters. Accounts,
 * passwords and settings are left alone.
 */
class UnbanIp extends Command
{
    protected $signature = 'security:unban
                            {ip? : The IP address to unban}
                            {--all : Lift every ban instead}';

    protected $description = 'Lift admin sign-in bans by IP address, or all of them, without using the web admin';

    public function handle(LoginBanService $bans): int
    {
        $ip = $this->argument('ip');
        $all = (bool) $this->option('all');

        if ($ip !== null && $all) {
            $this->components->error('Pass one IP address or --all, not both.');

            return self::FAILURE;
        }

        if ($all) {
            return $this->liftAll($bans);
        }

        if ($ip === null) {
            $this->components->error('Pass the IP address to unban, or --all to lift every ban.');
            $this->listActive($bans);

            return self::FAILURE;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $this->components->error(sprintf('"%s" is not a valid IP address.', $ip));

            return self::FAILURE;
        }

        return $this->liftOne($bans, $ip);
    }

    private function liftOne(LoginBanService $bans, string $ip): int
    {
        $ban = $bans->lift($ip);

        if ($ban !== null) {
            $this->components->info(sprintf('Lifted the ban on %s (%s).', $ip, $this->describe($ban)));
        } else {
            $this->components->info(sprintf('%s had no active ban.', $ip));
        }

        $this->components->info(sprintf('Cleared the failed sign-in count for %s.', $ip));

        AdminLogger::activity(
            'settings.security.unban',
            $ban !== null
                ? sprintf('Removed the sign-in ban on %s from the command line.', $ip)
                : sprintf('Cleared the failed sign-in count for %s from the command line.', $ip),
            null,
            'Console',
        );

        return self::SUCCESS;
    }

    private function liftAll(LoginBanService $bans): int
    {
        $lifted = $bans->liftAll();

        if ($lifted->isEmpty()) {
            $this->components->info('No IP address was banned. Nothing to lift.');
        } else {
            foreach ($lifted as $ban) {
                $this->components->info(sprintf('Lifted the ban on %s (%s).', $ban->ip_address, $this->describe($ban)));
            }

            $this->components->info(sprintf(
                'Lifted %d %s and cleared %s failed sign-in %s.',
                $lifted->count(),
                $lifted->count() === 1 ? 'ban' : 'bans',
                $lifted->count() === 1 ? 'its' : 'their',
                $lifted->count() === 1 ? 'count' : 'counts',
            ));
        }

        AdminLogger::activity(
            'settings.security.unban_all',
            $lifted->isEmpty()
                ? 'Cleared the Banned IPs list from the command line; no ban was in force.'
                : sprintf(
                    'Cleared every sign-in ban (%d) from the command line: %s.',
                    $lifted->count(),
                    $lifted->pluck('ip_address')->implode(', '),
                ),
            null,
            'Console',
        );

        return self::SUCCESS;
    }

    /** What is banned right now, so a bare `security:unban` shows what there is to lift. */
    private function listActive(LoginBanService $bans): void
    {
        $active = $bans->active();

        if ($active->isEmpty()) {
            $this->components->info('No IP address is banned right now.');

            return;
        }

        $this->table(
            ['IP address', 'Failed attempts', 'Banned until'],
            $active->map(fn (BannedIp $ban) => [
                $ban->ip_address,
                $ban->failed_attempts,
                LocalTime::format($ban->expires_at),
            ])->all(),
        );
    }

    private function describe(BannedIp $ban): string
    {
        return sprintf(
            '%d failed %s, was blocked until %s',
            $ban->failed_attempts,
            $ban->failed_attempts === 1 ? 'attempt' : 'attempts',
            LocalTime::format($ban->expires_at),
        );
    }
}
