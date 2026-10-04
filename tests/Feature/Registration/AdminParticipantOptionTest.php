<?php

namespace Tests\Feature\Registration;

use App\Http\Controllers\ParticipantSizeController;
use App\Models\ActivityLog;
use App\Models\AuditLog;
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
use App\Services\Registration\ParticipantSizeWriter;
use App\Support\ParticipantOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Recording what one person is taking, from the admin correction dialog.
 *
 * WHY IT EXISTS
 *
 * The event asks each head for a size, the public form collects it and the signed
 * confirmation link collects it again from whoever registered. An administrator could
 * not set one at all. On event day that is the whole problem: forty-three people have
 * no size on record, most of them will never open the email, and the counter needs to
 * write one down as the shirt is handed over or taken over the phone.
 *
 * WHAT IS BEING ASSERTED
 *
 * Mostly negatives, because this screen sits beside real receipts. Not one money field
 * moves, the receipt ledger is left byte for byte as it was, and stock_taken stays
 * exactly what the real selections add up to. The one case where a choice could move
 * money — an option carrying its own price, the 5XL that genuinely costs more — is
 * refused outright rather than silently re-priced, and the refusal says where to take
 * it instead.
 *
 * The fields themselves come from the event's own configuration, not from a list in the
 * dialog, so an event collecting nothing draws nothing.
 */
class AdminParticipantOptionTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private EventAddon $tee;

    /** @var array<string, EventAddonVariant> label => option */
    private array $options = [];

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

        // Price null and stock null, exactly as the live rows are.
        foreach (['S', 'M', 'L', 'XL'] as $order => $label) {
            $this->options[$label] = EventAddonVariant::create([
                'event_addon_id' => $this->tee->id,
                'label' => $label,
                'price' => null,
                'stock' => null,
                'sort_order' => $order + 1,
            ]);
        }

        /*
         | The one that costs more. Nothing live carries a price today, and that is
         | exactly why it is in the fixture: the refusal must be driven by the row
         | rather than by an assumption that every option is free.
         */
        $this->options['5XL'] = EventAddonVariant::create([
            'event_addon_id' => $this->tee->id,
            'label' => '5XL',
            'price' => 5,
            'stock' => null,
            'sort_order' => 5,
        ]);

        $this->tee = $this->tee->fresh();
    }

    /* ---------------------------------------------------------------------
     | Fixtures
     * ------------------------------------------------------------------ */

    /** @param  array<string, mixed>  $money */
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
            'email' => strtolower(str_replace('-', '', $registration->reference)) . $n . '@example.test',
            'gender' => 'male',
            'race' => 'malay',
        ]);
    }

    /** A line carrying the charge with no option named on it. */
    private function lineWithoutOption(EventRegistration $registration, EventParticipant $person): EventRegistrationAddon
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

    /** Somebody who did choose, whose choice took stock when they registered. */
    private function lineWithOption(EventRegistration $registration, EventParticipant $person, string $label): EventRegistrationAddon
    {
        $option = $this->options[$label];

        $option->increment('stock_taken');

        return EventRegistrationAddon::create([
            'event_registration_id' => $registration->id,
            'event_participant_id' => $person->id,
            'event_addon_id' => $this->tee->id,
            'event_addon_variant_id' => $option->id,
            'name' => $this->tee->name,
            'variant_label' => $option->label,
            'unit_price' => 40,
            'quantity' => 1,
            'line_total' => 40,
        ]);
    }

    /** A receipt on the entry, so the ledger has something to be left alone. */
    private function receipt(EventRegistration $registration, float $amount): EventRegistrationPayment
    {
        return EventRegistrationPayment::create([
            'event_registration_id' => $registration->id,
            'amount' => $amount,
            'received_at' => now()->subDay(),
            'source' => EventRegistrationPayment::SOURCE_MANUAL,
            'actor_label' => 'counter',
        ]);
    }

    /* ---------------------------------------------------------------------
     | Users
     * ------------------------------------------------------------------ */

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

        // admin.access is always granted: without it EnsureUserCanAccessAdmin signs the
        // session out before any permission on the route is consulted.
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
     * Somebody who may correct a person's details.
     *
     * participants.update is the permission the correction dialog already sits behind,
     * which is the whole reason the choice was put inside that dialog: no role has to
     * be re-seeded for the counter to be able to record a size.
     */
    private function editor(): User
    {
        return $this->userWith(['participants.view', 'participants.update']);
    }

    /* ---------------------------------------------------------------------
     | Helpers
     * ------------------------------------------------------------------ */

    /**
     * The dialog's own payload: this person's details exactly as they stand, plus
     * whatever was chosen for them.
     *
     * @param  array<int, int|string>  $choices  addonId => optionId
     * @return array<string, mixed>
     */
    private function payload(EventParticipant $person, array $choices = []): array
    {
        // Read back from the row rather than from the object the fixture built, so
        // the payload carries the columns the table filled in by default.
        $person = $person->fresh() ?? $person;

        return [
            'editing_person' => $person->id,
            'full_name' => $person->full_name,
            'ic_number' => $person->ic_number,
            'phone' => $person->phone,
            'email' => $person->email,
            'gender' => $person->gender,
            'race' => $person->race,

            // Posted back as they stand, because the request makes a column the row
            // already carries compulsory. country arrives with a default, so leaving
            // it out would make every save fail on a field nobody touched.
            'address_line_1' => $person->address_line_1,
            'address_line_2' => $person->address_line_2,
            'postcode' => $person->postcode,
            'city' => $person->city,
            'state' => $person->state,
            'country' => $person->country,

            'sizes' => $choices === [] ? [] : [$person->id => $choices],
        ];
    }

    /**
     * Save the dialog.
     *
     * @param  array<int, int|string>  $choices  addonId => optionId
     * @param  array<string, mixed>  $overrides
     */
    private function save(
        EventRegistration $registration,
        EventParticipant $person,
        array $choices = [],
        ?User $user = null,
        array $overrides = [],
    ) {
        return $this->actingAs($user ?? $this->editor())
            ->from(route('admin.event.participants.show', $registration))
            ->put(
                route('admin.event.participants.person.update', [$registration, $person]),
                array_merge($this->payload($person, $choices), $overrides),
            );
    }

    private function open(EventRegistration $registration, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->editor())
            ->get(route('admin.event.participants.show', $registration));
    }

    /**
     * Every money field on the entry, as the row actually holds them.
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

    /**
     * The receipt ledger, row for row.
     *
     * @return array<int, array<string, mixed>>
     */
    private function ledger(EventRegistration $registration): array
    {
        return EventRegistrationPayment::query()
            ->where('event_registration_id', $registration->id)
            ->orderBy('id')
            ->get()
            ->map(fn (EventRegistrationPayment $row) => [
                'id' => $row->id,
                'amount' => $row->amount,
                'source' => $row->source,
                'reference' => $row->reference,
                'recorded_by' => $row->recorded_by,
                'actor_label' => $row->actor_label,
                'received_at' => $row->received_at?->toDateTimeString(),
                'created_at' => $row->created_at?->toDateTimeString(),
                'updated_at' => $row->updated_at?->toDateTimeString(),
            ])
            ->all();
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

    /** The error key the dialog binds a refusal to. */
    private function key(EventParticipant $person): string
    {
        return sprintf('sizes.%d.%d', $person->id, $this->tee->id);
    }

    /* ---------------------------------------------------------------------
     | 1. Recording one, with nothing else moving
     * ------------------------------------------------------------------ */

    public function test_an_admin_records_a_choice_for_somebody_who_had_none(): void
    {
        $registration = $this->registration('REG-2026-0101', [
            'payment_status' => EventRegistration::PAYMENT_PARTIAL,
            'addons_total' => 80,
            'amount' => 80,
            'amount_paid' => 40,
        ]);

        $person = $this->person($registration, 1);
        $other = $this->person($registration, 2, ParticipantOptions::ROLE_PARTICIPANT);

        $line = $this->lineWithoutOption($registration, $person);
        $otherLine = $this->lineWithoutOption($registration, $other);

        $this->receipt($registration, 40);

        $money = $this->money($registration);
        $ledger = $this->ledger($registration);

        $this->save($registration, $person, [$this->tee->id => $this->options['M']->id])
            ->assertRedirect(route('admin.event.participants.show', $registration))
            ->assertSessionHasNoErrors();

        $line = $line->fresh();

        $this->assertSame($this->options['M']->id, $line->event_addon_variant_id);
        $this->assertSame('M', $line->variant_label);

        // The same two rows, and what the entry was charged untouched on both.
        $this->assertSame(2, $registration->addonLines()->count());
        $this->assertSame('40.00', $line->unit_price);
        $this->assertSame(1, $line->quantity);
        $this->assertSame('40.00', $line->line_total);

        // One person's dialog answers for one person.
        $this->assertNull($otherLine->fresh()->event_addon_variant_id);

        // THE assertion. Nothing about what is owed, what arrived, or where the entry
        // stands has moved, and the receipt ledger is as it was.
        $this->assertSame($money, $this->money($registration));
        $this->assertSame($ledger, $this->ledger($registration));

        $this->assertSame(['S' => 0, 'M' => 1, 'L' => 0, 'XL' => 0, '5XL' => 0], $this->stock());
    }

    public function test_a_participant_with_no_line_at_all_gets_one_at_zero(): void
    {
        // The fee-era shape: one person, RM 40.00 in the fee rather than in the items,
        // settled in full, and no item line to write a choice onto.
        $registration = $this->registration('REG-2026-0102', [
            'payment_status' => EventRegistration::PAYMENT_PAID,
            'registration_fee' => 40,
            'addons_total' => 0,
            'amount' => 40,
            'amount_paid' => 40,
        ]);

        $person = $this->person($registration, 1);
        $this->receipt($registration, 40);

        $money = $this->money($registration);
        $ledger = $this->ledger($registration);

        $this->save($registration, $person, [$this->tee->id => $this->options['L']->id])
            ->assertSessionHasNoErrors();

        $line = $registration->addonLines()->sole();

        $this->assertSame($this->options['L']->id, $line->event_addon_variant_id);
        $this->assertSame('L', $line->variant_label);

        // Zero, and it must stay zero: this person has already paid for the shirt.
        $this->assertSame('0.00', $line->unit_price);
        $this->assertSame('0.00', $line->line_total);

        $this->assertSame($money, $this->money($registration));
        $this->assertSame($ledger, $this->ledger($registration));
    }

    public function test_the_dialog_still_corrects_details_while_recording_a_choice(): void
    {
        $registration = $this->registration('REG-2026-0103');
        $person = $this->person($registration, 1);
        $this->lineWithoutOption($registration, $person);

        $money = $this->money($registration);

        $this->save($registration, $person, [$this->tee->id => $this->options['S']->id], null, [
            'full_name' => 'Peserta Yang Betul',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Peserta Yang Betul', $person->fresh()->full_name);
        $this->assertSame($this->options['S']->id, $registration->addonLines()->sole()->event_addon_variant_id);
        $this->assertSame($money, $this->money($registration));
    }

    /* ---------------------------------------------------------------------
     | 2. Stock, which must stay exact
     * ------------------------------------------------------------------ */

    public function test_changing_a_recorded_choice_moves_the_count_rather_than_adding_to_it(): void
    {
        $registration = $this->registration('REG-2026-0104');
        $person = $this->person($registration, 1);
        $line = $this->lineWithOption($registration, $person, 'M');

        $this->assertSame(['S' => 0, 'M' => 1, 'L' => 0, 'XL' => 0, '5XL' => 0], $this->stock());

        $money = $this->money($registration);

        $this->save($registration, $person, [$this->tee->id => $this->options['XL']->id])
            ->assertSessionHasNoErrors();

        // One shirt before, one shirt after. The old size is handed back.
        $this->assertSame(['S' => 0, 'M' => 0, 'L' => 0, 'XL' => 1, '5XL' => 0], $this->stock());

        $this->assertSame($this->options['XL']->id, $line->fresh()->event_addon_variant_id);
        $this->assertSame('XL', $line->fresh()->variant_label);
        $this->assertSame(1, $registration->addonLines()->count());
        $this->assertSame($money, $this->money($registration));
    }

    public function test_submitting_an_unchanged_choice_moves_nothing(): void
    {
        $registration = $this->registration('REG-2026-0105');
        $person = $this->person($registration, 1);
        $line = $this->lineWithOption($registration, $person, 'L');

        $this->receipt($registration, 40);

        $money = $this->money($registration);
        $ledger = $this->ledger($registration);
        $touched = $line->fresh()->updated_at;

        $this->save($registration, $person, [$this->tee->id => $this->options['L']->id])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $this->assertSame(['S' => 0, 'M' => 0, 'L' => 1, 'XL' => 0, '5XL' => 0], $this->stock());
        $this->assertSame(1, $registration->addonLines()->count());
        $this->assertEquals($touched, $line->fresh()->updated_at, 'An unchanged choice must not even touch the row.');
        $this->assertSame($money, $this->money($registration));
        $this->assertSame($ledger, $this->ledger($registration));

        // Nothing happened, and nothing claims to have happened.
        $this->assertFalse(AuditLog::query()->where('event', ParticipantSizeWriter::TRAIL_BY_STAFF)->exists());
        $this->assertFalse(ActivityLog::query()->where('action', 'participants.person-option')->exists());
    }

    public function test_leaving_the_field_alone_leaves_what_is_on_record(): void
    {
        $registration = $this->registration('REG-2026-0106');
        $person = $this->person($registration, 1);
        $line = $this->lineWithOption($registration, $person, 'M');

        /*
         | The dialog opened to fix a phone number, with the field left on "Not
         | recorded" — which is what a blank posts. A blank means "leave whatever is on
         | record" and must never read as "erase it".
         */
        $this->save($registration, $person, [$this->tee->id => ''], null, ['phone' => '0198887777'])
            ->assertSessionHasNoErrors();

        $this->assertSame('0198887777', $person->fresh()->phone);
        $this->assertSame($this->options['M']->id, $line->fresh()->event_addon_variant_id);
        $this->assertSame(['S' => 0, 'M' => 1, 'L' => 0, 'XL' => 0, '5XL' => 0], $this->stock());
    }

    /* ---------------------------------------------------------------------
     | 3. Stock limits, which are null today but must not be assumed
     * ------------------------------------------------------------------ */

    public function test_a_sold_out_option_is_refused_and_drawn_as_unavailable(): void
    {
        $this->options['S']->forceFill(['stock' => 1, 'stock_taken' => 1])->save();

        $registration = $this->registration('REG-2026-0107');
        $person = $this->person($registration, 1);
        $this->lineWithoutOption($registration, $person);

        $page = $this->open($registration);
        $page->assertOk();
        $page->assertSee('sold out');

        $this->save($registration, $person, [$this->tee->id => $this->options['S']->id])
            ->assertSessionHasErrors($this->key($person));

        $this->assertNull($registration->addonLines()->sole()->event_addon_variant_id);
        $this->assertSame(1, (int) $this->options['S']->fresh()->stock_taken);
    }

    /* ---------------------------------------------------------------------
     | 4. An option that carries its own price
     * ------------------------------------------------------------------ */

    public function test_an_option_priced_differently_from_the_one_recorded_is_refused(): void
    {
        $registration = $this->registration('REG-2026-0108', [
            'payment_status' => EventRegistration::PAYMENT_PAID,
            'amount_paid' => 40,
        ]);

        $person = $this->person($registration, 1);
        $line = $this->lineWithoutOption($registration, $person);

        $this->receipt($registration, 40);

        $money = $this->money($registration);
        $ledger = $this->ledger($registration);

        $admin = $this->editor();

        $response = $this->save($registration, $person, [$this->tee->id => $this->options['5XL']->id], $admin);

        $response->assertSessionHasErrors($this->key($person));

        // Said in money, and said where to take it instead. Re-pricing one entry from
        // a correction dialog is the door this whole area has spent its rounds closing.
        $errors = session('errors')->get($this->key($person));
        $this->assertStringContainsString('RM 5.00', $errors[0]);
        $this->assertStringContainsString('payment', $errors[0]);

        // And the operator actually reads it: the dialog comes back open with the
        // refusal under the field that caused it.
        $page = $this->actingAs($admin)->get(route('admin.event.participants.show', $registration));

        $page->assertOk();
        $page->assertSee('Take the difference through the payment screens');

        // Refused means nothing written: not the line, not the count, not the money,
        // and not a trail entry claiming it happened.
        $this->assertNull($line->fresh()->event_addon_variant_id);
        $this->assertSame(['S' => 0, 'M' => 0, 'L' => 0, 'XL' => 0, '5XL' => 0], $this->stock());
        $this->assertSame($money, $this->money($registration));
        $this->assertSame($ledger, $this->ledger($registration));
        $this->assertFalse(AuditLog::query()->where('event', ParticipantSizeWriter::TRAIL_BY_STAFF)->exists());
    }

    public function test_swapping_one_free_option_for_another_free_one_is_allowed(): void
    {
        // The guard is about the price changing, not about there being a price. Every
        // option on the live event is free, and all of those must stay settable.
        $registration = $this->registration('REG-2026-0109');
        $person = $this->person($registration, 1);
        $line = $this->lineWithOption($registration, $person, 'M');

        $this->save($registration, $person, [$this->tee->id => $this->options['L']->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->options['L']->id, $line->fresh()->event_addon_variant_id);
    }

    public function test_the_registrants_own_link_is_unchanged_by_the_staff_refusal(): void
    {
        // Not an admin screen at all. The signed link is the registrant choosing for
        // themselves, which is a purchase decision and not a correction, so the guard
        // above must not have followed the shared writer onto that page.
        $registration = $this->registration('REG-2026-0110');
        $person = $this->person($registration, 1);
        $line = $this->lineWithoutOption($registration, $person);

        $money = $this->money($registration);

        $this->post(ParticipantSizeController::urlFor($registration), [
            'sizes' => [$person->id => [$this->tee->id => $this->options['5XL']->id]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($this->options['5XL']->id, $line->fresh()->event_addon_variant_id);
        $this->assertSame($money, $this->money($registration));
    }

    /* ---------------------------------------------------------------------
     | 5. The trail
     * ------------------------------------------------------------------ */

    public function test_the_trail_says_whether_an_admin_or_the_registrant_recorded_it(): void
    {
        $mine = $this->registration('REG-2026-0111');
        $byRegistrant = $this->person($mine, 1);
        $this->lineWithoutOption($mine, $byRegistrant);

        $theirs = $this->registration('REG-2026-0112');
        $byStaff = $this->person($theirs, 1);
        $line = $this->lineWithoutOption($theirs, $byStaff);

        // The registrant, through their own link, with nobody signed in.
        $this->post(ParticipantSizeController::urlFor($mine), [
            'sizes' => [$byRegistrant->id => [$this->tee->id => $this->options['S']->id]],
        ])->assertRedirect();

        $registrantRow = AuditLog::query()
            ->where('event', ParticipantSizeWriter::TRAIL_BY_REGISTRANT)
            ->sole();

        $this->assertNull($registrantRow->user_id);
        $this->assertSame(EventRegistrationAddon::class, $registrantRow->auditable_type);
        $this->assertStringContainsString('registrant', $registrantRow->new_values['recorded_by']);

        // A named member of staff, at the counter.
        $admin = $this->editor();

        $this->save($theirs, $byStaff, [$this->tee->id => $this->options['XL']->id], $admin)
            ->assertSessionHasNoErrors();

        $staffRow = AuditLog::query()
            ->where('event', ParticipantSizeWriter::TRAIL_BY_STAFF)
            ->sole();

        $this->assertSame($admin->id, $staffRow->user_id);
        $this->assertSame($admin->logLabel(), $staffRow->new_values['recorded_by']);
        $this->assertSame($line->id, (int) $staffRow->auditable_id);
        $this->assertSame('XL', $staffRow->new_values['variant_label']);
        $this->assertSame($byStaff->full_name, $staffRow->new_values['participant']);

        // What it replaced, so a changed answer can be read as a change.
        $this->assertNull($staffRow->old_values['variant_label']);

        // And the activity log carries the press itself, the way every other action on
        // this screen does.
        $this->assertTrue(
            ActivityLog::query()
                ->where('action', 'participants.person-option')
                ->where('user_id', $admin->id)
                ->exists(),
        );

        // The two are distinguishable, which is the whole point of the pair.
        $this->assertNotSame($registrantRow->event, $staffRow->event);
    }

    /* ---------------------------------------------------------------------
     | 6. The dialog draws itself from the event
     * ------------------------------------------------------------------ */

    public function test_the_dialog_draws_a_field_for_each_choice_the_event_collects_per_person(): void
    {
        $registration = $this->registration('REG-2026-0113', ['addons_total' => 80, 'amount' => 80]);
        $first = $this->person($registration, 1);
        $second = $this->person($registration, 2, ParticipantOptions::ROLE_PARTICIPANT);

        $this->lineWithoutOption($registration, $first);
        $this->lineWithOption($registration, $second, 'M');

        // A second thing collected one per head, to prove the dialog is reading the
        // event rather than naming shirts.
        $cap = EventAddon::create([
            'event_id' => $this->event->id,
            'name' => 'EVENT CAP',
            'price' => 0,
            'is_required' => false,
            'is_active' => true,
            'per_participant' => true,
            'selection_type' => EventAddon::SELECTION_RADIO,
        ]);

        foreach (['Free size'] as $order => $label) {
            EventAddonVariant::create([
                'event_addon_id' => $cap->id,
                'label' => $label,
                'price' => null,
                'stock' => null,
                'sort_order' => $order + 1,
            ]);
        }

        $page = $this->open($registration);

        $page->assertOk();
        $page->assertSee('Chosen for this person');

        // One field per person per item, named the way the writer reads them.
        foreach ([$first, $second] as $person) {
            foreach ([$this->tee, $cap] as $addon) {
                $page->assertSee(sprintf('name="sizes[%d][%d]"', $person->id, $addon->id), false);
            }
        }

        $page->assertSee('HSN EVENT TEE');
        $page->assertSee('EVENT CAP');

        // Whatever is already recorded comes back selected, so this is a correction
        // tool as much as an entry one, and the person with nothing says so.
        $page->assertSee(sprintf('value="%d" selected', $this->options['M']->id), false);
        $page->assertSee('not recorded yet');

        // An option carrying its own price says so where it is chosen.
        $page->assertSee('+RM 5.00');
    }

    public function test_an_event_that_collects_no_choices_draws_no_field(): void
    {
        $plain = Event::create([
            'slug' => 'plain-' . uniqid(),
            'title' => 'LARIAN KOMUNITI',
            'category' => 'Community',
            'starts_at' => now()->addMonth()->toDateString(),
            'ends_at' => now()->addMonth()->toDateString(),
            'status' => Event::STATUS_OPEN,
            'registration_mode' => Event::MODE_GROUPING,
            'location' => 'Sibu',
            'fee' => 10,
            'seats_total' => 0,
            'min_players' => 1,
            'max_players' => 20,
        ]);

        /*
         | An item with options that is NOT one choice per head: it is a quantity, "how
         | many of each", which is not a question about one person. The dialog must
         | leave it alone, and that is the configuration doing the deciding rather than
         | the absence of items.
         */
        $meals = EventAddon::create([
            'event_id' => $plain->id,
            'name' => 'BANQUET SEAT',
            'price' => 15,
            'is_required' => false,
            'is_active' => true,
            'per_participant' => false,
            'selection_type' => EventAddon::SELECTION_QUANTITY,
        ]);

        EventAddonVariant::create([
            'event_addon_id' => $meals->id,
            'label' => 'Adult',
            'price' => null,
            'stock' => null,
            'sort_order' => 1,
        ]);

        $registration = EventRegistration::create([
            'event_id' => $plain->id,
            'reference' => 'REG-2026-0114',
            'mode' => $plain->registration_mode,
            'team_name' => 'Pasukan Larian',
            'status' => EventRegistration::STATUS_PENDING,
            'payment_status' => EventRegistration::PAYMENT_UNPAID,
            'registration_fee' => 10,
            'addons_total' => 0,
            'amount' => 10,
            'amount_paid' => 0,
        ]);

        EventParticipant::create([
            'event_registration_id' => $registration->id,
            'role' => ParticipantOptions::ROLE_MANAGER,
            'full_name' => 'Peserta Larian',
            'ic_number' => '880101' . $registration->id . '0001',
            'phone' => '0128508124',
            'email' => 'larian' . $registration->id . '@example.test',
        ]);

        $page = $this->open($registration);

        $page->assertOk();
        $page->assertDontSee('Chosen for this person');
        $page->assertDontSee('name="sizes[', false);
    }

    /* ---------------------------------------------------------------------
     | 7. Who may do it
     * ------------------------------------------------------------------ */

    public function test_it_is_refused_without_the_permission_and_refused_on_a_get(): void
    {
        $registration = $this->registration('REG-2026-0115');
        $person = $this->person($registration, 1);
        $line = $this->lineWithoutOption($registration, $person);

        // Reading a record is not correcting one.
        $viewer = $this->userWith(['participants.view']);

        $this->actingAs($viewer)
            ->put(
                route('admin.event.participants.person.update', [$registration, $person]),
                $this->payload($person, [$this->tee->id => $this->options['M']->id]),
            )
            ->assertForbidden();

        $this->assertNull($line->fresh()->event_addon_variant_id);
        $this->assertSame(['S' => 0, 'M' => 0, 'L' => 0, 'XL' => 0, '5XL' => 0], $this->stock());

        // And a link, a scanner or a mail client following the URL cannot write.
        $this->actingAs($this->editor())
            ->get(route('admin.event.participants.person.update', [$registration, $person]))
            ->assertStatus(405);

        $this->assertNull($line->fresh()->event_addon_variant_id);
    }

    public function test_a_choice_posted_against_somebody_elses_entry_is_refused(): void
    {
        $mine = $this->registration('REG-2026-0116');
        $myPerson = $this->person($mine, 1);
        $this->lineWithoutOption($mine, $myPerson);

        $theirs = $this->registration('REG-2026-0117');
        $theirPerson = $this->person($theirs, 1);
        $theirLine = $this->lineWithoutOption($theirs, $theirPerson);

        // My entry's URL, their person, carrying a choice.
        $this->actingAs($this->editor())
            ->put(
                route('admin.event.participants.person.update', [$mine, $theirPerson]),
                $this->payload($theirPerson, [$this->tee->id => $this->options['XL']->id]),
            )
            ->assertSessionHasErrors('participant');

        $this->assertNull($theirLine->fresh()->event_addon_variant_id);
        $this->assertSame(['S' => 0, 'M' => 0, 'L' => 0, 'XL' => 0, '5XL' => 0], $this->stock());
    }
}
