<?php

namespace Tests\Feature\Registration;

use App\Mail\EventTemplateMail;
use App\Models\Event;
use App\Models\EventAddon;
use App\Models\EventAddonVariant;
use App\Models\EventNotification;
use App\Models\EventParticipant;
use App\Models\EventRegistration;
use App\Models\EventRegistrationAddon;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\EventTemplates;
use App\Support\ParticipantOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Seeing who still owes a shirt size, and asking them for it.
 *
 * "siapa yang ada pilih saiz ini" — thirty-two entries on HARI SUKAN NEGARA 2026 need a
 * size collected and the owner cannot tell them apart from the rest of the list. The
 * answer is the same shape as the Reminder column beside it: the state is read from the
 * message log, because this project has twice had a bug from two sources recording one
 * fact, and the count on screen is counted through the list's own filtered() so it
 * describes exactly the rows the button will write to.
 *
 * Mail::fake() in setUp(). Every address in here belongs to a real participant on the
 * system this mirrors.
 */
class SizeConfirmationLinkTest extends TestCase
{
    use RefreshDatabase;

    /** Seeing the screen, and being allowed to email somebody from it. */
    private const CAN_NOTIFY = ['participants.view', 'participants.notify'];

    private Event $event;

    private EventAddon $tee;

    /** @var array<string, EventAddonVariant> */
    private array $sizes = [];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        // The wording itself. Without the saved template nothing is ever queued and
        // half of these assertions would pass for the wrong reason.
        $this->seed(\Database\Seeders\EventTemplateSeeder::class);

        $this->event = $this->sizedEvent('HARI SUKAN NEGARA 2026 PERINGKAT BAHAGIAN SIBU');

        [$this->tee, $this->sizes] = $this->tee($this->event);
    }

    /* ---------------------------------------------------------------------
     | Fixtures
     * ------------------------------------------------------------------ */

    private function sizedEvent(string $title): Event
    {
        return Event::create([
            'slug' => 'event-' . uniqid(),
            'title' => $title,
            'category' => 'Community',
            'starts_at' => now()->addMonth()->toDateString(),
            'ends_at' => now()->addMonth()->toDateString(),
            'status' => Event::STATUS_OPEN,
            'registration_mode' => Event::MODE_GROUPING,
            'fee' => null,
            'charges_addons_per_participant' => true,
            'seats_total' => 0,
            'min_players' => 1,
            'max_players' => 20,
        ]);
    }

    /**
     * A compulsory shirt with sizes, priced per head, exactly as event 4 holds it.
     *
     * @return array{0: EventAddon, 1: array<string, EventAddonVariant>}
     */
    private function tee(Event $event): array
    {
        $addon = EventAddon::create([
            'event_id' => $event->id,
            'name' => 'HSN EVENT TEE',
            'price' => 40,
            'is_required' => true,
            'is_active' => true,
            'per_participant' => true,
            'selection_type' => EventAddon::SELECTION_RADIO,
        ]);

        $sizes = [];

        foreach (['S', 'M', 'L'] as $order => $label) {
            $sizes[$label] = EventAddonVariant::create([
                'event_addon_id' => $addon->id,
                'label' => $label,
                'price' => null,
                'stock' => null,
                'sort_order' => $order + 1,
            ]);
        }

        return [$addon->fresh(), $sizes];
    }

    /**
     * One entry with one person on it, in whichever size shape is asked for.
     *
     * $size: null leaves a line with no size on it, a label records that choice, and
     * 'none' leaves no line at all — the three shapes the live table holds.
     */
    private function registration(
        Event $event,
        string $reference,
        ?string $size = null,
        string $paymentStatus = EventRegistration::PAYMENT_UNPAID,
        float $paid = 0,
    ): EventRegistration {
        $registration = EventRegistration::create([
            'event_id' => $event->id,
            'reference' => $reference,
            'mode' => $event->registration_mode,
            'team_name' => 'Pasukan ' . $reference,
            'status' => EventRegistration::STATUS_PENDING,
            'payment_status' => $paymentStatus,
            'registration_fee' => 0,
            'addons_total' => 40,
            'amount' => 40,
            'amount_paid' => $paid,
        ]);

        $person = EventParticipant::create([
            'event_registration_id' => $registration->id,
            'role' => ParticipantOptions::ROLE_MANAGER,
            'full_name' => 'Pengurus ' . $reference,
            'ic_number' => '900101' . str_pad((string) $registration->id, 6, '0', STR_PAD_LEFT),
            'phone' => '0128508124',
            'email' => strtolower(str_replace('-', '', $reference)) . '@example.test',
            'gender' => 'male',
            'race' => 'malay',
        ]);

        if ($size !== 'none') {
            $variant = $size === null ? null : $this->sizes[$size];

            $variant?->increment('stock_taken');

            EventRegistrationAddon::create([
                'event_registration_id' => $registration->id,
                'event_participant_id' => $person->id,
                'event_addon_id' => $this->tee->id,
                'event_addon_variant_id' => $variant?->id,
                'name' => $this->tee->name,
                'variant_label' => $variant?->label,
                'unit_price' => 40,
                'quantity' => 1,
                'line_total' => 40,
            ]);
        }

        return $registration->fresh();
    }

    /** A row in the message log, the way the notifier writes one. */
    private function logged(EventRegistration $registration, string $status, array $overrides = []): EventNotification
    {
        return EventNotification::create($overrides + [
            'event_registration_id' => $registration->id,
            'template_key' => EventTemplates::SIZE_CONFIRMATION,
            'channel' => EventTemplates::CHANNEL_EMAIL,
            'recipient' => $registration->participants()->first()?->email,
            'participant_ids' => [],
            'status' => $status,
            'queued_at' => now()->subMinutes(30),
            'sent_at' => $status === EventNotification::STATUS_SENT ? now()->subMinutes(29) : null,
        ]);
    }

    /**
     * A user holding exactly the named permissions and nothing else.
     *
     * A real role with real pivot rows rather than the super-admin shortcut, because
     * part of this file is about the action being refused without its permission.
     *
     * @param  array<int, string>  $permissions
     */
    private function userWith(array $permissions): User
    {
        $role = Role::create(['slug' => 'staff-' . uniqid(), 'name' => 'Staff', 'is_active' => true]);

        $ids = [];

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

    private function listFor(Event $event, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->userWith(self::CAN_NOTIFY))
            ->get(route('admin.event.participants', ['tab' => 'group', 'event' => $event->id]));
    }

    private function ask(EventRegistration $registration, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->userWith(self::CAN_NOTIFY))
            ->from(route('admin.event.participants', ['tab' => 'group']))
            ->post(route('admin.event.participants.sizes', $registration));
    }

    private function askAll(array $filters, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->userWith(self::CAN_NOTIFY))
            ->from(route('admin.event.participants', $filters))
            ->post(route('admin.event.participants.sizes.all', $filters));
    }

    /* ---------------------------------------------------------------------
     | 1. The indicator
     * ------------------------------------------------------------------ */

    public function test_the_list_marks_exactly_the_entries_missing_a_size(): void
    {
        $noSize = $this->registration($this->event, 'REG-2026-0068');
        $noLine = $this->registration($this->event, 'REG-2026-0047', 'none', EventRegistration::PAYMENT_PAID, 40);
        $answered = $this->registration($this->event, 'REG-2026-0070', 'M');

        $list = $this->listFor($this->event);
        $list->assertOk();

        // Two of the three, and the strip above the table counts the same two.
        $list->assertSee('2 entries on this list have no shirt size recorded, 2 people to confirm.');
        $list->assertSee('Ask all 2 for sizes');

        foreach ([$noSize, $noLine] as $registration) {
            $list->assertSee('1 person on ' . $registration->reference . ' has no size recorded', false);
            $list->assertSee('Send a size confirmation link for ' . $registration->reference);
        }

        // The one that answered is not chased, and says so.
        $list->assertSee('All recorded');
        $list->assertDontSee('Send a size confirmation link for ' . $answered->reference);

        // Nobody has been asked yet, which is the whole reason the column exists.
        $list->assertSee('Nobody has been asked for a size on ' . $noSize->reference);
        $list->assertSee('Never asked');
    }

    public function test_the_indicator_and_the_bulk_count_exclude_another_events_rows(): void
    {
        $this->registration($this->event, 'REG-2026-0068');

        // A second event, also collecting sizes, also missing two of them. The screen
        // is filtered to the first one, so neither the count nor the send may reach it.
        $other = $this->sizedEvent('PUBG MOBILE SIBU 2026');
        [$otherTee, $otherSizes] = $this->tee($other);

        $otherRegistration = EventRegistration::create([
            'event_id' => $other->id,
            'reference' => 'REG-2026-0100',
            'mode' => $other->registration_mode,
            'team_name' => 'Pasukan Lain',
            'status' => EventRegistration::STATUS_PENDING,
            'payment_status' => EventRegistration::PAYMENT_UNPAID,
            'registration_fee' => 0,
            'addons_total' => 40,
            'amount' => 40,
            'amount_paid' => 0,
        ]);

        $otherPerson = EventParticipant::create([
            'event_registration_id' => $otherRegistration->id,
            'role' => ParticipantOptions::ROLE_MANAGER,
            'full_name' => 'Pengurus Lain',
            'ic_number' => '900101999999',
            'phone' => '0128508124',
            'email' => 'lain@example.test',
            'gender' => 'male',
            'race' => 'malay',
        ]);

        EventRegistrationAddon::create([
            'event_registration_id' => $otherRegistration->id,
            'event_participant_id' => $otherPerson->id,
            'event_addon_id' => $otherTee->id,
            'event_addon_variant_id' => null,
            'name' => $otherTee->name,
            'unit_price' => 40,
            'quantity' => 1,
            'line_total' => 40,
        ]);

        $list = $this->listFor($this->event);

        $list->assertOk();
        $list->assertSee('1 entry on this list has no shirt size recorded, 1 person to confirm.');
        $list->assertDontSee($otherRegistration->reference);

        // And the bulk send under those filters leaves the other event alone.
        $this->askAll(['tab' => 'group', 'event' => $this->event->id])->assertSessionHas('status');

        Mail::assertQueued(EventTemplateMail::class, 1);
        $this->assertCount(0, $otherRegistration->sizeConfirmations);
        $this->assertSame(0, (int) $otherSizes['M']->fresh()->stock_taken);
    }

    public function test_an_event_that_collects_no_sizes_says_nothing_about_them(): void
    {
        $plain = $this->sizedEvent('KEJOHANAN BOLA SEPAK');

        $registration = EventRegistration::create([
            'event_id' => $plain->id,
            'reference' => 'REG-2026-0200',
            'mode' => $plain->registration_mode,
            'team_name' => 'Pasukan Tanpa Baju',
            'status' => EventRegistration::STATUS_PENDING,
            'payment_status' => EventRegistration::PAYMENT_UNPAID,
            'registration_fee' => 40,
            'addons_total' => 0,
            'amount' => 40,
            'amount_paid' => 0,
        ]);

        EventParticipant::create([
            'event_registration_id' => $registration->id,
            'role' => ParticipantOptions::ROLE_MANAGER,
            'full_name' => 'Pengurus Bola',
            'ic_number' => '900101888888',
            'phone' => '0128508124',
            'email' => 'bola@example.test',
            'gender' => 'male',
            'race' => 'malay',
        ]);

        $list = $this->listFor($plain);

        $list->assertOk();
        $list->assertDontSee('no shirt size recorded');
        $list->assertDontSee('Never asked');
        $list->assertDontSee('Send a size confirmation link for ' . $registration->reference);

        // Refused server-side too, so hiding the control is not the only guard.
        $this->ask($registration)->assertSessionHas('warning');

        Mail::assertNothingQueued();
    }

    /* ---------------------------------------------------------------------
     | 2. Asking one registrant
     * ------------------------------------------------------------------ */

    public function test_asking_one_registrant_queues_the_link_and_the_list_then_shows_it(): void
    {
        $registration = $this->registration($this->event, 'REG-2026-0068');

        $this->ask($registration)->assertSessionHas('status');

        // Queued, never sent inline: this is a button on a table row.
        Mail::assertQueued(EventTemplateMail::class, 1);
        Mail::assertNotSent(EventTemplateMail::class);

        $logged = $registration->fresh()->sizeConfirmations;

        $this->assertCount(1, $logged);
        $this->assertSame(EventTemplates::SIZE_CONFIRMATION, $logged->first()->template_key);
        $this->assertSame(EventNotification::STATUS_QUEUED, $logged->first()->status);

        // Nothing was added to the registration to record this. The message log is the
        // only place it lives, which is the lesson from the badge and the ledger
        // disagreeing on screen.
        $this->assertFalse(
            Schema::hasColumn('event_registrations', 'size_link_sent_at'),
            'The size link state is read from the message log, so there must be no second stamp on the registration.',
        );

        $address = $registration->participants()->first()->email;

        $queued = $this->listFor($this->event);
        $queued->assertSee('Queued');
        $queued->assertSee('Size link queued to ' . $address, false);
        $queued->assertDontSee('Size link sent to ' . $address, false);

        // The worker runs. EventTemplateMail::build() writes exactly this.
        $logged->first()->forceFill(['status' => EventNotification::STATUS_SENT, 'sent_at' => now()])->save();

        $sent = $this->listFor($this->event);
        $sent->assertSee('Size link sent to ' . $address, false);
        $sent->assertSee('Asked');
        $sent->assertDontSee('Never asked');
    }

    public function test_the_email_carries_the_signed_size_link_and_says_nothing_is_owed(): void
    {
        $registration = $this->registration($this->event, 'REG-2026-0068');

        $this->ask($registration)->assertSessionHas('status');

        Mail::assertQueued(EventTemplateMail::class, function (EventTemplateMail $mail) use ($registration) {
            return str_contains($mail->renderedBody, '/registration/sizes/' . $registration->reference)
                && str_contains($mail->renderedBody, 'signature=')
                && str_contains($mail->renderedBody, 'NOTHING IS OWED AND NOTHING IS BEING CHARGED')
                && str_contains($mail->renderedBody, $this->event->title)
                // The dates, because somebody asked weeks later needs to know which
                // event and when.
                && str_contains($mail->renderedBody, $this->event->starts_at->format('d M Y'))
                // And no payment link, on a message that promises no payment.
                && ! str_contains($mail->renderedBody, '/registration/payment/');
        });
    }

    public function test_a_settled_entry_is_still_asked_for_its_size(): void
    {
        // The twenty-two: paid in full, and no size on record. Payment state is none of
        // this feature's business, and a paid entry is the one definitely getting a
        // shirt.
        $registration = $this->registration($this->event, 'REG-2026-0047', 'none', EventRegistration::PAYMENT_PAID, 40);

        $this->assertTrue($registration->isSettledInFull());

        $this->ask($registration)->assertSessionHas('status');

        Mail::assertQueued(EventTemplateMail::class, 1);
        $this->assertCount(1, $registration->fresh()->sizeConfirmations);
    }

    public function test_an_entry_with_every_size_recorded_is_refused(): void
    {
        $registration = $this->registration($this->event, 'REG-2026-0070', 'M');

        $this->ask($registration)->assertSessionHas('warning');

        Mail::assertNothingQueued();
        $this->assertCount(0, $registration->fresh()->sizeConfirmations);
    }

    /* ---------------------------------------------------------------------
     | 3. The bulk send
     * ------------------------------------------------------------------ */

    public function test_the_bulk_send_queues_one_message_per_entry_that_needs_one(): void
    {
        $first = $this->registration($this->event, 'REG-2026-0068');
        $second = $this->registration($this->event, 'REG-2026-0047', 'none', EventRegistration::PAYMENT_PAID, 40);
        $answered = $this->registration($this->event, 'REG-2026-0070', 'L');

        $this->askAll(['tab' => 'group', 'event' => $this->event->id])->assertSessionHas('status');

        // Two, not three, and one each rather than one per person.
        Mail::assertQueued(EventTemplateMail::class, 2);

        $this->assertCount(1, $first->fresh()->sizeConfirmations);
        $this->assertCount(1, $second->fresh()->sizeConfirmations);
        $this->assertCount(0, $answered->fresh()->sizeConfirmations);
    }

    public function test_the_bulk_send_honours_the_search_box(): void
    {
        $wanted = $this->registration($this->event, 'REG-2026-0068');
        $other = $this->registration($this->event, 'REG-2026-0069');

        $this->askAll(['tab' => 'group', 'event' => $this->event->id, 'q' => $wanted->reference])
            ->assertSessionHas('status');

        Mail::assertQueued(EventTemplateMail::class, 1);

        $this->assertCount(1, $wanted->fresh()->sizeConfirmations);
        $this->assertCount(0, $other->fresh()->sizeConfirmations);
    }

    public function test_the_bulk_send_says_what_it_passed_over(): void
    {
        $this->registration($this->event, 'REG-2026-0070', 'M');
        $this->registration($this->event, 'REG-2026-0071', 'L');

        $this->askAll(['tab' => 'group', 'event' => $this->event->id])
            ->assertSessionHas('warning');

        Mail::assertNothingQueued();
    }

    /* ---------------------------------------------------------------------
     | 4. The cooldown
     * ------------------------------------------------------------------ */

    public function test_a_second_press_inside_the_cooldown_sends_nothing(): void
    {
        $registration = $this->registration($this->event, 'REG-2026-0068');

        $this->ask($registration)->assertSessionHas('status');
        $this->ask($registration->fresh())->assertSessionHas('warning');

        // One email and one log row, not two of either.
        Mail::assertQueued(EventTemplateMail::class, 1);
        $this->assertCount(1, $registration->fresh()->sizeConfirmations);

        // And the bulk press leaves them alone as well, rather than the two controls
        // disagreeing about who may be written to.
        $this->askAll(['tab' => 'group', 'event' => $this->event->id])->assertSessionHas('warning');

        Mail::assertQueued(EventTemplateMail::class, 1);
    }

    public function test_an_attempt_that_told_nobody_does_not_hold_the_next_one_back(): void
    {
        $registration = $this->registration($this->event, 'REG-2026-0068');

        // What EventTemplateMail::failed() leaves behind after --tries=3: this
        // registrant looks contacted and has heard nothing.
        $this->logged($registration, EventNotification::STATUS_FAILED, [
            'reason' => 'Connection could not be established with host smtp.hostinger.com',
        ]);

        $list = $this->listFor($this->event);
        $list->assertSee('Send failed');
        $list->assertSee('Connection could not be established with host smtp.hostinger.com');

        $this->ask($registration->fresh())->assertSessionHas('status');

        Mail::assertQueued(EventTemplateMail::class, 1);
    }

    public function test_the_cooldown_lapses_and_the_entry_can_be_asked_again(): void
    {
        $registration = $this->registration($this->event, 'REG-2026-0068');

        $this->logged($registration, EventNotification::STATUS_SENT, [
            'queued_at' => now()->subHours(EventRegistration::SIZE_LINK_COOLDOWN_HOURS + 1),
            'sent_at' => now()->subHours(EventRegistration::SIZE_LINK_COOLDOWN_HOURS + 1),
        ]);

        $this->assertFalse($registration->fresh()->sizeLinkSentRecently());

        $this->ask($registration->fresh())->assertSessionHas('status');

        Mail::assertQueued(EventTemplateMail::class, 1);
    }

    /* ---------------------------------------------------------------------
     | 5. Who may press it
     * ------------------------------------------------------------------ */

    public function test_it_is_refused_without_the_notify_permission(): void
    {
        $registration = $this->registration($this->event, 'REG-2026-0068');

        $viewer = $this->userWith(['participants.view']);

        $this->actingAs($viewer)
            ->post(route('admin.event.participants.sizes', $registration))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post(route('admin.event.participants.sizes.all', ['tab' => 'group', 'event' => $this->event->id]))
            ->assertForbidden();

        Mail::assertNothingQueued();

        // Nor are the controls drawn for them, so nobody is offered a press that is
        // going to be refused. The column itself still reports the state.
        $list = $this->listFor($this->event, $viewer);
        $list->assertSee('1 missing');
        $list->assertDontSee('Send a size confirmation link for ' . $registration->reference);
        $list->assertDontSee('Ask all 1 for sizes');
    }

    public function test_it_is_refused_on_get(): void
    {
        $registration = $this->registration($this->event, 'REG-2026-0068');

        // A mail client or a scanner following links would otherwise be able to send
        // one. POST only, and both routes are declared that way.
        $this->actingAs($this->userWith(self::CAN_NOTIFY))
            ->get('/admin/event/participants/' . $registration->id . '/sizes')
            ->assertStatus(405);

        $this->actingAs($this->userWith(self::CAN_NOTIFY))
            ->get('/admin/event/participants/sizes')
            ->assertStatus(404);

        Mail::assertNothingQueued();
    }
}
