<?php

namespace Tests\Feature\Registration;

use App\Models\Event;
use App\Models\EventAddon;
use App\Models\EventAddonVariant;
use App\Models\EventParticipant;
use App\Models\EventRegistration;
use App\Models\EventRegistrationAddon;
use App\Models\EventRegistrationPayment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Registration\RegistrationTotalsRecalculator;
use App\Support\PaymentFigures;
use App\Support\ParticipantOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * One charge, two different shapes, and the one that money must not notice.
 *
 * HARI SUKAN NEGARA 2026 PERINGKAT BAHAGIAN SIBU charges nothing to enter and
 * RM 40.00 for a required shirt per head. Every entry owes RM 40.00 a person and the
 * totals on all fifty-four of them are right. The shapes are not:
 *
 *   A  twenty-two entries, one person each. registration_fee 40.00, addons_total
 *      0.00, no item line at all. RM 880.00.
 *   B  thirty-two entries, fifty-seven people. registration_fee 0.00, addons_total
 *      people x 40.00, one item line a head. RM 2,280.00.
 *
 * RM 3,160.00 either way, so no money is wrong. What is wrong is that the shirt list
 * is built from item lines: it returns fifty-seven shirts when seventy-nine people
 * have paid for one, so the organiser under-orders by twenty-two and those twenty-two
 * are not on the counter handout at all. The same RM 880.00 reads as registration-fee
 * income on an event whose fee is zero.
 *
 * This file is mostly about what must NOT happen. Shape A is re-described, not
 * re-charged: amount, amount_paid, payment_status, status, the receipt ledger, the
 * gateway reference and every variant's stock_taken are pinned byte for byte across
 * the press, and shape B is pinned as never written at all.
 */
class FeeEraShapeCorrectionTest extends TestCase
{
    use RefreshDatabase;

    /** The real price, the real head charge. */
    private const TEE = 40.0;

    protected function setUp(): void
    {
        parent::setUp();

        // Nothing here has anything to prove about mail.
        Mail::fake();
    }

    /* ---------------------------------------------------------------------
     | Fixtures: the live event, as it stands
     * ------------------------------------------------------------------ */

    private function event(array $overrides = []): Event
    {
        return Event::create($overrides + [
            'slug' => 'hsn-sibu-' . uniqid(),
            'title' => 'HARI SUKAN NEGARA 2026 PERINGKAT BAHAGIAN SIBU',
            'category' => 'Community',
            'starts_at' => now()->addMonth()->toDateString(),
            'ends_at' => now()->addMonth()->toDateString(),
            'status' => Event::STATUS_OPEN,
            'registration_mode' => Event::MODE_GROUPING,

            // Free to enter. The shirt is the only money on this event.
            'fee' => 0,
            'seats_total' => 0,
            'min_players' => 1,
            'max_players' => 20,
            'charges_addons_per_participant' => true,
        ]);
    }

    /**
     * HSN EVENT TEE: required, RM 40.00, eight sizes that add nothing.
     *
     * stock_taken is seeded at the live counts, so a test can prove the press moves
     * none of them rather than proving zero stayed zero.
     *
     * @return array{0: EventAddon, 1: \Illuminate\Support\Collection<string, EventAddonVariant>}
     */
    private function tee(Event $event): array
    {
        $addon = EventAddon::create([
            'event_id' => $event->id,
            'name' => 'HSN EVENT TEE',
            'price' => self::TEE,
            'is_required' => true,
            'is_active' => true,
            'selection_type' => EventAddon::SELECTION_RADIO,
            'per_participant' => true,
        ]);

        $taken = ['S' => 8, 'M' => 8, 'L' => 4, 'XL' => 5, '2XL' => 2, '3XL' => 3, '4XL' => 0, '5XL' => 0];
        $variants = collect();
        $order = 0;

        foreach ($taken as $label => $count) {
            $variants[$label] = EventAddonVariant::create([
                'event_addon_id' => $addon->id,
                'label' => $label,
                'price' => null,
                'stock' => null,
                'stock_taken' => $count,
                'sort_order' => ++$order,
            ]);
        }

        return [$addon->fresh()->load('variants'), $variants];
    }

    private function reference(): string
    {
        return 'REG-2026-' . str_pad((string) (EventRegistration::query()->count() + 46), 4, '0', STR_PAD_LEFT);
    }

    private function person(EventRegistration $registration, int $n): EventParticipant
    {
        return EventParticipant::create([
            'event_registration_id' => $registration->id,
            'role' => ParticipantOptions::ROLE_PARTICIPANT,
            'full_name' => 'Member ' . $n . ' of ' . $registration->reference,
            'ic_number' => '9101' . str_pad((string) $registration->id, 4, '0', STR_PAD_LEFT) . str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'phone' => '0145000' . str_pad((string) $n, 3, '0', STR_PAD_LEFT),
            'email' => 'head' . $n . '-' . $registration->id . '@example.test',
            'gender' => 'male',
            'race' => 'malay',
        ]);
    }

    /**
     * SHAPE A — the shirt money filed as a registration fee.
     *
     * The twenty-two. registration_fee carries it, addons_total is zero, and there is
     * no item line, so the shirt list cannot see these people.
     */
    private function shapeA(Event $event, int $people = 1, array $overrides = []): EventRegistration
    {
        $registration = EventRegistration::create($overrides + [
            'event_id' => $event->id,
            'reference' => $this->reference(),
            'mode' => $event->registration_mode,
            'team_name' => null,
            'status' => EventRegistration::STATUS_PENDING,
            'payment_status' => EventRegistration::PAYMENT_UNPAID,
            'registration_fee' => self::TEE * $people,
            'addons_total' => 0,
            'amount' => self::TEE * $people,
        ]);

        for ($n = 1; $n <= $people; $n++) {
            $this->person($registration, $n);
        }

        return $registration->fresh();
    }

    /**
     * SHAPE B — the shirt as a per-head item, which is what the form writes today.
     *
     * One line a person at the head price, the size recorded on it. Nothing here is
     * wrong, and nothing about it may be rewritten.
     */
    private function shapeB(Event $event, EventAddon $addon, EventAddonVariant $size, int $people = 1): EventRegistration
    {
        $total = self::TEE * $people;

        $registration = EventRegistration::create([
            'event_id' => $event->id,
            'reference' => $this->reference(),
            'mode' => $event->registration_mode,
            'team_name' => $people > 1 ? 'Group of ' . $people : null,
            'status' => EventRegistration::STATUS_CONFIRMED,
            'payment_status' => EventRegistration::PAYMENT_PAID,
            'registration_fee' => 0,
            'addons_total' => $total,
            'amount' => $total,
            'amount_paid' => $total,
        ]);

        for ($n = 1; $n <= $people; $n++) {
            $person = $this->person($registration, $n);

            EventRegistrationAddon::create([
                'event_registration_id' => $registration->id,
                'event_participant_id' => $person->id,
                'event_addon_id' => $addon->id,
                'event_addon_variant_id' => $size->id,
                'name' => $addon->name,
                'variant_label' => $size->label,
                'unit_price' => self::TEE,
                'quantity' => 1,
                'line_total' => self::TEE,
            ]);
        }

        return $registration->fresh();
    }

    /** Money on record, the way the ledger holds it. */
    private function receipt(EventRegistration $registration, float $amount, string $reference = 'pur_live_001'): void
    {
        EventRegistrationPayment::create([
            'event_registration_id' => $registration->id,
            'amount' => $amount,
            'received_at' => now()->subDays(3),
            'reference' => $reference,
            'source' => EventRegistrationPayment::SOURCE_GATEWAY,
        ]);

        $registration->forceFill([
            'amount_paid' => $amount,
            'payment_status' => EventRegistration::PAYMENT_PAID,
            'status' => EventRegistration::STATUS_CONFIRMED,
            'payment_reference' => $reference,
            'payment_synced_at' => now()->subDays(3),
        ])->save();
    }

    /* ---------------------------------------------------------------------
     | Snapshots
     |
     | Read through the query builder rather than the model, so what is compared is
     | the row as stored and not a cast of it.
     * ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private function row(EventRegistration $registration): array
    {
        return (array) DB::table('event_registrations')->where('id', $registration->id)->first();
    }

    /** @return array<int, array<string, mixed>> */
    private function ledger(EventRegistration $registration): array
    {
        return DB::table('event_registration_payments')
            ->where('event_registration_id', $registration->id)
            ->orderBy('id')
            ->get()
            ->map(fn ($payment) => (array) $payment)
            ->all();
    }

    /** @return array<int, int> variant id => stock_taken */
    private function stock(): array
    {
        return DB::table('event_addon_variants')
            ->orderBy('id')
            ->pluck('stock_taken', 'id')
            ->map(fn ($taken) => (int) $taken)
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function lines(EventRegistration $registration): array
    {
        return DB::table('event_registration_addons')
            ->where('event_registration_id', $registration->id)
            ->orderBy('id')
            ->get()
            ->map(fn ($line) => (array) $line)
            ->all();
    }

    /** @return array{collected: float, outstanding: float} */
    private function eventTotals(Event $event): array
    {
        return PaymentFigures::totalsFor(EventRegistration::query()->where('event_id', $event->id));
    }

    /** Shirts the counter handout would print, which is one per item line with a head on it. */
    private function shirtCount(Event $event): int
    {
        return (int) DB::table('event_registration_addons')
            ->join('event_registrations', 'event_registrations.id', '=', 'event_registration_addons.event_registration_id')
            ->where('event_registrations.event_id', $event->id)
            ->whereNotNull('event_registration_addons.event_participant_id')
            ->sum('event_registration_addons.quantity');
    }

    private function shirtIncome(Event $event): float
    {
        return round((float) DB::table('event_registration_addons')
            ->join('event_registrations', 'event_registrations.id', '=', 'event_registration_addons.event_registration_id')
            ->where('event_registrations.event_id', $event->id)
            ->sum('event_registration_addons.line_total'), 2);
    }

    private function feeIncome(Event $event): float
    {
        return round((float) DB::table('event_registrations')
            ->where('event_id', $event->id)
            ->sum('registration_fee'), 2);
    }

    /* ---------------------------------------------------------------------
     | Who may press it
     * ------------------------------------------------------------------ */

    /**
     * A user holding exactly the named permissions and nothing else.
     *
     * A real role with real pivot rows rather than the super-admin shortcut, because
     * part of this file is about the action being refused, and super-admin
     * short-circuits hasPermission() to true.
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

        // admin.access is always granted: without it the admin middleware signs the
        // session out before any route permission is consulted.
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

    /** participants.update, the permission the Recheck action already runs behind. */
    private function corrector(): User
    {
        return $this->userWith(['participants.view', 'participants.update']);
    }

    private function recalculator(): RegistrationTotalsRecalculator
    {
        return app(RegistrationTotalsRecalculator::class);
    }

    /* ---------------------------------------------------------------------
     | The preview tells the two apart
     * ------------------------------------------------------------------ */

    public function test_a_one_person_fee_era_entry_previews_as_a_shape_correction_not_a_re_pricing(): void
    {
        $event = $this->event();
        $this->tee($event);

        // REG-2026-0047: one person, RM 40.00 charged, RM 40.00 received.
        $registration = $this->shapeA($event);
        $this->receipt($registration, 40);

        $correction = $this->recalculator()->preview($event)[0];

        $this->assertNull($correction->blocked);
        $this->assertSame(1, $correction->people);

        // The total is right, so nothing is being re-priced.
        $this->assertSame(40.0, $correction->currentAmount);
        $this->assertSame(40.0, $correction->correctedAmount);
        $this->assertSame(0.0, $correction->difference());
        $this->assertFalse($correction->movesMoney());

        // What is wrong is which column the money sits in.
        $this->assertTrue($correction->reshapes());
        $this->assertTrue($correction->changes());
        $this->assertSame(40.0, $correction->currentRegistrationFee());
        $this->assertSame(0.0, $correction->correctedRegistrationFee);
        $this->assertSame(0.0, $correction->currentAddonsTotal());
        $this->assertSame(40.0, $correction->correctedAddonsTotal);
        $this->assertSame(1, $correction->additionsCount());

        // And the badge is not up for discussion.
        $this->assertSame(EventRegistration::PAYMENT_PAID, $correction->correctedPaymentStatus());
        $this->assertSame(0.0, $correction->correctedOutstanding());
    }

    public function test_the_preview_separates_a_wrong_total_from_a_wrong_shape(): void
    {
        $event = $this->event();
        [$addon, $sizes] = $this->tee($event);

        // A genuine shortfall: six people charged for one shirt between them.
        $undercharged = $this->shapeA($event, 6, ['registration_fee' => 40, 'amount' => 40]);

        // Right total, wrong description.
        $feeEra = $this->shapeA($event);

        // Nothing wrong at all.
        $correct = $this->shapeB($event, $addon, $sizes['M']);

        $response = $this->actingAs($this->corrector())
            ->get(route('admin.event.participants.recalculate', $event));

        $response->assertOk();

        // Three headings, three different answers, each with its count.
        $response->assertSee('Total is wrong · 1 entry');
        $response->assertSee('Total is right, items do not describe it · 1 entry');
        $response->assertSee('No change needed · 1 entry');

        $response->assertSee($undercharged->reference);
        $response->assertSee($feeEra->reference);
        $response->assertSee($correct->reference);

        // The re-itemised row is readable as "the amount does not move": the fee and
        // the item total swap over, the amount is printed as staying put, and the
        // table foots at nothing added to what is owed.
        $response->assertSee('stays RM 40.00');
        $response->assertSee('1 with no size on record');
        $response->assertSee('RM 0.00');

        // And the shortfall is still announced as money.
        $response->assertSee('+RM 200.00');
    }

    public function test_the_preview_writes_nothing(): void
    {
        $event = $this->event();
        [$addon, $sizes] = $this->tee($event);

        $feeEra = $this->shapeA($event);
        $this->receipt($feeEra, 40);
        $correct = $this->shapeB($event, $addon, $sizes['L']);

        $rows = [$this->row($feeEra), $this->row($correct)];
        $ledger = $this->ledger($feeEra);
        $lines = [$this->lines($feeEra), $this->lines($correct)];
        $stock = $this->stock();

        $this->actingAs($this->corrector())
            ->get(route('admin.event.participants.recalculate', $event))
            ->assertOk();

        $this->assertSame($rows, [$this->row($feeEra), $this->row($correct)]);
        $this->assertSame($ledger, $this->ledger($feeEra));
        $this->assertSame($lines, [$this->lines($feeEra), $this->lines($correct)]);
        $this->assertSame($stock, $this->stock());
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    /* ---------------------------------------------------------------------
     | Applying it: the money must not notice
     * ------------------------------------------------------------------ */

    public function test_confirming_moves_the_charge_onto_an_item_line_and_not_a_sen_anywhere_else(): void
    {
        $event = $this->event();
        [$addon] = $this->tee($event);

        $registration = $this->shapeA($event);
        $this->receipt($registration, 40);

        $before = $this->row($registration);
        $ledgerBefore = $this->ledger($registration);
        $stockBefore = $this->stock();

        $this->actingAs($this->corrector())
            ->post(route('admin.event.participants.recalculate.apply', $event), ['confirm' => '1'])
            ->assertRedirect(route('admin.event.participants.recalculate', $event))
            ->assertSessionHasNoErrors();

        $after = $this->row($registration);

        /*
         | The whole row, column by column, with only the two that name the charge
         | allowed to differ. Stronger than asserting the figures one at a time: a
         | column nobody thought to list here cannot slip through.
         */
        foreach ($before as $column => $value) {
            if (in_array($column, ['registration_fee', 'addons_total', 'updated_at'], true)) {
                continue;
            }

            $this->assertSame($value, $after[$column], sprintf('%s must not change.', $column));
        }

        // Named one at a time as well, as floats: the loop above compares the stored
        // value against itself, and these say what that value has to be.
        $this->assertSame(40.0, (float) $after['amount']);
        $this->assertSame(40.0, (float) $after['amount_paid']);
        $this->assertSame(EventRegistration::PAYMENT_PAID, $after['payment_status']);
        $this->assertSame(EventRegistration::STATUS_CONFIRMED, $after['status']);
        $this->assertSame('pur_live_001', $after['payment_reference']);
        $this->assertSame(0.0, (float) $after['refunded_amount']);

        // The charge now says what it is for.
        $this->assertSame(0.0, (float) $after['registration_fee']);
        $this->assertSame(40.0, (float) $after['addons_total']);

        // One line, the head price, no size invented for it.
        $line = $registration->fresh()->addonLines->sole();

        $this->assertSame($addon->id, $line->event_addon_id);
        $this->assertSame($registration->participants->sole()->id, $line->event_participant_id);
        $this->assertSame('40.00', $line->unit_price);
        $this->assertSame(1, $line->quantity);
        $this->assertSame('40.00', $line->line_total);
        $this->assertNull($line->event_addon_variant_id);
        $this->assertNull($line->variant_label);

        // The ledger is evidence, not a derived figure. It is not touched.
        $this->assertSame($ledgerBefore, $this->ledger($registration));

        // No size was chosen, so no size's count moves.
        $this->assertSame($stockBefore, $this->stock());
    }

    public function test_an_unpaid_fee_era_entry_keeps_its_unpaid_status_and_nothing_received(): void
    {
        $event = $this->event();
        $this->tee($event);

        // REG-2026-0051: one person, RM 40.00 owed, nothing in.
        $registration = $this->shapeA($event);

        $this->assertCount(1, $this->recalculator()->apply($event));

        $after = $registration->fresh();

        $this->assertSame('40.00', $after->amount);
        $this->assertSame('0.00', $after->amount_paid);
        $this->assertSame(EventRegistration::PAYMENT_UNPAID, $after->payment_status);
        $this->assertSame(EventRegistration::STATUS_PENDING, $after->status);
        $this->assertSame(40.0, $after->outstandingAmount());

        $this->assertSame('0.00', $after->registration_fee);
        $this->assertSame('40.00', $after->addons_total);
        $this->assertSame([], $this->ledger($registration));
    }

    public function test_a_failed_fee_era_entry_keeps_reading_failed(): void
    {
        $event = $this->event();
        $this->tee($event);

        // REG-2026-0068: the gateway refused it. Still owes RM 40.00 for a shirt.
        $registration = $this->shapeA($event, 1, [
            'payment_status' => EventRegistration::PAYMENT_FAILED,
        ]);

        $this->assertCount(1, $this->recalculator()->apply($event));

        $after = $registration->fresh();

        $this->assertSame(EventRegistration::PAYMENT_FAILED, $after->payment_status);
        $this->assertSame('40.00', $after->amount);
        $this->assertSame('40.00', $after->addons_total);
        $this->assertSame('0.00', $after->registration_fee);
    }

    public function test_a_shape_b_entry_previews_no_change_and_is_never_written(): void
    {
        $event = $this->event();
        [$addon, $sizes] = $this->tee($event);

        $single = $this->shapeB($event, $addon, $sizes['S']);
        $group = $this->shapeB($event, $addon, $sizes['XL'], 4);

        foreach ($this->recalculator()->preview($event) as $correction) {
            $this->assertFalse($correction->movesMoney());
            $this->assertFalse($correction->reshapes());
            $this->assertFalse($correction->changes());
        }

        $rows = [$this->row($single), $this->row($group)];
        $lines = [$this->lines($single), $this->lines($group)];

        $this->assertSame([], $this->recalculator()->apply($event));

        // Not merely unchanged in value: not written. The timestamps are part of the
        // comparison, so a re-save that happened to store the same figures would fail.
        $this->assertSame($rows, [$this->row($single), $this->row($group)]);
        $this->assertSame($lines, [$this->lines($single), $this->lines($group)]);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_a_multi_person_fee_era_entry_gets_one_line_per_person(): void
    {
        $event = $this->event();
        [$addon] = $this->tee($event);

        // None of the live twenty-two is a group, but the arithmetic must not depend
        // on that: a three-person entry charged RM 120.00 as a fee is the same bug.
        $registration = $this->shapeA($event, 3);
        $this->receipt($registration, 120);

        $correction = $this->recalculator()->preview($event)[0];

        $this->assertFalse($correction->movesMoney());
        $this->assertTrue($correction->reshapes());
        $this->assertSame(3, $correction->additionsCount());

        $this->assertCount(1, $this->recalculator()->apply($event));

        $after = $registration->fresh();

        $this->assertSame('120.00', $after->amount);
        $this->assertSame('120.00', $after->amount_paid);
        $this->assertSame('0.00', $after->registration_fee);
        $this->assertSame('120.00', $after->addons_total);
        $this->assertSame(EventRegistration::PAYMENT_PAID, $after->payment_status);

        $lines = $after->addonLines;

        $this->assertCount(3, $lines);
        $this->assertSame(120.0, round((float) $lines->sum('line_total'), 2));
        $this->assertSame(
            $after->participants->pluck('id')->sort()->values()->all(),
            $lines->pluck('event_participant_id')->sort()->values()->all(),
        );

        foreach ($lines as $line) {
            $this->assertSame($addon->id, $line->event_addon_id);
            $this->assertSame('40.00', $line->line_total);
            $this->assertNull($line->event_addon_variant_id);
        }
    }

    public function test_applying_twice_finds_nothing_the_second_time(): void
    {
        $event = $this->event();
        $this->tee($event);

        $registration = $this->shapeA($event);
        $this->receipt($registration, 40);

        $this->assertCount(1, $this->recalculator()->apply($event));

        $first = $this->row($registration);
        $firstLines = $this->lines($registration);

        $this->assertSame([], $this->recalculator()->apply($event));

        $this->assertSame($first, $this->row($registration));
        $this->assertSame($firstLines, $this->lines($registration));
        $this->assertCount(1, $this->lines($registration));

        // One trail entry, not two.
        $this->assertDatabaseCount('audit_logs', 1);
    }

    /* ---------------------------------------------------------------------
     | The event as a whole
     * ------------------------------------------------------------------ */

    public function test_the_event_reads_the_same_money_and_a_complete_shirt_list_afterwards(): void
    {
        $event = $this->event();
        [$addon, $sizes] = $this->tee($event);

        // Two shape A, one paid and one not. Two shape B covering three people.
        $paid = $this->shapeA($event);
        $this->receipt($paid, 40);

        $this->shapeA($event);

        $this->shapeB($event, $addon, $sizes['M']);
        $this->shapeB($event, $addon, $sizes['3XL'], 2);

        $totalsBefore = $this->eventTotals($event);
        $chargedBefore = round((float) DB::table('event_registrations')->where('event_id', $event->id)->sum('amount'), 2);
        $stockBefore = $this->stock();

        // Five people, four of whom the shirt list can see.
        $this->assertSame(3, $this->shirtCount($event));
        $this->assertSame(120.0, $this->shirtIncome($event));
        $this->assertSame(80.0, $this->feeIncome($event));

        $this->actingAs($this->corrector())
            ->post(route('admin.event.participants.recalculate.apply', $event), ['confirm' => '1'])
            ->assertSessionHasNoErrors();

        // Every shirt accounted for, and all of it reads as shirt income.
        $this->assertSame(5, $this->shirtCount($event));
        $this->assertSame(200.0, $this->shirtIncome($event));
        $this->assertSame(0.0, $this->feeIncome($event));

        // And not a figure on the money screens moves.
        $this->assertSame($totalsBefore, $this->eventTotals($event));
        $this->assertSame($chargedBefore, round((float) DB::table('event_registrations')->where('event_id', $event->id)->sum('amount'), 2));
        $this->assertSame($stockBefore, $this->stock());

        // One shape, everywhere: the fee column is empty and every head has a line.
        $this->assertSame(0, (int) DB::table('event_registrations')
            ->where('event_id', $event->id)
            ->where('registration_fee', '>', 0)
            ->count());
    }

    public function test_every_changed_row_leaves_a_trail_naming_who_did_it_and_both_shapes(): void
    {
        $event = $this->event();
        $this->tee($event);

        $registration = $this->shapeA($event);
        $this->receipt($registration, 40);

        $admin = $this->corrector();

        $this->actingAs($admin)
            ->post(route('admin.event.participants.recalculate.apply', $event), ['confirm' => '1'])
            ->assertSessionHasNoErrors();

        $audit = \App\Models\AuditLog::query()
            ->where('auditable_type', EventRegistration::class)
            ->where('auditable_id', $registration->id)
            ->sole();

        // Named apart from a re-pricing, because no money moved.
        $this->assertSame('amount.reitemised', $audit->event);
        $this->assertSame($admin->id, $audit->user_id);

        $this->assertSame(40.0, (float) $audit->old_values['registration_fee']);
        $this->assertSame(0.0, (float) $audit->old_values['addons_total']);
        $this->assertSame(0, (int) $audit->old_values['addon_lines']);

        $this->assertSame(0.0, (float) $audit->new_values['registration_fee']);
        $this->assertSame(40.0, (float) $audit->new_values['addons_total']);
        $this->assertSame(1, (int) $audit->new_values['addon_lines']);

        // The figure that must read the same on both sides.
        $this->assertSame(40.0, (float) $audit->old_values['amount']);
        $this->assertSame(40.0, (float) $audit->new_values['amount']);
        $this->assertSame(0.0, (float) $audit->new_values['difference']);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action' => 'participants.recalculate',
        ]);

        $this->assertNotNull(\App\Models\ActivityLog::query()
            ->where('action', 'participants.recalculate')
            ->where('description', 'like', 'Re-itemised ' . $registration->reference . '%')
            ->first());
    }

    /* ---------------------------------------------------------------------
     | Who may not press it
     * ------------------------------------------------------------------ */

    public function test_it_is_refused_without_participants_update_and_refused_on_get(): void
    {
        $event = $this->event();
        $this->tee($event);

        $registration = $this->shapeA($event);
        $this->receipt($registration, 40);

        $before = $this->row($registration);

        // Able to read the list, not to correct what an entry was charged.
        $viewer = $this->userWith(['participants.view']);

        $this->actingAs($viewer)
            ->get(route('admin.event.participants.recalculate', $event))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post(route('admin.event.participants.recalculate.apply', $event), ['confirm' => '1'])
            ->assertForbidden();

        // A GET cannot write, whoever is asking.
        $this->actingAs($this->corrector())
            ->get(route('admin.event.participants.recalculate.apply', $event))
            ->assertStatus(405);

        // And the confirmation is not optional.
        $this->actingAs($this->corrector())
            ->post(route('admin.event.participants.recalculate.apply', $event))
            ->assertSessionHasErrors('confirm');

        $this->assertSame($before, $this->row($registration));
        $this->assertSame([], $this->lines($registration));
    }
}
