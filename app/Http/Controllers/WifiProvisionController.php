<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Support\WifiCredentials;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The one address the venue's router is allowed to ask for.
 *
 * This exists so the router never has to accept a connection. The alternative was opening
 * RouterOS management to the internet so this application could reach in and create
 * accounts, which trades the security of the one box whose compromise takes the whole
 * network with it for the convenience of not writing this file. Here the router opens the
 * connection, on its own schedule, and its firewall stays shut.
 *
 * What comes back is a list of working logins in the clear. There is no way around that —
 * the router needs the passwords to create the accounts — so the protections are stacked
 * rather than relied on one at a time:
 *
 * The token is long, random, and belongs to one event, so a leak stops at that event.
 *
 * The credentials expire at the end of the event, which is the protection that still
 * holds after the token has leaked. That is the one worth trusting.
 *
 * Every request is logged with its source, so a fetch from somewhere unexpected leaves a
 * trace rather than passing silently.
 *
 * Deliberately not behind admin authentication. A router cannot log in, and giving it a
 * session would mean storing admin credentials on a network device sitting in a hall.
 */
class WifiProvisionController extends Controller
{
    /**
     * Hand the router the script for one event.
     *
     * Served as plain text rather than a download, because RouterOS fetches it to a file
     * and then imports it; a Content-Disposition header only confuses the browser somebody
     * inevitably tests it in.
     */
    public function script(Request $request, string $token): Response
    {
        /*
         | Looked up by token alone, with no event id in the path.
         |
         | A URL carrying both would tell whoever found it which event it belongs to and
         | invite them to try neighbouring ids. This way the secret is the only thing in
         | it, and a wrong one is indistinguishable from an address that never existed.
         */
        $event = Event::query()
            ->whereNotNull('wifi_token')
            ->where('wifi_token', $token)
            ->first();

        if ($event === null) {
            Log::warning('A Wi-Fi provisioning script was requested with an unknown token.', [
                'ip' => $request->ip(),
                'agent' => substr((string) $request->userAgent(), 0, 120),
            ]);

            // The same 404 an address that never existed would give, so a probe learns
            // nothing about whether it was close.
            throw new NotFoundHttpException();
        }

        $script = WifiCredentials::scriptFor($event);

        // Marked after the script is built, so a row is only claimed as live on the
        // router once there is something to hand over.
        $marked = WifiCredentials::markProvisioned($event);

        Log::info('A Wi-Fi provisioning script was fetched.', [
            'event' => $event->id,
            'accounts' => $marked,
            'ip' => $request->ip(),
        ]);

        return response($script, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',

            // Nothing between here and the router may keep a copy of a file full of
            // passwords, and a stale copy would also hide competitors who registered
            // since the last fetch.
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',

            // Not for a browser to index, follow, or archive.
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
        ]);
    }
}
