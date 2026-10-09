<?php

namespace App\Http\Middleware;

use App\Services\AdminLogger;
use App\Support\IpAllowlist;
use App\Support\SessionGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs a user out of the admin when their IP is not on the allowlist.
 *
 * The sign-in screen already refuses such a user; this covers a session that was
 * started before the list was saved, or that moved to another network. Runs on the
 * authenticated admin group only — never on the public site, registration,
 * checkout or the payment callbacks — and does nothing while the list is empty,
 * which is the default.
 *
 * C. A super admin is never held to the list, here or at sign in, so a wrong list
 * cannot lock the owner out.
 */
class EnforceIpAllowlist
{
    /** Query value telling the sign-in screen why the user is there. */
    public const NOTICE = 'network';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $ip = (string) $request->ip();

        if ($user === null || IpAllowlist::permits($ip)) {
            return $next($request);
        }

        if ($user->role !== null && $user->role->isSuperAdmin()) {
            return $next($request);
        }

        AdminLogger::activity(
            'auth.revoked',
            sprintf('Signed out: %s is not on the admin IP allowlist.', $ip),
            null,
            null,
            AdminLogger::LEVEL_WARN,
        );

        // Logs out AND deletes the sessions row, like every other sign-out.
        SessionGuard::logoutAndDestroy($request);

        if ($request->expectsJson()) {
            abort(403, 'Your current network is not on the admin IP allowlist.');
        }

        // The reason travels in the URL, not the session: logoutAndDestroy deletes
        // the new session row once the response is sent, so a flashed message
        // would be gone before the sign-in screen could show it.
        return redirect()->route('admin.login', ['notice' => self::NOTICE]);
    }
}
