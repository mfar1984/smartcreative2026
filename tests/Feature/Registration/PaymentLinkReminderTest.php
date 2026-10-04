<?php

namespace Tests\Feature\Registration;

use App\Mail\EventTemplateMail;
use App\Models\Event;
use App\Models\EventNotification;
use App\Models\EventParticipant;
use App\Models\EventRegistration;
use App\Models\EventRegistrationPayment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Payment\RegistrationBalanceCharge;
use App\Support\EventTemplates;
use App\Support\LocalTime;
use App\Support\ParticipantOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Chasing the balance on HARI SUKAN NEGARA 2026, and being able to see that it happened.
 *
 * "sekarang ni saya tak sure participant dah dapat email atau tidak" — the owner is
 * looking at twenty-three entries on one event that still owe money, five of which have
 * already been emailed, and from the list those five looked exactly like the other
 * eighteen. The answer is not a new column on the registration: every message already
 * has a row in the message log with its own delivery state, so the list reads that.
 *
 * Mail::fake() in setUp(), because every fixture in here reaches a real participant
 * address on the live system this mirrors.
 */
class PaymentLinkReminderTest extends TestCase
{
    use RefreshDatabase;

    /** Seeing the screen, and being allowed to email somebody from it. */
    private const CAN_NOTIFY = ['participants.view', 'participants.notify'];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        // The wording itself. Without the saved template nothing is ever queued and
        // every assertion below would pass for the wrong reason.
        $this->seed(\Database\Seeders\EventTemplateSeeder::class);
    }

    /* ---------------------------------------------------------------------
     | Fixtures
     * ------------------------------------------------------------------ */

    private function event(): Event
    {
        return Event::create([
            'slug' => 'hsn-sibu-' . uniqid(),
            'title' => 'HARI SUKAN NEGARA 2026 PERINGKAT BAHAGIAN SIBU',
            'category' => 'Community',
            'starts_at' => now()->addMonth()->toDateString(),
            'ends_at' => now()->addMonth()->toDateString(),
            'status' => Event::STATUS_OPEN,
            'registration_mode' => Event::MODE_GROUPING,
            'fee' => 40,
            'seats_total' => 0,
            'min_players' => 1,
            'max_players' => 20,
        ]);
    }

    /**
     * One entry with one person on it who can be written to.
     *
     * The amounts are the live ones: RM 40.00 a head, and whatever has arrived
     * against it recorded both on the row and in the receipt ledger, so
     * outstandingAmount() and the badge cannot disagree.
     */
    private function registration(Event $event, string $reference, string $paymentStatus, float $amount = 40, float $paid = 0): EventRegistration
    {
        $registration = EventRegistration::create([
            'event_id' => $event->id,
            'reference' => $reference,
            'mode' => $event->registration_mode,
            'team_name' => 'Pasukan ' . $reference,
            'status' => EventRegistration::STATUS_PENDING,
            'payment_status' => $paymentStatus,
            'registration_fee' => $amount,
            'addons_total' => 0,
            'amount' => $amount,
            'amount_paid' => $paid,
        ]);

        EventParticipant::create([
            'event_registration_id' => $registration->id,
            'role' => ParticipantOptions::ROLE_MANAGER,
            'full_name' => 'Pengurus ' . $reference,
            'ic_number' => '900101' . str_pad((string) $registration->id, 6, '0', STR_PAD_LEFT),
            'phone' => '0128508124',
            'email' => strtolower(str_replace('-', '', $reference)) . '@example.test',
            'gender' => 'male',
            'race' => 'malay',
        ]);

        if ($paid > 0) {
            EventRegistrationPayment::create([
                'event_registration_id' => $registration->id,
                'amount' => $paid,
                'received_at' => now()->subDay(),
                'source' => EventRegistrationPayment::SOURCE_MANUAL,
            ]);
        }

        return $registration->fresh();
    }

    /**
     * A row in the message log, the way the notifier writes one.
     *
     * Status is passed rather than derived: 'queued' is what the press records,
     * 'sent' is what EventTemplateMail::build() writes when the cron worker runs,
     * and 'failed' is what failed() writes after --tries=3.
     */
    private function logged(EventRegistration $registration, string $status, array $overrides = []): EventNotification
    {
        $address = $registration->participants()->first()?->email;

        return EventNotification::create($overrides + [
            'event_registration_id' => $registration->id,
            'template_key' => EventTemplates::PAYMENT_REMINDER,
            'channel' => EventTemplates::CHANNEL_EMAIL,
            'recipient' => $address,
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
     * part of this file is about the action being refused without its permission, and
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

    private function listFor(Event $event, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->userWith(self::CAN_NOTIFY))
            ->get(route('admin.event.participants', ['tab' => 'group', 'event' => $event->id]));
    }

    private function send(EventRegistration $registration, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->userWith(self::CAN_NOTIFY))
            ->from(route('admin.event.participants', ['tab' => 'group']))
            ->post(route('admin.event.participants.remind', $registration));
    }

    /* ---------------------------------------------------------------------
     | 1. Who is offered the action
     * ------------------------------------------------------------------ */

    public function test_the_payment_link_is_offered_on_every_state_that_still_owes_money(): void
    {
        $event = $this->event();

        /*
         | The four the owner named, each one Pending with money outstanding:
         | REG-2026-0095 Awaiting Payment, 0092 and 0091 Unpaid, 0090 Failed, and a
         | part-paid entry alongside them because that is the state the ledger
         | correction produced.
         */
        $states = [
            'REG-2026-0095' => [EventRegistration::PAYMENT_PENDING, 160.0, 0.0],
            'REG-2026-0092' => [EventRegistration::PAYMENT_UNPAID, 40.0, 0.0],
            'REG-2026-0090' => [EventRegistration::PAYMENT_FAILED, 40.0, 0.0],
            'REG-2026-0066' => [EventRegistration::PAYMENT_PARTIAL, 80.0, 40.0],
        ];

        foreach ($states as $reference => [$status, $amount, $paid]) {
            $this->registration($event, $reference, $status, $amount, $paid);
        }

        $list = $this->listFor($event);
        $list->assertOk();

        foreach (array_keys($states) as $reference) {
            $list->assertSee('Send a payment link for ' . $reference);
        }

        // And each one is actually accepted, not merely drawn.
        foreach (array_keys($states) as $reference) {
            $this->send(EventRegistration::where('reference', $reference)->sole())
                ->assertSessionHas('status');
        }

        Mail::assertQueued(EventTemplateMail::class, 4);
    }

    public function test_it_is_not_offered_on_a_settled_cancelled_or_refunded_entry(): void
    {
        $event = $this->event();

        $paid = $this->registration($event, 'REG-2026-0047', EventRegistration::PAYMENT_PAID, 40, 40);

        $cancelled = $this->registration($event, 'REG-2026-0048', EventRegistration::PAYMENT_UNPAID);
        $cancelled->forceFill(['status' => EventRegistration::STATUS_CANCELLED])->save();

        $refunded = $this->registration($event, 'REG-2026-0049', EventRegistration::PAYMENT_REFUNDED);
        $refunded->forceFill(['refunded_amount' => 40, 'refunded_at' => now()])->save();

        $list = $this->listFor($event);
        $list->assertOk();

        foreach ([$paid, $cancelled, $refunded] as $registration) {
            $list->assertDontSee('Send a payment link for ' . $registration->reference);

            // Refused server-side on the same test, so hiding the button is not the
            // only thing standing between a settled entry and an email.
            $this->send($registration->fresh())->assertSessionHas('warning');
        }

        Mail::assertNothingQueued();
    }

    /* ---------------------------------------------------------------------
     | 2. Being able to see that it happened
     * ------------------------------------------------------------------ */

    public function test_sending_writes_one_message_log_row_and_the_list_then_shows_it(): void
    {
        $event = $this->event();
        $registration = $this->registration($event, 'REG-2026-0095', EventRegistration::PAYMENT_PENDING, 160);

        $this->send($registration)->assertSessionHas('status');

        // Queued, never sent inline: the admin presses this on a table row and must
        // not wait on SMTP.
        Mail::assertQueued(EventTemplateMail::class, 1);
        Mail::assertNotSent(EventTemplateMail::class);

        $logged = $registration->fresh()->paymentReminders;

        $this->assertCount(1, $logged);
        $this->assertSame(EventTemplates::PAYMENT_REMINDER, $logged->first()->template_key);
        $this->assertSame(EventNotification::STATUS_QUEUED, $logged->first()->status);

        // Nothing was added to the registration itself to record this. The message
        // log is the only place it lives.
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('event_registrations', 'payment_link_sent_at'),
            'The reminder state is read from the message log, so there must be no second stamp on the registration.',
        );

        $address = $registration->participants()->first()->email;

        // Before the worker runs the list says Queued, not Sent: a row stuck here
        // means the cron worker is not running, and claiming otherwise is how the
        // owner ends up believing somebody was told.
        $queued = $this->listFor($event);
        $queued->assertSee('Queued');
        $queued->assertSee('Payment link queued to ' . $address, false);
        $queued->assertDontSee('Payment link sent to ' . $address, false);

        // The worker runs. EventTemplateMail::build() writes exactly this.
        $logged->first()->forceFill([
            'status' => EventNotification::STATUS_SENT,
            'sent_at' => now(),
        ])->save();

        $sent = $this->listFor($event);
        $sent->assertSee('Payment link sent to ' . $address, false);
        $sent->assertSee(LocalTime::format($registration->fresh()->paymentLinkSentAt()));
        $sent->assertDontSee('Never sent');
    }

    public function test_an_entry_nobody_has_contacted_reads_differently_from_one_already_emailed(): void
    {
        $event = $this->event();

        $untouched = $this->registration($event, 'REG-2026-0090', EventRegistration::PAYMENT_FAILED);
        $chased = $this->registration($event, 'REG-2026-0046', EventRegistration::PAYMENT_PENDING);

        $this->logged($chased, EventNotification::STATUS_SENT);

        $list = $this->listFor($event);
        $list->assertOk();

        // The whole point: eighteen of twenty-three had never been written to, and
        // from the list they looked the same as the five that had.
        $list->assertSee('No payment link has ever gone out for ' . $untouched->reference);
        $list->assertSee('Never sent');

        $list->assertSee('Payment link sent to ' . $chased->participants()->first()->email, false);
    }

    public function test_a_settled_entry_stays_quiet_in_the_reminder_column(): void
    {
        $event = $this->event();
        $this->registration($event, 'REG-2026-0047', EventRegistration::PAYMENT_PAID, 40, 40);

        $list = $this->listFor($event);

        $list->assertOk();

        // Nothing to chase, so the column says nothing. A list that shouts at every
        // settled row is one nobody reads.
        $list->assertDontSee('Never sent');
        $list->assertDontSee('Send failed');
    }

    public function test_a_failed_attempt_reads_as_failed_rather_than_as_contacted(): void
    {
        $event = $this->event();
        $registration = $this->registration($event, 'REG-2026-0092', EventRegistration::PAYMENT_UNPAID);

        // What SendTemplateSms::handle() and EventTemplateMail::failed() leave behind
        // after --tries=3: the row turns failed and carries the reason.
        $this->logged($registration, EventNotification::STATUS_FAILED, [
            'reason' => 'Connection could not be established with host smtp.hostinger.com',
        ]);

        $list = $this->listFor($event);

        $list->assertOk();
        $list->assertSee('Send failed');
        $list->assertSee('Not contacted');
        $list->assertSee('Connection could not be established with host smtp.hostinger.com');

        // This registrant looks contacted and has heard nothing, so the next press
        // must go through rather than sitting out a cooldown.
        $this->send($registration)->assertSessionHas('status');

        Mail::assertQueued(EventTemplateMail::class, 1);
    }

    /* ---------------------------------------------------------------------
     | 3. The cooldown
     * ------------------------------------------------------------------ */

    public function test_a_second_press_inside_the_cooldown_sends_nothing(): void
    {
        $event = $this->event();
        $registration = $this->registration($event, 'REG-2026-0091', EventRegistration::PAYMENT_UNPAID);

        $this->send($registration)->assertSessionHas('status');
        $this->send($registration->fresh())->assertSessionHas('warning');

        // One email and one log row, not two of either.
        Mail::assertQueued(EventTemplateMail::class, 1);
        $this->assertCount(1, $registration->fresh()->paymentReminders);
    }

    public function test_the_cooldown_lapses_and_the_entry_can_be_chased_again(): void
    {
        $event = $this->event();
        $registration = $this->registration($event, 'REG-2026-0091', EventRegistration::PAYMENT_UNPAID);

        $this->logged($registration, EventNotification::STATUS_SENT, [
            'queued_at' => now()->subHours(EventRegistration::PAYMENT_LINK_COOLDOWN_HOURS + 1),
            'sent_at' => now()->subHours(EventRegistration::PAYMENT_LINK_COOLDOWN_HOURS + 1),
        ]);

        $this->assertFalse($registration->fresh()->paymentLinkRemindedRecently());

        $this->send($registration->fresh())->assertSessionHas('status');

        Mail::assertQueued(EventTemplateMail::class, 1);
    }

    /* ---------------------------------------------------------------------
     | 4. What the link asks for
     * ------------------------------------------------------------------ */

    public function test_the_link_charges_the_outstanding_balance_and_not_the_whole_amount(): void
    {
        $event = $this->event();

        // The live shape: RM 240.00 owed, RM 40.00 arrived against the old figure.
        $registration = $this->registration($event, 'REG-2026-0066', EventRegistration::PAYMENT_PARTIAL, 240, 40);

        $this->send($registration)->assertSessionHas('status');

        // The email names the balance, because that is what the recipient is being
        // asked for.
        Mail::assertQueued(EventTemplateMail::class, function (EventTemplateMail $mail) use ($registration) {
            return str_contains($mail->renderedBody, 'RM 200.00')
                && str_contains($mail->renderedBody, '/registration/payment/' . $registration->reference);
        });

        // And the purchase the link opens is built from the row, not from anything
        // the link carries.
        $charge = app(RegistrationBalanceCharge::class)->build($registration->fresh());

        $this->assertSame(20000, $charge->amountCents);
        $this->assertNotSame(24000, $charge->amountCents);
    }

    /* ---------------------------------------------------------------------
     | 5. Who may press it
     * ------------------------------------------------------------------ */

    public function test_it_is_refused_without_the_notify_permission(): void
    {
        $event = $this->event();
        $registration = $this->registration($event, 'REG-2026-0090', EventRegistration::PAYMENT_FAILED);

        $viewer = $this->userWith(['participants.view']);

        $this->actingAs($viewer)
            ->post(route('admin.event.participants.remind', $registration))
            ->assertForbidden();

        Mail::assertNothingQueued();

        // Nor is the control drawn for them, so nobody is offered a press that is
        // going to be refused.
        $this->listFor($event, $viewer)
            ->assertDontSee('Send a payment link for ' . $registration->reference);
    }

    public function test_it_is_refused_on_get(): void
    {
        $event = $this->event();
        $registration = $this->registration($event, 'REG-2026-0090', EventRegistration::PAYMENT_FAILED);

        // A link in an email client that follows links would otherwise be able to
        // send one. POST only, and the route is declared that way.
        $this->actingAs($this->userWith(self::CAN_NOTIFY))
            ->get('/admin/event/participants/' . $registration->id . '/remind')
            ->assertStatus(405);

        Mail::assertNothingQueued();
    }
}
