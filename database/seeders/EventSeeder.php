<?php

namespace Database\Seeders;

use App\Models\Event;
use Illuminate\Database\Seeder;

class EventSeeder extends Seeder
{
    /**
     * The four events that used to be hardcoded inside
     * RegistrationController::getEvents(). Moving them here keeps the public
     * page showing exactly what it showed before, while giving the admin
     * screens real rows to work with.
     *
     * Matched on slug so re-running the seeder updates rather than duplicates.
     */
    public function run(): void
    {
        $events = [
            [
                'slug' => 'youth-leadership-summit-2026',
                'title' => 'Youth Leadership Summit 2026',
                'category' => 'Youth Event',
                'description' => 'A three-day summit bringing together young leaders for workshops on public speaking, team building and community project design.',
                'starts_at' => '2026-09-12',
                'ends_at' => '2026-09-14',
                'time' => '9:00 am - 5:00 pm',
                'location' => 'Menara Keck Seng, Kuala Lumpur',
                'fee' => 150.00,
                'seats_total' => 200,
                'seats_taken' => 148,
                'status' => Event::STATUS_OPEN,
            ],
            [
                'slug' => 'corporate-digital-strategy-workshop',
                'title' => 'Corporate Digital Strategy Workshop',
                'category' => 'Corporate Event',
                'description' => 'A hands-on workshop for management teams covering digital transformation planning, content strategy and measuring campaign performance.',
                'starts_at' => '2026-10-08',
                'ends_at' => '2026-10-08',
                'time' => '2:00 pm - 6:00 pm',
                'location' => 'Sunway Putra Hotel, Kuala Lumpur',
                'fee' => 480.00,
                'seats_total' => 60,
                'seats_taken' => 54,
                'status' => Event::STATUS_CLOSING_SOON,
            ],
            [
                'slug' => 'national-futsal-championship-2026',
                'title' => 'National Futsal Championship 2026',
                'category' => 'Sports Event',
                'description' => 'Open category futsal championship for registered clubs and corporate teams. Team registration includes match kit and insurance coverage.',
                'starts_at' => '2026-11-21',
                'ends_at' => '2026-11-22',
                'time' => '8:00 am - 7:00 pm',
                'location' => 'Kompleks Sukan Negara, Bukit Jalil',
                'fee' => 900.00,
                'seats_total' => 32,
                'seats_taken' => 32,
                'status' => Event::STATUS_FULL,
            ],
            [
                'slug' => 'online-registration-system-training',
                'title' => 'Online Registration System Training',
                'category' => 'Training',
                'description' => 'A free introductory session for event organisers on setting up and managing participant registration using our online platform.',
                'starts_at' => '2026-09-30',
                'ends_at' => '2026-09-30',
                'time' => '10:00 am - 12:30 pm',
                'location' => 'Online via Zoom',
                'fee' => null,
                'seats_total' => 500,
                'seats_taken' => 212,
                'status' => Event::STATUS_OPEN,
            ],
        ];

        foreach ($events as $event) {
            Event::updateOrCreate(['slug' => $event['slug']], $event);
        }
    }
}
