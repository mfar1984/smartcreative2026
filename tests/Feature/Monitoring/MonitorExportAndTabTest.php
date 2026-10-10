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
 * What a monitoring account may actually DO, and the Monitoring tab that opens one.
 *
 * THE OWNER NARROWED THIS HIMSELF, in these words: "dia tidak boleh buat apa. kecuali
 * export participant sahaja. atau export participant di attendance. dan export
 * participant di coupon." Three exports. Nothing else, anywhere.
 *
 * So there are two halves here and they fail for different reasons:
 *
 *   THE THREE EXPORTS WORK, and every one of them is confined to the assigned events.
 *   An export is the whole leak in a single download, so a scope that holds on screen
 *   and not in the file would be worse than no scope at all.
 *
 *   EVERY OTHER CONTROL IS ABSENT FROM THE PAGE. Not refused when pressed — absent.
 *   The route-level refusals are proven next door in MonitorEventScopeTest; what is
 *   asserted here is that the buttons are not rendered, because a control a view-only
 *   observer can see is a control they will press and a support call either way. An
 *   absence test is also the one that fails when somebody later adds a button without
 *   thinking about who is reading the screen.
 */
class MonitorExportAndTabTest extends TestCase
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

    /** @param array<int, Event> $events */
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
            'registration_mode' => Event::MODE_INDIVIDUAL,
            'fee' => 40,
            'seats_total' => 100,
            'min_players' => 1,
            'max_players' => 20,
        ]);
    }

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

    /** The body of a streamed download, which assertSee cannot reach. */
    private function csv($response): string
    {
        return $response->streamedContent();
    }

    /* ---------------------------------------------------------------------
     | The three exports
     * ------------------------------------------------------------------ */

    public function test_a_monitor_can_export_participants_and_the_file_holds_only_assigned_events(): void
    {
        $this->deploy();
        [$a, $b, , , $monitor] = $this->twoEvents();

        $this->actingAs($monitor);

        $response = $this->get(route('admin.event.participants.export', ['event' => $a->id]));
        $response->assertOk();

        $csv = $this->csv($response);

        // Their own event's person and card number, which is the point of the file.
        $this->assertStringContainsString('Amir Assigned', $csv);
        $this->assertStringContainsString('900101010001', $csv);

        // And not one row of the event they were never given.
        $this->assertStringNotContainsString('Bakar Withheld', $csv);
        $this->assertStringNotContainsString('900202020002', $csv);

        // The other event's file is refused outright rather than quietly emptied.
        $this->get(route('admin.event.participants.export', ['event' => $b->id]))->assertForbidden();
    }

    public function test_a_monitor_can_export_attendance_and_the_file_holds_only_assigned_events(): void
    {
        $this->deploy();
        [$a, $b, $entryA, , $monitor] = $this->twoEvents();

        EventAttendance::create([
            'event_participant_id' => $entryA->participants()->firstOrFail()->id,
            'event_id' => $a->id,
            'event_registration_id' => $entryA->id,
            'checked_in_at' => now(),
            'ic_verified' => true,
        ]);

        $this->actingAs($monitor);

        $response = $this->get(route('admin.event.attendance.export', ['event' => $a->id]));
        $response->assertOk();

        $csv = $this->csv($response);

        $this->assertStringContainsString('Amir Assigned', $csv);
        $this->assertStringContainsString('900101010001', $csv);

        // The arrival state is the column that makes this file different from the
        // participants one.
        $this->assertStringContainsString('Present', $csv);

        $this->assertStringNotContainsString('Bakar Withheld', $csv);
        $this->assertStringNotContainsString('900202020002', $csv);

        $this->get(route('admin.event.attendance.export', ['event' => $b->id]))->assertForbidden();
    }

    public function test_the_attendance_export_marks_somebody_who_never_arrived_as_absent(): void
    {
        $this->deploy();
        [$a, , , , $monitor] = $this->twoEvents();

        // Nobody checked in, so the one row on the event must read Absent. Derived
        // rather than stored, the same way the Absent tab is.
        $response = $this->actingAs($monitor)->get(route('admin.event.attendance.export', ['event' => $a->id]));

        $response->assertOk();
        $this->assertStringContainsString('Absent', $this->csv($response));
    }

    public function test_the_attendance_export_works_for_staff_and_covers_every_event(): void
    {
        $this->deploy();
        [$a, $b, $entryA, $entryB] = $this->twoEvents();

        /*
         | The export is NOT a monitor-only feature. A screen with a download for a
         | third-party observer and none for the people working the door would be
         | absurd, so the office gets it too — one event at a time, like the two
         | exports beside it.
         */
        foreach (['super-admin', 'administrator'] as $slug) {
            $staff = $this->userWithRole($slug);

            $this->actingAs($staff);

            $first = $this->get(route('admin.event.attendance.export', ['event' => $a->id]));
            $first->assertOk();
            $this->assertStringContainsString('Amir Assigned', $this->csv($first));

            // The other event too, which a monitor is refused: staff are not narrowed.
            $second = $this->get(route('admin.event.attendance.export', ['event' => $b->id]));
            $second->assertOk();
            $this->assertStringContainsString('Bakar Withheld', $this->csv($second));
        }

        $this->assertNotNull($entryA);
        $this->assertNotNull($entryB);
    }

    public function test_the_attendance_export_needs_both_permissions(): void
    {
        $this->deploy();
        [$a] = $this->twoEvents();

        /*
         | Two permissions on one route, the only one in this module that asks for a
         | pair: attendance.view because it is this screen's data, and
         | participants.export because the file carries identity card numbers out of
         | the building. Holding either alone is not enough, which is what keeps
         | somebody who may read the counter screen from carrying the cards home.
         */
        $viewOnly = $this->userWithRole('viewer');
        $this->assertTrue($viewOnly->hasPermission('attendance.view'));
        $this->assertFalse($viewOnly->hasPermission('participants.export'));

        $this->actingAs($viewOnly)
            ->get(route('admin.event.attendance.export', ['event' => $a->id]))
            ->assertForbidden();
    }

    public function test_an_export_without_an_event_is_refused_rather_than_widened(): void
    {
        $this->deploy();
        [, , , , $monitor] = $this->twoEvents();

        $this->actingAs($monitor);

        /*
         | One file holding every identity card number ever collected is a different
         | risk from one event's, so the request is turned down rather than quietly
         | covering everything the scope allows. The same answer all three exports give.
         */
        $this->get(route('admin.event.participants.export'))->assertRedirect();
        $this->get(route('admin.event.attendance.export'))->assertRedirect();
    }

    public function test_a_monitor_can_export_a_coupon_and_the_file_holds_only_its_own_uses(): void
    {
        $this->deploy();
        [$a, $b, $entryA, $entryB, $monitor] = $this->twoEvents();

        // One batch on both events, which is the case the coupon rule exists for.
        $shared = Coupon::create([
            'name' => 'SHARED10',
            'kind' => Coupon::KIND_EVENT,
            'mode' => Coupon::MODE_SHARED,
            'quantity' => 10,
            'expires_at' => now()->addMonth()->toDateString(),
            'discount_type' => Coupon::DISCOUNT_FIXED,
            'discount_value' => 5,
            'design' => 'classic',
        ]);

        $shared->events()->sync([$a->id, $b->id]);

        foreach ([[$entryA, 'Amir Assigned'], [$entryB, 'Bakar Withheld']] as [$entry, $name]) {
            CouponCode::create([
                'coupon_id' => $shared->id,
                'event_registration_id' => $entry->id,
                'code' => $shared->name,
                'participant_name' => $name,
                'discount_amount' => 5,
                'redeemed_at' => now(),
            ]);
        }

        $response = $this->actingAs($monitor)->get(route('admin.coupons.report.export', $shared));
        $response->assertOk();

        $csv = $this->csv($response);

        // "export participant di coupon": the file names the people who used it, and
        // for a monitor only the ones on their own event.
        $this->assertStringContainsString('Amir Assigned', $csv);
        $this->assertStringNotContainsString('Bakar Withheld', $csv);
    }

    /* ---------------------------------------------------------------------
     | Absence: no control but the export
     * ------------------------------------------------------------------ */

    public function test_the_participants_screen_draws_no_action_control_for_a_monitor(): void
    {
        $this->deploy();
        [$a, , , , $monitor] = $this->twoEvents();

        $html = $this->actingAs($monitor)
            ->get(route('admin.event.participants', ['event' => $a->id]))
            ->assertOk()
            ->getContent();

        // Every write the screen can offer, by the URL its form or link would carry.
        // Asserted as an ABSENCE, which is what fails when somebody adds a button
        // later without asking who reads the page.
        foreach ([
            route('admin.event.participants.remind', 1),
            route('admin.event.participants.resend', 1),
            route('admin.event.participants.sizes', 1),
            route('admin.event.participants.sizes.all'),
            route('admin.event.participants.payment', 1),
            route('admin.event.participants.tally', 1),
            route('admin.event.participants.transfer', 1),
            route('admin.event.participants.recalculate', $a),
            route('admin.event.participants.receipts', $a),
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html);
        }

        /*
         | Deleting is asserted by its VERB rather than its URL, because the delete
         | route and the view route are the same path — /participants/{id}, DELETE and
         | GET — so a URL test would match the View link every row legitimately has.
         | No form on this page may carry a DELETE, which covers the entry delete and
         | anything else destructive added beside it.
         */
        $this->assertStringNotContainsString('value="DELETE"', $html);

        // The one thing it may do is there, and so is the table it describes.
        $this->assertStringContainsString(route('admin.event.participants.export', ['event' => $a->id]), $html);
        $this->assertStringContainsString('Amir Assigned', $html);
    }

    public function test_no_screen_offers_a_monitor_a_link_it_would_be_refused(): void
    {
        $this->deploy();
        [$a, , $entryA, , $monitor] = $this->twoEvents();

        $mine = Coupon::create([
            'name' => 'MINE10',
            'kind' => Coupon::KIND_EVENT,
            'mode' => Coupon::MODE_SHARED,
            'quantity' => 10,
            'expires_at' => now()->addMonth()->toDateString(),
            'discount_type' => Coupon::DISCOUNT_FIXED,
            'discount_value' => 5,
            'design' => 'classic',
        ]);

        $mine->events()->sync([$a->id]);

        $this->actingAs($monitor);

        /*
         | A DEAD LINK is not a write, but the owner's instruction covers it: a
         | view-only observer should not be shown anything that cannot work. The
         | breadcrumb on every one of these screens linked to the Dashboard, which a
         | monitor has no permission for — the same trap the sponsorship area had
         | already been fixed for.
         */
        $screens = [
            route('admin.event.participants', ['event' => $a->id]),
            route('admin.event.participants.show', $entryA),
            route('admin.event.attendance', ['event' => $a->id]),
            route('admin.event.collection', ['event' => $a->id]),
            route('admin.event.reporting'),
            route('admin.coupons.index'),
            route('admin.coupons.tracking'),
            route('admin.coupons.report'),
            route('admin.coupons.report.show', $mine),
        ];

        foreach ($screens as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString(
                sprintf('href="%s"', route('admin.dashboard')),
                $html,
                sprintf('%s must not offer a monitor a Dashboard link it cannot open.', $url),
            );
        }
    }

    public function test_a_monitors_navigation_shows_only_the_seven_screens_it_may_read(): void
    {
        $this->deploy();
        [$a, , , , $monitor] = $this->twoEvents();

        $html = $this->actingAs($monitor)
            ->get(route('admin.event.participants', ['event' => $a->id]))
            ->assertOk()
            ->getContent();

        /*
         | "dia akan nampak macam dalam gambar ni saja." The navigation is built from
         | the permission on each item, so this is really an assertion about the role's
         | permission list — but it is the one the owner would check, and it is the
         | difference between the account he asked for and one that merely cannot press
         | anything.
         */
        foreach ([
            route('admin.event.participants'),
            route('admin.event.attendance'),
            route('admin.event.collection'),
            route('admin.event.reporting'),
            route('admin.coupons.index'),
            route('admin.coupons.tracking'),
            route('admin.coupons.report'),
        ] as $allowed) {
            $this->assertStringContainsString(sprintf('href="%s"', $allowed), $html);
        }

        // And nothing else. Registration is the one Event item a monitor must not
        // have: "dalam event, dia tidak boleh create event itu."
        foreach ([
            route('admin.event.registration'),
            route('admin.event.settings'),
            route('admin.payments.overview'),
            route('admin.tournaments.index'),
            route('admin.campaigns.index'),
            route('admin.settings.users'),
            route('admin.settings.roles'),
            route('admin.shop.products'),
        ] as $forbidden) {
            $this->assertStringNotContainsString(sprintf('href="%s"', $forbidden), $html);
        }
    }

    public function test_staff_keep_the_dashboard_crumb_on_those_screens(): void
    {
        $this->deploy();
        [$a] = $this->twoEvents();

        // The regression guard for making the crumb conditional.
        $html = $this->actingAs($this->userWithRole('administrator'))
            ->get(route('admin.event.participants', ['event' => $a->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(sprintf('href="%s"', route('admin.dashboard')), $html);
    }

    public function test_the_participant_detail_screen_draws_no_action_control_for_a_monitor(): void
    {
        $this->deploy();
        [, , $entryA, , $monitor] = $this->twoEvents();

        $person = $entryA->participants()->firstOrFail();

        $html = $this->actingAs($monitor)
            ->get(route('admin.event.participants.show', $entryA))
            ->assertOk()
            ->getContent();

        foreach ([
            route('admin.event.participants.remind', $entryA),
            route('admin.event.participants.resend', $entryA),
            route('admin.event.participants.entry.update', $entryA),
            route('admin.event.participants.transfer', $entryA),
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html);
        }

        /*
         | The person routes and the entry delete are asserted by VERB, for the reason
         | given on the list test: PUT /participants/{id}/people/{id} and DELETE of the
         | same path are one URL, and DELETE /participants/{id} is the URL of the page
         | being read. Neither verb may appear in any form on this screen.
         */
        $this->assertStringNotContainsString('value="DELETE"', $html);
        $this->assertStringNotContainsString('value="PUT"', $html);
        $this->assertNotNull($person);

        /*
         | And no "Open Event" link, which is a DEAD link rather than a write: a
         | monitor holds participants.view and deliberately not events.view, so the
         | link was drawn and answered 403 when pressed. Gated on the permission its
         | own target asks for.
         */
        $this->assertStringNotContainsString(route('admin.event.registration.show', $entryA->event), $html);

        // What it IS there to read: the person, and their card number in full.
        $this->assertStringContainsString('Amir Assigned', $html);
        $this->assertStringContainsString('900101010001', $html);
    }

    public function test_staff_keep_the_open_event_link_on_an_entry(): void
    {
        $this->deploy();
        [, , $entryA] = $this->twoEvents();

        // The regression guard for making that link conditional: anybody who may read
        // an event still gets it.
        $html = $this->actingAs($this->userWithRole('administrator'))
            ->get(route('admin.event.participants.show', $entryA))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('admin.event.registration.show', $entryA->event), $html);
        $this->assertStringContainsString('Open Event', $html);
    }

    public function test_the_attendance_screen_draws_no_counter_control_for_a_monitor(): void
    {
        $this->deploy();
        [$a, , $entryA, , $monitor] = $this->twoEvents();

        $person = $entryA->participants()->firstOrFail();

        $html = $this->actingAs($monitor)
            ->get(route('admin.event.attendance', ['event' => $a->id, 'registration' => $entryA->id]))
            ->assertOk()
            ->getContent();

        foreach ([
            route('admin.event.attendance.check-in', $person),
            route('admin.event.attendance.undo-check-in', $person),
            route('admin.event.attendance.swap', $person),
            route('admin.event.attendance.remove-player', $person),
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html);
        }

        // The export is on the list tabs, where the file is actually about something.
        $present = $this->get(route('admin.event.attendance', ['tab' => 'present', 'event' => $a->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('admin.event.attendance.export', ['event' => $a->id]), $present);
    }

    public function test_the_collection_screen_draws_no_counter_control_for_a_monitor(): void
    {
        $this->deploy();
        [$a, , $entryA, , $monitor] = $this->twoEvents();

        $html = $this->actingAs($monitor)
            ->get(route('admin.event.collection', ['event' => $a->id]))
            ->assertOk()
            ->getContent();

        foreach ([
            route('admin.event.collection.code', $entryA),
            route('admin.event.collection.hand-over', $entryA),
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html);
        }
    }

    public function test_the_coupon_screens_draw_no_action_control_for_a_monitor(): void
    {
        $this->deploy();
        [$a, , , , $monitor] = $this->twoEvents();

        $mine = Coupon::create([
            'name' => 'MINE10',
            'kind' => Coupon::KIND_EVENT,
            'mode' => Coupon::MODE_SHARED,
            'quantity' => 10,
            'expires_at' => now()->addMonth()->toDateString(),
            'discount_type' => Coupon::DISCOUNT_FIXED,
            'discount_value' => 5,
            'design' => 'classic',
        ]);

        $mine->events()->sync([$a->id]);

        $this->actingAs($monitor);

        $list = $this->get(route('admin.coupons.index'))->assertOk()->getContent();

        foreach ([
            route('admin.coupons.create'),
            route('admin.coupons.edit', $mine),
            route('admin.coupons.destroy', $mine),
            // The artwork download used to be drawn for anybody holding the view
            // permission, which a monitor does hold. It is the one control on this
            // page that had no gate of its own.
            route('admin.coupons.design', $mine),
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $list);
        }

        /*
         | And the column that held them is gone rather than left empty: those three
         | were everything it could contain, so a header over blank cells would be a
         | control somebody goes looking for.
         */
        $this->assertStringNotContainsString('Actions', $list);
        $this->assertStringContainsString('MINE10', $list);

        $show = $this->get(route('admin.coupons.report.show', $mine))->assertOk()->getContent();

        $this->assertStringNotContainsString(route('admin.coupons.codes.store', $mine), $show);
        $this->assertStringNotContainsString(route('admin.coupons.edit', $mine), $show);

        /*
         | The sponsorship panel is not drawn either, and that is correctness rather
         | than caution: those four figures are a sponsorship's money across the WHOLE
         | batch, so they are the one set here that cannot honestly be narrowed to one
         | event.
         */
        $this->assertStringNotContainsString('Committed', $show);
        $this->assertStringNotContainsString('Sponsorship left', $show);

        // The export is still offered, because it is one of the three.
        $this->assertStringContainsString(route('admin.coupons.report.export', $mine), $show);
    }

    /**
     * The navigation offers a monitor nothing it would be refused on.
     *
     * The sidebar hides what the role cannot reach, so this is mostly a check that
     * the permission list is doing its job. It earns its place because of the one
     * thing it caught: the sidebar's own Payments icon was not gated on anything at
     * all, so every view-only audience — a handler, a sponsorship and now a monitor —
     * was being shown a link that answers 403 when pressed. It is behind
     * payments.view now, the permission the screen it points at requires.
     *
     * Each link is asserted present for STAFF and absent for the monitor. Without the
     * staff half an absence assertion would also pass on a page that failed to render
     * a sidebar at all, and would then guard nothing for ever.
     */
    public function test_the_navigation_offers_a_monitor_nothing_it_cannot_reach(): void
    {
        $this->deploy();
        [$a, , , , $monitor] = $this->twoEvents();

        $monitorHtml = $this->actingAs($monitor)
            ->get(route('admin.event.participants'))
            ->assertOk()
            ->getContent();

        $staffHtml = $this->actingAs($this->userWithRole('administrator'))
            ->get(route('admin.event.participants'))
            ->assertOk()
            ->getContent();

        // A whole href attribute rather than a bare path, so one screen's URL cannot
        // be found inside another's.
        $link = fn (string $name) => sprintf('href="%s"', route($name));

        // What it came for.
        $this->assertStringContainsString($link('admin.event.participants'), $monitorHtml);

        /*
         | admin.dashboard is deliberately not in this list. The breadcrumb and the
         | masthead link to it on every screen for every audience, which is how a
         | handler and a sponsorship already behave, so it is a pre-existing property
         | of the layout rather than anything this feature decides.
         */
        foreach ([
            'admin.event.registration',
            'admin.event.settings',
            'admin.settings.users',
            'admin.settings.roles',
            'admin.payments.overview',
            'admin.tournaments.index',
            'admin.campaigns.index',
            'admin.shop.products',
        ] as $name) {
            $this->assertStringContainsString(
                $link($name),
                $staffHtml,
                sprintf('%s is not in the staff navigation, so its absence for a monitor proves nothing.', $name),
            );

            $this->assertStringNotContainsString(
                $link($name),
                $monitorHtml,
                sprintf('A monitoring account must not be shown a link to %s.', $name),
            );
        }
    }

    public function test_staff_keep_every_coupon_control_they_had(): void
    {
        $this->deploy();
        [$a] = $this->twoEvents();

        $mine = Coupon::create([
            'name' => 'MINE10',
            'kind' => Coupon::KIND_EVENT,
            'mode' => Coupon::MODE_SHARED,
            'quantity' => 10,
            'expires_at' => now()->addMonth()->toDateString(),
            'discount_type' => Coupon::DISCOUNT_FIXED,
            'discount_value' => 5,
            'design' => 'classic',
        ]);

        $mine->events()->sync([$a->id]);

        // The regression guard for the column being conditional: a super admin must
        // still get the Actions column and everything that was ever in it.
        $list = $this->actingAs($this->userWithRole('super-admin'))
            ->get(route('admin.coupons.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Actions', $list);
        $this->assertStringContainsString(route('admin.coupons.create'), $list);
        $this->assertStringContainsString(route('admin.coupons.edit', $mine), $list);
        $this->assertStringContainsString(route('admin.coupons.design', $mine), $list);
    }

    /* ---------------------------------------------------------------------
     | The Monitoring tab
     * ------------------------------------------------------------------ */

    public function test_the_monitoring_tab_lists_only_monitors_and_the_other_tabs_exclude_them(): void
    {
        $this->deploy();

        $a = $this->event('ASSIGNED CARNIVAL 2026');

        $monitor = $this->monitor([$a], 'Monitor Account');
        $handler = $this->userWithRole('handler', ['is_handler' => true, 'name' => 'Handler Account']);
        $sponsor = $this->userWithRole('sponsor', ['is_sponsor' => true, 'name' => 'Sponsor Account']);
        $admin = $this->userWithRole('administrator', ['name' => 'Admin Account']);

        $this->actingAs($this->userWithRole('super-admin'));

        // No account appears on two tabs, which is the whole reason the flags are
        // written as constants by four separate endpoints.
        $expectations = [
            'monitoring' => $monitor,
            'handler' => $handler,
            'sponsorship' => $sponsor,
            'users' => $admin,
        ];

        foreach ($expectations as $tab => $belongs) {
            $html = $this->get(route('admin.settings.users', ['tab' => $tab]))->assertOk()->getContent();

            $this->assertStringContainsString($belongs->name, $html, sprintf('%s must appear on the %s tab.', $belongs->name, $tab));

            foreach ($expectations as $otherTab => $elsewhere) {
                if ($otherTab === $tab) {
                    continue;
                }

                $this->assertStringNotContainsString(
                    $elsewhere->username,
                    $html,
                    sprintf('%s belongs on the %s tab and must not be listed on %s.', $elsewhere->name, $otherTab, $tab),
                );
            }
        }

        // The events column is the whole reason somebody opens this tab.
        $monitoring = $this->get(route('admin.settings.users', ['tab' => 'monitoring']))->getContent();
        $this->assertStringContainsString('ASSIGNED CARNIVAL 2026', $monitoring);
    }

    public function test_creating_a_monitoring_account_assigns_the_role_server_side_and_its_events(): void
    {
        $this->deploy();

        $a = $this->event('ASSIGNED CARNIVAL 2026');
        $b = $this->event('WITHHELD CHAMPIONSHIP 2026');

        $this->actingAs($this->userWithRole('super-admin'))
            ->post(route('admin.settings.users.monitors.store'), [
                'name' => 'Persatuan Luar',
                'username' => 'persatuan-luar',
                'email' => 'luar@example.test',
                'password' => 'secret-password-1!',
                'password_confirmation' => 'secret-password-1!',
                'is_active' => true,
                'events' => [$a->id],
            ])
            ->assertSessionHasNoErrors();

        $created = User::where('username', 'persatuan-luar')->firstOrFail();

        $this->assertTrue($created->isMonitor());
        $this->assertSame(Role::MONITOR, $created->role->slug);
        $this->assertSame([$a->id], $created->monitoredEvents()->pluck('events.id')->all());
        $this->assertTrue($created->isRestrictedToAssignedEvents());

        // The event it was not given.
        $this->assertFalse($created->monitorsEvent($b));
    }

    public function test_a_posted_super_admin_role_id_is_never_granted_at_the_monitor_endpoint(): void
    {
        $this->deploy();

        $a = $this->event('ASSIGNED CARNIVAL 2026');
        $superAdminRole = Role::where('slug', Role::SUPER_ADMIN)->firstOrFail();

        /*
         | THE ESCALATION THIS ENDPOINT MUST NOT ALLOW. A role granted only
         | monitors.create would otherwise be able to mint a super admin by posting a
         | role_id, so the role is looked up by its fixed slug and written last, where
         | nothing in the payload can reach it. The same shape the handler and sponsor
         | endpoints are tested for.
         */
        $this->actingAs($this->userWithRole('super-admin'))
            ->post(route('admin.settings.users.monitors.store'), [
                'name' => 'Smuggler',
                'username' => 'smuggler',
                'email' => 'smuggler@example.test',
                'password' => 'secret-password-1!',
                'password_confirmation' => 'secret-password-1!',
                'is_active' => true,
                'events' => [$a->id],
                'role_id' => $superAdminRole->id,
                'is_monitor' => false,
            ])
            ->assertSessionHasNoErrors();

        $created = User::where('username', 'smuggler')->firstOrFail();

        $this->assertSame(Role::MONITOR, $created->role->slug);
        $this->assertNotSame($superAdminRole->id, $created->role_id);
        // is_monitor was posted false and is still true: the flag is a constant here.
        $this->assertTrue($created->isMonitor());
    }

    public function test_a_cross_tab_id_is_refused_in_both_directions(): void
    {
        $this->deploy();

        $a = $this->event('ASSIGNED CARNIVAL 2026');

        $monitor = $this->monitor([$a], 'Monitor Account');
        $handler = $this->userWithRole('handler', ['is_handler' => true, 'name' => 'Handler Account']);
        $sponsor = $this->userWithRole('sponsor', ['is_sponsor' => true, 'name' => 'Sponsor Account']);
        $admin = $this->userWithRole('administrator', ['name' => 'Admin Account']);

        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->actingAs($this->userWithRole('super-admin'));

        /*
         | Four lists over ONE table, so an id from any of them is a valid route
         | parameter on another's routes. Without the refusal a role granted only
         | monitoring management could edit an administrator by changing the number in
         | the URL.
         */
        $payload = [
            'name' => 'Renamed',
            'username' => 'renamed-'.uniqid(),
            'email' => uniqid().'@example.test',
            'is_active' => true,
        ];

        // A monitor id posted at the other three tabs' endpoints.
        $this->put(route('admin.settings.users.update', $monitor), $payload + ['role_id' => $admin->role_id])->assertForbidden();
        $this->put(route('admin.settings.users.handlers.update', $monitor), $payload)->assertForbidden();
        $this->put(route('admin.settings.users.sponsors.update', $monitor), $payload)->assertForbidden();
        $this->delete(route('admin.settings.users.destroy', $monitor))->assertForbidden();
        $this->delete(route('admin.settings.users.handlers.destroy', $monitor))->assertForbidden();
        $this->delete(route('admin.settings.users.sponsors.destroy', $monitor))->assertForbidden();

        // And the other three tabs' ids posted at the monitor endpoints.
        foreach ([$handler, $sponsor, $admin] as $elsewhere) {
            $this->put(route('admin.settings.users.monitors.update', $elsewhere), $payload)->assertForbidden();
            $this->delete(route('admin.settings.users.monitors.destroy', $elsewhere))->assertForbidden();
        }

        // Nobody was renamed and nobody was deleted.
        $this->assertSame('Monitor Account', $monitor->fresh()->name);
        $this->assertSame('Handler Account', $handler->fresh()->name);
        $this->assertSame('Sponsor Account', $sponsor->fresh()->name);
        $this->assertSame('Admin Account', $admin->fresh()->name);
    }

    public function test_editing_a_monitoring_account_can_add_and_remove_events(): void
    {
        $this->deploy();

        $a = $this->event('ASSIGNED CARNIVAL 2026');
        $b = $this->event('WITHHELD CHAMPIONSHIP 2026');

        $monitor = $this->monitor([$a]);
        $admin = $this->userWithRole('super-admin');

        $base = [
            'name' => $monitor->name,
            'username' => $monitor->username,
            'email' => $monitor->email,
            'is_active' => true,
        ];

        // Widened to both.
        $this->actingAs($admin)
            ->put(route('admin.settings.users.monitors.update', $monitor), $base + ['events' => [$a->id, $b->id]])
            ->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $monitor->fresh()->monitoredEvents()->pluck('events.id')->all());

        // Narrowed back to one, which is how sight is taken away.
        $this->actingAs($admin)
            ->put(route('admin.settings.users.monitors.update', $monitor), $base + ['events' => [$b->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame([$b->id], $monitor->fresh()->monitoredEvents()->pluck('events.id')->all());

        /*
         | Nothing sent clears it altogether. A form sends only what is ticked, so
         | treating an absent field as "leave it alone" would make unticking the last
         | event impossible.
         */
        $this->actingAs($admin)
            ->put(route('admin.settings.users.monitors.update', $monitor), $base)
            ->assertSessionHasNoErrors();

        $this->assertSame([], $monitor->fresh()->monitoredEvents()->pluck('events.id')->all());

        // The account is still a monitor: neither the role nor the flag is in the
        // payload, so an edit here cannot turn it into anything else.
        $this->assertTrue($monitor->fresh()->isMonitor());
        $this->assertSame(Role::MONITOR, $monitor->fresh()->role->slug);
    }

    public function test_a_crafted_event_id_is_refused_by_validation(): void
    {
        $this->deploy();

        $this->actingAs($this->userWithRole('super-admin'))
            ->post(route('admin.settings.users.monitors.store'), [
                'name' => 'Crafted',
                'username' => 'crafted',
                'email' => 'crafted@example.test',
                'password' => 'secret-password-1!',
                'password_confirmation' => 'secret-password-1!',
                'is_active' => true,
                'events' => [999999],
            ])
            ->assertSessionHasErrors('events.0');

        $this->assertDatabaseMissing('users', ['username' => 'crafted']);
        $this->assertDatabaseCount('monitor_event', 0);
    }

    public function test_deleting_a_monitoring_account_leaves_its_events_untouched(): void
    {
        $this->deploy();

        $a = $this->event('ASSIGNED CARNIVAL 2026');
        $this->registration($a, 'REG-A-0001', 'Amir Assigned', '900101010001');

        $monitor = $this->monitor([$a]);

        $this->actingAs($this->userWithRole('super-admin'))
            ->delete(route('admin.settings.users.monitors.destroy', $monitor))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('users', ['id' => $monitor->id]);

        // The pivot cascaded; the event and its entries are exactly as they were,
        // because nothing a monitor could do ever left a mark on one.
        $this->assertDatabaseCount('monitor_event', 0);
        $this->assertDatabaseHas('events', ['id' => $a->id, 'title' => 'ASSIGNED CARNIVAL 2026']);
        $this->assertDatabaseHas('event_registrations', ['reference' => 'REG-A-0001']);
    }

    public function test_each_monitor_permission_is_required_by_its_own_endpoint(): void
    {
        $this->deploy();

        $a = $this->event('ASSIGNED CARNIVAL 2026');
        $monitor = $this->monitor([$a]);

        /*
         | Four slugs rather than reusing users.*, so monitoring management can be
         | granted without administrator management. A role holding none of them
         | reaches none of the four endpoints.
         */
        $noMonitorRights = $this->userWithRole('administrator');

        $this->assertFalse($noMonitorRights->hasPermission('monitors.view'));

        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->actingAs($noMonitorRights);

        // The screen opens on its own users.view, but the Monitoring tab is not drawn
        // and cannot be reached by editing the query string.
        $html = $this->get(route('admin.settings.users', ['tab' => 'monitoring']))->assertOk()->getContent();
        $this->assertStringNotContainsString($monitor->username, $html);

        $this->post(route('admin.settings.users.monitors.store'))->assertForbidden();
        $this->put(route('admin.settings.users.monitors.update', $monitor))->assertForbidden();
        $this->delete(route('admin.settings.users.monitors.destroy', $monitor))->assertForbidden();
    }
}
