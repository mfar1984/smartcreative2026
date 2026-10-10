<?php

namespace App\Http\Controllers\Admin\Event;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Models\Event;
use App\Models\EventQuestion;
use App\Support\MonitorScope;
use Illuminate\Http\Request;

class AnalyticReportingController extends Controller
{
    public function index(Request $request)
    {
        /*
         | Registration counts are loaded because the fee is charged once per
         | registration, so revenue cannot be derived from the seat count.
         |
         | visibleTo narrows the whole screen for a monitoring account, and it is the
         | only change this controller needs: every summary figure, the lifecycle
         | split, the per-category table and the event list below are all read off this
         | one collection. An aggregate is the easiest place for a scope to leak,
         | because a total silently including an event they were never given looks
         | exactly like a correct total.
         */
        $events = Event::query()
            ->visibleTo($request->user())
            ->withCount('registrations')
            ->get();

        /*
         | Contact enquiries are the organisation's own post bag: they arrive through
         | the public contact form and belong to no event at all, so there is no way to
         | attribute one to an assigned event and no reason a third-party observer
         | should be reading them. The card is dropped for a monitoring account rather
         | than shown as a zero, which would be a figure asserting there were none.
         */
        $showsEnquiries = ! (bool) $request->user()?->isRestrictedToAssignedEvents();

        return view('admin.event.reporting', [
            'questionResponses' => $this->questionResponses($request),
            'summary' => array_values(array_filter([
                [
                    'label' => 'Total Events',
                    'value' => $events->count(),
                    'note' => sprintf('%d cancelled', $events->where('status', Event::STATUS_CANCELLED)->count()),
                    'accent' => 'blue',
                    'icon' => 'clipboard',
                ],
                [
                    'label' => 'Open for Registration',
                    'value' => $events->filter(fn (Event $event) => $event->canRegister())->count(),
                    'note' => sprintf('%d fully booked', $events->where('status', Event::STATUS_FULL)->count()),
                    'accent' => 'green',
                    'icon' => 'send',
                ],
                [
                    'label' => 'Seats Taken',
                    'value' => (int) $events->sum('seats_taken'),
                    'note' => sprintf('of %s offered', number_format((int) $events->sum('seats_total'))),
                    'accent' => 'purple',
                    'icon' => 'users',
                ],
                $showsEnquiries ? [
                    'label' => 'Contact Enquiries',
                    'value' => ContactMessage::count(),
                    'note' => sprintf('%d in the last 30 days', ContactMessage::where('created_at', '>=', now()->subDays(30))->count()),
                    'accent' => 'amber',
                    'icon' => 'mail',
                ] : null,
            ])),

            // Grouped in PHP rather than SQL because the collection is already
            // loaded for the summary figures above.
            'byLifecycle' => $events->groupBy(fn (Event $event) => $event->lifecycle())->map->count(),
            'byCategory' => $events->groupBy('category')->map(fn ($group) => [
                'events' => $group->count(),
                'seats_total' => (int) $group->sum('seats_total'),
                'seats_taken' => (int) $group->sum('seats_taken'),
                'registrations' => (int) $group->sum('registrations_count'),
                'revenue' => (float) $group->sum(
                    fn (Event $event) => $event->registrationAmount() * $event->registrations_count
                ),
            ])->sortKeys(),

            'events' => $events->sortBy('starts_at'),
        ]);
    }

    /**
     * How each event's own questions were answered.
     *
     * Counted in the database rather than by loading every answer, because a
     * popular event produces one row per person per question and this screen only
     * wants the totals.
     *
     * A compulsory question will always read 100%, since nobody could submit
     * without it. That is not a useful figure and the view says so rather than
     * presenting it as a finding; the optional ones are what this panel is for.
     *
     * @return \Illuminate\Support\Collection<int, EventQuestion>
     */
    private function questionResponses(Request $request)
    {
        return MonitorScope::throughRelation(EventQuestion::query(), $request->user(), 'event', 'events.id')
            ->with('event:id,title')
            ->withCount([
                'answers as answers_total',
                'answers as answers_yes' => fn ($query) => $query->where('answered', true),
            ])
            ->orderBy('event_id')
            ->orderBy('sort_order')
            ->get()
            // Nothing to report on a question nobody has reached yet, and a list of
            // zeroes buries the ones that do have answers.
            ->filter(fn (EventQuestion $question) => $question->answers_total > 0)
            ->values();
    }
}
