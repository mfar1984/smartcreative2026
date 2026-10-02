<?php

namespace Tests\Feature\Registration;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Role;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Submitted times read on the Malaysian clock while the column stays UTC.
 *
 * The office reported entries dated eight hours early, and the cause was never
 * the data: config/app.php pins the application to UTC, so every row was stored
 * correctly and simply read on the wrong clock. The fix converts at the display
 * layer, which means the thing worth testing is not only that the screens now
 * say 8:51 am but that the database still says 00:51:00 afterwards.
 *
 * The chosen instant straddles midnight UTC on purpose. 00:51 UTC becomes 8:51
 * in Kuala Lumpur, so the am/pm and the hour both move; a conversion that
 * quietly did nothing could not pass by coincidence.
 */
class RegistrationDisplayTimezoneTest extends TestCase
{
    use RefreshDatabase;

    /** The stored value, in UTC, exactly as the column holds it. */
    private const STORED = '2026-10-02 00:51:00';

    /** The same instant read in Kuala Lumpur, in the format the screens use. */
    private const SHOWN = '02 Oct 2026, 8:51 am';

    /** What the screens used to say, and must not say again. */
    private const WAS_SHOWN = '02 Oct 2026, 12:51 am';

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
     * An individual entry submitted at the known instant.
     *
     * Individual because the participants list opens on that tab, so an entry in
     * any other mode would leave the table empty and the assertion vacuous. No
     * payment_reference, so ParticipantController::show()'s refreshPayment()
     * returns early and the detail screen never reaches for the gateway.
     */
    private function registration(): EventRegistration
    {
        $event = Event::create([
            'slug' => 'event-' . uniqid(),
            'title' => 'Family Fun Day',
            'category' => 'Community',
            'starts_at' => now()->addWeek()->toDateString(),
            'ends_at' => now()->addWeek()->toDateString(),
            'status' => Event::STATUS_OPEN,
            'registration_mode' => Event::MODE_INDIVIDUAL,
            'fee' => 10,
            'seats_total' => 100,
        ]);

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

        // created_at is not fillable, so the submitted time is written after the
        // row exists rather than through create().
        $registration->forceFill(['created_at' => Carbon::parse(self::STORED, 'UTC')])->save();

        $this->assertSame(self::STORED, $registration->fresh()->created_at->toDateTimeString());

        return $registration->fresh();
    }

    public function test_the_application_timezone_is_still_utc(): void
    {
        /*
         | The guard against the tempting one-line "fix".
         |
         | Flipping app.timezone to Asia/Kuala_Lumpur would make every screen read
         | correctly and reinterpret every stored row at the same time, because the
         | local .env already carries an APP_TIMEZONE nobody is reading. Storage
         | stays UTC; only the display moves.
         */
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('Asia/Kuala_Lumpur', config('app.display_timezone'));
        $this->assertSame('Asia/Kuala_Lumpur', LocalTime::zone());
    }

    public function test_the_helper_reads_a_stored_utc_instant_eight_hours_later(): void
    {
        $moment = Carbon::parse(self::STORED, 'UTC');

        $this->assertSame(self::SHOWN, LocalTime::format($moment));

        // The clone is what keeps this safe: the instant handed in is unchanged,
        // so the same call on a model attribute cannot shift the model.
        $this->assertSame('UTC', $moment->getTimezone()->getName());
        $this->assertSame(self::STORED, $moment->toDateTimeString());

        // And a missing date reads as the dash the screens used to write for
        // themselves.
        $this->assertSame('—', LocalTime::format(null));
    }

    public function test_the_participants_list_shows_the_submitted_time_in_kuala_lumpur(): void
    {
        $registration = $this->registration();

        $response = $this->actingAs($this->administrator())
            ->get(route('admin.event.participants'));

        $response->assertOk();
        $response->assertSee(self::SHOWN);
        $response->assertDontSee(self::WAS_SHOWN);
    }

    public function test_the_entry_screen_shows_the_submitted_time_in_kuala_lumpur(): void
    {
        $registration = $this->registration();

        $response = $this->actingAs($this->administrator())
            ->get(route('admin.event.participants.show', $registration));

        $response->assertOk();
        $response->assertSee(self::SHOWN);
        $response->assertDontSee(self::WAS_SHOWN);
    }

    public function test_rendering_the_screens_does_not_move_the_stored_timestamp(): void
    {
        $registration = $this->registration();
        $admin = $this->administrator();

        // Both screens rendered first, so the assertion is made after whatever a
        // page render does to the model rather than before it.
        $this->actingAs($admin)->get(route('admin.event.participants'))->assertOk();
        $this->actingAs($admin)->get(route('admin.event.participants.show', $registration))->assertOk();

        // Read straight off the column, past the Eloquent date cast, because the
        // cast would hide a shifted string by parsing it back again.
        $raw = (string) DB::table('event_registrations')
            ->where('id', $registration->id)
            ->value('created_at');

        $this->assertStringStartsWith(self::STORED, $raw);
        $this->assertSame(self::STORED, $registration->fresh()->created_at->toDateTimeString());
    }
}
