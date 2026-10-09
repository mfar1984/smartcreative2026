<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnforceIpAllowlist;
use App\Http\Requests\Admin\LoginRequest;
use App\Services\AdminLogger;
use App\Services\Security\LoginBanService;
use App\Support\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    /**
     * The sign-in form. NEVER blocked, not even for a banned address.
     *
     * A super admin can sign in through a ban (see LoginRequest), and this form is
     * the only way to do it, so a banned address still gets the form, with a notice
     * saying sign in from its network is blocked and until when.
     */
    public function create(Request $request, LoginBanService $bans)
    {
        $ip = (string) $request->ip();
        $ban = $bans->activeBan($ip);

        return view('admin.auth.login', [
            'banNotice' => $ban !== null ? $bans->blockedMessage($ban) : null,

            // Carried in the URL rather than flashed: the sign-out that sends people
            // here deletes the session row after the response, flash included.
            'networkNotice' => $request->query('notice') === EnforceIpAllowlist::NOTICE
                ? sprintf('You were signed out because your current network (IP %s) is not on the admin IP allowlist.', $ip)
                : null,
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function store(LoginRequest $request)
    {
        $request->authenticate();

        $user = $request->user();

        // Authentication succeeded, but this account may still not be allowed
        // into the admin area. Reject it and end the session immediately.
        if (! $user->canAccessAdmin()) {
            AdminLogger::activity(
                'auth.denied',
                'Sign in refused: account is inactive or has no admin access.',
                $user->id,
                $user->logLabel(),
            );

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'username' => 'This account does not have access to the admin area.',
            ]);
        }

        // Guards against session fixation: the pre-login session id is discarded.
        $request->session()->regenerate();

        // When one-session-per-user is on, drop this user's other session rows so
        // only the session just created survives. Runs after regenerate() so the
        // new id is in place and is the one kept. Off by default, so first deploy
        // is unchanged.
        SessionGuard::enforceSingleSession($request, $user->id);

        // Stamp the activity marker the inactivity middleware reads, so the first
        // authenticated request after login is measured from now, not from a stale
        // value left by an earlier session.
        $request->session()->put('last_activity_at', now()->timestamp);

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        AdminLogger::activity('auth.login', 'Signed in to the admin area.');

        // A handler holds only the Tournament permissions, so the dashboard would
        // open near-empty for them. Land them on the tournaments list instead, the
        // one screen their navigation leads with. Every other role keeps landing on
        // the dashboard exactly as before. Branched here because this is the single
        // place the post-login destination is chosen.
        $landing = $user->isHandler()
            ? route('admin.tournaments.index')
            : route('admin.dashboard');

        return redirect()->intended($landing);
    }

    public function destroy(Request $request)
    {
        AdminLogger::activity('auth.logout', 'Signed out of the admin area.');

        // Logs out AND deletes the underlying sessions row, so logging out no
        // longer leaves a stale user_id NULL row behind — the owner's complaint.
        SessionGuard::logoutAndDestroy($request);

        return redirect()->route('admin.login');
    }
}
