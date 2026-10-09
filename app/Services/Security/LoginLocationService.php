<?php

namespace App\Services\Security;

use App\Mail\NewSignInLocation;
use App\Models\User;
use App\Models\UserKnownIp;
use App\Services\AdminLogger;
use App\Support\LocalTime;
use App\Support\MailSettings;
use App\Support\SecuritySettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Remembering which addresses an account signs in from, and warning it about a
 * new one. All of it lives here.
 *
 * "Somewhere new" means an IP ADDRESS with no user_known_ips row for this
 * account. There is no geolocation: a city name needs an external service or a
 * bundled database, which is a dependency this owner's shared cPanel hosting
 * does not need to carry for what it adds. If city names are ever wanted they
 * can hang off these same rows.
 *
 * Two things it deliberately does NOT do:
 *
 *   It does not read the activity log to decide what it has seen. The Security
 *   tab can switch activity logging off, and an alarm that stops working when
 *   logging is disabled is worse than no alarm, because turning logging off is
 *   the first thing anybody with the admin would do. The dedicated table is
 *   written on every successful sign in, whatever either switch says.
 *
 *   It does not let a mail failure reach the sign in. The message is queued, and
 *   the dispatch is wrapped as well, so an unreachable mail server or queue is
 *   logged and the person still gets in.
 */
class LoginLocationService
{
    /** Activity action for a sign in from an address not seen before. */
    public const ACTION = 'auth.new_location';

    /**
     * Record this sign in's address, and warn the account if it is a new one.
     *
     * Returns true when a warning was raised (recorded, and emailed if there is
     * an address to email), false when the sign in needed no warning.
     */
    public function record(User $user, string $ip, ?string $userAgent = null): bool
    {
        $ip = trim($ip);

        if ($ip === '') {
            return false;
        }

        $agent = $userAgent === null ? null : substr($userAgent, 0, 512);

        $known = UserKnownIp::query()
            ->where('user_id', $user->id)
            ->where('ip_address', $ip)
            ->first();

        if ($known !== null) {
            $known->forceFill([
                'hits' => $known->hits + 1,
                'last_seen_at' => now(),
                'user_agent' => $agent ?? $known->user_agent,
            ])->save();

            return false;
        }

        /*
         | An account with no addresses on record has never signed in through this
         | feature, so there is nothing for this address to be new relative to.
         | Warning here would mean every account's first sign in after deploy is a
         | false alarm — the migration seeds users.last_login_ip precisely so that
         | an established account is already known, but an account that has truly
         | never signed in has nothing to seed. Record it silently.
         */
        $isFirstEver = ! UserKnownIp::query()->where('user_id', $user->id)->exists();

        UserKnownIp::create([
            'user_id' => $user->id,
            'ip_address' => $ip,
            'hits' => 1,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'user_agent' => $agent,
        ]);

        if ($isFirstEver) {
            return false;
        }

        // The switch governs the warning, not the record above: an operator who
        // turns it off still has a complete picture of which addresses are known,
        // so turning it back on does not then warn about every one of them.
        if (! SecuritySettings::newLocationWarningEnabled()) {
            return false;
        }

        // Warn level, and the action begins with "auth.", which is on
        // AdminLogger::ALWAYS_RECORDED — a security warning is written even when
        // activity logging has been switched off.
        AdminLogger::activity(
            self::ACTION,
            sprintf('Signed in from %s, an address not used by this account before.', $ip),
            $user->id,
            $user->logLabel(),
            AdminLogger::LEVEL_WARN,
        );

        $this->warn($user, $ip, $agent);

        return true;
    }

    /**
     * Email the account. Nothing here may throw into the sign in.
     */
    private function warn(User $user, string $ip, ?string $agent): void
    {
        if (blank($user->email)) {
            return;
        }

        try {
            // The saved SMTP profile lives in the database rather than in config,
            // so it has to be applied before anything is queued.
            MailSettings::apply();

            Mail::to($user->email, $user->name)->queue(new NewSignInLocation(
                user: $user,

                ipAddress: $ip,

                // Formatted here, on the office clock, rather than in the view: the
                // queue worker renders the view and has no request to take a
                // timezone from.
                signedInAt: LocalTime::format(now()),

                userAgent: $agent,
            ));
        } catch (Throwable $exception) {
            Log::error('New sign-in location warning could not be queued.', [
                'user' => $user->logLabel(),
                'ip' => $ip,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
