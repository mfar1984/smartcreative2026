<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicMaintenanceMode
{
    /**
     * Show a holding page to website visitors when maintenance mode is on.
     *
     * Deliberately separate from Laravel's own `artisan down`: this only covers
     * the public site, so an administrator can never lock themselves out of
     * the admin area by flipping the switch.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldBypass($request)) {
            return $next($request);
        }

        if (Setting::read('maintenance.enabled', '0') !== '1') {
            return $next($request);
        }

        return response()->view('pages.site-maintenance', [
            'heading' => Setting::read('maintenance.heading', 'We are carrying out maintenance'),
            'message' => Setting::read('maintenance.message', 'The website is temporarily unavailable. Please check back shortly.'),
        ], Response::HTTP_SERVICE_UNAVAILABLE);
    }

    private function shouldBypass(Request $request): bool
    {
        // The admin area and the health endpoint must always answer.
        if ($request->is('admin', 'admin/*', 'up')) {
            return true;
        }

        // A signed in administrator can keep browsing the live site to check
        // their work while visitors see the holding page.
        return $request->user()?->canAccessAdmin() ?? false;
    }
}
