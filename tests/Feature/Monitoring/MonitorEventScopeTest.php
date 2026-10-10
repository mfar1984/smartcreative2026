<?php

namespace Tests\Feature\Monitoring;

use App\Models\Coupon;
use App\Models\CouponCode;
use App\Models\Event;
use App\Models\EventAttendance;
use App\Models\EventParticipant;
use App\Models\EventRegistration;
use App\Models\Role;
use App\Models\User;
use App\Support\ParticipantOptions;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A monitoring account is bound to the events assigned to it, and to nothing else.
 *
 * THE RISK THIS FILE EXISTS FOR. Unlike the handler and the sponsor, who were given
 * purpose-built read-only areas, a monitor reads the REAL STAFF SCREENS — screens
 * written assuming the reader sees every event and can act on it. Three independent
 * layers hold it in: the role's permissions, ScopeEventToMonitor on the route groups,
 * and the event scope applied in each listing's own query. A gap in any one of them is
 * a third-party organiser reading another organiser's competitors, their identity card
 * numbers and their takings.
 *
 * So the assertions here are BY URL, surface by surface, because that is the attack:
 * the lists are narrowed, but narrowing a list only hides a link, and every screen
 * hanging off an event is reachable by changing a number. An event is named five
 * different ways across these routes — by path, through a registration, through a
 * participant, through a coupon, and in the query string — and each way is asserted
 * separately. A route nobody tested is the one that leaks.
 *
 * The last tests are the guard rail in the other direction: staff still see every
 * event. An access control change that quietly narrows the real operators is worse
 * than the gap it closed.
 */
class MonitorEventScopeTest extends TestCase
{
    use RefreshDatabase;

    private function deploy(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(string $slug, array $overrides = []): User
    {
        $role = Role::where('slug', $slug)->firstOrFail();

        return User::create(array_merge([
            'name' => sprintf('Test %s', $slug),
            'username' => sprintf('%s-%s', $slug, uniqid()),
            'email' => sprintf('%s@example.test', uniqid()),
            'password' => 'secret-password-1!',
            'role_id' => $role->id,
            'is_active' => true,
        ], $overrides));
    }

    /**
     * A monitoring account, optionally already assigned to events.
     *
     * @param  array<int, Event>  $events
     */
    private function monitor(array $events = [], string $name = 'Outside Organiser'): User
    {
        $monitor = $this->userWithRole(Role::MONITOR, ['is_monitor' => true, 'name' => $name]);

        if ($events !== []) {
            $monitor->monitoredEvents()->sync(collect($events)->pluck('id')->all());
        }

        return $monitor->fresh()->load('role');
    }

    private function event(string $title): Event
    {
        return Event::create([
            'slug' => sprintf('event-%s', uniqid()),
            'title' => $title,
            'category' => 'Community',
            'starts_at' => now()->addMonth()->toDateString(),
            'ends_at' => now()->addMonth()->toDateString(),
            'status' => Event::STATUS_OPEN,
            // Individual mode so the entries land on the Participants screen's own
            // default tab. The scope being tested has nothing to do with the tabs, and
            // a fixture that needed ?tab= to be visible would hide a scope failure
            // behind an empty list.
            'registration_mode' => Event::MODE_INDIVIDUAL,
            'fee' => 40,
            'seats_total' => 100,
            'min_players' => 1,
            'max_players' => 20,
        ]);
    }

    /**
     * One entry with one person on it, carrying a card number the tests look for.
     */
    private function registration(Event $event, string $reference, string $personName, string $card): EventRegistration
    {
        $registration = EventRegistration::create([
            'event_id' => $event->id,
            'reference' => $reference,
            'mode' => $event->registration_mode,
            'team_name' => 'Pasukan '.$reference,
            'status' => EventRegistration::STATUS_CONFIRMED,
            'payment_status' => EventRegistration::PAYMENT_PAID,
            'registration_fee' => 40,
            'addons_total' => 0,
            'amount' => 40,
            'amount_paid' => 40,
        ]);

        EventParticipant::create([
            'event_registration_id' => $registration->id,
            'role' => ParticipantOptions::ROLE_MANAGER,
            'full_name' => $personName,
            'ic_number' => $card,
            'phone' => '0128508124',
            'email' => strtolower(str_replace([' ', '-'], '', $reference)).'@example.test',
            'gender' => 'male',
            'race' => 'malay',
        ]);

        return $registration->fresh();
    }

    /**
     * Two events, each with one entry, and the monitor assigned to the first only.
     *
     * The fixture is two events for the reason the whole feature is risky: event A's
     * screens are only correct if event B's people, cards and figures are absent, and
     * a scope that is simply missing looks exactly like a correct one when there is
     * only one event in the database.
     *
     * @return array{0: Event, 1: Event, 2: EventRegistration, 3: EventRegistration, 4: User}
     */
    private function twoEvents(): array
    {
        $a = $this->event('ASSIGNED CARNIVAL 2026');
        $b = $this->event('WITHHELD CHAMPIONSHIP 2026');

        $entryA = $this->registration($a, 'REG-A-0001', 'Amir Assigned', '900101010001');
        $entryB = $this->registration($b, 'REG-B-0001', 'Bakar Withheld', '900202020002');

        return [$a, $b, $entryA, $entryB, $this->monitor([$a])];
    }

    /* ---------------------------------------------------------------------
     | Layer 3: the listings
     * ------------------------------------------------------------------ */

    public function test_a_monitor_sees_only_the_assigned_events_participants(): void
    {
        $this->deploy();
        [$a, $b, , , $monitor] = $this->twoEvents();

        $response = $this->actingAs($monitor)->get(route('admin.event.participants'));

        $response->assertOk();
        $response->assertSee('Amir Assigned');
        $response->assertDontSee('Bakar Withheld');

        // The event picker offers only the assigned event, so it cannot offer one the
        // request scope would then refuse.
        $response->assertSee($a->title);
        $response->assertDontSee($b->title);
    }

    public function test_a_monitor_sees_only_the_assigned_events_attendance(): void
    {
        $this->deploy();
        [$a, $b, $entryA, $entryB, $monitor] = $this->twoEvents();

        foreach ([$entryA, $entryB] as $entry) {
            $person = $entry->participants()->firstOrFail();

            EventAttendance::create([
                'event_participant_id' => $person->id,
                'event_id' => $entry->event_id,
                'event_registration_id' => $entry->id,
                'checked_in_at' => now(),
                'ic_verified' => true,
            ]);
        }

        $present = $this->actingAs($monitor)->get(route('admin.event.attendance', ['tab' => 'present']));

        $present->assertOk();
        $present->assertSee('Amir Assigned');
        $present->assertDontSee('Bakar Withheld');
        $present->assertSee($a->title);
        $present->assertDontSee($b->title);
    }

    public function test_a_monitor_sees_only_the_assigned_events_absent_list(): void
    {
        $this->deploy();
        [, $b, , , $monitor] = $this->twoEvents();

        $absent = $this->actingAs($monitor)->get(route('admin.event.attendance', ['tab' => 'absent']));

        $absent->assertOk();
        $absent->assertSee('Amir Assigned');
        $absent->assertDontSee('Bakar Withheld');
        $absent->assertDontSee($b->title);
    }

    public function test_a_monitor_sees_only_the_assigned_event_in_reporting(): void
    {
        $this->deploy();
        [$a, $b, , , $monitor] = $this->twoEvents();

        $response = $this->actingAs($monitor)->get(route('admin.event.reporting'));

        $response->assertOk();
        $response->assertSee($a->title);
        $response->assertDontSee($b->title);

        /*
         | The aggregate, which is where a scope leaks most quietly. One event is
         | assigned and each event holds one entry, so "Total Events" must read 1: an
         | unscoped version would read 2 and look perfectly correct.
         */
        $response->assertSeeInOrder(['Total Events', '1']);
    }

    public function test_a_monitor_with_no_assignment_sees_an_empty_list_not_everything(): void
    {
        $this->deploy();
        [$a, $b] = $this->twoEvents();

        // Deliberately assigned to nothing. The dangerous failure mode is a scope that
        // treats "no assignment" as "no filter".
        $monitor = $this->monitor([], 'Unassigned Observer');

        $participants = $this->actingAs($monitor)->get(route('admin.event.participants'));
        $participants->assertOk();
        $participants->assertDontSee('Amir Assigned');
        $participants->assertDontSee('Bakar Withheld');
        $participants->assertDontSee($a->title);
        $participants->assertDontSee($b->title);

        $attendance = $this->actingAs($monitor)->get(route('admin.event.attendance', ['tab' => 'absent']));
        $attendance->assertOk();
        $attendance->assertDontSee('Amir Assigned');
        $attendance->assertDontSee('Bakar Withheld');

        // And it can open neither event by name in the URL.
        $this->actingAs($monitor)->get(route('admin.event.participants', ['event' => $a->id]))->assertForbidden();
        $this->actingAs($monitor)->get(route('admin.event.participants', ['event' => $b->id]))->assertForbidden();
    }

    /* ---------------------------------------------------------------------
     | Layer 2: the URL, every way an event is named
     * ------------------------------------------------------------------ */

    public function test_a_monitor_cannot_reach_another_events_screens_by_url(): void
    {
        $this->deploy();
        [, $b, , $entryB, $monitor] = $this->twoEvents();

        $this->actingAs($monitor);

        // ?event= on each index screen. The query string is exactly as editable as a
        // path segment, and these three are the screens the account exists for.
        $this->get(route('admin.event.participants', ['event' => $b->id]))->assertForbidden();
        $this->get(route('admin.event.attendance', ['event' => $b->id]))->assertForbidden();
        $this->get(route('admin.event.collection', ['event' => $b->id]))->assertForbidden();

        // An ENTRY of B's, by its own id: nothing on A's screens links to it.
        $this->get(route('admin.event.participants.show', $entryB))->assertForbidden();

        // ?registration= opens an entry at the attendance counter, which draws its
        // people, their card numbers and its payment state onto the page.
        $this->get(route('admin.event.attendance', ['registration' => $entryB->id]))->assertForbidden();

        // The event in the path.
        $this->get(route('admin.event.registration.show', $b))->assertForbidden();
    }

    public function test_a_monitor_cannot_read_an_identity_card_from_another_event(): void
    {
        $this->deploy();
        [, , $entryA, $entryB, $monitor] = $this->twoEvents();

        $personA = $entryA->participants()->firstOrFail();
        $personB = $entryB->participants()->firstOrFail();

        $this->actingAs($monitor);

        /*
         | The single most sensitive read in the module, and the one a list-only scope
         | would have missed completely: this route is keyed by the PERSON alone, so it
         | names no event anywhere in the URL. Refused through their entry.
         */
        $this->get(route('admin.event.participants.ic', ['participant' => $personB->id, 'side' => 'front']))
            ->assertForbidden();

        /*
         | Their own event's card is NOT refused by the scope — the owner asked for the
         | card number to be visible, because an outside organiser checks a competitor
         | at the door. 404 is the right answer here and the proof the scope let it
         | through: no file was ever uploaded for this fixture, so the controller
         | reports a missing image rather than a refusal.
         */
        $this->get(route('admin.event.participants.ic', ['participant' => $personA->id, 'side' => 'front']))
            ->assertNotFound();
    }

    public function test_a_monitor_still_reaches_its_own_events_screens(): void
    {
        $this->deploy();
        [$a, , $entryA, , $monitor] = $this->twoEvents();

        $this->actingAs($monitor);

        $this->get(route('admin.event.participants'))->assertOk();
        $this->get(route('admin.event.participants', ['event' => $a->id]))->assertOk();
        $this->get(route('admin.event.participants.show', $entryA))->assertOk();
        $this->get(route('admin.event.attendance', ['event' => $a->id]))->assertOk();
        $this->get(route('admin.event.attendance', ['registration' => $entryA->id]))->assertOk();
        $this->get(route('admin.event.collection', ['event' => $a->id]))->assertOk();
        $this->get(route('admin.event.reporting'))->assertOk();
    }

    public function test_a_monitor_sees_identity_card_numbers_and_payment_figures_for_its_own_events(): void
    {
        $this->deploy();
        [, , $entryA, , $monitor] = $this->twoEvents();

        // Wanted, and said out loud: the owner's instruction is that the monitor sees
        // the cards and the payment state, because they are organising the event.
        $response = $this->actingAs($monitor)->get(route('admin.event.participants.show', $entryA));

        $response->assertOk();
        $response->assertSee('900101010001');
        $response->assertSee('Amir Assigned');
    }

    /* ---------------------------------------------------------------------
     | Layer 1: every write refused
     * ------------------------------------------------------------------ */

    /**
     * Take the rate limiter out of the way of a refusal test.
     *
     * Laravel keys an inline throttle on the signed-in user ALONE, not on the route,
     * so every throttled admin route shares one bucket per account. Walking twenty of
     * them in one test therefore starts answering 429 part way down the list — and a
     * 429 arriving before the 403 would hide exactly the hole these tests exist to
     * catch. The permission layer is what is under test here, so the limiter is
     * switched off and every refusal is the real one.
     */
    private function withoutRateLimiting(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    }

    public function test_a_monitor_cannot_write_anything_at_the_attendance_counter(): void
    {
        $this->deploy();
        [, , $entryA, , $monitor] = $this->twoEvents();

        $person = $entryA->participants()->firstOrFail();

        $this->withoutRateLimiting();
        $this->actingAs($monitor);

        /*
         | Its OWN event, deliberately. The scope is satisfied for every one of these,
         | so what refuses them is the permission the role does not hold — which is the
         | layer under test. A monitor assigned to an event must still be unable to
         | touch it.
         */
        $this->post(route('admin.event.attendance.check-in', $person))->assertForbidden();
        $this->delete(route('admin.event.attendance.undo-check-in', $person))->assertForbidden();
        $this->put(route('admin.event.attendance.swap', $person))->assertForbidden();
        $this->delete(route('admin.event.attendance.remove-player', $person))->assertForbidden();

        $this->post(route('admin.event.collection.code', $entryA))->assertForbidden();
        $this->post(route('admin.event.collection.hand-over', $entryA))->assertForbidden();

        // Nobody was checked in and nothing was handed over.
        $this->assertDatabaseCount('event_attendances', 0);
        $this->assertDatabaseCount('collection_handovers', 0);
    }

    public function test_a_monitor_cannot_move_money_or_send_a_message(): void
    {
        $this->deploy();
        [$a, , $entryA, , $monitor] = $this->twoEvents();

        $this->withoutRateLimiting();
        $this->actingAs($monitor);

        // The owner was explicit: "dia tidak boleh sending reminder, dia cuma hanya
        // boleh lihat sahaja."
        $this->post(route('admin.event.participants.payment', $entryA))->assertForbidden();
        $this->post(route('admin.event.participants.tally', $entryA))->assertForbidden();
        $this->post(route('admin.event.participants.remind', $entryA))->assertForbidden();
        $this->post(route('admin.event.participants.resend', $entryA))->assertForbidden();
        $this->post(route('admin.event.participants.sizes', $entryA))->assertForbidden();
        $this->post(route('admin.event.participants.sizes.all'))->assertForbidden();

        // Re-pricing and the receipt ledger, both of which move figures.
        $this->get(route('admin.event.participants.recalculate', $a))->assertForbidden();
        $this->post(route('admin.event.participants.recalculate.apply', $a))->assertForbidden();
        $this->get(route('admin.event.participants.receipts', $a))->assertForbidden();
        $this->post(route('admin.event.participants.receipts.apply', $a))->assertForbidden();

        // Not one ringgit moved and no message was queued.
        $this->assertDatabaseCount('event_registration_payments', 0);
        $this->assertDatabaseHas('event_registrations', [
            'id' => $entryA->id,
            'amount' => 40,
            'amount_paid' => 40,
        ]);
    }

    public function test_a_monitor_cannot_edit_or_delete_an_entry_or_a_person(): void
    {
        $this->deploy();
        [, , $entryA, , $monitor] = $this->twoEvents();

        $person = $entryA->participants()->firstOrFail();

        $this->withoutRateLimiting();
        $this->actingAs($monitor);

        $this->get(route('admin.event.participants.transfer', $entryA))->assertForbidden();
        $this->post(route('admin.event.participants.transfer.save', $entryA))->assertForbidden();
        $this->put(route('admin.event.participants.entry.update', $entryA))->assertForbidden();
        $this->put(route('admin.event.participants.person.update', [$entryA, $person]))->assertForbidden();
        $this->delete(route('admin.event.participants.person.remove', [$entryA, $person]))->assertForbidden();
        $this->delete(route('admin.event.participants.destroy', $entryA))->assertForbidden();

        // The entry and its person are exactly as they were.
        $this->assertDatabaseHas('event_registrations', ['id' => $entryA->id, 'reference' => 'REG-A-0001']);
        $this->assertDatabaseHas('event_participants', ['id' => $person->id, 'full_name' => 'Amir Assigned']);
    }

    public function test_a_monitor_cannot_create_or_edit_an_event_or_reach_another_module(): void
    {
        $this->deploy();
        [$a, , , , $monitor] = $this->twoEvents();

        $this->withoutRateLimiting();
        $this->actingAs($monitor);

        // The owner's words: "dalam event, dia tidak boleh create event itu."
        $this->get(route('admin.event.registration.create'))->assertForbidden();
        $this->post(route('admin.event.registration.store'))->assertForbidden();
        $this->get(route('admin.event.registration.edit', $a))->assertForbidden();
        $this->put(route('admin.event.registration.update', $a))->assertForbidden();
        $this->delete(route('admin.event.registration.destroy', $a))->assertForbidden();
        $this->post(route('admin.event.registration.wifi.issue', $a))->assertForbidden();
        $this->post(route('admin.event.registration.wifi.send', $a))->assertForbidden();

        // Event message templates, and the modules a third party has no business in.
        $this->get(route('admin.event.settings'))->assertForbidden();
        $this->get(route('admin.dashboard'))->assertForbidden();
        $this->get(route('admin.payments.overview'))->assertForbidden();
        $this->get(route('admin.tournaments.index'))->assertForbidden();
        $this->get(route('admin.sponsorship.index'))->assertForbidden();

        // The event survived, with its own title.
        $this->assertDatabaseHas('events', ['id' => $a->id, 'title' => 'ASSIGNED CARNIVAL 2026']);
        $this->assertDatabaseCount('events', 2);
    }

    public function test_a_monitor_cannot_manage_monitoring_accounts(): void
    {
        $this->deploy();
        [, , , , $monitor] = $this->twoEvents();

        /*
         | The one escalation this role could otherwise reach: a monitor that could
         | open the Monitoring tab could tick itself onto every event in the system.
         | The role deliberately holds none of the four monitors.* slugs.
         */
        $this->withoutRateLimiting();
        $this->actingAs($monitor);

        $this->get(route('admin.settings.users'))->assertForbidden();
        $this->post(route('admin.settings.users.monitors.store'))->assertForbidden();
        $this->put(route('admin.settings.users.monitors.update', $monitor))->assertForbidden();
        $this->delete(route('admin.settings.users.monitors.destroy', $monitor))->assertForbidden();

        $this->assertFalse($monitor->hasPermission('monitors.view'));
        $this->assertFalse($monitor->hasPermission('monitors.create'));
        $this->assertFalse($monitor->hasPermission('monitors.update'));
        $this->assertFalse($monitor->hasPermission('monitors.delete'));
    }

    /* ---------------------------------------------------------------------
     | Coupons
     * ------------------------------------------------------------------ */

    /**
     * A batch ticked onto the given events.
     *
     * @param  array<int, Event>  $events
     */
    private function coupon(string $name, array $events): Coupon
    {
        $coupon = Coupon::create([
            'name' => $name,
            'kind' => Coupon::KIND_EVENT,
            'mode' => Coupon::MODE_SHARED,
            'quantity' => 10,
            'expires_at' => now()->addMonth()->toDateString(),
            'discount_type' => Coupon::DISCOUNT_FIXED,
            'discount_value' => 5,
            'design' => 'classic',
        ]);

        $coupon->events()->sync(collect($events)->pluck('id')->all());

        return $coupon;
    }

    /** One redemption of a batch against one entry. */
    private function redemption(Coupon $coupon, EventRegistration $registration, float $discount, string $name): CouponCode
    {
        return CouponCode::create([
            'coupon_id' => $coupon->id,
            'event_registration_id' => $registration->id,
            'code' => $coupon->name,
            'participant_name' => $name,
            'discount_amount' => $discount,
            'redeemed_at' => now(),
        ]);
    }

    public function test_a_monitor_sees_only_coupons_that_apply_to_an_assigned_event(): void
    {
        $this->deploy();
        [$a, $b, , , $monitor] = $this->twoEvents();

        $mine = $this->coupon('MINE10', [$a]);
        $theirs = $this->coupon('THEIRS10', [$b]);
        $untagged = $this->coupon('UNTAGGED10', []);

        $this->actingAs($monitor);

        $list = $this->get(route('admin.coupons.index'));
        $list->assertOk();
        $list->assertSee('MINE10');
        $list->assertDontSee('THEIRS10');
        // An untagged batch is a draft of the office's, not a discount on their event.
        $list->assertDontSee('UNTAGGED10');

        $report = $this->get(route('admin.coupons.report'));
        $report->assertOk();
        $report->assertSee('MINE10');
        $report->assertDontSee('THEIRS10');

        // And a batch that applies only to B is refused by its own id, on every screen
        // that takes one.
        $this->get(route('admin.coupons.report.show', $theirs))->assertForbidden();
        $this->get(route('admin.coupons.report.export', $theirs))->assertForbidden();
        $this->get(route('admin.coupons.design', $theirs))->assertForbidden();
        $this->get(route('admin.coupons.tracking', ['coupon' => $theirs->id]))->assertForbidden();
        $this->get(route('admin.coupons.report.show', $untagged))->assertForbidden();

        // Its own batch opens.
        $this->get(route('admin.coupons.report.show', $mine))->assertOk();
        $this->get(route('admin.coupons.tracking', ['coupon' => $mine->id]))->assertOk();
    }

    public function test_a_shared_batch_shows_a_monitor_only_the_uses_on_its_own_event(): void
    {
        $this->deploy();
        [$a, $b, $entryA, $entryB, $monitor] = $this->twoEvents();

        /*
         | THE CASE THE COUPON RULE WAS WRITTEN FOR: one batch ticked on both events.
         | Refusing it outright would hide a discount really given on the monitor's own
         | event; showing it unscoped would name another organiser's entrant and add
         | their money to the figures. So the batch is shown and its USES are scoped.
         */
        $shared = $this->coupon('SHARED10', [$a, $b]);

        $this->redemption($shared, $entryA, 5, 'Amir Assigned');
        $this->redemption($shared, $entryB, 5, 'Bakar Withheld');

        $this->actingAs($monitor);

        $tracking = $this->get(route('admin.coupons.tracking'));
        $tracking->assertOk();
        $tracking->assertSee('Amir Assigned');
        $tracking->assertDontSee('Bakar Withheld');

        // The discount badge covers the filtered set, so it must be their RM 5.00 and
        // not the RM 10.00 the two redemptions add up to.
        $tracking->assertSee('5.00');
        $tracking->assertDontSee('10.00');

        $show = $this->get(route('admin.coupons.report.show', $shared));
        $show->assertOk();
        $show->assertSee('Amir Assigned');
        $show->assertDontSee('Bakar Withheld');
    }

    public function test_every_coupon_write_is_refused_for_a_monitor(): void
    {
        $this->deploy();
        [$a, , , , $monitor] = $this->twoEvents();

        $mine = $this->coupon('MINE10', [$a]);

        $this->withoutRateLimiting();
        $this->actingAs($monitor);

        $this->get(route('admin.coupons.create'))->assertForbidden();
        $this->post(route('admin.coupons.store'))->assertForbidden();
        $this->get(route('admin.coupons.edit', $mine))->assertForbidden();
        $this->put(route('admin.coupons.update', $mine))->assertForbidden();
        $this->delete(route('admin.coupons.destroy', $mine))->assertForbidden();
        $this->post(route('admin.coupons.codes.store', $mine))->assertForbidden();

        $this->assertDatabaseHas('coupons', ['id' => $mine->id, 'name' => 'MINE10']);
        $this->assertDatabaseCount('coupons', 1);
    }

    /* ---------------------------------------------------------------------
     | Login
     * ------------------------------------------------------------------ */

    public function test_the_monitor_role_holds_admin_access_and_a_monitor_can_sign_in(): void
    {
        $this->deploy();

        $role = Role::where('slug', Role::MONITOR)->firstOrFail();

        /*
         | Load bearing since the role-cannot-access fix: canAccessAdmin requires
         | admin.access and login refuses without it, so a monitor role missing this
         | slug would be an account nobody could ever sign in to.
         */
        $this->assertTrue($role->grantsAdminAccess());
        $this->assertTrue($role->is_protected);

        $a = $this->event('ASSIGNED CARNIVAL 2026');
        $monitor = $this->monitor([$a]);

        $this->assertTrue($monitor->canAccessAdmin());

        $response = $this->post(route('admin.login.attempt'), [
            'username' => $monitor->username,
            'password' => 'secret-password-1!',
        ]);

        // Lands on Participants rather than the dashboard, which it has no permission
        // for and which totals every event in the system.
        $response->assertRedirect(route('admin.event.participants'));
        $this->assertAuthenticatedAs($monitor);

        $this->get(route('admin.event.participants'))->assertOk();
    }

    /* ---------------------------------------------------------------------
     | No regression for the people who run the place
     * ------------------------------------------------------------------ */

    public function test_a_super_admin_and_an_administrator_still_see_every_event(): void
    {
        $this->deploy();
        [$a, $b, , $entryB] = $this->twoEvents();

        // Assigned to a monitor, which must make no difference to staff.
        $this->monitor([$a]);

        foreach (['super-admin', 'administrator'] as $slug) {
            $staff = $this->userWithRole($slug);

            $this->actingAs($staff);

            $participants = $this->get(route('admin.event.participants'));
            $participants->assertOk();
            $participants->assertSee('Amir Assigned');
            $participants->assertSee('Bakar Withheld');
            $participants->assertSee($a->title);
            $participants->assertSee($b->title);

            /*
             | Analytic Reporting is deliberately NOT asserted for staff here, and the
             | reason is a defect this feature uncovered rather than caused: the staff
             | version of that screen counts ContactMessage, and NO MIGRATION CREATES
             | contact_messages. It is absent from a freshly migrated database and from
             | the live one, so the screen answers 500 for every staff account today
             | and did so before any of this was written. Asserting it here would be a
             | test failing for somebody else's bug.
             |
             | The monitor's version of the screen IS asserted, above: a monitoring
             | account is not shown the enquiries card at all — enquiries belong to no
             | event — so it never reaches the missing table.
             */

            // Both events' own screens, by id.
            $this->get(route('admin.event.participants', ['event' => $b->id]))->assertOk();
            $this->get(route('admin.event.participants.show', $entryB))->assertOk();
            $this->get(route('admin.event.attendance', ['event' => $b->id]))->assertOk();
        }
    }

    public function test_staff_still_see_every_coupon_and_every_redemption(): void
    {
        $this->deploy();
        [$a, $b, $entryA, $entryB] = $this->twoEvents();

        $this->monitor([$a]);

        $shared = $this->coupon('SHARED10', [$a, $b]);
        $this->redemption($shared, $entryA, 5, 'Amir Assigned');
        $this->redemption($shared, $entryB, 5, 'Bakar Withheld');

        $this->coupon('UNTAGGED10', []);

        $this->actingAs($this->userWithRole('super-admin'));

        $list = $this->get(route('admin.coupons.index'));
        $list->assertOk();
        $list->assertSee('SHARED10');
        // The untagged batch is still the office's to see, which is the half of the
        // coupon rule that only matters to staff.
        $list->assertSee('UNTAGGED10');

        $tracking = $this->get(route('admin.coupons.tracking'));
        $tracking->assertOk();
        $tracking->assertSee('Amir Assigned');
        $tracking->assertSee('Bakar Withheld');

        // Both redemptions, so the badge is the full RM 10.00.
        $tracking->assertSee('10.00');
    }

    public function test_a_super_admin_flagged_as_a_monitor_is_never_narrowed(): void
    {
        $this->deploy();
        [$a, $b] = $this->twoEvents();

        /*
         | The way back in when an assignment goes wrong, and the same exemption the
         | handler scope keeps. The flag is set and no event is assigned, which for any
         | other role would mean seeing nothing.
         */
        $superAdmin = $this->userWithRole('super-admin', ['is_monitor' => true]);

        $this->assertFalse($superAdmin->isRestrictedToAssignedEvents());

        $response = $this->actingAs($superAdmin)->get(route('admin.event.participants'));

        $response->assertOk();
        $response->assertSee('Amir Assigned');
        $response->assertSee('Bakar Withheld');
        $response->assertSee($a->title);
        $response->assertSee($b->title);
    }
}
