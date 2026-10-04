<?php

namespace Tests\Feature\Registration;

use App\Http\Controllers\ParticipantSizeController;
use App\Models\Event;
use App\Models\EventAddon;
use App\Models\EventAddonVariant;
use App\Models\EventParticipant;
use App\Models\EventRegistration;
use App\Models\EventRegistrationAddon;
use App\Support\ParticipantOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Collecting a shirt size that was never asked for, without moving a sen.
 *
 * HARI SUKAN NEGARA 2026 PERINGKAT BAHAGIAN SIBU started asking for a size part way
 * through its entries. Of seventy-nine people on it, thirty answered, twenty-seven have
 * an item line with no size on it — the shape the totals recalculation deliberately left
 * behind — and twenty-two have no line at all, because their RM 40.00 is still in
 * registration_fee from when the shirt was the event fee. All of them have paid for a
 * shirt and the organiser cannot order any of them.
 *
 * The charge on those entries was corrected once already, by hand, against a live table
 * holding real receipts. So the assertion this file exists for is the negative one: a
 * size is recorded and NOT ONE money field moves. The three shapes above are each
 * covered, because each one is written differently and only one of them can be got wrong
 * cheaply.
 *
 * Mail::fake() in setUp(): the fixtures carry participant addresses and this mirrors a
 * live system in CHIP live mode.
 */
class ParticipantSizeConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private EventAddon $tee;

    /** @var array<string, EventAddonVariant> label => variant */
    private array $sizes = [];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->event = Event::create([
            'slug' => 'hsn-sibu-' . uniqid(),
            'title' => 'HARI SUKAN NEGARA 2026 PERINGKAT BAHAGIAN SIBU',
            'category' => 'Community',
            'starts_at' => now()->addMonth()->toDateString(),
            'ends_at' => now()->addMonth()->toDateString(),
            'status' => Event::STATUS_OPEN,
            'registration_mode' => Event::MODE_GROUPING,
            'location' => 'Sibu',

            // The live shape: free to enter, the shirt is the only money, and it is
            // charged per head.
            'fee' => null,
            'charges_addons_per_participant' => true,
            'seats_total' => 0,
            'min_players' => 1,
            'max_players' => 20,
        ]);

        $this->tee = EventAddon::create([
            'event_id' => $this->event->id,
            'name' => 'HSN EVENT TEE',
            'price' => 40,
            'is_required' => true,
            'is_active' => true,
            'per_participant' => true,
            'selection_type' => EventAddon::SELECTION_RADIO,
        ]);

        // Price null and stock null, exactly as the live rows are: every size costs the
        // same and nothing is capped.
        foreach (['S', 'M', 'L', 'XL'] as $order => $label) {
            $this->sizes[$label] = EventAddonVariant::create([
                'event_addon_id' => $this->tee->id,
                'label' => $label,
                'price' => null,
                'stock' => null,
                'sort_order' => $order + 1,
            ]);
        }

        $this->tee = $this->tee->fresh();
    }

    /* ---------------------------------------------------------------------
     | Fixtures: the three shapes
     * ------------------------------------------------------------------ */

    private function registration(string $reference, array $money = []): EventRegistration
    {
        return EventRegistration::create($money + [
            'event_id' => $this->event->id,
            'reference' => $reference,
            'mode' => $this->event->registration_mode,
            'team_name' => 'Pasukan ' . $reference,
            'status' => EventRegistration::STATUS_PENDING,
            'payment_status' => EventRegistration::PAYMENT_UNPAID,
            'registration_fee' => 0,
            'addons_total' => 40,
            'amount' => 40,
            'amount_paid' => 0,
        ]);
    }

    private function person(EventRegistration $registration, int $n, string $role = ParticipantOptions::ROLE_MANAGER): EventParticipant
    {
        return EventParticipant::create([
            'event_registration_id' => $registration->id,
            'role' => $role,
            'full_name' => 'Peserta ' . $registration->reference . ' ' . $n,
            'ic_number' => '9001010' . $registration->id . str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'phone' => '0128508124',
            'email' => strtolower(str_replace('-', '', $registration->reference)) . '@example.test',
            'gender' => 'male',
            'race' => 'malay',
        ]);
    }

    /**
     * The twenty-seven: a line carrying the charge with no size on it.
     *
     * Written the way RegistrationTotalsRecalculator writes one — the price per head,
     * one unit, no variant — because that is what is actually in the table.
     */
    private function lineWithoutSize(EventRegistration $registration, EventParticipant $person): EventRegistrationAddon
    {
        return EventRegistrationAddon::create([
            'event_registration_id' => $registration->id,
            'event_participant_id' => $person->id,
            'event_addon_id' => $this->tee->id,
            'event_addon_variant_id' => null,
            'name' => $this->tee->name,
            'variant_label' => null,
            'unit_price' => 40,
            'quantity' => 1,
            'line_total' => 40,
        ]);
    }

    /** Somebody who did choose, and whose choice took stock when they registered. */
    private function lineWithSize(EventRegistration $registration, EventParticipant $person, string $label): EventRegistrationAddon
    {
        $variant = $this->sizes[$label];

        $variant->increment('stock_taken');

        return EventRegistrationAddon::create([
            'event_registration_id' => $registration->id,
            'event_participant_id' => $person->id,
            'event_addon_id' => $this->tee->id,
            'event_addon_variant_id' => $variant->id,
            'name' => $this->tee->name,
            'variant_label' => $variant->label,
            'unit_price' => 40,
            'quantity' => 1,
            'line_total' => 40,
        ]);
    }

    /* ---------------------------------------------------------------------
     | Helpers
     * ------------------------------------------------------------------ */

    private function open(EventRegistration $registration)
    {
        return $this->get(ParticipantSizeController::urlFor($registration));
    }

    /**
     * @param  array<int, array<int, int|string>>  $sizes  participantId => addonId => variantId
     */
    private function confirm(EventRegistration $registration, array $sizes)
    {
        return $this->post(ParticipantSizeController::urlFor($registration), ['sizes' => $sizes]);
    }

    /**
     * Every money field on the entry, as the row actually holds them.
     *
     * The decimal cast returns strings, so assertSame on this is a comparison of the
     * stored figures and not of floats that happen to be close.
     *
     * @return array<string, string|null>
     */
    private function money(EventRegistration $registration): array
    {
        return $registration->fresh()->only([
            'registration_fee', 'addons_total', 'amount', 'amount_paid',
            'refunded_amount', 'payment_status', 'status',
        ]);
    }

    /** @return array<string, int> label => stock_taken */
    private function stock(): array
    {
        return EventAddonVariant::query()
            ->where('event_addon_id', $this->tee->id)
            ->orderBy('sort_order')
            ->pluck('stock_taken', 'label')
            ->map(fn ($taken) => (int) $taken)
            ->all();
    }

    /* ---------------------------------------------------------------------
     | 1. A line that already exists and names no size
     * ------------------------------------------------------------------ */

    public function test_a_line_with_no_size_has_the_variant_written_onto_that_same_line(): void
    {
        $registration = $this->registration('REG-2026-0068', [
            'payment_status' => EventRegistration::PAYMENT_PARTIAL,
            'addons_total' => 80,
            'amount' => 80,
            'amount_paid' => 40,
        ]);

        $person = $this->person($registration, 1);
        $other = $this->person($registration, 2, ParticipantOptions::ROLE_PARTICIPANT);

        $line = $this->lineWithoutSize($registration, $person);
        $this->lineWithoutSize($registration, $other);

        $before = $this->money($registration);

        $this->confirm($registration, [
            $person->id => [$this->tee->id => $this->sizes['M']->id],
            $other->id => [$this->tee->id => $this->sizes['L']->id],
        ])->assertRedirect();

        // The same two rows, not four: a second line would both duplicate the shirt in
        // the hand-out list and, if it were ever priced, the invoice.
        $this->assertSame(2, $registration->addonLines()->count());

        $line = $line->fresh();

        $this->assertSame($this->sizes['M']->id, $line->event_addon_variant_id);
        $this->assertSame('M', $line->variant_label);

        // What the entry was charged, untouched. These three are the money on the line
        // and they are not this page's business.
        $this->assertSame('40.00', $line->unit_price);
        $this->assertSame(1, $line->quantity);
        $this->assertSame('40.00', $line->line_total);

        $this->assertSame($before, $this->money($registration));
    }

    /* ---------------------------------------------------------------------
     | 2. The twenty-two with no line at all — the critical one
     * ------------------------------------------------------------------ */

    public function test_a_participant_with_no_line_gets_one_at_zero_and_the_entry_does_not_move(): void
    {
        // REG-2026-0047's shape to the sen: one person, RM 40.00 in the fee rather than
        // in the items, settled in full.
        $registration = $this->registration('REG-2026-0047', [
            'payment_status' => EventRegistration::PAYMENT_PAID,
            'registration_fee' => 40,
            'addons_total' => 0,
            'amount' => 40,
            'amount_paid' => 40,
        ]);

        $person = $this->person($registration, 1);

        $this->assertSame(0, $registration->addonLines()->count());

        $before = $this->money($registration);

        $this->confirm($registration, [
            $person->id => [$this->tee->id => $this->sizes['L']->id],
        ])->assertRedirect();

        $line = $registration->addonLines()->sole();

        $this->assertSame($person->id, $line->event_participant_id);
        $this->assertSame($this->tee->id, $line->event_addon_id);
        $this->assertSame($this->sizes['L']->id, $line->event_addon_variant_id);
        $this->assertSame('L', $line->variant_label);
        $this->assertSame('HSN EVENT TEE', $line->name);

        /*
         | Zero, and it must stay zero. This person has already paid for the shirt: the
         | RM 40.00 is in registration_fee because they registered while the shirt was
         | the fee. A priced line would charge it twice over and would also make
         | addons_total disagree with the sum of the lines beneath it.
         */
        $this->assertSame('0.00', $line->unit_price);
        $this->assertSame('0.00', $line->line_total);
        $this->assertSame(1, $line->quantity);

        // THE assertion this feature exists under. Nothing about what is owed, what
        // arrived, or where the entry stands has moved.
        $this->assertSame($before, $this->money($registration));

        $this->assertSame('40.00', $registration->fresh()->amount);
        $this->assertSame('40.00', $registration->fresh()->amount_paid);
        $this->assertSame('0.00', $registration->fresh()->addons_total);
        $this->assertSame(EventRegistration::PAYMENT_PAID, $registration->fresh()->payment_status);
        $this->assertTrue($registration->fresh()->isSettledInFull());
    }

    /* ---------------------------------------------------------------------
     | 3. Stock
     * ------------------------------------------------------------------ */

    public function test_stock_moves_for_the_chosen_size_and_for_no_other(): void
    {
        $registration = $this->registration('REG-2026-0051');
        $person = $this->person($registration, 1);
        $this->lineWithoutSize($registration, $person);

        $this->assertSame(['S' => 0, 'M' => 0, 'L' => 0, 'XL' => 0], $this->stock());

        $this->confirm($registration, [$person->id => [$this->tee->id => $this->sizes['M']->id]]);

        $this->assertSame(['S' => 0, 'M' => 1, 'L' => 0, 'XL' => 0], $this->stock());
    }

    public function test_changing_a_recorded_size_moves_the_count_rather_than_adding_to_it(): void
    {
        $registration = $this->registration('REG-2026-0052');
        $person = $this->person($registration, 1);
        $line = $this->lineWithSize($registration, $person, 'M');

        $this->assertSame(['S' => 0, 'M' => 1, 'L' => 0, 'XL' => 0], $this->stock());

        $before = $this->money($registration);

        // The registrant corrects themselves: M was wrong, they want XL.
        $this->confirm($registration, [$person->id => [$this->tee->id => $this->sizes['XL']->id]]);

        // One shirt before, one shirt after. The old size is handed back rather than
        // left claimed, which is what keeps the figure usable for an order.
        $this->assertSame(['S' => 0, 'M' => 0, 'L' => 0, 'XL' => 1], $this->stock());

        $this->assertSame($this->sizes['XL']->id, $line->fresh()->event_addon_variant_id);
        $this->assertSame('XL', $line->fresh()->variant_label);
        $this->assertSame(1, $registration->addonLines()->count());
        $this->assertSame($before, $this->money($registration));
    }

    public function test_submitting_the_same_answer_twice_does_not_double_count_stock(): void
    {
        $registration = $this->registration('REG-2026-0053');
        $person = $this->person($registration, 1);
        $this->lineWithoutSize($registration, $person);

        $payload = [$person->id => [$this->tee->id => $this->sizes['S']->id]];

        $this->confirm($registration, $payload);
        $this->confirm($registration, $payload);

        // Two tabs, one shirt. The second submission reads the row the first wrote and
        // finds nothing to do.
        $this->assertSame(['S' => 1, 'M' => 0, 'L' => 0, 'XL' => 0], $this->stock());
        $this->assertSame(1, $registration->addonLines()->count());
    }

    public function test_submitting_with_nothing_changed_is_a_no_op(): void
    {
        $registration = $this->registration('REG-2026-0054');
        $person = $this->person($registration, 1);
        $line = $this->lineWithSize($registration, $person, 'L');

        $before = $this->money($registration);
        $updatedAt = $line->fresh()->updated_at;

        $this->confirm($registration, [$person->id => [$this->tee->id => $this->sizes['L']->id]])
            ->assertSessionHas('status');

        $this->assertSame(['S' => 0, 'M' => 0, 'L' => 1, 'XL' => 0], $this->stock());
        $this->assertSame(1, $registration->addonLines()->count());
        $this->assertEquals($updatedAt, $line->fresh()->updated_at, 'An unchanged answer must not even touch the row.');
        $this->assertSame($before, $this->money($registration));
    }

    public function test_an_empty_submission_changes_nothing(): void
    {
        $registration = $this->registration('REG-2026-0055');
        $person = $this->person($registration, 1);
        $this->lineWithoutSize($registration, $person);

        $before = $this->money($registration);

        $this->confirm($registration, [])->assertSessionHas('status');

        $this->assertNull($registration->addonLines()->sole()->event_addon_variant_id);
        $this->assertSame(['S' => 0, 'M' => 0, 'L' => 0, 'XL' => 0], $this->stock());
        $this->assertSame($before, $this->money($registration));
    }

    /* ---------------------------------------------------------------------
     | 4. Stock limits, which are null today but must not be assumed
     * ------------------------------------------------------------------ */

    public function test_a_sold_out_size_is_refused_and_drawn_as_unavailable(): void
    {
        // One of the four given a real limit, already exhausted.
        $this->sizes['S']->forceFill(['stock' => 1, 'stock_taken' => 1])->save();

        $registration = $this->registration('REG-2026-0056');
        $person = $this->person($registration, 1);
        $this->lineWithoutSize($registration, $person);

        $page = $this->open($registration);
        $page->assertOk();
        $page->assertSee('Sold out');

        $this->confirm($registration, [$person->id => [$this->tee->id => $this->sizes['S']->id]])
            ->assertSessionHasErrors('sizes.' . $person->id . '.' . $this->tee->id);

        $this->assertNull($registration->addonLines()->sole()->event_addon_variant_id);
        $this->assertSame(1, (int) $this->sizes['S']->fresh()->stock_taken);
    }

    /* ---------------------------------------------------------------------
     | 5. The link itself
     * ------------------------------------------------------------------ */

    public function test_the_page_opens_from_a_signed_link_and_lists_everybody_on_the_entry(): void
    {
        $registration = $this->registration('REG-2026-0057', ['addons_total' => 80, 'amount' => 80]);
        $first = $this->person($registration, 1);
        $second = $this->person($registration, 2, ParticipantOptions::ROLE_PARTICIPANT);

        $this->lineWithoutSize($registration, $first);
        $this->lineWithSize($registration, $second, 'M');

        $page = $this->open($registration);

        $page->assertOk();
        $page->assertSee($registration->reference);
        $page->assertSee($this->event->title);
        $page->assertSee($first->full_name);
        $page->assertSee($second->full_name);

        // It says what it is, twice, because everybody holding one of these links has
        // also been receiving payment reminders for the same event.
        $page->assertSee('This is only to confirm a shirt size');
        $page->assertSee('No payment is needed and nothing is charged here.');

        // The size already on record comes back selected, so the page is also the
        // correction tool.
        $page->assertSee('currently M');
    }

    public function test_a_url_edited_to_reach_another_registration_is_refused(): void
    {
        $mine = $this->registration('REG-2026-0058');
        $this->person($mine, 1);

        $theirs = $this->registration('REG-2026-0059');
        $theirPerson = $this->person($theirs, 1);
        $this->lineWithoutSize($theirs, $theirPerson);

        // The signature covers the whole URL, reference included, so swapping one
        // reference for another leaves a signature that does not match.
        $tampered = str_replace($mine->reference, $theirs->reference, ParticipantSizeController::urlFor($mine));

        $this->get($tampered)->assertForbidden()->assertSee('This link is no longer valid');

        $this->post($tampered, ['sizes' => [$theirPerson->id => [$this->tee->id => $this->sizes['M']->id]]])
            ->assertForbidden();

        $this->assertNull($theirs->addonLines()->sole()->event_addon_variant_id);
        $this->assertSame(['S' => 0, 'M' => 0, 'L' => 0, 'XL' => 0], $this->stock());
    }

    public function test_an_expired_link_says_so_rather_than_failing(): void
    {
        $registration = $this->registration('REG-2026-0060');
        $person = $this->person($registration, 1);
        $this->lineWithoutSize($registration, $person);

        $expired = URL::temporarySignedRoute(
            'registration.sizes',
            now()->subMinute(),
            ['reference' => $registration->reference],
        );

        $page = $this->get($expired);

        $page->assertForbidden();
        $page->assertSee('This link is no longer valid');
        $page->assertSee('ask for a fresh link');

        // Nothing about the entry is named to somebody holding a dead link.
        $page->assertDontSee($registration->reference);
    }

    public function test_an_unsigned_post_writes_nothing(): void
    {
        $registration = $this->registration('REG-2026-0061');
        $person = $this->person($registration, 1);
        $this->lineWithoutSize($registration, $person);

        $this->post(route('registration.sizes.store', ['reference' => $registration->reference]), [
            'sizes' => [$person->id => [$this->tee->id => $this->sizes['M']->id]],
        ])->assertForbidden();

        $this->assertNull($registration->addonLines()->sole()->event_addon_variant_id);
        $this->assertSame(['S' => 0, 'M' => 0, 'L' => 0, 'XL' => 0], $this->stock());
    }

    public function test_a_posted_participant_from_another_entry_is_never_looked_at(): void
    {
        $mine = $this->registration('REG-2026-0062');
        $myPerson = $this->person($mine, 1);
        $this->lineWithoutSize($mine, $myPerson);

        $theirs = $this->registration('REG-2026-0063');
        $theirPerson = $this->person($theirs, 1);
        $theirLine = $this->lineWithoutSize($theirs, $theirPerson);

        // A valid link for my own entry, carrying somebody else's participant id.
        $this->confirm($mine, [
            $theirPerson->id => [$this->tee->id => $this->sizes['XL']->id],
        ])->assertRedirect();

        $this->assertNull($theirLine->fresh()->event_addon_variant_id);
        $this->assertNull($mine->addonLines()->sole()->event_addon_variant_id);
        $this->assertSame(['S' => 0, 'M' => 0, 'L' => 0, 'XL' => 0], $this->stock());
    }

    /* ---------------------------------------------------------------------
     | 6. Payment state is none of its business
     * ------------------------------------------------------------------ */

    public function test_it_works_for_a_paid_an_unpaid_and_a_part_paid_entry(): void
    {
        $shapes = [
            'REG-2026-0064' => [EventRegistration::PAYMENT_PAID, 40.0, 40.0],
            'REG-2026-0065' => [EventRegistration::PAYMENT_UNPAID, 40.0, 0.0],
            'REG-2026-0066' => [EventRegistration::PAYMENT_PARTIAL, 80.0, 40.0],
        ];

        foreach ($shapes as $reference => [$status, $amount, $paid]) {
            $registration = $this->registration($reference, [
                'payment_status' => $status,
                'addons_total' => $amount,
                'amount' => $amount,
                'amount_paid' => $paid,
            ]);

            $person = $this->person($registration, 1);
            $this->lineWithoutSize($registration, $person);

            $before = $this->money($registration);

            $this->open($registration)->assertOk();

            $this->confirm($registration, [$person->id => [$this->tee->id => $this->sizes['M']->id]])
                ->assertRedirect();

            $this->assertSame(
                $this->sizes['M']->id,
                $registration->addonLines()->sole()->event_addon_variant_id,
                $reference . ' must be able to confirm a size whatever its payment says.',
            );

            $this->assertSame($before, $this->money($registration), $reference . ' must not move.');
        }

        // Three entries, three shirts.
        $this->assertSame(['S' => 0, 'M' => 3, 'L' => 0, 'XL' => 0], $this->stock());
    }
}
