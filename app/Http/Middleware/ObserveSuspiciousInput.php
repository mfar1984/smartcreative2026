<?php

namespace App\Http\Middleware;

use App\Services\Security\SecurityEventRecorder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Notices a probing pattern in request data, and then serves the request anyway.
 *
 * THIS MIDDLEWARE NEVER REFUSES A REQUEST. There is one return statement and it is
 * $next($request); there is no branch that can answer anything else, and there must
 * never be one. It exists because a field arriving with ' OR 1=1-- is stored
 * harmlessly as text today and nobody ever learns somebody probed — so the probe is
 * written to the Security Log at the lowest severity and the request carries on.
 *
 * App\Support\SuspiciousInput says at length why a pattern gate would be the wrong
 * thing here: this system takes public registrations for a sports event, and a
 * participant named O'Brien being refused is worse than the thing it would prevent.
 *
 * The observation is wrapped twice — once in the recorder, once here — because a
 * fault in the thing watching requests must not be able to break one.
 */
class ObserveSuspiciousInput
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            SecurityEventRecorder::observe($request);
        } catch (Throwable) {
            // Already swallowed and logged inside the recorder. Caught again here so
            // that nothing, not even a container fault while resolving it, can stop
            // the request being served.
        }

        return $next($request);
    }
}
