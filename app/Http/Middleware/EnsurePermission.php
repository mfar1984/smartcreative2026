<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class EnsurePermission
{
    /**
     * Require one or more permissions for a route.
     *
     * Usage: ->middleware('permission:users.view')
     *        ->middleware('permission:users.create,users.update')
     *        ->middleware('permission:users.view|handlers.view')
     *
     * Every comma separated permission must be held. A pipe inside one of them
     * means any one of that set is enough, which is what a screen whose tabs sit
     * behind different permissions needs: User Management opens for a role that
     * may read either list, and the screen itself draws only the tabs that role
     * holds.
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if ($user === null) {
            throw new AccessDeniedHttpException('Not authenticated.');
        }

        foreach ($permissions as $permission) {
            $alternatives = explode('|', $permission);

            foreach ($alternatives as $alternative) {
                if ($user->hasPermission($alternative)) {
                    continue 2;
                }
            }

            throw new AccessDeniedHttpException(
                sprintf('Missing the "%s" permission.', implode('" or "', $alternatives))
            );
        }

        return $next($request);
    }
}
