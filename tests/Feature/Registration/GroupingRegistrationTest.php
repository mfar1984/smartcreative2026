<?php

namespace Tests\Feature\Registration;

use App\Models\Event;
use App\Models\EventAddon;
use App\Models\EventAddonVariant;
use App\Models\EventRegistration;
use App\Models\EventRegistrationAddon;
use App\Models\Role;
use App\Models\User;
use App\Support\ParticipantOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Grouping mode: one submission for a whole group, where each member picks their
 * own option.
 *
 * It borrows Manager mode's machinery and differs in exactly two ways, both
 * asserted here: every offered item is collected per person rather than as one
 * bulk quantity, and places are counted per head rather than one per entry. The
 * money is Manager's: one registration fee and one charge for the item itself,
 * however many people are named.
 *
 * Manager and Individual are pinned in the same file on purpose. Grouping was
 * added alongside them and shares their validator, controller and views, so a
 * regression in either would most likely arrive through a change made for
 * Grouping. If these two tests start failing, the new mode has leaked.
 */
class GroupingRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function event(string $mode, array $overrides = []): Event
    {
        return Event::create($overrides + [
            'slug' => 'event-' . uniqid(),
            'title' => 'Family Fun Day',
            'category' => 'Community',
            'starts_at' => now()->addWeek()->toDateString(),
            'ends_at' => now()->addWeek()->toDateString(),
            'status' => Event::STATUS_OPEN,
            'registration_mode' => $mode,
            'fee' => 10,
            'seats_total' => 100,
            'min_players' => 3,
            'max_players' => 5,
        ]);
    }

    /**
     * A compulsory "Event Tee" at RM50: free S, M adds RM5, ten of each.
     *
     * @return array{0: EventAddon, 1: EventAddonVariant, 2: EventAddonVariant}
     */
    private function tee(Event $event, array $overrides = []): array
    {
        $addon = EventAddon::create($overrides + [
            'event_id' => $event->id,
            'name' => 'Event Tee',
            'price' => 50,
            'is_required' => true,
            'is_active' => true,
            'selection_type' => EventAddon::SELECTION_RADIO,
        ]);

        $small = EventAddonVariant::create([
            'event_addon_id' => $addon->id,
            'label' => 'S',
            'price' => 0,
            'stock' => 10,
            'sort_order' => 1,
        ]);

        $medium = EventAddonVariant::create([
            'event_addon_id' => $addon->id,
            'label' => 'M',
            'price' => 5,
            'stock' => 10,
            'sort_order' => 2,
        ]);

        return [$addon->fresh(), $small, $medium];
    }

    private function person(int $n, string $role = ParticipantOptions::ROLE_PARTICIPANT, array $overrides = []): array
    {
        return $overrides + [
            'role' => $role,
            'full_name' => 'Member ' . $n,
            'ic_number' => '900101' . str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'address_line_1' => $n . ' Jalan Dua',
            'city' => 'Shah Alam',
            'state' => 'Selangor',
            'country' => 'Malaysia',
            'phone' => '01200000' . $n,
            'email' => 'member' . $n . '@example.test',
            'gender' => 'female',
            'race' => 'malay',
        ];
    }

    private function submit(Event $event, array $payload)
    {
        return $this->post(route('registration.store', ['event' => $event->slug]), $payload);
    }

    /* ---------------------------------------------------------------------
     | Grouping
     * ------------------------------------------------------------------ */

    public function test_the_public_form_repeats_the_option_picker_for_every_member(): void
    {
        $event = $this->event(Event::MODE_GROUPING);
        [$addon, $small, $medium] = $this->tee($event);

        $response = $this->get(route('registration', ['register' => $event->slug]));

        $response->assertOk();

        // min_players rows are drawn, each with its own group for this item.
        foreach ([0, 1, 2] as $index) {
            $response->assertSee('name="participants[' . $index . '][addons][' . $addon->id . ']"', false);
        }

        $response->assertSee('data-person-addon', false);

        // And it is no longer in the shared picker, which is the one functional
        // difference from Manager mode.
        $response->assertDontSee('name="addons[' . $addon->id . '][choice]"', false);
        $response->assertDontSee('name="addons[' . $addon->id . '][' . $medium->id . ']"', false);
    }

    public function test_each_member_choice_is_recorded_against_that_member_with_one_group_total(): void
    {
        $event = $this->event(Event::MODE_GROUPING);
        [$addon, $small, $medium] = $this->tee($event);

        $this->submit($event, [
            'team_name' => 'The Rahmans',
            'participants' => [
                $this->person(1) + ['addons' => [$addon->id => (string) $small->id]],
                $this->person(2) + ['addons' => [$addon->id => (string) $medium->id]],
                $this->person(3) + ['addons' => [$addon->id => (string) $medium->id]],
            ],
        ])->assertSessionHasNoErrors();

        $registration = EventRegistration::query()->sole();

        $this->assertSame(Event::MODE_GROUPING, $registration->mode);
        $this->assertSame('The Rahmans', $registration->team_name);

        // One fee and one RM50 shirt charge for the whole group, never per head,
        // plus the two M surcharges.
        $this->assertSame('10.00', $registration->registration_fee);
        $this->assertSame('60.00', $registration->addons_total);
        $this->assertSame('70.00', $registration->amount);

        $people = $registration->participants()->orderBy('id')->get();
        $this->assertCount(3, $people);

        // The group charge belongs to the entry, not to anybody in it.
        $base = EventRegistrationAddon::query()->whereNull('event_addon_variant_id')->sole();
        $this->assertNull($base->event_participant_id);
        $this->assertSame('50.00', $base->unit_price);
        $this->assertSame(1, $base->quantity);

        $chosen = EventRegistrationAddon::query()
            ->whereNotNull('event_addon_variant_id')
            ->orderBy('id')
            ->get();

        $this->assertCount(3, $chosen);

        foreach ([
            [0, $people[0]->id, $small->id, 'S'],
            [1, $people[1]->id, $medium->id, 'M'],
            [2, $people[2]->id, $medium->id, 'M'],
        ] as [$line, $personId, $variantId, $label]) {
            $this->assertSame($personId, $chosen[$line]->event_participant_id);
            $this->assertSame($variantId, $chosen[$line]->event_addon_variant_id);
            $this->assertSame($label, $chosen[$line]->variant_label);
            // Each member's pick is one unit, exactly like a quantity of one.
            $this->assertSame(1, $chosen[$line]->quantity);
        }

        $this->assertSame(1, $small->fresh()->stock_taken);
        $this->assertSame(2, $medium->fresh()->stock_taken);

        // Counted per head: every participant in the group takes one place.
        $this->assertSame(3, $event->fresh()->seats_taken);
    }

    public function test_a_grouping_submission_with_no_choices_is_refused_for_every_member(): void
    {
        $event = $this->event(Event::MODE_GROUPING);
        [$addon] = $this->tee($event);

        $this->submit($event, [
            'team_name' => 'The Rahmans',
            'participants' => [
                $this->person(1),
                $this->person(2),
                $this->person(3),
            ],
        ])->assertSessionHasErrors([
            'participants.0.addons.' . $addon->id,
            'participants.1.addons.' . $addon->id,
            'participants.2.addons.' . $addon->id,
        ]);

        $this->assertSame(0, EventRegistration::query()->count());
        $this->assertSame(0, EventRegistrationAddon::query()->count());
        $this->assertSame(0, $event->fresh()->seats_taken);
    }

    public function test_a_group_smaller_than_the_minimum_is_refused(): void
    {
        $event = $this->event(Event::MODE_GROUPING);
        [$addon, $small] = $this->tee($event);

        $this->submit($event, [
            'team_name' => 'The Rahmans',
            'participants' => [
                $this->person(1) + ['addons' => [$addon->id => (string) $small->id]],
                $this->person(2) + ['addons' => [$addon->id => (string) $small->id]],
            ],
        ])->assertSessionHasErrors('participants');

        $this->assertSame(0, EventRegistration::query()->count());
    }

    public function test_a_group_larger_than_the_maximum_is_refused(): void
    {
        $event = $this->event(Event::MODE_GROUPING);
        [$addon, $small] = $this->tee($event);

        $participants = [];

        for ($n = 1; $n <= 6; $n++) {
            $participants[] = $this->person($n) + ['addons' => [$addon->id => (string) $small->id]];
        }

        $this->submit($event, [
            'team_name' => 'The Rahmans',
            'participants' => $participants,
        ])->assertSessionHasErrors('participants');

        $this->assertSame(0, EventRegistration::query()->count());
    }

    public function test_squad_roles_are_refused_at_a_grouping_event(): void
    {
        $event = $this->event(Event::MODE_GROUPING);
        [$addon, $small] = $this->tee($event);

        $this->submit($event, [
            'team_name' => 'The Rahmans',
            'participants' => [
                $this->person(1, ParticipantOptions::ROLE_MANAGER) + ['addons' => [$addon->id => (string) $small->id]],
                $this->person(2, ParticipantOptions::ROLE_PLAYER) + ['addons' => [$addon->id => (string) $small->id]],
                $this->person(3) + ['addons' => [$addon->id => (string) $small->id]],
            ],
        ])->assertSessionHasErrors(['participants.0.role', 'participants.1.role']);

        $this->assertSame(0, EventRegistration::query()->count());
    }

    public function test_a_grouping_event_still_collects_a_quantity_addon_per_member(): void
    {
        $event = $this->event(Event::MODE_GROUPING);
        [$addon, $small, $medium] = $this->tee($event, [
            'selection_type' => EventAddon::SELECTION_QUANTITY,
        ]);

        $this->submit($event, [
            'team_name' => 'The Rahmans',
            'participants' => [
                $this->person(1) + ['addons' => [$addon->id => [$medium->id => 2]]],
                $this->person(2) + ['addons' => [$addon->id => [$small->id => 1]]],
                $this->person(3) + ['addons' => [$addon->id => [$small->id => 1]]],
            ],
        ])->assertSessionHasNoErrors();

        $registration = EventRegistration::query()->sole();

        // RM50 once for the group, plus two M surcharges. Quantities are kept.
        $this->assertSame('60.00', $registration->addons_total);
        $this->assertSame(2, $medium->fresh()->stock_taken);
        $this->assertSame(2, $small->fresh()->stock_taken);
    }

    /* ---------------------------------------------------------------------
     | The two modes Grouping must not have disturbed
     * ------------------------------------------------------------------ */

    public function test_manager_mode_still_takes_one_place_and_one_fee_for_a_squad(): void
    {
        $event = $this->event(Event::MODE_MANAGER);

        $this->submit($event, [
            'team_name' => 'Alpha Squad',
            'participants' => [
                $this->person(1, ParticipantOptions::ROLE_MANAGER),
                $this->person(2, ParticipantOptions::ROLE_PLAYER),
                $this->person(3, ParticipantOptions::ROLE_PLAYER),
                $this->person(4, ParticipantOptions::ROLE_PLAYER),
            ],
        ])->assertSessionHasNoErrors();

        $registration = EventRegistration::query()->sole();

        $this->assertSame(Event::MODE_MANAGER, $registration->mode);
        $this->assertSame('10.00', $registration->registration_fee);
        $this->assertSame('10.00', $registration->amount);
        $this->assertCount(4, $registration->participants);

        // One place for the whole squad, however many players it names.
        $this->assertSame(1, $event->fresh()->seats_taken);
    }

    public function test_individual_mode_still_refuses_a_second_person(): void
    {
        $event = $this->event(Event::MODE_INDIVIDUAL, [
            'min_players' => null,
            'max_players' => null,
        ]);

        $this->submit($event, [
            'participants' => [
                $this->person(1),
                $this->person(2),
            ],
        ])->assertSessionHasErrors('participants');

        $this->assertSame(0, EventRegistration::query()->count());
        $this->assertSame(0, $event->fresh()->seats_taken);
    }

    /* ---------------------------------------------------------------------
     | The admin event screen
     * ------------------------------------------------------------------ */

    /**
     * Somebody who may read every admin screen.
     */
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

    public function test_the_admin_event_screen_prints_member_bounds_for_a_grouping_event(): void
    {
        $event = $this->event(Event::MODE_GROUPING);

        $response = $this->actingAs($this->administrator())
            ->get(route('admin.event.registration.show', ['event' => $event->id]));

        $response->assertOk();
        $response->assertSee($event->modeLabel());
        // The bounds are set and editable for grouping, so the screen has to
        // report them rather than showing the label with nothing after it.
        $response->assertSee('3 to 5');
        $response->assertSee('participants per group');
        $response->assertDontSee('players per manager');
    }

    public function test_the_admin_event_screen_still_prints_player_bounds_for_a_manager_event(): void
    {
        $event = $this->event(Event::MODE_MANAGER);

        $response = $this->actingAs($this->administrator())
            ->get(route('admin.event.registration.show', ['event' => $event->id]));

        $response->assertOk();
        $response->assertSee('3 to 5');
        $response->assertSee('players per manager');
        $response->assertDontSee('participants per group');
    }

    public function test_individual_mode_still_registers_one_person_for_one_place(): void
    {
        $event = $this->event(Event::MODE_INDIVIDUAL, [
            'min_players' => null,
            'max_players' => null,
        ]);

        $this->submit($event, [
            'participants' => [$this->person(1)],
        ])->assertSessionHasNoErrors();

        $registration = EventRegistration::query()->sole();

        $this->assertSame(Event::MODE_INDIVIDUAL, $registration->mode);
        $this->assertSame('10.00', $registration->amount);
        $this->assertSame(1, $event->fresh()->seats_taken);
    }
}
