<?php

namespace Tests\Feature\Registration;

use App\Models\Event;
use App\Models\EventAddon;
use App\Models\EventAddonVariant;
use App\Models\EventParticipant;
use App\Models\EventRegistration;
use App\Models\EventRegistrationAddon;
use App\Models\Role;
use App\Models\User;
use App\Support\ParticipantOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Marking an add-on as a thing somebody physically takes away on the day.
 *
 * event_addons already said what an extra costs, who it is asked of and how it is
 * chosen. It said nothing about whether anything leaves a table and ends up in a
 * pair of hands, so a collection screen had no way to tell a shirt from an
 * insurance line and would have listed both.
 *
 * is_handed_over is that one answer. It is additive and defaults to false, and the
 * tests that matter most here are the negative ones: an add-on that has never heard
 * of the column must behave exactly as it does today, and ticking the box on a live
 * event must not move a sen or a unit of stock. Where and when it is handed over is
 * deliberately not stored — the add-on belongs to an event and the event already
 * carries the date, time, location and address.
 */
class AddonHandedOverTest extends TestCase
{
    use RefreshDatabase;

    /** An input tag for the flag, drawn ticked. A regex because @checked sits on its own line. */
    private const TICKED_BOX = '/<input[^>]*\[is_handed_over\][^>]*\bchecked\b[^>]*>/';

    private const LABEL = 'Handed over at the event';

    private const HELPER = 'Appears on the collection list and tracks who has taken theirs. The place and date come from the event itself.';

    protected function setUp(): void
    {
        parent::setUp();

        // Nothing here has anything to prove about mail.
        Mail::fake();
    }

    /* ---------------------------------------------------------------------
     | Fixtures
     * ------------------------------------------------------------------ */

    private function event(array $overrides = []): Event
    {
        return Event::create($overrides + [
            'slug' => 'collect-' . uniqid(),
            'title' => 'Kejohanan Bola Sepak',
            'category' => 'Sports',
            'starts_at' => now()->addWeek()->toDateString(),
            'ends_at' => now()->addWeek()->toDateString(),
            'status' => Event::STATUS_OPEN,
            'registration_mode' => Event::MODE_GROUPING,
            'location' => 'Sibu',
            'fee' => null,
            'seats_total' => 0,
            'min_players' => 1,
            'max_players' => 20,
        ]);
    }

    /**
     * A compulsory RM40 shirt in two sizes, both inheriting the add-on price so
     * nothing in here depends on a surcharge.
     *
     * @return array{0: EventAddon, 1: EventAddonVariant, 2: EventAddonVariant}
     */
    private function tee(Event $event, array $overrides = []): array
    {
        $addon = EventAddon::create($overrides + [
            'event_id' => $event->id,
            'name' => 'EVENT TEE',
            'price' => 40,
            'is_required' => true,
            'is_active' => true,
            'selection_type' => EventAddon::SELECTION_RADIO,
        ]);

        $small = EventAddonVariant::create([
            'event_addon_id' => $addon->id,
            'label' => 'S',
            'price' => null,
            'stock' => 50,
            'sort_order' => 1,
        ]);

        $medium = EventAddonVariant::create([
            'event_addon_id' => $addon->id,
            'label' => 'M',
            'price' => null,
            'stock' => 50,
            'sort_order' => 2,
        ]);

        return [$addon->fresh(), $small, $medium];
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
     * The event form, carrying the event back as it stands so a save about the
     * add-on flag changes nothing about the event itself.
     *
     * @return array<string, mixed>
     */
    private function eventForm(Event $event, array $overrides = []): array
    {
        return $overrides + [
            'title' => $event->title,
            'category' => $event->category,
            'starts_at' => $event->starts_at->toDateString(),
            'ends_at' => $event->ends_at->toDateString(),
            'location' => $event->location,
            'seats_total' => $event->seats_total,
            'status' => $event->status,
            'registration_mode' => $event->registration_mode,
            'min_players' => $event->min_players,
            'max_players' => $event->max_players,
        ];
    }

    /**
     * One add-on row as the builder posts it, options and all.
     *
     * Option prices go back as blank, which is how they are stored: every size
     * charges the add-on price. Stock goes back at its stored figure, which the
     * request refuses to see lowered below what has been ordered.
     *
     * @param  array<int, EventAddonVariant>  $variants
     * @return array<int, array<string, mixed>>
     */
    private function addonRows(EventAddon $addon, array $variants, array $overrides = []): array
    {
        return [
            $overrides + [
                'id' => (string) $addon->id,
                'name' => $addon->name,
                'price' => '40',
                'is_required' => '1',
                'is_active' => '1',
                'selection_type' => $addon->selection_type,
                'variants' => collect($variants)
                    ->map(fn (EventAddonVariant $variant) => [
                        'id' => (string) $variant->id,
                        'label' => $variant->label,
                        'price' => '',
                        'stock' => (string) $variant->stock,
                    ])
                    ->all(),
            ],
        ];
    }

    /* ---------------------------------------------------------------------
     | The default, which is every add-on already on the system
     * ------------------------------------------------------------------ */

    public function test_an_addon_created_without_the_setting_is_not_handed_over(): void
    {
        $event = $this->event();

        // Built without mentioning the key, exactly as every row stored before the
        // column existed.
        $addon = EventAddon::create([
            'event_id' => $event->id,
            'name' => 'Insurance',
            'price' => 5,
            'is_active' => true,
        ])->fresh();

        $this->assertFalse($addon->is_handed_over);
        $this->assertFalse($addon->isHandedOver());
    }

    public function test_the_helper_answers_true_once_the_flag_is_set(): void
    {
        $event = $this->event();

        $shirt = EventAddon::create([
            'event_id' => $event->id,
            'name' => 'EVENT TEE',
            'price' => 40,
            'is_active' => true,
            'is_handed_over' => true,
        ])->fresh();

        // Cast, not a loose truthy string from the driver.
        $this->assertTrue($shirt->is_handed_over);
        $this->assertTrue($shirt->isHandedOver());

        // Withdrawing an add-on stops new sales; it does not unsell the shirts
        // already paid for, which still have to be handed over.
        $shirt->update(['is_active' => false]);

        $this->assertTrue($shirt->fresh()->isHandedOver());
    }

    /* ---------------------------------------------------------------------
     | The admin control
     * ------------------------------------------------------------------ */

    public function test_the_create_screen_offers_the_box_unticked_with_its_helper_copy(): void
    {
        $response = $this->actingAs($this->administrator())
            ->get(route('admin.event.registration.create'));

        $response->assertOk();
        $response->assertSee('name="addons[__INDEX__][is_handed_over]"', false);
        $response->assertSee(self::LABEL);
        $response->assertSee(self::HELPER);

        // Unticked, so a new add-on is not treated as collectable by surprise.
        $this->assertDoesNotMatchRegularExpression(self::TICKED_BOX, $response->getContent());
    }

    public function test_the_box_round_trips_ticked_and_unticked_through_the_admin_save_path(): void
    {
        $admin = $this->administrator();
        $event = $this->event();
        [$addon, $small, $medium] = $this->tee($event);

        $this->assertFalse($addon->isHandedOver());

        // Ticked.
        $this->actingAs($admin)
            ->put(route('admin.event.registration.update', $event), $this->eventForm($event, [
                'addons' => $this->addonRows($addon, [$small, $medium], ['is_handed_over' => '1']),
            ]))
            ->assertSessionHasNoErrors();

        $this->assertTrue($addon->fresh()->isHandedOver());

        // ...and the edit form comes back ticked rather than resetting.
        $response = $this->actingAs($admin)
            ->get(route('admin.event.registration.edit', $event->fresh()));

        $response->assertOk();
        $this->assertMatchesRegularExpression(self::TICKED_BOX, $response->getContent());

        // Unticked again, because a setting that cannot be undone is a trap.
        $this->actingAs($admin)
            ->put(route('admin.event.registration.update', $event), $this->eventForm($event, [
                'addons' => $this->addonRows($addon, [$small, $medium], ['is_handed_over' => '0']),
            ]))
            ->assertSessionHasNoErrors();

        $this->assertFalse($addon->fresh()->isHandedOver());

        $reopened = $this->actingAs($admin)
            ->get(route('admin.event.registration.edit', $event->fresh()));

        $reopened->assertOk();
        $this->assertDoesNotMatchRegularExpression(self::TICKED_BOX, $reopened->getContent());
    }

    public function test_a_new_addon_saved_with_the_box_ticked_keeps_it(): void
    {
        $admin = $this->administrator();
        $event = $this->event();

        $this->actingAs($admin)
            ->put(route('admin.event.registration.update', $event), $this->eventForm($event, [
                'addons' => [
                    [
                        'name' => 'EVENT TEE',
                        'price' => '40',
                        'is_active' => '1',
                        'is_handed_over' => '1',
                        'selection_type' => EventAddon::SELECTION_QUANTITY,
                        'variants' => [
                            ['label' => 'S', 'price' => '', 'stock' => '10'],
                        ],
                    ],
                    [
                        'name' => 'Insurance',
                        'price' => '5',
                        'is_active' => '1',
                        'selection_type' => EventAddon::SELECTION_QUANTITY,
                    ],
                ],
            ]))
            ->assertSessionHasNoErrors();

        $addons = $event->fresh()->addons()->orderBy('sort_order')->get();

        $this->assertCount(2, $addons);

        // The shirt is collected; the insurance line has nothing to hand over,
        // which is the distinction the column exists for.
        $this->assertTrue($addons[0]->isHandedOver());
        $this->assertFalse($addons[1]->isHandedOver());
    }

    /* ---------------------------------------------------------------------
     | A payload that never drew the box
     * ------------------------------------------------------------------ */

    public function test_an_absent_key_leaves_a_stored_true_alone(): void
    {
        $event = $this->event();
        [$addon, $small, $medium] = $this->tee($event, ['is_handed_over' => true]);

        $this->assertTrue($addon->isHandedOver());

        // The key is simply not sent, which is what a partial update reusing this
        // request does. Reading that as false would drop a shirt already being
        // collected off the list.
        $rows = $this->addonRows($addon, [$small, $medium]);

        $this->assertArrayNotHasKey('is_handed_over', $rows[0]);

        $this->actingAs($this->administrator())
            ->put(route('admin.event.registration.update', $event), $this->eventForm($event, [
                'addons' => $rows,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertTrue($addon->fresh()->isHandedOver());
    }

    /* ---------------------------------------------------------------------
     | The negative assertion: no money and no stock moves
     * ------------------------------------------------------------------ */

    public function test_ticking_it_moves_no_money_and_no_stock_on_an_existing_registration(): void
    {
        $event = $this->event();
        [$addon, $small, $medium] = $this->tee($event);

        $registration = EventRegistration::create([
            'event_id' => $event->id,
            'reference' => 'REG-2026-0001',
            'mode' => $event->registration_mode,
            'team_name' => 'Pasukan Satu',
            'status' => EventRegistration::STATUS_CONFIRMED,
            'payment_status' => EventRegistration::PAYMENT_PAID,
            'registration_fee' => 0,
            'addons_total' => 40,
            'amount' => 40,
            'amount_paid' => 40,
        ]);

        $person = EventParticipant::create([
            'event_registration_id' => $registration->id,
            'role' => ParticipantOptions::ROLE_MANAGER,
            'full_name' => 'Peserta Satu',
            'ic_number' => '900101010001',
            'phone' => '0128508124',
            'email' => 'peserta1@example.test',
            'gender' => 'male',
            'race' => 'malay',
        ]);

        $line = EventRegistrationAddon::create([
            'event_registration_id' => $registration->id,
            'event_participant_id' => $person->id,
            'event_addon_id' => $addon->id,
            'event_addon_variant_id' => $small->id,
            'name' => $addon->name,
            'variant_label' => $small->label,
            'unit_price' => 40,
            'quantity' => 1,
            'line_total' => 40,
        ]);

        $small->increment('stock_taken');

        // The figures as they stand, read back from the database rather than from
        // the numbers typed above.
        $before = [
            'registration' => $registration->fresh()->only([
                'registration_fee', 'addons_total', 'amount', 'amount_paid', 'payment_status', 'status',
            ]),
            'line' => $line->fresh()->only(['unit_price', 'quantity', 'line_total', 'event_addon_variant_id']),
            'addon_price' => $addon->fresh()->price,
            'small' => $small->fresh()->only(['price', 'stock', 'stock_taken']),
            'medium' => $medium->fresh()->only(['price', 'stock', 'stock_taken']),
            'fee' => $event->fresh()->fee,
        ];

        $this->actingAs($this->administrator())
            ->put(route('admin.event.registration.update', $event), $this->eventForm($event, [
                'addons' => $this->addonRows($addon, [$small, $medium], ['is_handed_over' => '1']),
            ]))
            ->assertSessionHasNoErrors();

        // The flag is the only thing that moved.
        $this->assertTrue($addon->fresh()->isHandedOver());

        $this->assertSame($before['registration'], $registration->fresh()->only([
            'registration_fee', 'addons_total', 'amount', 'amount_paid', 'payment_status', 'status',
        ]));

        $this->assertSame($before['line'], $line->fresh()->only([
            'unit_price', 'quantity', 'line_total', 'event_addon_variant_id',
        ]));

        $this->assertSame($before['addon_price'], $addon->fresh()->price);
        $this->assertSame($before['small'], $small->fresh()->only(['price', 'stock', 'stock_taken']));
        $this->assertSame($before['medium'], $medium->fresh()->only(['price', 'stock', 'stock_taken']));
        $this->assertSame($before['fee'], $event->fresh()->fee);

        // Said again plainly, because these four are the ones that would be
        // noticed last and hurt most.
        $fresh = $registration->fresh();

        $this->assertSame('40.00', $fresh->amount);
        $this->assertSame('40.00', $fresh->amount_paid);
        $this->assertSame(EventRegistration::PAYMENT_PAID, $fresh->payment_status);
        $this->assertSame(1, $small->fresh()->stock_taken);
    }
}
