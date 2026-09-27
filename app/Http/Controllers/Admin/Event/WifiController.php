<?php

namespace App\Http\Controllers\Admin\Event;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\WifiCredential;
use App\Services\AdminLogger;
use App\Services\WifiCredentialNotifier;
use App\Support\WifiCredentials;
use Illuminate\Http\Request;

/**
 * The four things an organiser does with venue Wi-Fi.
 *
 * Issue the logins that were missed, rotate the secret in the fetch URL, tell people what
 * their login is, and print slips for the counter. Kept apart from the event's own
 * controller because none of these are editing an event: they act on credentials, and two
 * of them reach outside the building.
 *
 * Each is a POST. Fetching the script is the only GET in this feature, and that one is the
 * router's, not an operator's.
 */
class WifiController extends Controller
{
    /**
     * Issue logins for registrations that never got one.
     *
     * Needed because the flag is nearly always turned on after people have registered. The
     * hook that issues on registration cannot reach backwards, so without this the feature
     * would look broken on the first event anybody used it for.
     *
     * Safe to press repeatedly: it reports what it created, which is nothing the second
     * time.
     */
    public function issue(Request $request, Event $event)
    {
        if (! $event->offersWifi()) {
            return back()->with('error', 'Turn on "Create WiFi portal user and password" for this event first.');
        }

        $issued = WifiCredentials::backfill($event);

        AdminLogger::activity(
            'event.wifi.issue',
            sprintf('Issued %d Wi-Fi login(s) for %s.', $issued, $event->title),
        );

        return back()->with('status', $issued === 0
            ? 'Everybody registered for this event already has a login. Nothing to issue.'
            : sprintf(
                '%d Wi-Fi login%s issued. They will not work until the router fetches them.',
                $issued,
                $issued === 1 ? '' : 's',
            ));
    }

    /**
     * Replace the secret in the fetch URL.
     *
     * For when the old address has been somewhere it should not have been. It breaks the
     * scheduled fetch on the router until the new command is pasted in, which is said
     * plainly rather than discovered on the day.
     */
    public function rotate(Request $request, Event $event)
    {
        WifiCredentials::rotateToken($event);

        AdminLogger::activity(
            'event.wifi.rotate',
            sprintf('Rotated the Wi-Fi fetch token for %s.', $event->title),
        );

        return back()->with('status', 'A new fetch address has been generated. The old one now returns nothing, so paste the new command into the router before the event.');
    }

    /**
     * Email the logins to everybody who has one and has not been told.
     *
     * The counts come back separately on purpose. "Sent 88" on its own hides the eight
     * people with no email address on file, who are precisely the ones who need a printed
     * slip, and hides anybody whose login is not on the router yet.
     */
    public function send(Request $request, Event $event, WifiCredentialNotifier $notifier)
    {
        if (! $event->offersWifi()) {
            return back()->with('error', 'Turn on "Create WiFi portal user and password" for this event first.');
        }

        $outcome = $notifier->sendFor($event);

        AdminLogger::activity(
            'event.wifi.send',
            sprintf('Sent %d Wi-Fi login(s) for %s.', $outcome['sent'], $event->title),
        );

        if ($outcome['sent'] === 0 && $outcome['not_provisioned'] > 0) {
            return back()->with('error', sprintf(
                'Nothing was sent. %d login%s waiting for the router to fetch them, and a login that is not on the router yet would fail if somebody tried it.',
                $outcome['not_provisioned'],
                $outcome['not_provisioned'] === 1 ? ' is' : 's are',
            ));
        }

        if ($outcome['sent'] === 0 && $outcome['no_email'] === 0 && $outcome['failed'] === 0) {
            return back()->with('status', 'Everybody with a working login has already been told. Nothing to send.');
        }

        $parts = [sprintf('%d email%s queued.', $outcome['sent'], $outcome['sent'] === 1 ? '' : 's')];

        if ($outcome['no_email'] > 0) {
            $parts[] = sprintf(
                '%d competitor%s no email address on file, so print their slips for the counter.',
                $outcome['no_email'],
                $outcome['no_email'] === 1 ? ' has' : 's have',
            );
        }

        if ($outcome['not_provisioned'] > 0) {
            $parts[] = sprintf(
                '%d skipped because the router has not fetched them yet.',
                $outcome['not_provisioned'],
            );
        }

        if ($outcome['failed'] > 0) {
            $parts[] = sprintf('%d could not be queued and are in the log.', $outcome['failed']);
        }

        return back()->with('status', implode(' ', $parts));
    }

    /**
     * A printable sheet of logins for the registration counter.
     *
     * The fallback that needs nothing to work: no phone, no signal, no inbox. Handed over
     * when somebody arrives, which is after the router has been provisioned by definition,
     * so the ordering problem that haunts emailing cannot happen here.
     *
     * Grouped by team, because that is the order a queue arrives in.
     */
    public function slips(Request $request, Event $event)
    {
        $credentials = WifiCredential::query()
            ->where('event_id', $event->id)
            ->live()
            ->forAttending()
            ->with(['participant', 'registration'])
            ->get()
            ->sortBy([
                fn (WifiCredential $c) => $c->registration?->team_name ?? '',
                fn (WifiCredential $c) => $c->participant?->full_name ?? '',
            ])
            ->groupBy(fn (WifiCredential $c) => $c->registration?->team_name ?: 'Unassigned');

        AdminLogger::activity(
            'event.wifi.slips',
            sprintf('Opened the Wi-Fi slips for %s.', $event->title),
        );

        return view('admin.event.wifi-slips', [
            'event' => $event,
            'groups' => $credentials,
            'total' => $credentials->flatten()->count(),
        ]);
    }
}
