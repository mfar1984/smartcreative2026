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
     *
     * All listed permissions must be held.
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if ($user === null) {
            throw new AccessDeniedHttpException('Not authenticated.');
        }

        foreach ($permissions as $permission) {
            if (! $user->hasPermission($permission)) {
                throw new AccessDeniedHttpException(
                    sprintf('Missing the "%s" permission.', $permission)
                );
            }
        }

        return $next($request);
    }
}
