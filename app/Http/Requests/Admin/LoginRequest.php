<?php

namespace App\Http\Requests\Admin;

use App\Models\BannedIp;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\AdminLogger;
use App\Services\Security\LoginBanService;
use App\Services\Security\SecurityEventRecorder;
use App\Support\IpAllowlist;
use App\Support\Security\LoginRefusal;
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

        // Why the gate refused a correct password, so the message below can fit the
        // reason. Null while the password is wrong AND after a successful sign in.
        $refusal = null;

        $signedIn = Auth::attemptWhen(
            $credentials,
            function (User $user) use (&$verified, &$refusal, $ban, $ip): bool {
                $verified = $user;
                $refusal = $this->mayEnter($user, $ban, $ip);

                return $refusal === null;
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
            /*
             | One of the three refusals that cannot reach the Security Log through
             | the exception hook in bootstrap/app.php, because it answers with a
             | validation error rather than an HTTP status. Recorded here by hand.
             |
             | An ORDINARY wrong password is deliberately NOT a security event: that
             | is somebody mistyping, it already goes to the activity log, and the
             | sign-in ban already counts it. Somebody still knocking after the
             | address has been barred is a different thing.
             */
            SecurityEventRecorder::record(
                SecurityEvent::TYPE_LOGIN_BANNED,
                SecurityEvent::SEVERITY_CRITICAL,
                sprintf('Sign in attempted from %s while that address is blocked.', $ip),
                $this,
            );

            // The same notice for a wrong password and for a right one that is not
            // a super admin's, so it reveals nothing. Nothing more is counted
            // against an address that is already barred.
            throw ValidationException::withMessages([
                'username' => $bans->blockedMessage($ban),
            ]);
        }

        if ($refusal === LoginRefusal::NOT_ALLOWLISTED) {
            // The password was right; the allowlist refused it.
            AdminLogger::activity(
                'auth.denied',
                sprintf('Sign in refused: %s is not on the admin IP allowlist.', $ip),
                $verified->id,
                $verified->logLabel(),
                AdminLogger::LEVEL_WARN,
            );

            // Second of the three hand-recorded refusals. A correct password from an
            // address that is not on the list is the one worth reading twice.
            SecurityEventRecorder::record(
                SecurityEvent::TYPE_LOGIN_NOT_ALLOWLISTED,
                SecurityEvent::SEVERITY_CRITICAL,
                sprintf('Sign in refused for %s: %s is not on the admin IP allowlist.', $verified->logLabel(), $ip),
                $this,
            );

            throw ValidationException::withMessages([
                'username' => sprintf(
                    'Sign in from your current network (IP %s) is not allowed for this account. Ask a super admin to add it to the allowlist.',
                    $ip,
                ),
            ]);
        }

        if ($refusal === LoginRefusal::CANNOT_ACCESS) {
            /*
             | The password was right, but the account cannot reach the admin: an
             | inactive account, an inactive role, or a role that was built without
             | the "Access the admin area" permission. This is the trap that wasted
             | the owner half an hour — the old generic "wrong credentials" sent him
             | hunting a password that was never wrong. So we name it plainly instead.
             |
             | It is NOT a Security Log entry. The Security Log is for refusals about
             | where a request came from — a blocked or un-allowlisted address — not
             | for a correctly authenticated person hitting a permissions wall. This
             | is a misconfiguration, not an attack, so it goes to the ordinary
             | activity log where an admin can see it happened and fix the role.
            */
            AdminLogger::activity(
                'auth.denied',
                sprintf('Sign in refused for %s: the account cannot access the admin area (inactive account, inactive role, or a role without admin access).', $verified->logLabel()),
                $verified->id,
                $verified->logLabel(),
                AdminLogger::LEVEL_WARN,
            );

            throw ValidationException::withMessages([
                'username' => $this->cannotAccessMessage($verified),
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
     *
     * The single place that decides entry. Returns null when the user is let in, or
     * the reason they are not, so authenticate() can word the message without having
     * to re-derive which wall was hit. The entry decision is unchanged — the same
     * people get in and the same people are turned away — only the reason is now
     * legible rather than collapsed into one false.
     */
    private function mayEnter(User $user, ?BannedIp $ban, string $ip): ?LoginRefusal
    {
        $isSuperAdmin = $user->role !== null && $user->role->isSuperAdmin();

        // B. A ban bars everyone except a super admin who can actually reach the
        // admin. An inactive super admin gets the same notice as anybody else, so the
        // ban is only lifted by a sign in that is going to succeed.
        if ($ban !== null) {
            return $isSuperAdmin && $user->canAccessAdmin() ? null : LoginRefusal::BANNED;
        }

        // C. The allowlist never applies to a super admin, and checked first so an
        // address that is not on the list reads as a network refusal exactly as
        // before, even when the account also lacks admin access.
        if (! $isSuperAdmin && ! IpAllowlist::permits($ip)) {
            return LoginRefusal::NOT_ALLOWLISTED;
        }

        // The permissions wall. A super admin always passes; anybody else needs an
        // active account, an active role and the admin.access permission. Without it
        // the account was previously signed in and then bounced by the admin
        // middleware with a misleading "your session has ended"; now it never signs
        // in and is told plainly why.
        if (! $user->canAccessAdmin()) {
            return LoginRefusal::CANNOT_ACCESS;
        }

        return null;
    }

    /**
     * The message for a correct password whose account cannot reach the admin.
     *
     * Three shapes, cheap to tell apart from the user already in hand, because an
     * operator who switched an account off and one who built a role without admin
     * access are looking for different things. All three end the same way: contact
     * an administrator, because none of them is something the person can fix.
     */
    private function cannotAccessMessage(User $user): string
    {
        if (! $user->is_active) {
            return 'This account has been deactivated and cannot sign in. Please contact an administrator.';
        }

        if ($user->role === null || ! $user->role->is_active) {
            return 'This account\'s role is inactive, so it cannot sign in. Please contact an administrator.';
        }

        // The exact trap: a role built without "Access the admin area".
        return 'This account\'s role does not have access to the admin area, so it cannot sign in. Please contact an administrator.';
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
