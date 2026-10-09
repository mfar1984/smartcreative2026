<?php

namespace App\Http\Middleware;

use App\Services\AdminLogger;
use App\Support\SecuritySettings;
use App\Support\SessionGuard;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs an inactive admin out after the configured number of idle minutes.
 *
 * Runs on the authenticated admin group, after the auth middleware, so a user is
 * always present when it reads the session. It keeps a last_activity_at timestamp
 * in the session and, on each request, compares the gap to the admin-configured
 * inactivity window. When the gap is exceeded the user is logged out AND the
 * sessions row is destroyed, matching the manual-logout fix; otherwise the marker
 * is refreshed and the request proceeds.
 *
 * The window is driven by SecuritySettings, not by config/session.php, so it can
 * be changed without a deploy and does not touch public visitors' sessions. Its
 * default equals the configured session lifetime, so first deploy logs nobody out
 * sooner than before.
 */
class EnforceInactivityTimeout
{
    public function handle(Request $request, Closure $next): Response
    {
        // Defensive: the group guarantees auth, but if this ever runs without a
        // user there is nothing to time out.
        if (! Auth::check()) {
            return $next($request);
        }

        $timeoutSeconds = SecuritySettings::sessionTimeoutMinutes() * 60;
        $lastActivity = $request->session()->get('last_activity_at');

        if ($lastActivity !== null && (now()->timestamp - (int) $lastActivity) > $timeoutSeconds) {
            AdminLogger::activity('auth.timeout', 'Signed out after a period of inactivity.');

            SessionGuard::logoutAndDestroy($request);

            // An HTML request is redirected to the sign-in screen with a notice; a
            // non-GET or expects-JSON request gets a 401 so a background request
            // does not silently swallow the logout.
            if ($request->expectsJson()) {
                abort(401, 'Session expired due to inactivity.');
            }

            return redirect()
                ->route('admin.login')
                ->with('status', 'You were signed out after a period of inactivity.');
        }

        // Still inside the window: stamp the current time and carry on.
        $request->session()->put('last_activity_at', now()->timestamp);

        return $next($request);
    }
}
