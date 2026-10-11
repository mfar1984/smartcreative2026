<?php

namespace Tests\Feature\Registration;

use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\EventRegistration;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\ParticipantOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Four fields the public registration form now insists on.
 *
 * WHY IT EXISTS
 *
 * Date of birth, postcode and the emergency contact pair were optional for as long
 * as the form had existed, and the organiser needs all four for every new entry:
 * an age bracket, a postal district, and somebody to ring if a competitor goes
 * down on the field.
 *
 * WHAT IS BEING ASSERTED
 *
 * Mostly the boundary, because the change is only safe if it stops at the public
 * form. Each of the four is proved on its own, so a later edit cannot quietly drop
 * one back to nullable and still pass. The existing shape rules are proved to be
 * untouched. And the three paths that deliberately still accept a blank — the
 * admin correction dialog, the transfer screen and the counter swap — are proved
 * to still accept one, because 367 people were already on file when this changed
 * and making their rows unsaveable would be the expensive mistake.
 *
 * The rendered markup is asserted rather than the rule alone, so the asterisk on
 * the label and the rule behind it cannot drift apart.
 */
class CompulsoryParticipantFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    /* ---------------------------------------------------------------------
     | Fixtures
     * ------------------------------------------------------------------ */

    private function event(array $overrides = []): Event
    {
        return Event::create($overrides + [
            'slug' => 'event-' . uniqid(),
            'title' => 'Larian Sibu',
            'category' => 'Community',
            'starts_at' => now()->addWeek()->toDateString(),
            'ends_at' => now()->addWeek()->toDateString(),
            'status' => Event::STATUS_OPEN,
            'registration_mode' => Event::MODE_INDIVIDUAL,
            'fee' => null,
            'seats_total' => 100,
            'min_players' => 1,
            'max_players' => 5,
        ]);
    }

    /** One complete person, as the public form posts them. */
    private function person(array $overrides = []): array
    {
        return $overrides + [
            'role' => ParticipantOptions::ROLE_PARTICIPANT,
            'full_name' => 'Aminah Yusof',
            'ic_number' => '900101010001',
            'date_of_birth' => '1990-01-01',
            'address_line_1' => '1 Jalan Sibu',
            'postcode' => '96000',
            'city' => 'Sibu',
            'state' => 'Sarawak',
            'country' => 'Malaysia',
            'phone' => '0140000001',
            'email' => 'aminah@example.test',
            'gender' => 'female',
            'race' => 'malay',
            'emergency_contact_name' => 'Hassan Yusof',
            'emergency_contact_phone' => '0149876543',
        ];
    }

    private function submit(Event $event, array $person)
    {
        return $this->post(route('registration.store', ['event' => $event->slug]), [
            'participants' => [$person],
        ]);
    }

    /**
     * Somebody already on file from before the four fields were asked for: a card
     * number, a phone and an address, and nothing in the columns this change
     * added.
     */
    private function legacyEntry(Event $event, string $role = ParticipantOptions::ROLE_PARTICIPANT): array
    {
        $registration = EventRegistration::create([
            'event_id' => $event->id,
            'reference' => EventRegistration::nextReference(),
            'mode' => $event->registration_mode,
            'status' => EventRegistration::STATUS_PENDING,
            'payment_status' => EventRegistration::PAYMENT_UNPAID,
            'registration_fee' => 0,
            'addons_total' => 0,
            'amount' => 0,
            'amount_paid' => 0,
        ]);

        $person = EventParticipant::create([
            'event_registration_id' => $registration->id,
            'role' => $role,
            'full_name' => 'Peserta Lama',
            'ic_number' => '8505050' . random_int(10000, 99999),
            'address_line_1' => '2 Jalan Lama',
            'city' => 'Sibu',
            'state' => 'Sarawak',
            'phone' => '0145550001',
            'email' => 'lama-' . uniqid() . '@example.test',
            'gender' => 'male',
            'race' => 'malay',

            // The point of the fixture. Nullable columns, left null.
            'date_of_birth' => null,
            'postcode' => null,
            'emergency_contact_name' => null,
            'emergency_contact_phone' => null,
        ]);

        return [$registration, $person->fresh()];
    }

    /**
     * A user holding exactly the named permissions and nothing else.
     *
     * @param  array<int, string>  $permissions
     */
    private function userWith(array $permissions): User
    {
        $role = Role::create([
            'slug' => 'staff-' . uniqid(),
            'name' => 'Staff',
            'is_active' => true,
        ]);

        $ids = [];

        // admin.access is always granted: without it EnsureUserCanAccessAdmin signs
        // the session out before any permission on the route is consulted.
        foreach (array_unique(['admin.access', ...$permissions]) as $index => $slug) {
            $ids[] = Permission::firstOrCreate(
                ['slug' => $slug],
                ['name' => $slug, 'group' => 'Event', 'module' => 'Participants', 'action' => 'view', 'sort_order' => $index],
            )->id;
        }

        $role->permissions()->sync($ids);

        return User::create([
            'name' => 'Staff',
            'username' => 'staff-' . uniqid(),
            'email' => uniqid() . '@example.test',
            'password' => 'secret-password',
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }

    /* ---------------------------------------------------------------------
     | Refused on the public form
     * ------------------------------------------------------------------ */

    /** @return array<string, array<int, string>> */
    public static function compulsoryFields(): array
    {
        // Named, so a failure says which of the four fell over.
        return [
            'date of birth' => ['date_of_birth'],
            'postcode' => ['postcode'],
            'emergency contact name' => ['emergency_contact_name'],
            'emergency contact number' => ['emergency_contact_phone'],
        ];
    }

    /**
     * One case per field, so a later edit cannot drop one back to nullable and
     * still leave this file green.
     */
    #[DataProvider('compulsoryFields')]
    public function test_a_public_registration_is_refused_without_each_field(string $field): void
    {
        $event = $this->event();

        $person = $this->person();
        unset($person[$field]);

        $this->submit($event, $person)
            ->assertSessionHasErrors('participants.0.' . $field);

        $this->assertSame(0, EventRegistration::query()->count());
    }

    public function test_a_complete_public_registration_stores_all_four(): void
    {
        $event = $this->event();

        $this->submit($event, $this->person())->assertSessionHasNoErrors();

        $person = EventParticipant::query()->sole();

        $this->assertSame('1990-01-01', $person->date_of_birth->toDateString());
        $this->assertSame('96000', $person->postcode);
        $this->assertSame('Hassan Yusof', $person->emergency_contact_name);
        $this->assertSame('0149876543', $person->emergency_contact_phone);
    }

    /* ---------------------------------------------------------------------
     | The shape rules that were already there
     * ------------------------------------------------------------------ */

    public function test_the_emergency_number_still_refuses_letters(): void
    {
        $event = $this->event();

        $this->submit($event, $this->person(['emergency_contact_phone' => 'ring my mother']))
            ->assertSessionHasErrors('participants.0.emergency_contact_phone');

        $this->assertSame(0, EventRegistration::query()->count());
    }

    public function test_a_date_of_birth_still_has_to_be_in_the_past(): void
    {
        $event = $this->event();

        $this->submit($event, $this->person(['date_of_birth' => now()->addYear()->toDateString()]))
            ->assertSessionHasErrors('participants.0.date_of_birth');

        $this->assertSame(0, EventRegistration::query()->count());
    }

    /* ---------------------------------------------------------------------
     | The entries already on file
     * ------------------------------------------------------------------ */

    /**
     * The regression that would hurt most. Somebody registered before these four
     * were asked for has to stay correctable, or the admin screen becomes the one
     * place their misspelt name can never be fixed.
     */
    public function test_the_admin_dialog_still_saves_a_person_with_all_four_blank(): void
    {
        $event = $this->event();
        [$registration, $person] = $this->legacyEntry($event);

        $this->actingAs($this->userWith(['participants.view', 'participants.update']))
            ->from(route('admin.event.participants.show', $registration))
            ->put(route('admin.event.participants.person.update', [$registration, $person]), [
                'full_name' => 'Peserta Lama Dibetulkan',
                'ic_number' => $person->ic_number,
                'phone' => $person->phone,
                'email' => $person->email,
                'gender' => $person->gender,
                'race' => $person->race,
                'address_line_1' => $person->address_line_1,
                'city' => $person->city,
                'state' => $person->state,
                'country' => $person->country,

                // The four are simply absent, exactly as the dialog posts them for
                // a row that never carried any.
            ])
            ->assertSessionHasNoErrors();

        $person = $person->fresh();

        $this->assertSame('Peserta Lama Dibetulkan', $person->full_name);
        $this->assertNull($person->date_of_birth);
        $this->assertNull($person->postcode);
        $this->assertNull($person->emergency_contact_name);
        $this->assertNull($person->emergency_contact_phone);
    }

    /**
     * A move brings people in with name, card, phone and email alone. Somebody
     * standing in at short notice will not produce a postcode, and demanding one
     * would block a change that has to happen.
     */
    public function test_a_transfer_still_accepts_somebody_with_all_four_blank(): void
    {
        $from = $this->event(['registration_mode' => Event::MODE_GROUPING]);
        $to = $this->event(['registration_mode' => Event::MODE_GROUPING, 'title' => 'Larian Kuching']);

        [$registration] = $this->legacyEntry($from);

        $this->actingAs($this->userWith(['participants.view', 'participants.transfer']))
            ->post(route('admin.event.participants.transfer.save', $registration), [
                'event_id' => $to->id,
                'add' => [[
                    'full_name' => 'Pengganti Segera',
                    'ic_number' => '950505050005',
                    'phone' => '0145550002',
                    'email' => 'pengganti@example.test',
                ]],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($to->id, $registration->fresh()->event_id);

        $arrived = EventParticipant::query()->where('full_name', 'Pengganti Segera')->sole();

        $this->assertNull($arrived->date_of_birth);
        $this->assertNull($arrived->postcode);
        $this->assertNull($arrived->emergency_contact_name);
        $this->assertNull($arrived->emergency_contact_phone);
    }

    /**
     * The counter swap goes further and writes those columns as null on purpose:
     * what was there described the player being replaced.
     */
    public function test_a_counter_swap_still_accepts_somebody_with_all_four_blank(): void
    {
        $event = $this->event(['registration_mode' => Event::MODE_MANAGER]);
        [, $person] = $this->legacyEntry($event, ParticipantOptions::ROLE_PLAYER);

        $this->actingAs($this->userWith(['attendance.view', 'attendance.update']))
            ->put(route('admin.event.attendance.swap', $person), [
                'full_name' => 'Pemain Ganti',
                'ic_number' => '960606060006',
                'phone' => '0145550003',
            ])
            ->assertSessionHasNoErrors();

        $person = $person->fresh();

        $this->assertSame('Pemain Ganti', $person->full_name);
        $this->assertNull($person->date_of_birth);
        $this->assertNull($person->postcode);
        $this->assertNull($person->emergency_contact_name);
        $this->assertNull($person->emergency_contact_phone);
    }

    /* ---------------------------------------------------------------------
     | The form itself
     * ------------------------------------------------------------------ */

    /**
     * Asserted on the markup the component actually emits, so the label and the
     * rule cannot drift apart: a field that stops being required here would lose
     * its asterisk and fail this.
     */
    public function test_the_public_form_marks_all_four_as_compulsory(): void
    {
        $event = $this->event();

        $response = $this->get(route('registration', ['register' => $event->slug]));

        $response->assertOk();

        foreach ([
            'Date of Birth',
            'Postcode',
            'Emergency Contact Name',
            'Emergency Contact Number',
        ] as $label) {
            $response->assertSee(
                $label . ' <span class="text-red-600" aria-hidden="true">*</span>',
                false,
            );
        }

        foreach (['date_of_birth', 'postcode', 'emergency_contact_name', 'emergency_contact_phone'] as $field) {
            $response->assertSee('name="participants[0][' . $field . ']" required', false);
        }
    }
}
