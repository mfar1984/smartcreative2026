<?php

namespace App\Http\Controllers\Admin\Event;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Models\Event;
use Illuminate\Http\Request;

class AnalyticReportingController extends Controller
{
    public function index(Request $request)
    {
        // Registration counts are loaded because the fee is charged once per
        // registration, so revenue cannot be derived from the seat count.
        $events = Event::query()->withCount('registrations')->get();

        return view('admin.event.reporting', [
            'summary' => [
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
                [
                    'label' => 'Contact Enquiries',
                    'value' => ContactMessage::count(),
                    'note' => sprintf('%d in the last 30 days', ContactMessage::where('created_at', '>=', now()->subDays(30))->count()),
                    'accent' => 'amber',
                    'icon' => 'mail',
                ],
            ],

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
}
