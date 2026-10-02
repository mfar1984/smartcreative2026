<?php

namespace Tests\Feature\Registration;

use App\Models\Event;
use App\Models\EventAddon;
use App\Models\EventAddonVariant;
use App\Models\EventRegistration;
use App\Models\EventRegistrationAddon;
use App\Support\ParticipantOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * How an add-on's options are collected: as quantities, or as one radio choice.
 *
 * The quantity assertions matter at least as much as the radio ones. Radio is an
 * opt-in setting on a single add-on, and the whole case for it resting on one
 * column with a 'quantity' default is that nothing which has already been sold
 * changes. If a future edit makes an untouched add-on render or price
 * differently, the quantity tests here are what break.
 *
 * A radio choice is deliberately stored in the same shape as a quantity of one,
 * so stock, per-registration caps, pricing, totals and the exports never learn
 * that the setting exists.
 */
class AddonSelectionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An individual event with a RM10 fee, open for registration.
     */
    private function event(array $overrides = []): Event
    {
        return Event::create($overrides + [
            'slug' => 'tee-event-' . uniqid(),
            'title' => 'Fun Run',
            'category' => 'Sports',
            'starts_at' => now()->addWeek()->toDateString(),
            'ends_at' => now()->addWeek()->toDateString(),
            'status' => Event::STATUS_OPEN,
            'registration_mode' => Event::MODE_INDIVIDUAL,
            'fee' => 10,
            'seats_total' => 100,
        ]);
    }

    /**
     * "Event Tee" at RM50 with a free S and an M that adds RM5, ten of each.
     *
     * @return array{0: EventAddon, 1: EventAddonVariant, 2: EventAddonVariant}
     */
    private function tee(Event $event, array $overrides = []): array
    {
        $addon = EventAddon::create($overrides + [
            'event_id' => $event->id,
            'name' => 'Event Tee',
            'price' => 50,
            'is_active' => true,
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

    /**
     * One complete person, as the public form posts them.
     */
    private function person(int $n = 1, array $overrides = []): array
    {
        return $overrides + [
            'role' => ParticipantOptions::ROLE_PARTICIPANT,
            'full_name' => 'Runner ' . $n,
            'ic_number' => '900101' . str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'address_line_1' => $n . ' Jalan Satu',
            'city' => 'Kuala Lumpur',
            'state' => 'W.P. Kuala Lumpur',
            'country' => 'Malaysia',
            'phone' => '01100000' . $n,
            'email' => 'runner' . $n . '@example.test',
            'gender' => 'male',
            'race' => 'malay',
        ];
    }

    private function submit(Event $event, array $payload)
    {
        return $this->post(route('registration.store', ['event' => $event->slug]), $payload);
    }

    /* ---------------------------------------------------------------------
     | The default, which is what every add-on already on the system has
     * ------------------------------------------------------------------ */

    public function test_an_addon_saved_without_the_setting_is_a_quantity_addon(): void
    {
        $event = $this->event();

        $addon = EventAddon::create([
            'event_id' => $event->id,
            'name' => 'Banquet Seat',
            'price' => 20,
            'is_active' => true,
        ]);

        $this->assertSame(EventAddon::SELECTION_QUANTITY, $addon->fresh()->selection_type);
        $this->assertTrue($addon->fresh()->isQuantitySelection());
        $this->assertFalse($addon->fresh()->isRadioSelection());
    }

    public function test_a_quantity_addon_renders_number_inputs_on_the_public_form(): void
    {
        $event = $this->event();
        [$addon, $small, $medium] = $this->tee($event);

        $response = $this->get(route('registration', ['register' => $event->slug]));

        $response->assertOk();

        // One number box per option, which is the markup that exists today.
        $response->assertSee('name="addons[' . $addon->id . '][' . $small->id . ']"', false);
        $response->assertSee('name="addons[' . $addon->id . '][' . $medium->id . ']"', false);
        $response->assertSee('data-addon-qty', false);

        // And no radio group anywhere near it.
        $response->assertDontSee('name="addons[' . $addon->id . '][choice]"', false);
    }

    /**
     * The "must not change" case: quantities priced and stocked exactly as before.
     */
    public function test_a_quantity_addon_prices_and_stocks_as_it_always_did(): void
    {
        $event = $this->event();
        [$addon, $small, $medium] = $this->tee($event);

        $this->submit($event, [
            'participants' => [$this->person()],
            'addons' => [
                $addon->id => [$small->id => 0, $medium->id => 2],
            ],
        ])->assertSessionHasNoErrors();

        $registration = EventRegistration::query()->sole();

        $this->assertSame('10.00', $registration->registration_fee);
        // The add-on's own RM50 once, plus RM5 of surcharge twice.
        $this->assertSame('60.00', $registration->addons_total);
        $this->assertSame('70.00', $registration->amount);

        $lines = EventRegistrationAddon::query()->orderBy('id')->get();

        $this->assertCount(2, $lines);

        $this->assertNull($lines[0]->event_addon_variant_id);
        $this->assertSame(1, $lines[0]->quantity);
        $this->assertSame('50.00', $lines[0]->unit_price);

        $this->assertSame($medium->id, $lines[1]->event_addon_variant_id);
        $this->assertSame(2, $lines[1]->quantity);
        $this->assertSame('5.00', $lines[1]->unit_price);

        $this->assertSame(2, $medium->fresh()->stock_taken);
        $this->assertSame(0, $small->fresh()->stock_taken);
    }

    /* ---------------------------------------------------------------------
     | Radio
     * ------------------------------------------------------------------ */

    public function test_a_radio_addon_renders_one_radio_group_on_the_public_form(): void
    {
        $event = $this->event();
        [$addon, $small, $medium] = $this->tee($event, [
            'selection_type' => EventAddon::SELECTION_RADIO,
        ]);

        $response = $this->get(route('registration', ['register' => $event->slug]));

        $response->assertOk();

        // One group, so one shared name, and no quantity boxes for this add-on.
        $response->assertSee('name="addons[' . $addon->id . '][choice]"', false);
        $response->assertSee('value="' . $small->id . '"', false);
        $response->assertSee('value="' . $medium->id . '"', false);
        $response->assertDontSee('name="addons[' . $addon->id . '][' . $medium->id . ']"', false);

        // The surcharge is still surfaced per option.
        $response->assertSee('+RM 5.00');
    }

    public function test_a_sold_out_radio_option_is_shown_disabled_rather_than_hidden(): void
    {
        $event = $this->event();
        [$addon, $small, $medium] = $this->tee($event, [
            'selection_type' => EventAddon::SELECTION_RADIO,
        ]);

        $medium->update(['stock_taken' => 10]);

        $response = $this->get(route('registration', ['register' => $event->slug]));

        $response->assertOk();
        // Still listed, and said to be gone, so nobody hunts for a missing size.
        $response->assertSee('value="' . $medium->id . '"', false);
        $response->assertSee('Sold out');

        // The M radio itself carries disabled, and the in-stock S does not.
        $this->assertTrue(
            $this->radioIsDisabled($response->getContent(), $addon->id, $medium->id),
            'The sold-out option should render as a disabled radio.',
        );
        $this->assertFalse(
            $this->radioIsDisabled($response->getContent(), $addon->id, $small->id),
            'An option still in stock must stay selectable.',
        );
    }

    /**
     * Whether the radio for one option of one add-on is rendered disabled.
     */
    private function radioIsDisabled(string $html, int $addonId, int $variantId): bool
    {
        foreach ($this->radioInputs($html, $addonId) as $input) {
            if (str_contains($input, 'value="' . $variantId . '"')) {
                return str_contains($input, 'disabled');
            }
        }

        return false;
    }

    /**
     * Every radio input tag belonging to one add-on's group.
     *
     * @return array<int, string>
     */
    private function radioInputs(string $html, int $addonId): array
    {
        preg_match_all(
            '/<input[^>]*name="addons\[' . $addonId . '\]\[choice\]"[^>]*>/',
            $html,
            $matches,
        );

        return $matches[0];
    }

    public function test_a_radio_choice_is_stored_as_a_quantity_of_one(): void
    {
        $event = $this->event();
        [$addon, $small, $medium] = $this->tee($event, [
            'selection_type' => EventAddon::SELECTION_RADIO,
        ]);

        $this->submit($event, [
            'participants' => [$this->person()],
            'addons' => [
                $addon->id => ['choice' => (string) $medium->id],
            ],
        ])->assertSessionHasNoErrors();

        $registration = EventRegistration::query()->sole();

        $this->assertSame('10.00', $registration->registration_fee);
        // RM50 for the shirt, plus RM5 because M costs more. One of each.
        $this->assertSame('55.00', $registration->addons_total);
        $this->assertSame('65.00', $registration->amount);

        $lines = EventRegistrationAddon::query()->orderBy('id')->get();

        $this->assertCount(2, $lines);

        $this->assertNull($lines[0]->event_addon_variant_id);
        $this->assertSame(1, $lines[0]->quantity);

        $this->assertSame($medium->id, $lines[1]->event_addon_variant_id);
        $this->assertSame('M', $lines[1]->variant_label);
        $this->assertSame(1, $lines[1]->quantity);

        // A registration-level choice belongs to the entry, not to a person.
        $this->assertNull($lines[0]->event_participant_id);
        $this->assertNull($lines[1]->event_participant_id);

        // Same counter the quantity path moves, by one.
        $this->assertSame(1, $medium->fresh()->stock_taken);
        $this->assertSame(0, $small->fresh()->stock_taken);
    }

    public function test_a_radio_choice_for_a_sold_out_option_is_refused(): void
    {
        $event = $this->event();
        [$addon, $small, $medium] = $this->tee($event, [
            'selection_type' => EventAddon::SELECTION_RADIO,
        ]);

        $medium->update(['stock_taken' => 10]);

        $this->submit($event, [
            'participants' => [$this->person()],
            'addons' => [
                $addon->id => ['choice' => (string) $medium->id],
            ],
        ])->assertSessionHasErrors('addons.' . $addon->id . '.choice');

        $this->assertSame(0, EventRegistration::query()->count());
        $this->assertSame(0, EventRegistrationAddon::query()->count());
        $this->assertSame(10, $medium->fresh()->stock_taken);
    }

    public function test_a_radio_choice_from_another_events_addon_is_refused(): void
    {
        $event = $this->event();
        [$addon] = $this->tee($event, [
            'selection_type' => EventAddon::SELECTION_RADIO,
        ]);

        // Somebody else's catalogue, posted against this event.
        $other = $this->event();
        [, , $otherMedium] = $this->tee($other);

        $this->submit($event, [
            'participants' => [$this->person()],
            'addons' => [
                $addon->id => ['choice' => (string) $otherMedium->id],
            ],
        ])->assertSessionHasErrors('addons.' . $addon->id . '.choice');

        $this->assertSame(0, EventRegistration::query()->count());
        $this->assertSame(0, $otherMedium->fresh()->stock_taken);
    }

    public function test_an_optional_radio_addon_may_be_declined(): void
    {
        $event = $this->event();
        [$addon] = $this->tee($event, [
            'selection_type' => EventAddon::SELECTION_RADIO,
            'is_required' => false,
        ]);

        // What the "None" option posts.
        $this->submit($event, [
            'participants' => [$this->person()],
            'addons' => [
                $addon->id => ['choice' => ''],
            ],
        ])->assertSessionHasNoErrors();

        $registration = EventRegistration::query()->sole();

        $this->assertSame('0.00', $registration->addons_total);
        $this->assertSame('10.00', $registration->amount);
        $this->assertSame(0, EventRegistrationAddon::query()->count());
    }

    public function test_a_compulsory_radio_addon_refuses_a_missing_choice(): void
    {
        $event = $this->event();
        [$addon] = $this->tee($event, [
            'selection_type' => EventAddon::SELECTION_RADIO,
            'is_required' => true,
        ]);

        $this->submit($event, [
            'participants' => [$this->person()],
            'addons' => [
                $addon->id => ['choice' => ''],
            ],
        ])->assertSessionHasErrors('addons.' . $addon->id);

        $this->assertSame(0, EventRegistration::query()->count());
    }

    public function test_a_compulsory_radio_addon_offers_no_none_option_and_preselects_nothing(): void
    {
        $event = $this->event();
        [$addon] = $this->tee($event, [
            'selection_type' => EventAddon::SELECTION_RADIO,
            'is_required' => true,
        ]);

        $response = $this->get(route('registration', ['register' => $event->slug]));

        $response->assertOk();

        $radios = $this->radioInputs($response->getContent(), $addon->id);

        // Exactly the two real options: no empty "None" to fall back on.
        $this->assertCount(2, $radios);

        foreach ($radios as $radio) {
            $this->assertStringNotContainsString('value=""', $radio);
            // Nothing preselected, so the registrant has to make the choice.
            $this->assertStringNotContainsString('checked', $radio);
        }
    }

    public function test_an_optional_radio_addon_offers_a_none_option(): void
    {
        $event = $this->event();
        [$addon] = $this->tee($event, [
            'selection_type' => EventAddon::SELECTION_RADIO,
            'is_required' => false,
        ]);

        $response = $this->get(route('registration', ['register' => $event->slug]));

        $response->assertOk();

        $radios = $this->radioInputs($response->getContent(), $addon->id);

        // Two options plus a way to take nothing, consistent with today's
        // behaviour for an optional add-on.
        $this->assertCount(3, $radios);
        $this->assertTrue(
            collect($radios)->contains(fn (string $radio) => str_contains($radio, 'value=""')),
            'An optional radio add-on should offer a clear way to choose nothing.',
        );
    }
}
