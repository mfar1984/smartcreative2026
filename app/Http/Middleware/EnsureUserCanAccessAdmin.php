<?php

namespace App\Http\Middleware;

use App\Services\AdminLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserCanAccessAdmin
{
    /**
     * Authorisation gate for the admin area.
     *
     * Runs after the auth middleware, so a user is guaranteed to be present.
     * A session that becomes invalid mid-flight (account deactivated, role
     * removed) is signed out rather than left with a half-working session.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->canAccessAdmin()) {
            if ($user !== null) {
                AdminLogger::activity(
                    'auth.revoked',
                    'Session ended: admin access is no longer granted to this account.',
                );

                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return redirect()
                ->route('admin.login')
                ->withErrors(['username' => 'Your session has ended. Please sign in again.']);
        }

        return $next($request);
    }
}
