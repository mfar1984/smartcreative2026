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
use App\Models\Setting;
use App\Models\User;
use App\Services\Payment\RegistrationBalanceCharge;
use App\Services\Registration\RegistrationTotalsRecalculator;
use App\Support\PaymentSettings;
use App\Support\ParticipantOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Correcting entries that were charged under the old add-on rule.
 *
 * An item's own price used to be one charge for the whole registration, so a group of
 * six each choosing a RM 40.00 shirt was charged RM 40.00. AddonOrder now prices those
 * per head when the event says so, but only for new submissions: everything already in
 * the table still names the old figure, and this is a live table with money against it.
 *
 * The shape of these rows is the live one. The event is free to enter, the shirt is a
 * required radio add-on at RM 40.00 with free sizes, and each stored entry carries one
 * registration-level RM 40.00 line plus a free line per person recording their size.
 *
 * Three things are pinned here and they matter in this order: an entry already charging
 * the right amount is never rewritten, an entry that has paid in full keeps reading Paid,
 * and nothing is written by looking at the preview.
 */
class GroupingTotalRecalculationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Nothing here has anything to prove about mail, and a reminder sends one.
        Mail::fake();
    }

    /* ---------------------------------------------------------------------
     | Fixtures
     * ------------------------------------------------------------------ */

    private function event(array $overrides = []): Event
    {
        return Event::create($overrides + [
            'slug' => 'hsn-' . uniqid(),
            'title' => 'HARI SUKAN NEGARA 2026 PERINGKAT BAHAGIAN SIBU',
            'category' => 'Community',
            'starts_at' => now()->addMonth()->toDateString(),
            'ends_at' => now()->addMonth()->toDateString(),
            'status' => Event::STATUS_OPEN,
            'registration_mode' => Event::MODE_GROUPING,

            // Free to enter. The shirt is the only money, which is the live case.
            'fee' => null,
            'seats_total' => 0,
            'min_players' => 1,
            'max_players' => 20,

            // The setting the fix added, switched on: without it there is nothing to
            // correct, which is asserted on its own below.
            'charges_addons_per_participant' => true,
        ]);
    }

    /**
     * The required RM 40.00 shirt, sizes free.
     *
     * @return array{0: EventAddon, 1: EventAddonVariant}
     */
    private function tee(Event $event): array
    {
        $addon = EventAddon::create([
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
            'stock' => 500,
            'stock_taken' => 0,
            'sort_order' => 1,
        ]);

        return [$addon->fresh(), $small];
    }

    /**
     * One entry exactly as the old pricing stored it: the shirt charged once for the
     * whole group, and a free line per person naming the size they chose.
     */
    private function undercharged(
        Event $event,
        EventAddon $addon,
        EventAddonVariant $size,
        int $people,
        array $overrides = [],
    ): EventRegistration {
        $registration = EventRegistration::create($overrides + [
            'event_id' => $event->id,
            'reference' => 'REG-2026-' . str_pad((string) (EventRegistration::query()->count() + 70), 4, '0', STR_PAD_LEFT),
            'mode' => $event->registration_mode,
            'team_name' => 'Group of ' . $people,
            'status' => EventRegistration::STATUS_PENDING,
            'payment_status' => EventRegistration::PAYMENT_UNPAID,
            'registration_fee' => 0,
            'addons_total' => 40,
            'amount' => 40,
        ]);

        // The one charge for the group, carrying no person and no size.
        EventRegistrationAddon::create([
            'event_registration_id' => $registration->id,
            'event_participant_id' => null,
            'event_addon_id' => $addon->id,
            'event_addon_variant_id' => null,
            'name' => $addon->name,
            'variant_label' => null,
            'unit_price' => 40,
            'quantity' => 1,
            'line_total' => 40,
        ]);

        for ($n = 1; $n <= $people; $n++) {
            $person = EventParticipant::create([
                'event_registration_id' => $registration->id,
                'role' => ParticipantOptions::ROLE_PARTICIPANT,
                'full_name' => 'Member ' . $n . ' of ' . $registration->reference,
                'ic_number' => '9001' . str_pad((string) $registration->id, 4, '0', STR_PAD_LEFT) . str_pad((string) $n, 4, '0', STR_PAD_LEFT),
                'phone' => '0142000' . str_pad((string) $n, 3, '0', STR_PAD_LEFT),
                'email' => 'member' . $n . '-' . $registration->id . '@example.test',
                'gender' => 'male',
                'race' => 'malay',
            ]);

            EventRegistrationAddon::create([
                'event_registration_id' => $registration->id,
                'event_participant_id' => $person->id,
                'event_addon_id' => $addon->id,
                'event_addon_variant_id' => $size->id,
                'name' => $addon->name,
                'variant_label' => $size->label,
                'unit_price' => 0,
                'quantity' => 1,
                'line_total' => 0,
            ]);
        }

        return $registration->fresh();
    }

    /* ---------------------------------------------------------------------
     | The other two shapes the same event has been charged in
     |
     | undercharged() above is shape B: the shirt as an item charged once for the
     | entry. The live table holds two more, because the event's settings changed
     | twice while people were registering, and the arithmetic has to reach the same
     | answer from all three.
     * ------------------------------------------------------------------ */

    /** One person on an entry, with no item line of their own. */
    private function person(EventRegistration $registration, int $n): EventParticipant
    {
        return EventParticipant::create([
            'event_registration_id' => $registration->id,
            'role' => ParticipantOptions::ROLE_PARTICIPANT,
            'full_name' => 'Member ' . $n . ' of ' . $registration->reference,
            'ic_number' => '9002' . str_pad((string) $registration->id, 4, '0', STR_PAD_LEFT) . str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'phone' => '0143000' . str_pad((string) $n, 3, '0', STR_PAD_LEFT),
            'email' => 'person' . $n . '-' . $registration->id . '@example.test',
            'gender' => 'male',
            'race' => 'malay',
        ]);
    }

    private function reference(): string
    {
        return 'REG-2026-' . str_pad((string) (EventRegistration::query()->count() + 70), 4, '0', STR_PAD_LEFT);
    }

    /**
     * SHAPE A — the shirt was the event fee.
     *
     * Thirty-two of the thirty-nine live entries look like this. registration_fee
     * carries the RM 40.00, addons_total is zero, and there is not a single item line
     * on the entry. Re-pricing the stored lines therefore finds nothing wrong with it,
     * which is exactly why the corrected figure has to come from the head count.
     */
    private function shapeA(Event $event, int $people, array $overrides = []): EventRegistration
    {
        $registration = EventRegistration::create($overrides + [
            'event_id' => $event->id,
            'reference' => $this->reference(),
            'mode' => $event->registration_mode,
            'team_name' => 'Fee-era group of ' . $people,
            'status' => EventRegistration::STATUS_PENDING,
            'payment_status' => EventRegistration::PAYMENT_UNPAID,
            'registration_fee' => 40,
            'addons_total' => 0,
            'amount' => 40,
        ]);

        for ($n = 1; $n <= $people; $n++) {
            $this->person($registration, $n);
        }

        return $registration->fresh();
    }

    /**
     * SHAPE C — the shirt priced per head, which is what the fixed code writes.
     *
     * Each person's own line carries the RM 40.00 and the entry's total is already
     * right, so this must come out of the preview untouched.
     */
    private function shapeC(
        Event $event,
        EventAddon $addon,
        EventAddonVariant $size,
        int $people,
    ): EventRegistration {
        $total = 40 * $people;

        $registration = EventRegistration::create([
            'event_id' => $event->id,
            'reference' => $this->reference(),
            'mode' => $event->registration_mode,
            'team_name' => 'Per-head group of ' . $people,
            'status' => EventRegistration::STATUS_PENDING,
            'payment_status' => EventRegistration::PAYMENT_PENDING,
            'registration_fee' => 0,
            'addons_total' => $total,
            'amount' => $total,
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
                'unit_price' => 40,
                'quantity' => 1,
                'line_total' => 40,
            ]);
        }

        // The group line left behind at nothing, the way the live rows carry it.
        EventRegistrationAddon::create([
            'event_registration_id' => $registration->id,
            'event_participant_id' => null,
            'event_addon_id' => $addon->id,
            'event_addon_variant_id' => null,
            'name' => $addon->name,
            'variant_label' => null,
            'unit_price' => 0,
            'quantity' => 1,
            'line_total' => 0,
        ]);

        return $registration->fresh();
    }

    /** Money on record against an entry, the way the ledger holds it. */
    private function receipt(EventRegistration $registration, float $amount): void
    {
        EventRegistrationPayment::create([
            'event_registration_id' => $registration->id,
            'amount' => $amount,
            'received_at' => now()->subDay(),
            'source' => EventRegistrationPayment::SOURCE_MANUAL,
        ]);

        $registration->forceFill([
            'amount_paid' => $amount,
            'payment_status' => EventRegistration::PAYMENT_PAID,
            'status' => EventRegistration::STATUS_CONFIRMED,
        ])->save();
    }

    /**
     * A user holding exactly the named permissions, and nothing else.
     *
     * A real role with real pivot rows rather than the super-admin shortcut, because
     * half of this file is about the control being refused without its permission, and
     * super-admin short-circuits hasPermission() to true.
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

        // admin.access is always granted: without it EnsureUserCanAccessAdmin signs the
        // session out before any permission on the route is ever consulted, and every
        // assertion below would pass or fail for the wrong reason.
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

    /** Somebody allowed to see the screen and to correct what an entry was charged. */
    private function corrector(): User
    {
        return $this->userWith(['participants.view', 'participants.update', 'participants.notify']);
    }

    private function recalculator(): RegistrationTotalsRecalculator
    {
        return app(RegistrationTotalsRecalculator::class);
    }

    /* ---------------------------------------------------------------------
     | The arithmetic
     * ------------------------------------------------------------------ */

    public function test_a_six_person_entry_previews_as_the_add_on_price_per_head(): void
    {
        $event = $this->event();
        [$addon, $size] = $this->tee($event);

        $registration = $this->undercharged($event, $addon, $size, 6);

        $corrections = $this->recalculator()->preview($event);

        $this->assertCount(1, $corrections);

        $correction = $corrections[0];

        $this->assertSame($registration->id, $correction->registration->id);
        $this->assertSame(6, $correction->people);
        $this->assertSame(40.0, $correction->currentAmount);
        $this->assertSame(240.0, $correction->correctedAmount);
        $this->assertSame(200.0, $correction->difference());
        $this->assertTrue($correction->changes());
        $this->assertNull($correction->blocked);
    }

    public function test_a_one_person_entry_previews_as_no_change_and_is_not_rewritten(): void
    {
        $event = $this->event();
        [$addon, $size] = $this->tee($event);

        // The live REG-2026-0080: one person, RM 40.00, already right.
        $registration = $this->undercharged($event, $addon, $size, 1);
        $this->receipt($registration, 40);

        $correction = $this->recalculator()->preview($event)[0];

        $this->assertSame(40.0, $correction->correctedAmount);
        $this->assertSame(0.0, $correction->difference());
        $this->assertFalse($correction->changes());

        $touchedBefore = $registration->fresh()->updated_at;
        $lineBefore = EventRegistrationAddon::query()
            ->where('event_registration_id', $registration->id)
            ->orderBy('id')
            ->pluck('line_total')
            ->all();

        $this->assertSame([], $this->recalculator()->apply($event));

        $after = $registration->fresh();

        // Not merely unchanged in value: not written at all. The lines keep the split
        // they were sold with, and the row keeps its timestamp.
        $this->assertEquals($touchedBefore, $after->updated_at);
        $this->assertSame($lineBefore, EventRegistrationAddon::query()
            ->where('event_registration_id', $registration->id)
            ->orderBy('id')
            ->pluck('line_total')
            ->all());

        $this->assertSame(EventRegistration::PAYMENT_PAID, $after->payment_status);
        $this->assertSame(EventRegistration::STATUS_CONFIRMED, $after->status);
    }

    public function test_an_event_that_does_not_charge_per_participant_has_nothing_to_correct(): void
    {
        $event = $this->event(['charges_addons_per_participant' => false]);
        [$addon, $size] = $this->tee($event);

        $this->undercharged($event, $addon, $size, 6);

        $correction = $this->recalculator()->preview($event)[0];

        $this->assertFalse($correction->changes());
        $this->assertSame([], $this->recalculator()->apply($event));
    }

    /* ---------------------------------------------------------------------
     | Applying it
     * ------------------------------------------------------------------ */

    public function test_applying_writes_the_corrected_total_and_its_lines(): void
    {
        $event = $this->event();
        [$addon, $size] = $this->tee($event);

        $registration = $this->undercharged($event, $addon, $size, 6);

        $this->actingAs($this->corrector())
            ->post(route('admin.event.participants.recalculate.apply', $event), ['confirm' => '1'])
            ->assertRedirect(route('admin.event.participants.recalculate', $event));

        $after = $registration->fresh();

        $this->assertSame('240.00', $after->amount);
        $this->assertSame('240.00', $after->addons_total);

        // The lines add up to the charge, which is what CHIP insists on: it totals the
        // line items itself and refuses a purchase whose lines disagree.
        $lines = EventRegistrationAddon::query()
            ->where('event_registration_id', $after->id)
            ->get();

        $this->assertSame(240.0, round((float) $lines->sum('line_total'), 2));
        $this->assertSame(6, $lines->whereNotNull('event_participant_id')->count());

        foreach ($lines->whereNotNull('event_participant_id') as $line) {
            $this->assertSame('40.00', $line->unit_price);
        }

        // The group line is kept as a record of the choice, at nothing, rather than
        // deleted: the price moved onto each person's own line.
        $group = $lines->whereNull('event_participant_id')->sole();
        $this->assertSame('0.00', $group->line_total);
    }

    public function test_applying_twice_changes_nothing_the_second_time(): void
    {
        $event = $this->event();
        [$addon, $size] = $this->tee($event);

        $registration = $this->undercharged($event, $addon, $size, 6);

        $this->assertCount(1, $this->recalculator()->apply($event));

        $afterFirst = $registration->fresh();
        $this->assertSame('240.00', $afterFirst->amount);

        $this->assertSame([], $this->recalculator()->apply($event));

        $afterSecond = $registration->fresh();

        $this->assertSame('240.00', $afterSecond->amount);
        $this->assertSame('240.00', $afterSecond->addons_total);
        $this->assertEquals($afterFirst->updated_at, $afterSecond->updated_at);
    }

    public function test_an_entry_that_paid_the_full_corrected_amount_keeps_reading_paid_and_confirmed(): void
    {
        $event = $this->event();
        [$addon, $size] = $this->tee($event);

        $registration = $this->undercharged($event, $addon, $size, 6);

        // Somebody who transferred the right money against the wrong invoice.
        $this->receipt($registration, 240);

        $this->assertCount(1, $this->recalculator()->apply($event));

        $after = $registration->fresh();

        $this->assertSame('240.00', $after->amount);
        $this->assertSame(EventRegistration::PAYMENT_PAID, $after->payment_status);
        $this->assertSame(EventRegistration::STATUS_CONFIRMED, $after->status);
        $this->assertSame(0.0, $after->outstandingAmount());
    }

    public function test_an_entry_that_paid_the_old_amount_becomes_partly_paid_with_the_balance_outstanding(): void
    {
        $event = $this->event();
        [$addon, $size] = $this->tee($event);

        $registration = $this->undercharged($event, $addon, $size, 6);
        $this->receipt($registration, 40);

        $correction = $this->recalculator()->preview($event)[0];

        // The preview promises the outcome before anything moves.
        $this->assertSame(EventRegistration::PAYMENT_PARTIAL, $correction->correctedPaymentStatus());
        $this->assertSame('RM 200.00', $correction->correctedOutstandingLabel());

        $this->recalculator()->apply($event);

        $after = $registration->fresh();

        $this->assertSame(EventRegistration::PAYMENT_PARTIAL, $after->payment_status);
        $this->assertSame('Partly Paid', $after->paymentStatusLabel());
        $this->assertSame(200.0, $after->outstandingAmount());
        $this->assertSame(40.0, $after->amountPaid());

        // It is not settled any more, so the place must stop reading as confirmed.
        $this->assertSame(EventRegistration::STATUS_PENDING, $after->status);
        $this->assertTrue($after->owesBalance());
        $this->assertFalse($after->isPaid());
    }

    public function test_an_entry_that_paid_nothing_stays_unpaid_and_simply_owes_more(): void
    {
        $event = $this->event();
        [$addon, $size] = $this->tee($event);

        $registration = $this->undercharged($event, $addon, $size, 3);

        $this->recalculator()->apply($event);

        $after = $registration->fresh();

        $this->assertSame('120.00', $after->amount);
        $this->assertSame(EventRegistration::PAYMENT_UNPAID, $after->payment_status);
        $this->assertSame(0.0, $after->amountPaid());
        $this->assertSame(120.0, $after->outstandingAmount());
    }

    public function test_a_changed_entry_leaves_a_trail_naming_who_did_it_and_both_totals(): void
    {
        $event = $this->event();
        [$addon, $size] = $this->tee($event);

        $registration = $this->undercharged($event, $addon, $size, 6);
        $admin = $this->corrector();

        $this->actingAs($admin)
            ->post(route('admin.event.participants.recalculate.apply', $event), ['confirm' => '1'])
            ->assertSessionHasNoErrors();

        $audit = \App\Models\AuditLog::query()
            ->where('auditable_type', EventRegistration::class)
            ->where('auditable_id', $registration->id)
            ->where('event', 'amount.recalculated')
            ->sole();

        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame(40.0, (float) $audit->old_values['amount']);
        $this->assertSame(240.0, (float) $audit->new_values['amount']);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action' => 'participants.recalculate',
        ]);
    }

    /* ---------------------------------------------------------------------
     | The screen
     * ------------------------------------------------------------------ */

    public function test_the_preview_writes_nothing(): void
    {
        $event = $this->event();
        [$addon, $size] = $this->tee($event);

        $registration = $this->undercharged($event, $addon, $size, 6);
        $before = $registration->fresh();

        $response = $this->actingAs($this->corrector())
            ->get(route('admin.event.participants.recalculate', $event));

        $response->assertOk();
        $response->assertSee($registration->reference);
        $response->assertSee('RM 240.00');
        $response->assertSee('+RM 200.00');

        $after = $registration->fresh();

        $this->assertSame($before->amount, $after->amount);
        $this->assertSame($before->addons_total, $after->addons_total);
        $this->assertEquals($before->updated_at, $after->updated_at);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_the_preview_lists_the_entries_that_need_no_change(): void
    {
        $event = $this->event();
        [$addon, $size] = $this->tee($event);

        $single = $this->undercharged($event, $addon, $size, 1);

        $response = $this->actingAs($this->corrector())
            ->get(route('admin.event.participants.recalculate', $event));

        $response->assertOk();
        $response->assertSee('No change needed · 1 entry');
        $response->assertSee('Already charging the right amount.');
        $response->assertSee($single->reference);
    }

    public function test_a_cancelled_or_refunded_entry_is_left_as_it_stands(): void
    {
        $event = $this->event();
        [$addon, $size] = $this->tee($event);

        $cancelled = $this->undercharged($event, $addon, $size, 6, [
            'status' => EventRegistration::STATUS_CANCELLED,
        ]);

        $refunded = $this->undercharged($event, $addon, $size, 6);
        $refunded->forceFill([
            'payment_status' => EventRegistration::PAYMENT_REFUNDED,
            'amount_paid' => 40,
            'refunded_amount' => 40,
            'refunded_at' => now(),
        ])->save();

        $this->assertSame([], $this->recalculator()->apply($event));

        $this->assertSame('40.00', $cancelled->fresh()->amount);
        $this->assertSame('40.00', $refunded->fresh()->amount);

        $response = $this->actingAs($this->corrector())
            ->get(route('admin.event.participants.recalculate', $event));

        $response->assertOk();
        $response->assertSee('Cancelled, so its charge is left as it stands.');
        $response->assertSee('Refunded, so its charge is left as it stands.');
    }

    public function test_the_participants_screen_offers_the_action_once_an_event_is_chosen(): void
    {
        $event = $this->event();
        [$addon, $size] = $this->tee($event);

        $this->undercharged($event, $addon, $size, 6);

        $admin = $this->corrector();

        // No event chosen: the arithmetic belongs to one event's catalogue, so the
        // button is not offered as a sweep across all of them.
        $unfiltered = $this->actingAs($admin)->get(route('admin.event.participants', ['tab' => 'group']));
        $unfiltered->assertOk();
        $unfiltered->assertDontSee('Recheck Totals');

        $filtered = $this->actingAs($admin)
            ->get(route('admin.event.participants', ['tab' => 'group', 'event' => $event->id]));

        $filtered->assertOk();
        $filtered->assertSee('Recheck Totals');
        $filtered->assertSee(route('admin.event.participants.recalculate', $event), false);

        // And not to somebody who may only read the list.
        $viewer = $this->actingAs($this->userWith(['participants.view']))
            ->get(route('admin.event.participants', ['tab' => 'group', 'event' => $event->id]));

        $viewer->assertOk();
        $viewer->assertDontSee('Recheck Totals');
    }

    public function test_the_action_is_refused_without_the_permission_and_refused_on_get(): void
    {
        $event = $this->event();
        [$addon, $size] = $this->tee($event);

        $registration = $this->undercharged($event, $addon, $size, 6);

        // Able to read the screen, not to correct it.
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

        $this->assertSame('40.00', $registration->fresh()->amount);
    }

    public function test_the_apply_is_refused_without_the_confirmation(): void
    {
        $event = $this->event();
        [$addon, $size] = $this->tee($event);

        $registration = $this->undercharged($event, $addon, $size, 6);

        $this->actingAs($this->corrector())
            ->post(route('admin.event.participants.recalculate.apply', $event))
            ->assertSessionHasErrors('confirm');

        $this->assertSame('40.00', $registration->fresh()->amount);
    }

    public function test_the_grouping_tab_totals_follow_the_corrected_amounts(): void
    {
        $event = $this->event();
        [$addon, $size] = $this->tee($event);

        // Six people who paid RM 40.00 of a real RM 240.00, and three who paid nothing.
        $short = $this->undercharged($event, $addon, $size, 6);
        $this->receipt($short, 40);

        $this->undercharged($event, $addon, $size, 3);

        $admin = $this->corrector();

        $before = $this->actingAs($admin)->get(route('admin.event.participants', ['tab' => 'group']));
        $before->assertOk();

        // The figures the owner was shown: RM 40.00 collected against RM 40.00 still
        // owed, both built from the wrong totals.
        $before->assertSee('RM 40.00');

        $this->actingAs($admin)
            ->post(route('admin.event.participants.recalculate.apply', $event), ['confirm' => '1'])
            ->assertSessionHasNoErrors();

        $after = $this->actingAs($admin)->get(route('admin.event.participants', ['tab' => 'group']));
        $after->assertOk();

        // RM 40.00 in, RM 200.00 + RM 120.00 still to come.
        $after->assertSee('RM 320.00');
        $after->assertSee('Partly Paid');

        $this->assertSame(40.0, \App\Support\PaymentFigures::collected());
        $this->assertSame(320.0, \App\Support\PaymentFigures::outstanding());
    }

    /* ---------------------------------------------------------------------
     | Chasing the balance
     * ------------------------------------------------------------------ */

    public function test_the_balance_charge_asks_for_the_outstanding_and_not_the_corrected_total(): void
    {
        $event = $this->event();
        [$addon, $size] = $this->tee($event);

        $registration = $this->undercharged($event, $addon, $size, 6);
        $this->receipt($registration, 40);

        $this->recalculator()->apply($event);

        $charge = app(RegistrationBalanceCharge::class)->build($registration->fresh());

        $this->assertSame(20000, $charge->amountCents);
        $this->assertNotSame(24000, $charge->amountCents);
        $this->assertSame($registration->reference, $charge->reference);
        $this->assertCount(1, $charge->products);
        $this->assertSame(20000, $charge->products[0]['price']);
        $this->assertSame('1', $charge->products[0]['quantity']);
    }

    public function test_pressing_pay_on_the_reminder_link_opens_a_purchase_for_the_balance(): void
    {
        $event = $this->event();
        [$addon, $size] = $this->tee($event);

        $registration = $this->undercharged($event, $addon, $size, 6);
        $this->receipt($registration, 40);

        $this->recalculator()->apply($event);

        $this->configureGateway();

        Http::fake([
            'gate.chip-in.asia/api/v1/purchases/' => Http::response([
                'id' => 'pur_balance',
                'checkout_url' => 'https://gate.chip-in.asia/p/pur_balance',
                'status' => 'created',
            ]),
        ]);

        $payUrl = URL::temporarySignedRoute(
            'registration.payment.pay',
            now()->addDays(30),
            ['reference' => $registration->reference],
        );

        $this->post($payUrl)->assertRedirect('https://gate.chip-in.asia/p/pur_balance');

        // RM 200.00, to the cent, and built from the row rather than from the request.
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/purchases/')) {
                return false;
            }

            $products = $request->data()['purchase']['products'];

            $total = array_sum(array_map(
                fn (array $product) => $product['price'] * (int) $product['quantity'],
                $products,
            ));

            return $total === 20000;
        });
    }

    public function test_a_part_paid_entry_can_be_chased_and_the_reminder_carries_a_link(): void
    {
        $event = $this->event();
        [$addon, $size] = $this->tee($event);

        $registration = $this->undercharged($event, $addon, $size, 6);
        $this->receipt($registration, 40);

        $this->recalculator()->apply($event);

        $this->seed(\Database\Seeders\EventTemplateSeeder::class);

        $this->actingAs($this->corrector())
            ->post(route('admin.event.participants.remind', $registration))
            ->assertSessionHasNoErrors();

        $notification = $registration->fresh()->notifications()->sole();

        $this->assertSame('payment.reminder', $notification->template_key);
        $this->assertSame(\App\Models\EventNotification::STATUS_QUEUED, $notification->status);

        // The reminder reaches the registrant with a way to pay. Before this, a
        // part-paid entry was sent a chase-up with the link left empty.
        Mail::assertQueued(\App\Mail\EventTemplateMail::class, function ($mail) use ($registration) {
            return str_contains($mail->renderedBody, '/registration/payment/' . $registration->reference)
                && str_contains($mail->renderedBody, 'RM 200.00');
        });
    }

    public function test_the_public_payment_page_offers_the_balance_rather_than_the_whole_fee(): void
    {
        $event = $this->event();
        [$addon, $size] = $this->tee($event);

        $registration = $this->undercharged($event, $addon, $size, 6);
        $this->receipt($registration, 40);

        $this->recalculator()->apply($event);
        $this->configureGateway();

        $response = $this->get(URL::temporarySignedRoute(
            'registration.payment',
            now()->addDays(30),
            ['reference' => $registration->reference],
        ));

        $response->assertOk();
        $response->assertSee('Pay RM 200.00');
        $response->assertSee('Partly Paid');
        $response->assertDontSee('Pay RM 240.00');
    }

    /* ---------------------------------------------------------------------
     | Shape A — the entries charged as an event fee, with no item lines
     |
     | The reason this action had to be rewritten. These rows are the thirty-two the
     | owner was looking at, and the old arithmetic reported "No items on this entry,
     | so there is nothing to re-price" for every one of them.
     * ------------------------------------------------------------------ */

    public function test_a_fee_era_entry_with_no_item_lines_is_priced_from_its_head_count(): void
    {
        $event = $this->event();
        $this->tee($event);

        $registration = $this->shapeA($event, 6);

        $this->assertSame(0, $registration->addonLines()->count());

        $correction = $this->recalculator()->preview($event)[0];

        $this->assertNull($correction->blocked, 'A fee-era entry must not be reported as having nothing to re-price.');
        $this->assertSame(6, $correction->people);
        $this->assertSame(40.0, $correction->currentAmount);
        $this->assertSame(240.0, $correction->correctedAmount);
        $this->assertSame(200.0, $correction->difference());
        $this->assertTrue($correction->changes());

        /*
         | The one arithmetic that would double charge. registration_fee on these rows
         | still carries the RM 40.00 from when the shirt was the fee, and adding it to
         | six shirts gives RM 280.00: the organiser would be billing a shirt nobody
         | takes delivery of.
         */
        $this->assertNotSame(280.0, $correction->correctedAmount);
    }

    public function test_a_three_person_fee_era_entry_is_short_by_eighty(): void
    {
        $event = $this->event();
        $this->tee($event);

        // REG-2026-0072 exactly: three people, RM 40.00 arrived against the old figure.
        $registration = $this->shapeA($event, 3);
        $this->receipt($registration, 40);

        $correction = $this->recalculator()->preview($event)[0];

        $this->assertSame(120.0, $correction->correctedAmount);
        $this->assertSame(80.0, $correction->correctedOutstanding());
        $this->assertSame(EventRegistration::PAYMENT_PARTIAL, $correction->correctedPaymentStatus());
    }

    /**
     * REG-2026-0047: one person who paid RM 40.00 and owes RM 40.00.
     *
     * This used to be asserted as left completely untouched, on the grounds that the
     * total was right. The total is right; the row is still wrong, and the shirt list
     * is what proves it — it is built from item lines, and this entry has none, so
     * somebody who has paid for a shirt does not appear on it. So the money stays
     * exactly where it is and the description of it is corrected. The whole of the
     * money side is pinned here: amount, amount_paid, status and badge.
     */
    public function test_a_one_person_fee_era_entry_is_re_itemised_without_moving_its_money(): void
    {
        $event = $this->event();
        [$addon] = $this->tee($event);

        $registration = $this->shapeA($event, 1);
        $this->receipt($registration, 40);

        $correction = $this->recalculator()->preview($event)[0];

        $this->assertSame(40.0, $correction->correctedAmount);
        $this->assertSame(0.0, $correction->difference());

        // The two kinds of wrong, named apart. Nothing about the total moves.
        $this->assertFalse($correction->movesMoney());
        $this->assertTrue($correction->reshapes());
        $this->assertTrue($correction->changes());

        $this->assertCount(1, $this->recalculator()->apply($event));

        $after = $registration->fresh();

        // Not a sen moves, and nothing that follows the money is touched.
        $this->assertSame('40.00', $after->amount);
        $this->assertSame('40.00', $after->amount_paid);
        $this->assertSame(EventRegistration::PAYMENT_PAID, $after->payment_status);
        $this->assertSame(EventRegistration::STATUS_CONFIRMED, $after->status);

        // The charge now says what it is for.
        $this->assertSame('0.00', $after->registration_fee);
        $this->assertSame('40.00', $after->addons_total);

        $line = $after->addonLines->sole();

        $this->assertSame($addon->id, $line->event_addon_id);
        $this->assertSame($registration->participants->sole()->id, $line->event_participant_id);
        $this->assertSame('40.00', $line->line_total);
        $this->assertNull($line->event_addon_variant_id);
    }

    public function test_the_three_shapes_together_change_only_the_ones_that_are_wrong(): void
    {
        $event = $this->event();
        [$addon, $size] = $this->tee($event);

        // One of each, mirroring the live table.
        $feeEra = $this->shapeA($event, 6);        // 40.00, should be 240.00
        $feeEraSingle = $this->shapeA($event, 1);  // 40.00, already right
        $groupLine = $this->undercharged($event, $addon, $size, 1);
        $perHead = $this->shapeC($event, $addon, $size, 2);

        $corrections = collect($this->recalculator()->preview($event))
            ->keyBy(fn ($correction) => $correction->registration->reference);

        $this->assertTrue($corrections[$feeEra->reference]->movesMoney());
        $this->assertSame(240.0, $corrections[$feeEra->reference]->correctedAmount);

        // Right total, wrong description: corrected without moving money.
        $this->assertFalse($corrections[$feeEraSingle->reference]->movesMoney());
        $this->assertTrue($corrections[$feeEraSingle->reference]->reshapes());

        // Already itemised the way this would write it. Left alone entirely, lines and
        // all, because the split it was sold with is not wrong.
        $this->assertFalse($corrections[$groupLine->reference]->changes());

        $this->assertFalse($corrections[$perHead->reference]->changes());
        $this->assertSame(80.0, $corrections[$perHead->reference]->correctedAmount);

        $applied = $this->recalculator()->apply($event);

        $this->assertCount(2, $applied);
        $this->assertSame(
            [$feeEra->reference, $feeEraSingle->reference],
            collect($applied)->map(fn ($correction) => $correction->registration->reference)->sort()->values()->all(),
        );
    }

    public function test_confirming_a_fee_era_entry_itemises_it_per_person_and_clears_the_old_fee(): void
    {
        $event = $this->event();
        [$addon] = $this->tee($event);

        $registration = $this->shapeA($event, 6);

        $this->actingAs($this->corrector())
            ->post(route('admin.event.participants.recalculate.apply', $event), ['confirm' => '1'])
            ->assertSessionHasNoErrors();

        $after = $registration->fresh();

        $this->assertSame('240.00', $after->amount);
        $this->assertSame('240.00', $after->addons_total);

        // The fee the shirt used to be charged as, cleared. Left on the row it would
        // be charged a second time on top of the shirts, and would put a phantom
        // registration line on the gateway page beside them.
        $this->assertSame('0.00', $after->registration_fee);

        $lines = $after->addonLines;

        $this->assertCount(6, $lines);
        $this->assertSame(240.0, round((float) $lines->sum('line_total'), 2));
        $this->assertSame(6, $lines->whereNotNull('event_participant_id')->count());

        foreach ($lines as $line) {
            $this->assertSame('40.00', $line->unit_price);
            $this->assertSame($addon->id, $line->event_addon_id);

            // No size was ever recorded for these people, and none is invented. The
            // charge is owed because the shirt is required; the size is collected at
            // the counter.
            $this->assertNull($line->event_addon_variant_id);
            $this->assertNull($line->variant_label);
        }
    }

    public function test_creating_lines_without_a_size_moves_no_stock(): void
    {
        $event = $this->event();
        [, $size] = $this->tee($event);

        // Stock already consumed by the entries that did record a size.
        $size->forceFill(['stock_taken' => 3])->save();

        $this->shapeA($event, 6);

        $this->recalculator()->apply($event);

        $this->assertSame(3, $size->fresh()->stock_taken);
        $this->assertSame(497, $size->fresh()->stockLeft());
    }

    public function test_confirming_a_fee_era_entry_twice_is_a_no_op(): void
    {
        $event = $this->event();
        $this->tee($event);

        $registration = $this->shapeA($event, 6);

        $this->assertCount(1, $this->recalculator()->apply($event));

        $first = $registration->fresh();
        $firstLines = $first->addonLines->pluck('line_total', 'id')->all();

        $this->assertSame([], $this->recalculator()->apply($event));

        $second = $registration->fresh();

        $this->assertSame('240.00', $second->amount);
        $this->assertSame('0.00', $second->registration_fee);
        $this->assertEquals($first->updated_at, $second->updated_at);
        $this->assertSame($firstLines, $second->addonLines->pluck('line_total', 'id')->all());
        $this->assertCount(6, $second->addonLines);
    }

    /* ---------------------------------------------------------------------
     | How short it is, on the list
     * ------------------------------------------------------------------ */

    public function test_a_part_paid_entry_shows_its_shortfall_beside_the_payment(): void
    {
        $event = $this->event();
        $this->tee($event);

        $registration = $this->shapeA($event, 3);
        $this->receipt($registration, 40);

        $admin = $this->corrector();

        $this->actingAs($admin)
            ->post(route('admin.event.participants.recalculate.apply', $event), ['confirm' => '1'])
            ->assertSessionHasNoErrors();

        $after = $registration->fresh();

        $this->assertSame('120.00', $after->amount);
        $this->assertSame(EventRegistration::PAYMENT_PARTIAL, $after->payment_status);
        $this->assertSame(80.0, $after->outstandingAmount());

        $list = $this->actingAs($admin)->get(route('admin.event.participants', ['tab' => 'group']));

        $list->assertOk();
        $list->assertSee('Shortfall');
        $list->assertSee('Partly Paid');
        $list->assertSee('RM 80.00 short');

        // Neither Paid nor Unpaid, which were the two readings the owner was given.
        $this->assertFalse($after->isPaid());
        $this->assertTrue($after->isPartlyPaid());
    }

    public function test_a_covered_entry_reads_paid_with_nothing_short(): void
    {
        $event = $this->event();
        $this->tee($event);

        // Somebody who transferred the right money against the wrong invoice.
        $registration = $this->shapeA($event, 3);
        $this->receipt($registration, 120);

        $admin = $this->corrector();

        $this->actingAs($admin)
            ->post(route('admin.event.participants.recalculate.apply', $event), ['confirm' => '1'])
            ->assertSessionHasNoErrors();

        $after = $registration->fresh();

        $this->assertSame('120.00', $after->amount);
        $this->assertSame(EventRegistration::PAYMENT_PAID, $after->payment_status);
        $this->assertSame(EventRegistration::STATUS_CONFIRMED, $after->status);

        $list = $this->actingAs($admin)->get(route('admin.event.participants', ['tab' => 'group']));

        $list->assertOk();
        $list->assertSee('Correct');
        $list->assertDontSee('RM 0.00 short');
        $this->assertSame(0.0, $after->outstandingAmount());
    }

    public function test_the_amount_column_and_the_tab_totals_follow_the_corrected_figures(): void
    {
        $event = $this->event();
        $this->tee($event);

        $six = $this->shapeA($event, 6);
        $this->receipt($six, 40);

        $this->shapeA($event, 3);

        $admin = $this->corrector();

        $this->actingAs($admin)
            ->post(route('admin.event.participants.recalculate.apply', $event), ['confirm' => '1'])
            ->assertSessionHasNoErrors();

        $list = $this->actingAs($admin)->get(route('admin.event.participants', ['tab' => 'group']));

        $list->assertOk();
        $list->assertSee('RM 240.00');
        $list->assertSee('RM 120.00');
        $list->assertSee('RM 320.00');

        $this->assertSame(40.0, \App\Support\PaymentFigures::collected());
        $this->assertSame(320.0, \App\Support\PaymentFigures::outstanding());
    }

    public function test_the_preview_of_a_fee_era_entry_writes_nothing(): void
    {
        $event = $this->event();
        $this->tee($event);

        $registration = $this->shapeA($event, 6);
        $before = $registration->fresh();

        $response = $this->actingAs($this->corrector())
            ->get(route('admin.event.participants.recalculate', $event));

        $response->assertOk();
        $response->assertSee($registration->reference);
        $response->assertSee('RM 240.00');
        $response->assertSee('+RM 200.00');
        $response->assertSee('6 with no size on record');

        $after = $registration->fresh();

        $this->assertSame($before->amount, $after->amount);
        $this->assertSame($before->registration_fee, $after->registration_fee);
        $this->assertSame($before->addons_total, $after->addons_total);
        $this->assertEquals($before->updated_at, $after->updated_at);
        $this->assertSame(0, $after->addonLines()->count());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    /* ---------------------------------------------------------------------
     | Asking for the balance only
     * ------------------------------------------------------------------ */

    public function test_the_balance_link_on_a_fee_era_entry_charges_the_shortfall_only(): void
    {
        $event = $this->event();
        $this->tee($event);

        $registration = $this->shapeA($event, 3);
        $this->receipt($registration, 40);

        $this->recalculator()->apply($event);

        $charge = app(RegistrationBalanceCharge::class)->build($registration->fresh());

        // RM 80.00, which is what is missing. Not RM 120.00, which is the whole charge.
        $this->assertSame(8000, $charge->amountCents);
        $this->assertNotSame(12000, $charge->amountCents);
        $this->assertSame(8000, $charge->products[0]['price']);
        $this->assertCount(1, $charge->products);
    }

    /** CHIP configured enough for a checkout to be opened against the fake. */
    private function configureGateway(): void
    {
        Setting::write('integration.payments.provider', PaymentSettings::PROVIDER_CHIP, 'integration.payments');
        Setting::write('integration.payments.chip_brand_id', 'brand-' . uniqid(), 'integration.payments');
        Setting::write('integration.payments.chip_api_key', 'key-' . uniqid(), 'integration.payments');
        Setting::write('integration.payments.currency', 'MYR', 'integration.payments');
    }
}
