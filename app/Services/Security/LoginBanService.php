<?php

namespace App\Services\Security;

use App\Models\BannedIp;
use App\Services\AdminLogger;
use App\Support\IpAllowlist;
use App\Support\LocalTime;
use App\Support\SecuritySettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Banning an IP address after repeated failed admin sign ins. All of it lives here.
 *
 * Failures are counted per IP with the RateLimiter, inside the configured window,
 * because a count never needs to be listed. Reaching the threshold writes a
 * banned_ips row, which can be listed, removed and cleared from the Security tab
 * and from `php artisan security:unban`.
 *
 * ADMIN SIGN IN ONLY. Nothing here is called from the public website, public
 * registration and checkout, the payment return pages or the CHIP webhook, and it
 * must stay that way. On event day hundreds of participants on the stadium Wi-Fi
 * share one public IP, and the payment gateway calls back from a handful of fixed
 * addresses: a ban reaching the public side would block a whole venue from
 * registering, or stop payments being recorded. The ban guards the admin door only.
 *
 * Every check answers "not banned" when bans are switched off, and for an address
 * on the IP allowlist, which is trusted: its failures are never counted.
 *
 * banForSecurityEvents() adds a second REASON an address can end up in this table —
 * too many refused requests, counted by the Security Log — but not a second ban. It
 * writes the same row, which bars the door on the same terms through activeBan(),
 * appears in the same Banned IPs list and is lifted by the same two buttons. Note
 * what that means: ban_enabled governs whether ANY row here bars sign in, because
 * that switch means "do not bar the door"; security_ban_enabled governs only whether
 * the Security Log may create a row.
 */
final class LoginBanService
{
    /** RateLimiter key prefix for one address's failure count. */
    private const FAILURES = 'login-failures:';

    /**
     * The ban currently barring this address, or null.
     *
     * Null when bans are off or the address is allowlisted, even if a row exists, so
     * switching bans off or listing an address takes effect at once.
     */
    public function activeBan(string $ip): ?BannedIp
    {
        if (! $this->applies($ip)) {
            return null;
        }

        return BannedIp::query()
            ->active()
            ->where('ip_address', $ip)
            ->orderByDesc('expires_at')
            ->first();
    }

    public function isBanned(string $ip): bool
    {
        return $this->activeBan($ip) !== null;
    }

    /**
     * Count one failed sign in from this address, and ban it on reaching the limit.
     *
     * Returns the ban when this failure created one.
     */
    public function recordFailure(string $ip): ?BannedIp
    {
        if (! $this->applies($ip)) {
            return null;
        }

        $failures = RateLimiter::hit(self::FAILURES . $ip, SecuritySettings::banWindowMinutes() * 60);

        if ($failures < SecuritySettings::banAfterFailures()) {
            return null;
        }

        return $this->ban($ip, $failures);
    }

    /** Forget this address's failure count, after a successful sign in or an unban. */
    public function clearFailures(string $ip): void
    {
        RateLimiter::clear(self::FAILURES . $ip);
    }

    /** How many failures are currently counted against this address. */
    public function failures(string $ip): int
    {
        return RateLimiter::attempts(self::FAILURES . $ip);
    }

    /**
     * Lift this address's ban and clear its failure count.
     *
     * Every row for the address goes, expired ones included, so nothing about it is
     * left behind. Returns the ban that was in force, or null when there was none.
     */
    public function lift(string $ip): ?BannedIp
    {
        $active = BannedIp::query()
            ->active()
            ->where('ip_address', $ip)
            ->orderByDesc('expires_at')
            ->first();

        BannedIp::query()->where('ip_address', $ip)->delete();

        $this->clearFailures($ip);

        return $active;
    }

    /**
     * Lift every ban, and clear the failure count of every address that has a row.
     *
     * Returns the bans that were in force, so the caller can say exactly what it
     * cleared. Expired rows are removed too, as plain housekeeping.
     *
     * @return Collection<int, BannedIp>
     */
    public function liftAll(): Collection
    {
        $active = $this->active();
        $addresses = BannedIp::query()->distinct()->pluck('ip_address');

        BannedIp::query()->delete();

        foreach ($addresses as $ip) {
            $this->clearFailures((string) $ip);
        }

        return $active;
    }

    /**
     * Every ban in force, newest first, for the Banned IPs list.
     *
     * @return Collection<int, BannedIp>
     */
    public function active(): Collection
    {
        return BannedIp::query()
            ->active()
            ->orderByDesc('banned_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The notice a barred address sees on the sign-in screen.
     *
     * One sentence for every refused attempt, right password or wrong, so the
     * notice cannot be used to test whether a guess was correct.
     */
    public function blockedMessage(BannedIp $ban): string
    {
        return sprintf(
            'Sign in from this network is temporarily blocked after too many failed attempts. Try again after %s.',
            LocalTime::format($ban->expires_at),
        );
    }

    /**
     * Ban an address for repeated REFUSED REQUESTS, not for failed sign ins.
     *
     * The same ban, in the same banned_ips table, shown in the same Banned IPs list
     * on the Security tab, lifted by the same Remove and Clear all buttons and by
     * `php artisan security:unban`. A second ban mechanism with its own list and its
     * own unban would be a second place to look on the day somebody is locked out,
     * so there is only this one.
     *
     * Called only by SecurityEventRecorder::considerBan, which has already decided
     * the threshold was reached and has already exempted a super admin's session.
     * What is checked HERE is what must be true wherever the call comes from:
     *
     *   - security_ban_enabled, which SHIPS OFF. Counting runs regardless; barring
     *     an address does not happen until the owner arms it.
     *   - the IP allowlist, which is trusted by definition, exactly as the
     *     sign-in ban trusts it.
     *   - no ban already in force, so a flood of refusals renews nothing and writes
     *     one row rather than one per request.
     *
     * The duration is ban_duration_minutes, shared with the sign-in ban: one ban,
     * one length, one thing to explain. Returns the ban it created, or null.
     */
    public function banForSecurityEvents(string $ip, int $refusals): ?BannedIp
    {
        if ($ip === '' || ! SecuritySettings::securityBanEnabled() || IpAllowlist::contains($ip)) {
            return null;
        }

        if (BannedIp::query()->active()->where('ip_address', $ip)->exists()) {
            return null;
        }

        $minutes = SecuritySettings::banDurationMinutes();
        $now = now();

        // One row per address, like the sign-in ban: any earlier row has expired, so
        // it is replaced rather than left to pile up.
        BannedIp::query()->where('ip_address', $ip)->delete();

        return BannedIp::create([
            'ip_address' => $ip,
            'failed_attempts' => $refusals,
            'reason' => sprintf(
                '%d refused requests within %d minutes (Security Log)',
                $refusals,
                SecuritySettings::securityBanWindowMinutes(),
            ),
            'banned_at' => $now,
            'expires_at' => $now->copy()->addMinutes($minutes),
        ]);
    }

    /** Whether bans can touch this address at all. */
    private function applies(string $ip): bool
    {
        return $ip !== ''
            && SecuritySettings::banEnabled()
            // A. Allowlisted addresses are trusted: never counted, never banned.
            && ! IpAllowlist::contains($ip);
    }

    private function ban(string $ip, int $failures): BannedIp
    {
        $minutes = SecuritySettings::banDurationMinutes();
        $now = now();

        // One row per address. Any earlier row is expired by now (a barred address
        // is never counted), so it is replaced rather than left to pile up.
        BannedIp::query()->where('ip_address', $ip)->delete();

        $ban = BannedIp::create([
            'ip_address' => $ip,
            'failed_attempts' => $failures,
            'reason' => sprintf(
                '%d failed sign-in attempts within %d minutes',
                $failures,
                SecuritySettings::banWindowMinutes(),
            ),
            'banned_at' => $now,
            // E. Every ban ends on its own.
            'expires_at' => $now->copy()->addMinutes($minutes),
        ]);

        // The count is spent on this ban, so once it lifts the address starts again
        // from zero rather than being barred on its very next mistake.
        $this->clearFailures($ip);

        AdminLogger::activity(
            'auth.banned',
            sprintf('Sign in from %s blocked for %d minutes after %d failed attempts.', $ip, $minutes, $failures),
            null,
            null,
            AdminLogger::LEVEL_WARN,
        );

        return $ban;
    }
}
