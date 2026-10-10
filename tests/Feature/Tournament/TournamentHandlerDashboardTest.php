<?php

namespace Tests\Feature\Tournament;

use App\Models\Event;
use App\Models\PointRule;
use App\Models\Role;
use App\Models\Tournament;
use App\Models\User;
use App\Support\DashboardMetrics;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The hole Part 2 left open: the dashboard.
 *
 * A handler is confined to its assigned tournaments on every screen hanging off a
 * tournament, because ScopeTournamentToHandler is declared on that route group. The
 * dashboard is not in that group, and its tournament card counted every row in the
 * table — so a handler read the live, published and total figures for tournaments
 * it has never been given. Counts rather than names, so not a disclosure of
 * anybody's data, but it contradicted the confinement the whole of Part 2 exists to
 * provide.
 *
 * The figures are cached, which is why this is more than a WHERE clause. The cache
 * key now carries what the payload COVERS, so the tests below assert both
 * directions of the mix up: a handler must not be served an administrator's totals,
 * and an administrator must not be served a handler's.
 */
class TournamentHandlerDashboardTest extends TestCase
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

    private function handler(string $name = 'Handler One'): User
    {
        return $this->userWithRole('handler', ['is_handler' => true, 'name' => $name]);
    }

    /**
     * Three tournaments: one ongoing, one published, one more ongoing.
     *
     * The statuses differ on purpose. Two handlers each holding one tournament have
     * the same total, so a total alone would not catch them sharing a cache entry;
     * the live and published counts do.
     *
     * @return array{0: Tournament, 1: Tournament, 2: Tournament}
     */
    private function threeTournaments(): array
    {
        $event = Event::create([
            'slug' => sprintf('event-%s', uniqid()),
            'title' => 'Handler Event',
            'category' => 'E-Sport',
            'starts_at' => now()->addWeek()->toDateString(),
            'ends_at' => now()->addWeek()->toDateString(),
            'status' => 'published',
            'registration_mode' => 'manager',
            'seats_total' => 100,
        ]);

        $rule = PointRule::create([
            'name' => sprintf('Bracket %s', uniqid()),
            'kind' => PointRule::KIND_BRACKET,
            'squad_size' => 5,
            'components' => [
                ['key' => 'series_won', 'label' => 'Games Won', 'type' => 'per_unit', 'source' => 'series_won', 'value' => 1],
            ],
            'inputs' => [
                ['key' => 'series_won', 'label' => 'Games Won', 'type' => 'integer', 'min' => 0, 'max' => 3, 'required' => true],
            ],
            'tiebreak' => ['series_won'],
            'is_active' => true,
        ]);

        $make = fn (string $name, string $status): Tournament => Tournament::create([
            'event_id' => $event->id,
            'name' => $name,
            'format' => Tournament::FORMAT_SINGLE_ELIM,
            'point_rule_id' => $rule->id,
            'status' => $status,
            'seeding_method' => Tournament::SEEDING_MANUAL,
            'settings' => ['buffer_minutes' => 15, 'map_rotation' => ['Erangel']],
        ]);

        return [
            $make('Alpha Cup', Tournament::STATUS_ONGOING),
            $make('Bravo Cup', Tournament::STATUS_PUBLISHED),
            $make('Charlie Cup', Tournament::STATUS_ONGOING),
        ];
    }

    /**
     * The tournament figures as one viewer reads them.
     *
     * @return array{live: int, total: int, published: int}
     */
    private function figuresFor(?User $viewer): array
    {
        return app(DashboardMetrics::class)->all(30, 14, $viewer)['tournaments'];
    }

    private function dashboardAs(User $user): TestResponse
    {
        return $this->actingAs($user)->get(route('admin.dashboard'));
    }

    /**
     * The headline card as it was actually built for the response.
     *
     * @return array<string, mixed>
     */
    private function tournamentCard(TestResponse $response): array
    {
        $response->assertOk();

        $card = collect($response->viewData('cards'))->firstWhere('label', 'Tournaments');

        $this->assertNotNull($card, 'The dashboard must carry the Tournaments card.');

        return $card;
    }

    /* ---------------------------------------------------------------------
     | The figures themselves
     * ------------------------------------------------------------------ */

    public function test_a_handler_counts_only_the_tournament_assigned_to_it(): void
    {
        $this->deploy();

        [$alpha] = $this->threeTournaments();

        $handler = $this->handler();
        $alpha->handlers()->attach($handler);

        $figures = $this->figuresFor($handler);

        $this->assertSame(1, $figures['total'], 'One of the three is assigned, so the total is one.');
        $this->assertSame(1, $figures['live'], 'Alpha Cup is ongoing and is theirs.');
        $this->assertSame(0, $figures['published'], 'Bravo Cup is published but is not theirs.');
    }

    public function test_a_handler_with_no_assignment_counts_nothing(): void
    {
        $this->deploy();

        $this->threeTournaments();

        $figures = $this->figuresFor($this->handler());

        // Zero rather than everything: the same direction the tournaments listing
        // takes for an unassigned handler.
        $this->assertSame(0, $figures['total']);
        $this->assertSame(0, $figures['live']);
        $this->assertSame(0, $figures['published']);
    }

    public function test_an_administrator_still_counts_every_tournament(): void
    {
        $this->deploy();

        [$alpha] = $this->threeTournaments();

        // Assigned to a handler, which must make no difference to an administrator.
        $alpha->handlers()->attach($this->handler());

        $figures = $this->figuresFor($this->userWithRole('administrator'));

        $this->assertSame(3, $figures['total']);
        $this->assertSame(2, $figures['live']);
        $this->assertSame(1, $figures['published']);
    }

    public function test_a_super_admin_is_never_narrowed_even_carrying_the_handler_flag(): void
    {
        $this->deploy();

        [$alpha] = $this->threeTournaments();

        $super = $this->userWithRole('super-admin', ['is_handler' => true]);
        $alpha->handlers()->attach($super);

        $figures = $this->figuresFor($super);

        $this->assertSame(3, $figures['total'], 'The super admin is the way back in and is never confined.');
        $this->assertSame(2, $figures['live']);
        $this->assertSame(1, $figures['published']);
    }

    /* ---------------------------------------------------------------------
     | The cache, which is where a scoped figure goes wrong
     * ------------------------------------------------------------------ */

    public function test_a_handler_and_an_administrator_each_read_their_own_figures_in_one_cache_lifetime(): void
    {
        $this->deploy();

        [$alpha] = $this->threeTournaments();

        $handler = $this->handler();
        $alpha->handlers()->attach($handler);
        $admin = $this->userWithRole('administrator');

        /*
         | Handler first. A key that ignored the scope would store the narrowed
         | payload and then hand it to the administrator, who would be told the place
         | runs one tournament.
         */
        $first = $this->tournamentCard($this->dashboardAs($handler));
        $this->assertSame('1', $first['value']);
        $this->assertStringContainsString('1 in total', $first['note']);

        $second = $this->tournamentCard($this->dashboardAs($admin));
        $this->assertSame('2', $second['value']);
        $this->assertStringContainsString('3 in total', $second['note']);

        /*
         | Now the other way round, on a cleared cache. This is the leak being
         | closed: the administrator warms the entry and the handler must still not
         | be served it. Asserted in both orders because a broken key passes a single
         | direction half the time.
         */
        DashboardMetrics::forget();

        $third = $this->tournamentCard($this->dashboardAs($admin));
        $this->assertSame('2', $third['value']);
        $this->assertStringContainsString('3 in total', $third['note']);

        $fourth = $this->tournamentCard($this->dashboardAs($handler));
        $this->assertSame('1', $fourth['value']);
        $this->assertStringContainsString('1 in total', $fourth['note']);

        // And on the page itself, not only in the view data.
        $this->dashboardAs($handler)->assertSee('1 in total', false);
        $this->dashboardAs($admin)->assertSee('3 in total', false);
    }

    public function test_two_handlers_with_different_assignments_do_not_read_each_others_figures(): void
    {
        $this->deploy();

        [$alpha, $bravo] = $this->threeTournaments();

        $one = $this->handler('Handler One');
        $two = $this->handler('Handler Two');

        $alpha->handlers()->attach($one);   // ongoing
        $bravo->handlers()->attach($two);   // published

        // Same total, different statuses, so a shared cache entry shows up in the
        // live count rather than hiding behind a matching one.
        $first = $this->tournamentCard($this->dashboardAs($one));
        $this->assertSame('1', $first['value'], 'Handler One runs the ongoing tournament.');
        $this->assertStringContainsString('0 podium', $first['changeNote']);

        $second = $this->tournamentCard($this->dashboardAs($two));
        $this->assertSame('0', $second['value'], 'Handler Two runs nothing that is being played.');
        $this->assertStringContainsString('1 podium', $second['changeNote']);

        // Back to the first, whose entry is still warm and must be unchanged.
        $again = $this->tournamentCard($this->dashboardAs($one));
        $this->assertSame('1', $again['value']);
        $this->assertStringContainsString('0 podium', $again['changeNote']);
    }

    /* ---------------------------------------------------------------------
     | What a handler's dashboard actually renders
     * ------------------------------------------------------------------ */

    public function test_a_handlers_dashboard_carries_no_money_figure(): void
    {
        $this->deploy();

        [$alpha] = $this->threeTournaments();

        $handler = $this->handler();
        $alpha->handlers()->attach($handler);

        $response = $this->dashboardAs($handler);
        $response->assertOk();

        $can = $response->viewData('can');
        $this->assertFalse($can['money'], 'A handler holds no payments.view.');
        $this->assertFalse($can['unpaid']);
        $this->assertFalse($can['events'], 'A handler holds no events.view.');
        $this->assertTrue($can['tournaments']);

        // One card, and it is the tournament one. No Collected, no Outstanding, no
        // Registrations, no People Entered.
        $this->assertSame(
            ['Tournaments'],
            collect($response->viewData('cards'))->pluck('label')->all(),
        );

        // And nothing money shaped reaches the view at all.
        $this->assertSame([], $response->viewData('revenueSeries'));
        $this->assertSame([], $response->viewData('paymentBreakdown'));
        $this->assertSame([], $response->viewData('topEvents'));
        $this->assertSame([], $response->viewData('registrationSeries'));
        $this->assertSame([], $response->viewData('upcomingEvents'));

        $response->assertDontSee('Money Collected');
        $response->assertDontSee('Best Earning Events');
        $response->assertDontSee('Where Entries Stand');
        $response->assertDontSee('Coming Up');
    }

    public function test_the_only_card_a_handler_sees_links_somewhere_it_may_go(): void
    {
        $this->deploy();

        [$alpha] = $this->threeTournaments();

        $handler = $this->handler();
        $alpha->handlers()->attach($handler);

        $card = $this->tournamentCard($this->dashboardAs($handler));

        $this->assertSame(route('admin.tournaments.index'), $card['href']);

        // Followed, rather than assumed: a card pointing at a refusal is a dead end.
        $this->actingAs($handler)->get($card['href'])->assertOk();
    }

    public function test_an_administrators_dashboard_is_unchanged(): void
    {
        $this->deploy();

        [$alpha] = $this->threeTournaments();

        $alpha->handlers()->attach($this->handler());

        $response = $this->dashboardAs($this->userWithRole('administrator'));
        $response->assertOk();

        // The full strip, in the order the controller builds it.
        $this->assertSame(
            ['Collected', 'Outstanding', 'Registrations', 'People Entered', 'Tournaments'],
            collect($response->viewData('cards'))->pluck('label')->all(),
        );

        $response->assertSee('Money Collected');
        $response->assertSee('Coming Up');
    }
}
