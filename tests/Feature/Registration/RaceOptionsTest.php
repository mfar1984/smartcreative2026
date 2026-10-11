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
 * The full list of Malaysian ethnic groups on the race field.
 *
 * WHY IT EXISTS
 *
 * The field used to offer three umbrella buckets — Bumiputera Sabah, Bumiputera
 * Sarawak and Orang Asli — which told the organiser almost nothing. They are now
 * replaced by the groups themselves, and the buckets are no longer offered.
 *
 * Two of them are still in the database: 7 entries against bumiputera-sabah and
 * 204 against bumiputera-sarawak. Nobody but the owner can say what each of those
 * people actually is, so the rows are left alone and the keys stay valid behind
 * the admin screens. That is the whole risk in this change, and most of this file
 * exists to pin it down: if a retired key stops validating, or stops resolving to
 * a label, or stops being the selected option in the dialog, 211 real entries
 * either cannot be saved or get quietly rewritten on the next save.
 *
 * WHAT IS BEING ASSERTED
 *
 * That a new choice can only come from the current list, that the retired pair is
 * refused on the public form and accepted on the admin one, that a retired key
 * reads as its proper label rather than its slug, and that the markup of both
 * forms matches those rules — the dropdown is where a silent rewrite would start.
 */
class RaceOptionsTest extends TestCase
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

    /** An entry carrying a race key that is no longer offered. */
    private function entryStoredAs(Event $event, string $race): array
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
            'role' => ParticipantOptions::ROLE_PARTICIPANT,
            'full_name' => 'Peserta Sarawak',
            'ic_number' => '8505050' . random_int(10000, 99999),
            'address_line_1' => '2 Jalan Lama',
            'city' => 'Sibu',
            'state' => 'Sarawak',
            'country' => 'Malaysia',
            'phone' => '0145550001',
            'email' => 'lama-' . uniqid() . '@example.test',
            'gender' => 'male',
            'race' => $race,
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

    /**
     * The markup of one <select>, found by id, so an assertion about the race
     * options cannot be satisfied by some other field's options.
     */
    private function selectMarkup(string $html, string $id): string
    {
        $start = strpos($html, 'id="' . $id . '"');

        $this->assertNotFalse($start, 'No select with id "' . $id . '" was rendered.');

        $end = strpos($html, '</select>', $start);

        $this->assertNotFalse($end, 'The select with id "' . $id . '" was never closed.');

        return substr($html, $start, $end - $start);
    }

    /** @return array<int, string> the option values of a select, in order */
    private function optionValues(string $select): array
    {
        preg_match_all('/<option value="([^"]*)"/', $select, $matches);

        return $matches[1];
    }

    /* ---------------------------------------------------------------------
     | The list itself
     * ------------------------------------------------------------------ */

    public function test_the_list_runs_to_seventy_seven_with_other_last(): void
    {
        $this->assertCount(77, ParticipantOptions::RACES);
        $this->assertSame('other', array_key_last(ParticipantOptions::RACES));
    }

    /** Every group names keys that exist, so a heading cannot point at nothing. */
    public function test_every_grouped_key_is_a_real_race(): void
    {
        foreach (ParticipantOptions::RACE_GROUPS as $group => $keys) {
            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, ParticipantOptions::RACES, $group . ' names a key that is not in RACES: ' . $key);
            }
        }
    }

    public function test_the_retired_keys_are_not_offered_as_a_new_choice(): void
    {
        foreach (array_keys(ParticipantOptions::RACES_LEGACY) as $retired) {
            $this->assertArrayNotHasKey($retired, ParticipantOptions::RACES);
            $this->assertNotContains($retired, ParticipantOptions::raceKeys());
            $this->assertContains($retired, ParticipantOptions::raceKeysWithLegacy());
        }

        // Deleted outright: nothing in the database carried it.
        $this->assertNull(ParticipantOptions::raceLabel('orang-asli'));
    }

    /* ---------------------------------------------------------------------
     | The public form
     * ------------------------------------------------------------------ */

    /** @return array<string, array<int, string>> */
    public static function newlyOfferedRaces(): array
    {
        // One from each of the regional blocks, named so a failure says which.
        return [
            'Sarawak' => ['iban', 'Iban'],
            'Orang Ulu' => ['lun-bawang', 'Lun Bawang'],
            'Sabah' => ['kadazan', 'Kadazan'],
            'Orang Asli' => ['temuan', 'Temuan'],
            'Lain-lain' => ['portugis', 'Portugis (Kristang)'],
        ];
    }

    #[DataProvider('newlyOfferedRaces')]
    public function test_a_public_registration_accepts_a_newly_added_race(string $key, string $label): void
    {
        $event = $this->event();

        $this->submit($event, $this->person(['race' => $key]))->assertSessionHasNoErrors();

        $person = EventParticipant::query()->sole();

        $this->assertSame($key, $person->race);
        $this->assertSame($label, $person->raceLabel());
    }

    /** @return array<string, array<int, string>> */
    public static function withdrawnRaces(): array
    {
        return [
            'retired but still on file' => ['bumiputera-sarawak'],
            'retired and deleted' => ['orang-asli'],
        ];
    }

    #[DataProvider('withdrawnRaces')]
    public function test_a_public_registration_refuses_a_withdrawn_race(string $key): void
    {
        $event = $this->event();

        $this->submit($event, $this->person(['race' => $key]))
            ->assertSessionHasErrors('participants.0.race');

        $this->assertSame(0, EventRegistration::query()->count());
    }

    public function test_the_public_form_renders_the_groups_and_offers_no_retired_key(): void
    {
        $event = $this->event();

        $response = $this->get(route('registration', ['register' => $event->slug]));

        $response->assertOk();

        $select = $this->selectMarkup($response->getContent(), 'p-0-race');

        foreach (array_keys(ParticipantOptions::RACE_GROUPS) as $group) {
            $this->assertStringContainsString('<optgroup label="' . $group . '">', $select);
        }

        $values = $this->optionValues($select);

        // The empty "Select" prompt, then all 77.
        $this->assertSame('', $values[0]);
        $this->assertSame(ParticipantOptions::raceKeys(), array_slice($values, 1));

        // Which also settles it, but said plainly because it is the point.
        $this->assertSame('other', end($values));
        $this->assertStringNotContainsString('bumiputera', $select);
        $this->assertStringNotContainsString('orang-asli', $select);
    }

    /* ---------------------------------------------------------------------
     | The admin dialog, where the 211 rows live
     * ------------------------------------------------------------------ */

    /**
     * The regression that would hurt. 204 entries are stored as
     * bumiputera-sarawak; refusing the value they already hold would make every
     * one of them impossible to correct.
     */
    public function test_the_admin_dialog_saves_a_person_stored_as_a_retired_race(): void
    {
        $event = $this->event();
        [$registration, $person] = $this->entryStoredAs($event, 'bumiputera-sarawak');

        $this->actingAs($this->userWith(['participants.view', 'participants.update']))
            ->from(route('admin.event.participants.show', $registration))
            ->put(route('admin.event.participants.person.update', [$registration, $person]), [
                'full_name' => 'Peserta Sarawak Dibetulkan',
                'ic_number' => $person->ic_number,
                'phone' => $person->phone,
                'email' => $person->email,
                'gender' => $person->gender,

                // Posted back as it stands, which is what the dialog does for a
                // field nobody is changing.
                'race' => 'bumiputera-sarawak',

                'address_line_1' => $person->address_line_1,
                'city' => $person->city,
                'state' => $person->state,
                'country' => $person->country,
            ])
            ->assertSessionHasNoErrors();

        $person = $person->fresh();

        $this->assertSame('Peserta Sarawak Dibetulkan', $person->full_name);
        $this->assertSame('bumiputera-sarawak', $person->race);
    }

    public function test_a_retired_race_reads_as_its_label_and_not_its_slug(): void
    {
        [, $person] = $this->entryStoredAs($this->event(), 'bumiputera-sarawak');

        $this->assertSame('Bumiputera Sarawak', $person->raceLabel());

        [, $other] = $this->entryStoredAs($this->event(), 'bumiputera-sabah');

        $this->assertSame('Bumiputera Sabah', $other->raceLabel());
    }

    /**
     * A select whose options do not include the stored value renders blank, and
     * the next save then writes that blank over a real answer. So the dialog
     * carries the retired option for the row that holds it.
     */
    public function test_the_admin_dialog_shows_a_retired_race_as_the_selected_option(): void
    {
        $event = $this->event();
        [$registration, $person] = $this->entryStoredAs($event, 'bumiputera-sarawak');

        $response = $this->actingAs($this->userWith(['participants.view', 'participants.update']))
            ->get(route('admin.event.participants.show', $registration));

        $response->assertOk();

        $select = $this->selectMarkup($response->getContent(), 'race_' . $person->id);

        $this->assertStringContainsString(
            '<option value="bumiputera-sarawak" selected>Bumiputera Sarawak</option>',
            $select,
        );
        $this->assertStringContainsString('<optgroup label="Rekod Lama">', $select);
    }

    /** The same dialog on an ordinary row offers no retired option at all. */
    public function test_the_admin_dialog_offers_no_retired_option_on_an_ordinary_row(): void
    {
        $event = $this->event();
        [$registration, $person] = $this->entryStoredAs($event, 'iban');

        $response = $this->actingAs($this->userWith(['participants.view', 'participants.update']))
            ->get(route('admin.event.participants.show', $registration));

        $response->assertOk();

        $select = $this->selectMarkup($response->getContent(), 'race_' . $person->id);

        $this->assertStringContainsString('<option value="iban" selected>Iban</option>', $select);
        $this->assertStringNotContainsString('bumiputera', $select);
        $this->assertStringNotContainsString('Rekod Lama', $select);
    }
}
