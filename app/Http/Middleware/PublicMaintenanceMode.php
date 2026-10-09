<?php

namespace App\Http\Middleware;

use App\Support\MaintenanceSettings;
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
     *
     * Whether maintenance is on is DECIDED HERE, on every request, by reading the
     * switch and the saved window. No job turns it on and no job turns it off, so
     * a window ends because the time passed rather than because something ran —
     * see App\Support\MaintenanceSettings for why that matters.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldBypass($request)) {
            return $next($request);
        }

        if (! MaintenanceSettings::isHoldingPublicSite()) {
            return $next($request);
        }

        // Staff doing the work keep seeing the live site. Checked after the admin
        // bypass above, which is independent of this list: a mistake here can only
        // decide who sees the holding page, never who reaches the admin.
        if (MaintenanceSettings::exemptsIp((string) $request->ip())) {
            return $next($request);
        }

        return response()->view(
            MaintenanceSettings::HOLDING_PAGE_VIEW,
            MaintenanceSettings::holdingPageData(),
            Response::HTTP_SERVICE_UNAVAILABLE,
        );
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
