<?php

namespace Tests\Feature\Registration;

use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\EventRegistration;
use App\Models\EventRegistrationPayment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\ParticipantOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The money badges and the tab counts on the participants list belong to the filters.
 *
 * "cuba lihat dalam /admin/event/participants?tab=group&event=4" — the owner chose
 * HARI SUKAN NEGARA 2026 and read Collected RM 2,840.00, which he took to be what that
 * event had taken. It was not. The rows honoured the event filter; the two badges and
 * the five tab counts were built from a second query that swept every event, so
 * RM 1,200.00 of a different event's money sat in the figure, the Paid tab read 74
 * against an event holding 30, and the Team tab read 44 against an event holding none.
 *
 * The fixture is two events for that reason: one event's figures are only correct if
 * the other event's money and rows are excluded. The shape is the live one — a settled
 * entry and a part-paid entry on the event being looked at, and the whole Team tab
 * belonging to the other event.
 *
 * A read-path change. The last test here asserts the screen writes nothing at all.
 */
class ParticipantListScopeTest extends TestCase
{
    use RefreshDatabase;

    /** Seeing the screen is all any of this needs. */
    private const CAN_VIEW = ['participants.view'];

    /* ---------------------------------------------------------------------
     | Fixtures
     * ------------------------------------------------------------------ */

    /** The event being looked at: grouping entries, RM 40.00 a head. */
    private function sibu(): Event
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

    /** The other event, whose money and squads must stay out of the figures above. */
    private function mobileLegend(): Event
    {
        return Event::create([
            'slug' => 'ml-' . uniqid(),
            'title' => 'SIBU E-SPORT CHAMPIONSHIP 2026 MOBILE LEGEND',
            'category' => 'Esport',
            'starts_at' => now()->addMonth()->toDateString(),
            'ends_at' => now()->addMonth()->toDateString(),
            'status' => Event::STATUS_OPEN,
            'registration_mode' => Event::MODE_MANAGER,
            'fee' => 50,
            'seats_total' => 0,
            'min_players' => 1,
            'max_players' => 20,
        ]);
    }

    /**
     * One entry with one person on it.
     *
     * Whatever has arrived is written both on the row and into the receipt ledger, so
     * the badge and the ledger cannot disagree about it.
     */
    private function registration(
        Event $event,
        string $reference,
        string $paymentStatus,
        float $amount,
        float $paid = 0,
        string $status = EventRegistration::STATUS_PENDING,
    ): EventRegistration {
        $registration = EventRegistration::create([
            'event_id' => $event->id,
            'reference' => $reference,
            'mode' => $event->registration_mode,
            'team_name' => 'Pasukan ' . $reference,
            'status' => $status,
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
     * The live shape, in miniature.
     *
     * Sibu: RM 80.00 settled and RM 40.00 of RM 120.00 arrived, so RM 120.00 in and
     * RM 80.00 still owed. Mobile Legend: RM 200.00 in, nothing owed, and it owns the
     * only Team entry in the database.
     *
     * @return array{0: Event, 1: Event}
     */
    private function twoEvents(): array
    {
        $sibu = $this->sibu();
        $other = $this->mobileLegend();

        $this->registration($sibu, 'REG-2026-0071', EventRegistration::PAYMENT_PAID, 80, 80, EventRegistration::STATUS_CONFIRMED);
        $this->registration($sibu, 'REG-2026-0068', EventRegistration::PAYMENT_PARTIAL, 120, 40);
        $this->registration($other, 'REG-2026-0012', EventRegistration::PAYMENT_PAID, 200, 200, EventRegistration::STATUS_CONFIRMED);

        return [$sibu, $other];
    }

    /**
     * A user holding exactly the named permissions and nothing else.
     *
     * A real role with real pivot rows rather than the super-admin shortcut, so the
     * screen is reached the way an office account reaches it.
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

    /** The screen, with whatever filters are being tested. */
    private function list(array $filters = [], ?User $user = null)
    {
        return $this->actingAs($user ?? $this->userWith(self::CAN_VIEW))
            ->get(route('admin.event.participants', $filters));
    }

    /**
     * The five tab counts as the tab bar draws them.
     *
     * @return array<string, int>
     */
    private function tabCounts($response): array
    {
        return collect($response->viewData('tabs'))
            ->map(fn (array $tab) => (int) $tab['count'])
            ->all();
    }

    /* ---------------------------------------------------------------------
     | 1. The money badges
     * ------------------------------------------------------------------ */

    public function test_the_badges_report_only_the_chosen_events_money(): void
    {
        [$sibu] = $this->twoEvents();

        $filtered = $this->list(['tab' => 'group', 'event' => $sibu->id]);
        $filtered->assertOk();

        // RM 80.00 settled plus the RM 40.00 that arrived on the part-paid entry, and
        // the RM 80.00 balance behind it. The other event's RM 200.00 is not here.
        $this->assertSame(120.0, $filtered->viewData('totals')['collected']);
        $this->assertSame(80.0, $filtered->viewData('totals')['outstanding']);

        // And the two still add up to what the event charged, so nothing is counted
        // twice and nothing has gone missing.
        $this->assertSame(
            200.0,
            $filtered->viewData('totals')['collected'] + $filtered->viewData('totals')['outstanding'],
        );
    }

    public function test_with_no_event_chosen_the_badges_report_every_event(): void
    {
        $this->twoEvents();

        $all = $this->list(['tab' => 'group']);
        $all->assertOk();

        $this->assertSame(320.0, $all->viewData('totals')['collected']);
        $this->assertSame(80.0, $all->viewData('totals')['outstanding']);

        // The caption under the badges says so, rather than leaving the figure to be
        // read as one event's. This is the sentence the owner was missing.
        $all->assertSee('All events');
    }

    public function test_a_part_paid_entry_contributes_its_balance_and_not_its_charge(): void
    {
        $sibu = $this->sibu();

        // RM 40.00 of RM 120.00, which is the live REG-2026-0068 exactly.
        $this->registration($sibu, 'REG-2026-0068', EventRegistration::PAYMENT_PARTIAL, 120, 40);

        $totals = $this->list(['tab' => 'group', 'event' => $sibu->id])->viewData('totals');

        // Received, not charged: the charge in collected would invent RM 80.00.
        $this->assertSame(40.0, $totals['collected']);

        // Balance, not charge: the charge in outstanding would chase RM 120.00 from
        // somebody who has already transferred RM 40.00 of it.
        $this->assertSame(80.0, $totals['outstanding']);
    }

    public function test_the_badges_do_not_move_as_tabs_are_switched(): void
    {
        [$sibu] = $this->twoEvents();

        $paid = $this->list(['tab' => 'paid', 'event' => $sibu->id]);
        $unpaid = $this->list(['tab' => 'unpaid', 'event' => $sibu->id]);

        // Money belongs to the filters, not to the tab. Collected changing to RM 80.00
        // on the Paid tab would read as the takings having dropped.
        foreach ([$paid, $unpaid] as $response) {
            $this->assertSame(120.0, $response->viewData('totals')['collected']);
            $this->assertSame(80.0, $response->viewData('totals')['outstanding']);
        }
    }

    public function test_a_cancelled_entry_is_not_chased_in_the_outstanding_badge(): void
    {
        $sibu = $this->sibu();

        $this->registration($sibu, 'REG-2026-0091', EventRegistration::PAYMENT_UNPAID, 40);
        $this->registration($sibu, 'REG-2026-0092', EventRegistration::PAYMENT_UNPAID, 40, 0, EventRegistration::STATUS_CANCELLED);

        // Only the live one. Nobody is going to pay a cancelled entry, so counting it
        // would overstate what is still coming and never come down.
        $this->assertSame(40.0, $this->list(['event' => $sibu->id])->viewData('totals')['outstanding']);
    }

    /* ---------------------------------------------------------------------
     | 2. The tab counts
     * ------------------------------------------------------------------ */

    public function test_every_tab_count_is_narrowed_to_the_chosen_event(): void
    {
        [$sibu] = $this->twoEvents();

        $counts = $this->tabCounts($this->list(['tab' => 'group', 'event' => $sibu->id]));

        $this->assertSame([
            'individual' => 0,
            'group' => 2,
            // The other event's squad. This is the count that read 44 on an event
            // with no team entries at all.
            'team' => 0,
            'paid' => 1,
            'unpaid' => 1,
        ], $counts);
    }

    public function test_with_no_event_chosen_every_tab_counts_both_events(): void
    {
        $this->twoEvents();

        $this->assertSame([
            'individual' => 0,
            'group' => 2,
            'team' => 1,
            'paid' => 2,
            'unpaid' => 1,
        ], $this->tabCounts($this->list(['tab' => 'group'])));
    }

    public function test_a_tab_count_matches_the_rows_that_tab_actually_lists(): void
    {
        [$sibu] = $this->twoEvents();

        foreach (['individual', 'group', 'team', 'paid', 'unpaid'] as $tab) {
            $response = $this->list(['tab' => $tab, 'event' => $sibu->id]);
            $response->assertOk();

            $this->assertSame(
                $response->viewData('registrations')->total(),
                $this->tabCounts($response)[$tab],
                sprintf('The %s badge promises a different number of rows from the ones it lists.', $tab),
            );
        }
    }

    public function test_the_tab_links_carry_the_filters_the_counts_were_built_from(): void
    {
        [$sibu] = $this->twoEvents();

        $response = $this->list(['tab' => 'group', 'event' => $sibu->id]);

        // Otherwise a badge counting one event would link to a tab listing every
        // event, which is the disagreement this screen started with.
        $response->assertSee('event=' . $sibu->id . '&amp;tab=paid', false);
        $response->assertSee('event=' . $sibu->id . '&amp;tab=unpaid', false);
    }

    /* ---------------------------------------------------------------------
     | 3. The search box narrows both, the same way it narrows the rows
     * ------------------------------------------------------------------ */

    public function test_the_search_box_narrows_the_badges_and_the_counts_like_the_rows(): void
    {
        [$sibu] = $this->twoEvents();

        $response = $this->list(['tab' => 'group', 'event' => $sibu->id, 'q' => 'REG-2026-0068']);
        $response->assertOk();

        // One row, and the figures for that row alone.
        $this->assertSame(1, $response->viewData('registrations')->total());
        $this->assertSame(40.0, $response->viewData('totals')['collected']);
        $this->assertSame(80.0, $response->viewData('totals')['outstanding']);

        $this->assertSame([
            'individual' => 0,
            'group' => 1,
            'team' => 0,
            'paid' => 0,
            'unpaid' => 1,
        ], $this->tabCounts($response));
    }

    public function test_searching_a_persons_name_narrows_the_badges_as_well(): void
    {
        [$sibu] = $this->twoEvents();

        // The search reaches through to the people on the entry, and the figures have
        // to follow it there rather than only following the columns on the row.
        $response = $this->list(['tab' => 'group', 'event' => $sibu->id, 'q' => 'Pengurus REG-2026-0071']);
        $response->assertOk();

        $this->assertSame(1, $response->viewData('registrations')->total());
        $this->assertSame(80.0, $response->viewData('totals')['collected']);
        $this->assertSame(0.0, $response->viewData('totals')['outstanding']);
    }

    /* ---------------------------------------------------------------------
     | 4. What it costs, and what it writes
     * ------------------------------------------------------------------ */

    public function test_the_figures_cost_the_same_whatever_the_event_holds(): void
    {
        [$sibu] = $this->twoEvents();

        $user = $this->userWith(self::CAN_VIEW);

        // One request before either measurement, because the first one of a process
        // also reads the settings and the sidebar badges and then caches them. That
        // is not what is being measured here.
        $this->list(['tab' => 'group', 'event' => $sibu->id], $user)->assertOk();

        $small = $this->queriesFor(fn () => $this->list(['tab' => 'group', 'event' => $sibu->id], $user));

        // Ten more entries on the same event, each with a person and a receipt.
        for ($i = 1; $i <= 10; $i++) {
            $this->registration($sibu, sprintf('REG-2026-01%02d', $i), EventRegistration::PAYMENT_PARTIAL, 120, 40);
        }

        $large = $this->queriesFor(fn () => $this->list(['tab' => 'group', 'event' => $sibu->id], $user));

        // Aggregates over the filtered scope, so five tab counts and two money totals
        // cost the same on twelve rows as on two. A query per row would show here.
        $this->assertSame(
            count($small),
            count($large),
            'The screen is running more queries as rows are added.',
        );

        // And the absolute figure, so a per-row query added later is caught even on
        // its own: fifteen on a warm request, of which eight are the aggregates and
        // the eager loads.
        $this->assertLessThanOrEqual(20, count($large), 'The participants list has grown a query budget problem.');
    }

    public function test_the_screen_writes_nothing(): void
    {
        [$sibu] = $this->twoEvents();

        // Built before anything is measured, so creating it is not mistaken for the
        // screen writing.
        $user = $this->userWith(self::CAN_VIEW);

        $before = EventRegistration::query()
            ->orderBy('id')
            ->get(['id', 'amount', 'amount_paid', 'payment_status', 'status'])
            ->toArray();

        $queries = $this->queriesFor(fn () => $this->list(['tab' => 'group', 'event' => $sibu->id], $user)->assertOk());

        foreach ($queries as $sql) {
            $this->assertDoesNotMatchRegularExpression(
                '/^\s*(insert|update|delete|replace|truncate|alter|drop)\b/i',
                $sql,
                'Reading the participants list wrote to the database: ' . $sql,
            );
        }

        // And the stored totals are untouched, to the cent.
        $this->assertSame($before, EventRegistration::query()
            ->orderBy('id')
            ->get(['id', 'amount', 'amount_paid', 'payment_status', 'status'])
            ->toArray());
    }

    /**
     * Every statement one closure causes.
     *
     * @return array<int, string>
     */
    private function queriesFor(callable $callback): array
    {
        $queries = [];

        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $callback();

        // Nothing is detached: the listener is bound to this test's container, which
        // is thrown away with it.
        return $queries;
    }
}
