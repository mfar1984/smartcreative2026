<?php

namespace Tests\Feature\Registration;

use App\Models\Event;
use App\Models\EventAddon;
use App\Models\EventAddonVariant;
use App\Models\EventRegistration;
use App\Models\EventRegistrationAddon;
use App\Models\Role;
use App\Models\User;
use App\Support\AddonOrder;
use App\Support\ParticipantOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Charging each participant for their own add-ons.
 *
 * An add-on's own price has always been one charge for the whole registration: "Event Tee
 * RM50" meant being given shirts at all, and a size only carried money when that size cost
 * more. For a group buying one thing between them that is right. For merchandise it is not,
 * and a grouping event selling a RM40 shirt per head was taking RM40 from a party of ten
 * instead of RM400.
 *
 * The setting is off by default and the first test in this file is the one that matters
 * most: a grouping event that has never heard of the column must keep charging exactly what
 * it charges today. Everything already sold was priced on that reading.
 *
 * The screen and the server are asserted against each other rather than separately. The
 * running total in registration.blade.php adds data-addon-once once per add-on and then
 * data-price times each quantity; the figures it reads are re-derived here from the markup
 * and compared with what was actually charged, because a mismatch between those two is
 * precisely how money goes missing.
 */
class PerParticipantAddonChargeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Nothing in here has anything to prove about mail, and a registration
        // raises several.
        Mail::fake();
    }

    private function event(array $overrides = []): Event
    {
        return Event::create($overrides + [
            'slug' => 'hsn-' . uniqid(),
            'title' => 'Hari Sukan Negara',
            'category' => 'Community',
            'starts_at' => now()->addWeek()->toDateString(),
            'ends_at' => now()->addWeek()->toDateString(),
            'status' => Event::STATUS_OPEN,
            'registration_mode' => Event::MODE_GROUPING,
            // Free to enter, which is the live case: the shirt is the only money.
            'fee' => null,
            'seats_total' => 0,
            'min_players' => 1,
            'max_players' => 20,
        ]);
    }

    /**
     * A compulsory RM40 shirt as a radio group: S free, M adds RM5.
     *
     * @return array{0: EventAddon, 1: EventAddonVariant, 2: EventAddonVariant}
     */
    private function tee(Event $event, array $overrides = []): array
    {
        $addon = EventAddon::create($overrides + [
            'event_id' => $event->id,
            'name' => 'HSN EVENT TEE',
            'price' => 40,
            'is_required' => true,
            'is_active' => true,
            'selection_type' => EventAddon::SELECTION_RADIO,
        ]);

        $small = EventAddonVariant::create([
            'event_addon_id' => $addon->id,
            'label' => 'S',
            'price' => 0,
            'stock' => 50,
            'sort_order' => 1,
        ]);

        $medium = EventAddonVariant::create([
            'event_addon_id' => $addon->id,
            'label' => 'M',
            'price' => 5,
            'stock' => 50,
            'sort_order' => 2,
        ]);

        return [$addon->fresh(), $small, $medium];
    }

    private function person(int $n, array $overrides = []): array
    {
        return $overrides + [
            'role' => ParticipantOptions::ROLE_PARTICIPANT,
            'full_name' => 'Member ' . $n,
            'ic_number' => '900101' . str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'address_line_1' => $n . ' Jalan Sibu',
            'city' => 'Sibu',
            'state' => 'Sarawak',
            'country' => 'Malaysia',
            'phone' => '01400000' . $n,
            'email' => 'member' . $n . '@example.test',
            'gender' => 'male',
            'race' => 'malay',
        ];
    }

    private function submit(Event $event, array $payload)
    {
        return $this->post(route('registration.store', ['event' => $event->slug]), $payload);
    }

    /* ---------------------------------------------------------------------
     | Off, which is every event that already exists
     * ------------------------------------------------------------------ */

    public function test_a_fresh_event_charges_add_ons_once_for_the_whole_group(): void
    {
        // Built without mentioning the key, exactly as every row stored before
        // the column existed.
        $event = $this->event()->fresh();

        $this->assertFalse($event->charges_addons_per_participant);
        $this->assertFalse($event->chargesAddonsPerParticipant());
    }

    /**
     * The must-not-change assertion. Three people, one RM40 shirt charge.
     */
    public function test_three_participants_are_charged_one_add_on_price_between_them_by_default(): void
    {
        $event = $this->event();
        [$addon, $small] = $this->tee($event);

        $this->submit($event, [
            'team_name' => 'The Rahmans',
            'participants' => [
                $this->person(1) + ['addons' => [$addon->id => (string) $small->id]],
                $this->person(2) + ['addons' => [$addon->id => (string) $small->id]],
                $this->person(3) + ['addons' => [$addon->id => (string) $small->id]],
            ],
        ])->assertSessionHasNoErrors();

        $registration = EventRegistration::query()->sole();

        $this->assertSame('0.00', $registration->registration_fee);
        $this->assertSame('40.00', $registration->addons_total);
        $this->assertSame('40.00', $registration->amount);

        // One group charge carrying no participant, plus a free line per person
        // recording the size they asked for.
        $lines = EventRegistrationAddon::query()->orderBy('id')->get();

        $this->assertCount(4, $lines);
        $this->assertSame(
            1,
            $lines->whereNull('event_addon_variant_id')->count(),
            'The add-on price must still be one line for the whole registration.',
        );
        $this->assertSame(3, $small->fresh()->stock_taken);
    }

    /* ---------------------------------------------------------------------
     | On
     * ------------------------------------------------------------------ */

    public function test_each_participant_is_charged_their_own_add_on_when_the_setting_is_on(): void
    {
        $event = $this->event(['charges_addons_per_participant' => true]);
        [$addon, $small, $medium] = $this->tee($event);

        $this->assertTrue($event->chargesAddonsPerParticipant());

        $this->submit($event, [
            'team_name' => 'The Rahmans',
            'participants' => [
                $this->person(1) + ['addons' => [$addon->id => (string) $small->id]],
                $this->person(2) + ['addons' => [$addon->id => (string) $small->id]],
                $this->person(3) + ['addons' => [$addon->id => (string) $small->id]],
            ],
        ])->assertSessionHasNoErrors();

        $registration = EventRegistration::query()->sole();

        // RM40 each, three times, and the free event fee is still nothing.
        $this->assertSame('0.00', $registration->registration_fee);
        $this->assertSame('120.00', $registration->addons_total);
        $this->assertSame('120.00', $registration->amount);

        $lines = EventRegistrationAddon::query()->orderBy('id')->get();

        // One line per person, and no registration-level charge left over: the
        // add-on price now lives on each person's own line.
        $this->assertCount(3, $lines);
        $this->assertSame(0, $lines->whereNull('event_addon_variant_id')->count());

        $people = $registration->participants()->orderBy('id')->get();

        foreach ($lines as $position => $line) {
            $this->assertSame($people[$position]->id, $line->event_participant_id);
            $this->assertSame($small->id, $line->event_addon_variant_id);
            $this->assertSame('S', $line->variant_label);
            $this->assertSame('HSN EVENT TEE', $line->name);
            $this->assertSame(1, $line->quantity);
            $this->assertSame('40.00', $line->unit_price);
            $this->assertSame('40.00', $line->line_total);
        }

        // One shirt per person off the size each of them chose.
        $this->assertSame(3, $small->fresh()->stock_taken);
        $this->assertSame(0, $medium->fresh()->stock_taken);
    }

    public function test_a_size_that_costs_more_adds_to_that_persons_own_charge(): void
    {
        $event = $this->event(['charges_addons_per_participant' => true]);
        [$addon, $small, $medium] = $this->tee($event);

        $this->submit($event, [
            'team_name' => 'The Rahmans',
            'participants' => [
                $this->person(1) + ['addons' => [$addon->id => (string) $small->id]],
                $this->person(2) + ['addons' => [$addon->id => (string) $medium->id]],
                $this->person(3) + ['addons' => [$addon->id => (string) $medium->id]],
            ],
        ])->assertSessionHasNoErrors();

        // RM40 + RM45 + RM45.
        $this->assertSame('130.00', EventRegistration::query()->sole()->addons_total);

        $this->assertSame(1, $small->fresh()->stock_taken);
        $this->assertSame(2, $medium->fresh()->stock_taken);
    }

    public function test_a_quantity_item_charges_the_add_on_price_per_unit_each_person_takes(): void
    {
        $event = $this->event(['charges_addons_per_participant' => true]);
        [$addon, $small, $medium] = $this->tee($event, [
            'selection_type' => EventAddon::SELECTION_QUANTITY,
        ]);

        $order = AddonOrder::build($event->load('addons.variants'), null, [
            ['addons' => [$addon->id => [$medium->id => 2]]],
            ['addons' => [$addon->id => [$small->id => 1]]],
        ]);

        $this->assertTrue($order->isValid());

        // Two mediums at RM45 for the first person, one small at RM40 for the
        // second. Every unit is a shirt somebody takes away.
        $this->assertSame(130.0, $order->total());
        $this->assertSame([$medium->id => 2, $small->id => 1], $order->variantQuantities());
    }

    /* ---------------------------------------------------------------------
     | The flat event fee is not touched by any of this
     * ------------------------------------------------------------------ */

    public function test_the_event_fee_is_charged_once_whichever_way_add_ons_are_charged(): void
    {
        foreach ([false, true] as $perParticipant) {
            $event = $this->event([
                'fee' => 10,
                'charges_addons_per_participant' => $perParticipant,
            ]);

            [$addon, $small] = $this->tee($event);

            $order = AddonOrder::build($event->load('addons.variants'), null, [
                ['addons' => [$addon->id => (string) $small->id]],
                ['addons' => [$addon->id => (string) $small->id]],
                ['addons' => [$addon->id => (string) $small->id]],
            ]);

            $this->assertTrue($order->isValid());

            // The fee is one flat charge for the registration either way; only the
            // add-on part of the total moves.
            $this->assertSame(10.0, $event->registrationAmount());
            $this->assertSame($perParticipant ? 120.0 : 40.0, $order->total());
        }
    }

    /* ---------------------------------------------------------------------
     | The screen and the server have to agree
     * ------------------------------------------------------------------ */

    /**
     * The total the running total in registration.blade.php would show.
     *
     * Built the way that JavaScript builds it, from the same attributes: the
     * registration fee, plus each add-on's data-addon-once a single time when
     * anything in it is taken, plus data-price times every chosen quantity. The
     * chosen option is named per person so the arithmetic is driven by the markup
     * rather than by a figure this test made up.
     *
     * @param  array<int, int>  $chosenVariantIds  participant position => variant id
     */
    private function screenTotal(string $html, int $addonId, array $chosenVariantIds): float
    {
        $fee = (float) $this->attribute($html, '/data-registration-fee="([0-9.]+)"/');

        $cards = $this->personAddonCards($html, $addonId);
        $cents = (int) round($fee * 100);

        // data-addon-once is charged a single time across every card of one add-on.
        $once = (int) round(((float) $this->attribute($cards[0], '/data-addon-once="([0-9.]+)"/')) * 100);

        if ($once > 0 && $chosenVariantIds !== []) {
            $cents += $once;
        }

        foreach ($chosenVariantIds as $position => $variantId) {
            preg_match(
                '/<input[^>]*value="' . $variantId . '"[^>]*data-price="([0-9.]+)"[^>]*>/',
                $cards[$position],
                $match,
            );

            $this->assertNotEmpty($match, 'The form should price that option inside that person\'s card.');

            $cents += (int) round(((float) $match[1]) * 100);
        }

        return round($cents / 100, 2);
    }

    private function attribute(string $html, string $pattern): string
    {
        preg_match($pattern, $html, $match);

        $this->assertNotEmpty($match, 'Expected the form to carry ' . $pattern);

        return $match[1];
    }

    /**
     * The per-person add-on fieldset from each participant card, in order.
     *
     * The template row the Add button clones carries __INDEX__ in its input
     * names, so it is skipped: it is markup for a person who is not on the form.
     *
     * @return array<int, string>
     */
    private function personAddonCards(string $html, int $addonId): array
    {
        preg_match_all('/<fieldset[^>]*data-person-addon.*?<\/fieldset>/s', $html, $matches);

        return array_values(array_filter(
            $matches[0],
            fn (string $card) => str_contains($card, '[addons][' . $addonId . ']')
                && ! str_contains($card, '__INDEX__'),
        ));
    }

    public function test_the_running_total_on_screen_matches_what_a_three_person_group_is_charged(): void
    {
        $event = $this->event(['charges_addons_per_participant' => true, 'min_players' => 3]);
        [$addon, $small, $medium] = $this->tee($event);

        $page = $this->get(route('registration', ['register' => $event->slug]));
        $page->assertOk();

        // Person 1 takes S, persons 2 and 3 take M, which is what the submission
        // below sends.
        $chosen = [0 => $small->id, 1 => $medium->id, 2 => $medium->id];

        $screen = $this->screenTotal($page->getContent(), $addon->id, $chosen);

        $this->submit($event, [
            'team_name' => 'The Rahmans',
            'participants' => [
                $this->person(1) + ['addons' => [$addon->id => (string) $small->id]],
                $this->person(2) + ['addons' => [$addon->id => (string) $medium->id]],
                $this->person(3) + ['addons' => [$addon->id => (string) $medium->id]],
            ],
        ])->assertSessionHasNoErrors();

        $registration = EventRegistration::query()->sole();

        // RM40 + RM45 + RM45, said twice: once by the markup, once by the money.
        $this->assertSame('130.00', $registration->amount);
        $this->assertSame(130.0, $screen);
        $this->assertSame($screen, (float) $registration->amount);
    }

    public function test_the_running_total_on_screen_still_matches_when_the_setting_is_off(): void
    {
        $event = $this->event(['min_players' => 3]);
        [$addon, $small, $medium] = $this->tee($event);

        $page = $this->get(route('registration', ['register' => $event->slug]));
        $page->assertOk();

        $screen = $this->screenTotal($page->getContent(), $addon->id, [
            0 => $small->id,
            1 => $medium->id,
            2 => $medium->id,
        ]);

        $this->submit($event, [
            'team_name' => 'The Rahmans',
            'participants' => [
                $this->person(1) + ['addons' => [$addon->id => (string) $small->id]],
                $this->person(2) + ['addons' => [$addon->id => (string) $medium->id]],
                $this->person(3) + ['addons' => [$addon->id => (string) $medium->id]],
            ],
        ])->assertSessionHasNoErrors();

        // One RM40 shirt charge for the group plus two RM5 surcharges.
        $this->assertSame('50.00', EventRegistration::query()->sole()->amount);
        $this->assertSame(50.0, $screen);
    }

    /* ---------------------------------------------------------------------
     | Modes the setting must not reach
     * ------------------------------------------------------------------ */

    public function test_individual_mode_is_untouched_even_with_the_column_set(): void
    {
        $event = $this->event([
            'registration_mode' => Event::MODE_INDIVIDUAL,
            'fee' => 10,
            'charges_addons_per_participant' => true,
            'min_players' => null,
            'max_players' => null,
        ]);

        [$addon, $small, $medium] = $this->tee($event);

        $this->assertFalse($event->chargesAddonsPerParticipant());

        // A registration-level radio choice, which is what individual mode draws.
        $this->submit($event, [
            'participants' => [$this->person(1)],
            'addons' => [$addon->id => ['choice' => (string) $medium->id]],
        ])->assertSessionHasNoErrors();

        $registration = EventRegistration::query()->sole();

        // RM40 for the shirt plus RM5 because M costs more, exactly as before.
        $this->assertSame('45.00', $registration->addons_total);
        $this->assertSame('55.00', $registration->amount);
    }

    public function test_manager_mode_is_untouched_even_with_the_column_set(): void
    {
        $event = $this->event([
            'registration_mode' => Event::MODE_MANAGER,
            'charges_addons_per_participant' => true,
            'min_players' => 2,
        ]);

        // Asked of each player in their own card, which manager mode reaches
        // through the add-on's own per_participant flag.
        [$addon, $small] = $this->tee($event, ['per_participant' => true]);

        $this->assertFalse($event->chargesAddonsPerParticipant());

        $order = AddonOrder::build($event->load('addons.variants'), null, [
            ['addons' => [$addon->id => (string) $small->id]],
            ['addons' => [$addon->id => (string) $small->id]],
            ['addons' => [$addon->id => (string) $small->id]],
        ]);

        $this->assertTrue($order->isValid());

        // One shirt charge for the squad: the setting is grouping only.
        $this->assertSame(40.0, $order->total());
    }

    /* ---------------------------------------------------------------------
     | The admin control
     * ------------------------------------------------------------------ */

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
     * @return array<string, mixed>
     */
    private function eventForm(array $overrides = []): array
    {
        return $overrides + [
            'title' => 'Admin Made Event',
            'category' => 'Community',
            'starts_at' => now()->addWeek()->toDateString(),
            'ends_at' => now()->addWeek()->toDateString(),
            'location' => 'Sibu',
            'seats_total' => 0,
            'status' => Event::STATUS_DRAFT,
            'registration_mode' => Event::MODE_GROUPING,
            'min_players' => 3,
            'max_players' => 10,
        ];
    }

    /**
     * The admin checkbox drawn in the ticked state.
     *
     * A regex because @checked sits on its own line in form.blade.php, so any
     * literal spelling of it next to value="1" could never match.
     */
    private const TICKED_BOX = '/<input[^>]*\bid="charges_addons_per_participant"[^>]*\bchecked\b[^>]*>/';

    public function test_the_create_screen_offers_the_control_unticked_and_hidden_until_grouping(): void
    {
        $response = $this->actingAs($this->administrator())
            ->get(route('admin.event.registration.create'));

        $response->assertOk();
        $response->assertSee('id="charges_addons_per_participant"', false);
        $response->assertSee('Each participant is charged for their own add-ons');
        $response->assertSee('Grouping only. Ten participants choosing a RM 40.00 shirt pay RM 400.00, not RM 40.00.');

        // Unticked: a new event charges the add-on once, as every existing one does.
        $this->assertDoesNotMatchRegularExpression(self::TICKED_BOX, $response->getContent());

        // Hidden until Grouping is chosen, because the form opens on Individual.
        $this->assertMatchesRegularExpression(
            '/id="addon-charge-basis" class="hidden"/',
            $response->getContent(),
        );
    }

    public function test_the_control_round_trips_through_the_admin_save_path(): void
    {
        $admin = $this->administrator();

        // Saved with no mention of the key, which is the column default deciding
        // rather than an absent box reading as false.
        $this->actingAs($admin)
            ->post(route('admin.event.registration.store'), $this->eventForm())
            ->assertSessionHasNoErrors();

        $event = Event::query()->sole();

        $this->assertFalse($event->charges_addons_per_participant);

        // On...
        $this->actingAs($admin)
            ->put(route('admin.event.registration.update', $event), $this->eventForm([
                'charges_addons_per_participant' => '1',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertTrue($event->fresh()->charges_addons_per_participant);

        // ...and the edit form comes back ticked rather than resetting.
        $response = $this->actingAs($admin)
            ->get(route('admin.event.registration.edit', $event->fresh()));

        $response->assertOk();
        $this->assertMatchesRegularExpression(self::TICKED_BOX, $response->getContent());

        // ...and off again, because a pricing setting that cannot be undone is a trap.
        $this->actingAs($admin)
            ->put(route('admin.event.registration.update', $event), $this->eventForm([
                'charges_addons_per_participant' => '0',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertFalse($event->fresh()->charges_addons_per_participant);
    }
}
