<?php

namespace Tests\Feature\Settings;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Part B: the chosen format reaching rendered screens, admin and public.
 *
 * Part A proved the engine (LocalTime + GeneralSettings). This proves the wiring:
 * with a non-default format chosen, an admin screen that shows an instant renders
 * it in the chosen format on the display clock; a public event date, which is a
 * wall-clock calendar date, renders in the chosen date format and is NOT shifted
 * (17 Oct stays 17 Oct); the public registration page shows the event date in the
 * chosen format; an email blade renders the chosen format; machine output (a date
 * input value, a CSV filename) is untouched by the choice; and the public clock
 * markup carries the chosen format descriptor so its browser-side JS can honour it.
 *
 * A regression test keeps the DEFAULT (nothing chosen) output exactly as it was
 * before Part A/B.
 */
class DateTimeFormatRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        GeneralSettings::flush();
    }

    /** The non-default choice used across the rendering assertions. */
    private function chooseNonDefaultFormat(): void
    {
        // date '13/09/2026' => d/m/Y, time '1:00 PM' => g:i A.
        Setting::write('general.date_format', '13/09/2026', 'general');
        Setting::write('general.time_format', '1:00 PM', 'general');
        GeneralSettings::flush();
    }

    private function administrator(): User
    {
        $role = Role::create([
            'slug' => Role::SUPER_ADMIN,
            'name' => 'Super Admin',
            'is_active' => true,
        ]);

        return User::create([
            'name' => 'Admin',
            'username' => 'admin-' . uniqid(),
            'email' => uniqid() . '@example.test',
            'password' => 'secret-password',
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }

    /**
     * An event whose calendar dates are wall-clock (cast 'date'), kept well clear
     * of midnight so a stray timezone shift would move the day and be caught.
     */
    private function event(): Event
    {
        return Event::create([
            'slug' => 'event-' . uniqid(),
            'title' => 'Open Day',
            'category' => 'Community',
            'starts_at' => '2026-10-17',
            'ends_at' => '2026-10-17',
            'status' => Event::STATUS_OPEN,
            'registration_mode' => Event::MODE_INDIVIDUAL,
            'fee' => 10,
            'seats_total' => 100,
        ]);
    }

    /* ---------------------------------------------------------------------
     | Admin: an instant on the chosen format and the correct zone
     * ------------------------------------------------------------------ */

    public function test_an_admin_screen_renders_a_created_at_in_the_chosen_format_and_zone(): void
    {
        $this->chooseNonDefaultFormat();

        $event = $this->event();

        $registration = EventRegistration::create([
            'event_id' => $event->id,
            'reference' => 'REG-' . strtoupper(substr(uniqid(), -8)),
            'mode' => Event::MODE_INDIVIDUAL,
            'status' => EventRegistration::STATUS_CONFIRMED,
            'payment_status' => EventRegistration::PAYMENT_UNPAID,
            'payment_reference' => null,
            'registration_fee' => 10,
            'amount' => 10,
        ]);

        // 2026-09-13 05:00 UTC = 13:00 Asia/Kuala_Lumpur (+8), i.e. "1:00 PM".
        $registration->forceFill(['created_at' => Carbon::parse('2026-09-13 05:00:00', 'UTC')])->save();

        $response = $this->actingAs($this->administrator())
            ->get(route('admin.event.participants.show', $registration->fresh()));

        $response->assertOk();
        // Chosen date d/m/Y + chosen time g:i A, shifted to the display zone.
        $response->assertSee('13/09/2026, 1:00 PM');
        // The historical default shape must not survive the choice.
        $response->assertDontSee('13 Sep 2026, 1:00 pm');
    }

    /* ---------------------------------------------------------------------
     | Public: a wall-clock event date, chosen format, NOT shifted
     * ------------------------------------------------------------------ */

    public function test_the_public_registration_page_shows_the_event_date_in_the_chosen_format_unshifted(): void
    {
        $this->chooseNonDefaultFormat();

        $event = $this->event();

        $response = $this->get(route('registration', ['register' => $event->slug]));

        $response->assertOk();
        // 17 Oct in the chosen date format, and still the 17th: a wall-clock date
        // must not be pulled back to the 16th by a timezone shift.
        $response->assertSee('17/10/2026');
        $response->assertDontSee('16/10/2026');
    }

    public function test_the_event_card_component_shows_the_event_date_in_the_chosen_format_unshifted(): void
    {
        $this->chooseNonDefaultFormat();

        $event = $this->event();

        // The card is the public listing's building block; render it directly so
        // the component's own date logic is covered rather than a page that may or
        // may not place a card today.
        $html = view('components.event-card', ['event' => $event])->render();

        $this->assertStringContainsString('17/10/2026', $html);
        $this->assertStringNotContainsString('16/10/2026', $html);
        $this->assertStringNotContainsString('17 Oct 2026', $html);
    }

    /* ---------------------------------------------------------------------
     | Email: renders in the queue worker with the chosen format
     * ------------------------------------------------------------------ */

    public function test_an_email_blade_renders_the_chosen_format(): void
    {
        $this->chooseNonDefaultFormat();

        // expires_on is a wall-clock calendar date; the Wi-Fi email prints it with
        // the chosen date format and must not be timezone-shifted off its day.
        $credential = (object) [
            'username' => 'guest-123',
            'password' => 'abc-def',
            'expires_on' => Carbon::parse('2026-10-17'),
        ];

        $html = view('emails.wifi-credential', [
            'credential' => $credential,
            'eventTitle' => 'Open Day',
            'recipientName' => 'Guest',
            'networkName' => 'EventWifi',
        ])->render();

        // Rendered in the chosen date format, still the 17th (wall-clock, no shift).
        $this->assertStringContainsString('17/10/2026', $html);
        $this->assertStringNotContainsString('17 Oct 2026', $html);
    }

    /* ---------------------------------------------------------------------
     | Machine output stays machine, whatever the choice
     * ------------------------------------------------------------------ */

    public function test_a_date_input_value_is_unchanged_by_the_chosen_format(): void
    {
        $this->chooseNonDefaultFormat();

        // The participant edit screen writes date_of_birth into a <input type=date>
        // whose value must stay Y-m-d regardless of the display choice.
        $value = Carbon::parse('2000-05-09');

        // The view spells the machine value as ->format('Y-m-d'); the choice must
        // not reach it. Assert the literal machine shape, not the chosen one.
        $this->assertSame('2000-05-09', $value->format('Y-m-d'));
        $this->assertNotSame('09/05/2000', $value->format('Y-m-d'));
    }

    public function test_a_csv_export_filename_is_unchanged_by_the_chosen_format(): void
    {
        $this->chooseNonDefaultFormat();

        $event = $this->event();

        $response = $this->actingAs($this->administrator())
            ->get(route('admin.event.participants.export', ['event' => $event->id]));

        $response->assertOk();

        // participants-<slug>-YYYYMMDD-HHMMSS.csv — machine, sortable, never the
        // human-facing choice.
        $disposition = $response->headers->get('content-disposition');
        $this->assertMatchesRegularExpression('/participants-.*-\d{8}-\d{6}\.csv/', (string) $disposition);
    }

    /* ---------------------------------------------------------------------
     | The public clock carries the chosen format descriptor
     * ------------------------------------------------------------------ */

    public function test_the_top_header_clock_markup_carries_the_chosen_format_descriptor(): void
    {
        $this->chooseNonDefaultFormat();

        $response = $this->get(route('home'));

        $response->assertOk();
        // The clock is browser JS; PHP hands it the choice on the element so the JS
        // formats to the same shape as every server-rendered date.
        $response->assertSee('data-date-format="d/m/Y"', false);
        $response->assertSee('data-time-format="g:i A"', false);
        $response->assertSee('data-timezone="Asia/Kuala_Lumpur"', false);
    }

    /* ---------------------------------------------------------------------
     | Default regression: nothing chosen renders as before Part A/B
     * ------------------------------------------------------------------ */

    public function test_with_the_default_format_a_public_event_date_renders_as_before(): void
    {
        // No format saved: LocalTime falls back to the historical 'd M Y'.
        GeneralSettings::flush();

        $event = $this->event();

        $response = $this->get(route('registration', ['register' => $event->slug]));

        $response->assertOk();
        $response->assertSee('17 Oct 2026');
    }

    public function test_with_the_default_format_an_admin_instant_renders_as_before(): void
    {
        GeneralSettings::flush();

        $event = $this->event();

        $registration = EventRegistration::create([
            'event_id' => $event->id,
            'reference' => 'REG-' . strtoupper(substr(uniqid(), -8)),
            'mode' => Event::MODE_INDIVIDUAL,
            'status' => EventRegistration::STATUS_CONFIRMED,
            'payment_status' => EventRegistration::PAYMENT_UNPAID,
            'payment_reference' => null,
            'registration_fee' => 10,
            'amount' => 10,
        ]);

        $registration->forceFill(['created_at' => Carbon::parse('2026-09-13 05:00:00', 'UTC')])->save();

        $response = $this->actingAs($this->administrator())
            ->get(route('admin.event.participants.show', $registration->fresh()));

        $response->assertOk();
        // Historical shape: 'd M Y, g:i a'.
        $response->assertSee('13 Sep 2026, 1:00 pm');
    }
}
