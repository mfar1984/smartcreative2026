<?php

namespace App\Http\Requests\Admin;

use App\Models\BannedIp;
use App\Models\User;
use App\Services\AdminLogger;
use App\Services\Security\LoginBanService;
use App\Support\IpAllowlist;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Failed attempts allowed before the username is locked out.
     */
    private const MAX_ATTEMPTS = 5;

    /**
     * Lockout window in seconds.
     */
    private const DECAY_SECONDS = 60;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            // Deliberately not validated as an email: the username format is
            // free text, for example "administrator@root".
            'username' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to log the user in.
     *
     * Throttling is keyed on username and IP together so guessing one account
     * from many addresses, or many accounts from one address, both get limited.
     *
     * On top of that, the IP itself can be banned after repeated failures, and an
     * IP allowlist can bar everyone but a super admin. Both are decided inside the
     * attempt, after the password is checked and before anybody is logged in, so a
     * refusal never has to sign somebody out again.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        // The per-account limiter runs first and is never skipped, on the banned
        // path included, so no exception below can be used for unlimited guessing.
        $this->ensureIsNotRateLimited();

        $credentials = [
            'username' => $this->string('username')->toString(),
            'password' => $this->string('password')->toString(),
        ];

        $bans = app(LoginBanService::class);
        $ip = (string) $this->ip();
        $ban = $bans->activeBan($ip);

        // Set by the gate below, which attemptWhen only calls once the password has
        // been verified. Still null afterwards means the credentials were wrong.
        $verified = null;

        $signedIn = Auth::attemptWhen(
            $credentials,
            function (User $user) use (&$verified, $ban, $ip): bool {
                $verified = $user;

                return $this->mayEnter($user, $ban, $ip);
            },
            $this->boolean('remember'),
        );

        if ($signedIn) {
            RateLimiter::clear($this->throttleKey());
            $bans->clearFailures($ip);

            if ($ban !== null) {
                // B. A super admin got through a ban. Lift it, so the rest of the
                // office on that network can sign in again, and say so loudly.
                $bans->lift($ip);

                AdminLogger::activity(
                    'auth.ban_bypass',
                    sprintf('Super admin signed in from blocked address %s; the block was lifted.', $ip),
                    null,
                    null,
                    AdminLogger::LEVEL_WARN,
                );
            }

            return;
        }

        RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);

        if ($ban !== null) {
            // The same notice for a wrong password and for a right one that is not
            // a super admin's, so it reveals nothing. Nothing more is counted
            // against an address that is already barred.
            throw ValidationException::withMessages([
                'username' => $bans->blockedMessage($ban),
            ]);
        }

        if ($verified !== null) {
            // The password was right; the allowlist refused it.
            AdminLogger::activity(
                'auth.denied',
                sprintf('Sign in refused: %s is not on the admin IP allowlist.', $ip),
                $verified->id,
                $verified->logLabel(),
                AdminLogger::LEVEL_WARN,
            );

            throw ValidationException::withMessages([
                'username' => sprintf(
                    'Sign in from your current network (IP %s) is not allowed for this account. Ask a super admin to add it to the allowlist.',
                    $ip,
                ),
            ]);
        }

        $bans->recordFailure($ip);

        // One generic message for both a wrong username and a wrong
        // password, so the form cannot be used to discover valid accounts.
        throw ValidationException::withMessages([
            'username' => __('auth.failed'),
        ]);
    }

    /**
     * Whether a user whose password checked out may be signed in from this address.
     */
    private function mayEnter(User $user, ?BannedIp $ban, string $ip): bool
    {
        $isSuperAdmin = $user->role !== null && $user->role->isSuperAdmin();

        // B. A ban bars everyone except a super admin who can actually reach the
        // admin. An inactive super admin gets the same notice as anybody else, so the
        // ban is only lifted by a sign in that is going to succeed.
        if ($ban !== null) {
            return $isSuperAdmin && $user->canAccessAdmin();
        }

        // C. The allowlist never applies to a super admin.
        return $isSuperAdmin || IpAllowlist::permits($ip);
    }

    /**
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        Event::dispatch(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'username' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    public function throttleKey(): string
    {
        return Str::transliterate(
            Str::lower($this->string('username')->toString()) . '|' . $this->ip()
        );
    }
}
