<?php

namespace App\Http\Middleware;

use App\Models\Coupon;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\EventRegistration;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * A monitor only ever reaches the events assigned to it.
 *
 * Declared once on the whole Event and Coupon route groups rather than checked in
 * each controller, for the reason ScopeTournamentToHandler is: the thing being
 * guarded against is a URL typed by hand. The listings are narrowed where they are
 * queried, but narrowing a list only hides a link — a participant, an entry, an
 * identity card, a coupon report and every index's ?event= are all reachable by
 * changing a number, and every one of them has to refuse. One declaration cannot be
 * forgotten on a route added later, which a per-controller check can.
 *
 * WHY THIS IS HARDER THAN THE TOURNAMENT ONE
 *
 * A tournament is named three ways. An event is named SIX, because most of these
 * routes are not about an event at all — they are about something that belongs to
 * one:
 *
 *   {event}         the path, on registration.show/edit/update/destroy, the Wi-Fi
 *                   actions, recalculate and receipts
 *   {registration}  an entry, which reaches its event through event_id. participants
 *                   show/resend/payment/tally/remind/sizes/transfer/entry/destroy and
 *                   the two collection actions
 *   {participant}   a person, who reaches it through their entry. The identity card,
 *                   the four attendance counter actions and the per-person edits
 *   {coupon}        a batch, which reaches events through coupon_event. The report,
 *                   its export, the design file and every write on a batch
 *   ?event=         the index screens — Participants, Attendance, Collection and the
 *                   two exports — choose their event in the query string, which is as
 *                   editable as a path segment
 *   ?registration=  the Attendance counter opens one entry this way
 *   ?coupon=        Tracking filters by batch this way
 *
 * A route that names its event in none of these ways is a screen about no event in
 * particular, and those are narrowed at the query instead: Participants, Attendance,
 * Collection, Analytic Reporting, Coupon, Tracking and Coupon Report all call
 * visibleTo() where they build their lists.
 *
 * Does nothing at all unless the signed in user is a monitor, so no administrator and
 * no existing screen behaves differently.
 */
class ScopeEventToMonitor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->isRestrictedToAssignedEvents()) {
            return $next($request);
        }

        foreach ($this->eventsNamedIn($request) as $event) {
            if (! $user->monitorsEvent($event)) {
                throw new AccessDeniedHttpException('That event is not assigned to you.');
            }
        }

        // A coupon is not an event, so it is asked its own question: is this batch
        // ticked onto anything this account may see.
        foreach ($this->couponsNamedIn($request) as $coupon) {
            if (! $this->couponIsVisible($coupon, $user->visibleEventIds() ?? [])) {
                throw new AccessDeniedHttpException('That coupon does not apply to any event assigned to you.');
            }
        }

        return $next($request);
    }

    /**
     * Every event this request points at, however it names it.
     *
     * @return array<int, Event>
     */
    private function eventsNamedIn(Request $request): array
    {
        $named = [];

        // The path: /event/registration/{event} and everything hanging off it.
        $bound = $request->route('event');

        if ($bound instanceof Event) {
            $named[] = $bound;
        }

        /*
         | An entry, by path or in the query string.
         |
         | The query string one is the Attendance counter: ?registration= opens an
         | entry at the desk, and it is the only thing on that screen that shows a
         | person's details, so it has to be resolved exactly like the path.
         */
        foreach ([$request->route('registration'), $request->query('registration')] as $candidate) {
            $registration = $candidate instanceof EventRegistration
                ? $candidate
                : $this->find(EventRegistration::class, $candidate);

            if ($registration?->event !== null) {
                $named[] = $registration->event;
            }
        }

        /*
         | A person. The identity card route and the attendance counter actions are
         | keyed by the participant, so the event is two hops away — and the identity
         | card is the single most sensitive read in this module, so missing it would
         | be the whole leak.
         */
        $participant = $request->route('participant');
        $participant = $participant instanceof EventParticipant
            ? $participant
            : $this->find(EventParticipant::class, $participant);

        if ($participant?->registration?->event !== null) {
            $named[] = $participant->registration->event;
        }

        // The query string: every index screen and both exports choose their event
        // with ?event=, which is as easy to edit as a path segment.
        $requested = $request->query('event');

        if (is_scalar($requested) && (int) $requested > 0) {
            $event = Event::find((int) $requested);

            if ($event !== null) {
                $named[] = $event;
            }
        }

        return $named;
    }

    /**
     * Every coupon batch this request points at.
     *
     * @return array<int, Coupon>
     */
    private function couponsNamedIn(Request $request): array
    {
        $named = [];

        foreach ([$request->route('coupon'), $request->query('coupon')] as $candidate) {
            $coupon = $candidate instanceof Coupon
                ? $candidate
                : $this->find(Coupon::class, $candidate);

            if ($coupon !== null) {
                $named[] = $coupon;
            }
        }

        return $named;
    }

    /**
     * Whether a batch is ticked onto any event this account may see.
     *
     * A SHOP batch is ticked onto no event at all and is therefore never visible to a
     * monitor, which is the right answer: it discounts merchandise, which has nothing
     * to do with the event they were asked to watch.
     *
     * @param  array<int, int>  $assigned
     */
    private function couponIsVisible(Coupon $coupon, array $assigned): bool
    {
        if ($assigned === []) {
            return false;
        }

        return $coupon->events()->whereIn('events.id', $assigned)->exists();
    }

    /**
     * Resolve an id that arrived as a raw value rather than a bound model.
     *
     * A route parameter is already bound by the time this runs, but a query string
     * value is just a string, and a missing or non-numeric one has to mean "nothing
     * named" rather than an error: the controller behind it answers that case itself,
     * and a 404 raised here would change the reply to an ordinary mistyped URL.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<TModel>  $model
     * @return TModel|null
     */
    private function find(string $model, mixed $id)
    {
        if (! is_scalar($id) || (int) $id <= 0) {
            return null;
        }

        return $model::find((int) $id);
    }
}
