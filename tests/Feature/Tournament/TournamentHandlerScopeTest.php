<?php

namespace Tests\Feature\Tournament;

use App\Models\Event;
use App\Models\PointRule;
use App\Models\Role;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentStage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Part 2 of the Tournament Handler feature: a handler is bound to the tournaments
 * assigned to it, and to nothing else.
 *
 * Part 1 let a handler in and trimmed its sidebar, but every tournament in the
 * system was still visible to it. What is proven here is the binding: the pivot
 * decides what appears in a listing, and typing another tournament's id into a URL
 * is refused on every screen that hangs off a tournament, not only on the list.
 *
 * The last two tests are the guard rail in the other direction: an ordinary
 * administrator still sees and edits everything, because an access control change
 * that quietly locks the real operators out is worse than the gap it closed.
 */
class TournamentHandlerScopeTest extends TestCase
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

    private function event(): Event
    {
        return Event::create([
            'slug' => sprintf('event-%s', uniqid()),
            'title' => 'Handler Event',
            'category' => 'E-Sport',
            'starts_at' => now()->addWeek()->toDateString(),
            'ends_at' => now()->addWeek()->toDateString(),
            'status' => 'published',
            'registration_mode' => 'manager',
            'seats_total' => 100,
        ]);
    }

    private function rule(): PointRule
    {
        return PointRule::create([
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
    }

    private function tournament(string $name, Event $event, PointRule $rule, string $status = Tournament::STATUS_ONGOING): Tournament
    {
        return Tournament::create([
            'event_id' => $event->id,
            'name' => $name,
            'format' => Tournament::FORMAT_SINGLE_ELIM,
            'point_rule_id' => $rule->id,
            'status' => $status,
            'seeding_method' => Tournament::SEEDING_MANUAL,
            'settings' => ['buffer_minutes' => 15, 'map_rotation' => ['Erangel']],
        ]);
    }

    /* ---------------------------------------------------------------------
     | Listing
     * ------------------------------------------------------------------ */

    public function test_a_handler_sees_only_the_tournament_assigned_to_it(): void
    {
        $this->deploy();

        $event = $this->event();
        $rule = $this->rule();
        $a = $this->tournament('Alpha Cup', $event, $rule, Tournament::STATUS_SETUP);
        $b = $this->tournament('Bravo Cup', $event, $rule, Tournament::STATUS_SETUP);

        $handler = $this->handler();
        $a->handlers()->attach($handler);

        $response = $this->actingAs($handler)->get(route('admin.tournaments.index'));

        $response->assertOk();
        $response->assertSee('Alpha Cup');
        $response->assertDontSee('Bravo Cup');
    }

    public function test_a_handler_with_no_assignment_sees_an_empty_list(): void
    {
        $this->deploy();

        $event = $this->event();
        $rule = $this->rule();
        $a = $this->tournament('Alpha Cup', $event, $rule, Tournament::STATUS_SETUP);
        $this->tournament('Bravo Cup', $event, $rule, Tournament::STATUS_SETUP);

        $handler = $this->handler();

        $response = $this->actingAs($handler)->get(route('admin.tournaments.index'));

        $response->assertOk();
        $response->assertDontSee('Alpha Cup');
        $response->assertDontSee('Bravo Cup');

        // And it cannot open either of them by name in the URL.
        $this->actingAs($handler)->get(route('admin.tournaments.show', $a))->assertForbidden();
    }

    /* ---------------------------------------------------------------------
     | Every surface hanging off a tournament
     * ------------------------------------------------------------------ */

    public function test_a_handler_cannot_reach_another_tournaments_screens_by_url(): void
    {
        $this->deploy();

        $event = $this->event();
        $rule = $this->rule();
        $a = $this->tournament('Alpha Cup', $event, $rule);
        $b = $this->tournament('Bravo Cup', $event, $rule);

        $handler = $this->handler();
        $a->handlers()->attach($handler);

        $this->actingAs($handler);

        // The tournament itself, and its own settings tab.
        $this->get(route('admin.tournaments.show', $b))->assertForbidden();
        $this->get(route('admin.tournaments.show', ['tournament' => $b, 'tab' => 'settings']))->assertForbidden();
        $this->get(route('admin.tournaments.show', ['tournament' => $b, 'tab' => 'stages']))->assertForbidden();

        // Its edit form, and a save posted straight at it.
        $this->get(route('admin.tournaments.edit', $b))->assertForbidden();
        $this->put(route('admin.tournaments.update', $b), ['name' => 'Renamed'])->assertForbidden();
        $this->assertSame('Bravo Cup', $b->fresh()->name);

        // Matches and Standings, which name the tournament in the query string.
        $this->get(route('admin.tournaments.matches', ['tournament' => $b->id]))->assertForbidden();
        $this->get(route('admin.tournaments.standings', ['tournament' => $b->id]))->assertForbidden();
        $this->get(route('admin.tournaments.standings.export', $b))->assertForbidden();

        // Generating a draw and the entrant actions on another tournament.
        $this->post(route('admin.tournaments.stages.store', $b), ['name' => 'Main', 'type' => 'bracket'])->assertForbidden();
        $this->post(route('admin.tournaments.entrants.import', $b))->assertForbidden();
        $this->post(route('admin.tournaments.seed', $b), ['method' => Tournament::SEEDING_RANDOM])->assertForbidden();

        // Point Rules and the shared tournament settings are not per tournament, so
        // there is no B to reach: the write half of the rules screen and the whole
        // settings screen are refused on the permission a handler does not hold.
        $this->get(route('admin.tournaments.rules.create'))->assertForbidden();
        $this->get(route('admin.tournaments.settings'))->assertForbidden();
        $this->put(route('admin.tournaments.settings.update', 'match'), ['buffer_minutes' => 30])->assertForbidden();

        // Hall of Fame: B cannot be published or withdrawn by A's handler.
        $this->post(route('admin.tournaments.hall-of-fame.publish', $b))->assertForbidden();
        $this->post(route('admin.tournaments.hall-of-fame.withdraw', $b))->assertForbidden();
    }

    public function test_a_handler_cannot_open_or_score_a_fixture_in_another_tournament(): void
    {
        $this->deploy();

        $event = $this->event();
        $rule = $this->rule();
        $a = $this->tournament('Alpha Cup', $event, $rule);
        $b = $this->tournament('Bravo Cup', $event, $rule);

        $handler = $this->handler();
        $a->handlers()->attach($handler);

        // A fixture of B's, reachable only by its own id: nothing in A's screens
        // links to it.
        $stage = TournamentStage::create([
            'tournament_id' => $b->id,
            'name' => 'Main',
            'type' => TournamentStage::TYPE_BRACKET,
            'sequence' => 1,
            'advance_count' => 0,
            'match_count' => 1,
            'best_of' => ['1' => 1],
            'status' => TournamentStage::STATUS_PENDING,
        ]);

        $fixture = TournamentMatch::create([
            'tournament_id' => $b->id,
            'tournament_stage_id' => $stage->id,
            'round' => 1,
            'position' => 1,
            'best_of' => 1,
            'status' => TournamentMatch::STATUS_SCHEDULED,
        ]);

        $this->actingAs($handler);

        $this->get(route('admin.tournaments.matches.score', $fixture))->assertForbidden();
        $this->put(route('admin.tournaments.matches.score.save', $fixture), [])->assertForbidden();
        $this->delete(route('admin.tournaments.matches.score.clear', $fixture))->assertForbidden();
        $this->put(route('admin.tournaments.matches.fixture.update', $fixture), ['map' => 'Erangel'])->assertForbidden();
        $this->post(route('admin.tournaments.matches.resolve', $fixture), [])->assertForbidden();

        $this->assertSame(TournamentMatch::STATUS_SCHEDULED, $fixture->fresh()->status);
        $this->assertNull($fixture->fresh()->map);
    }

    public function test_a_handler_still_reaches_its_own_tournaments_screens(): void
    {
        $this->deploy();

        $event = $this->event();
        $rule = $this->rule();
        $a = $this->tournament('Alpha Cup', $event, $rule);

        $handler = $this->handler();
        $a->handlers()->attach($handler);

        $this->actingAs($handler);

        $this->get(route('admin.tournaments.show', $a))->assertOk();
        $this->get(route('admin.tournaments.matches', ['tournament' => $a->id]))->assertOk();
        $this->get(route('admin.tournaments.standings', ['tournament' => $a->id]))->assertOk();
        $this->get(route('admin.tournaments.standings.export', $a))->assertOk();
    }

    public function test_the_matches_and_standings_pickers_only_list_assigned_tournaments(): void
    {
        $this->deploy();

        $event = $this->event();
        $rule = $this->rule();
        $a = $this->tournament('Alpha Cup', $event, $rule);
        $this->tournament('Bravo Cup', $event, $rule);

        $handler = $this->handler();
        $a->handlers()->attach($handler);

        $this->actingAs($handler);

        $matches = $this->get(route('admin.tournaments.matches'));
        $matches->assertOk();
        $matches->assertSee('Alpha Cup');
        $matches->assertDontSee('Bravo Cup');

        $standings = $this->get(route('admin.tournaments.standings'));
        $standings->assertOk();
        $standings->assertSee('Alpha Cup');
        $standings->assertDontSee('Bravo Cup');
    }

    public function test_the_hall_of_fame_only_lists_assigned_tournaments(): void
    {
        $this->deploy();

        $event = $this->event();
        $rule = $this->rule();
        $a = $this->tournament('Alpha Cup', $event, $rule, Tournament::STATUS_COMPLETED);
        $this->tournament('Bravo Cup', $event, $rule, Tournament::STATUS_COMPLETED);

        $handler = $this->handler();
        $a->handlers()->attach($handler);

        $response = $this->actingAs($handler)->get(route('admin.tournaments.hall-of-fame'));

        $response->assertOk();
        $response->assertSee('Alpha Cup');
        $response->assertDontSee('Bravo Cup');
    }

    /* ---------------------------------------------------------------------
     | Assignment
     * ------------------------------------------------------------------ */

    public function test_the_new_tournament_form_shows_the_handler_checkbox_list(): void
    {
        $this->deploy();

        $this->event();
        $this->rule();
        $handler = $this->handler('Desk Referee');

        $response = $this->actingAs($this->userWithRole('super-admin'))
            ->get(route('admin.tournaments.create'));

        $response->assertOk();
        $response->assertSee('Who Runs It');
        $response->assertSee('name="handlers[]"', false);
        $response->assertSee(sprintf('value="%d"', $handler->id), false);
        $response->assertSee('Desk Referee');
        $response->assertSee($handler->email);
    }

    public function test_the_form_says_so_when_no_handler_account_exists(): void
    {
        $this->deploy();

        $this->event();
        $this->rule();

        $response = $this->actingAs($this->userWithRole('super-admin'))
            ->get(route('admin.tournaments.create'));

        $response->assertOk();
        $response->assertSee('No handler accounts exist yet');
        $response->assertSee(route('admin.settings.users'), false);
    }

    public function test_assigning_and_unassigning_through_the_form_persists(): void
    {
        $this->deploy();

        $event = $this->event();
        $rule = $this->rule();
        $one = $this->handler('Handler One');
        $two = $this->handler('Handler Two');
        $admin = $this->userWithRole('super-admin');

        $this->actingAs($admin)
            ->post(route('admin.tournaments.store'), [
                'event_id' => $event->id,
                'name' => 'Alpha Cup',
                'format' => Tournament::FORMAT_SINGLE_ELIM,
                'point_rule_id' => $rule->id,
                'seeding_method' => Tournament::SEEDING_MANUAL,
                'handlers' => [$one->id, $two->id],
            ])
            ->assertSessionHasNoErrors();

        $tournament = Tournament::where('name', 'Alpha Cup')->firstOrFail();

        $this->assertEqualsCanonicalizing(
            [$one->id, $two->id],
            $tournament->handlers()->pluck('users.id')->all(),
        );

        // The edit form opens with both ticked.
        $html = $this->actingAs($admin)->get(route('admin.tournaments.edit', $tournament))->getContent();

        foreach ([$one, $two] as $ticked) {
            $this->assertMatchesRegularExpression(
                sprintf('/id="handler-%d"[^>]*checked/', $ticked->id),
                $html,
                sprintf('%s must open ticked on the edit form.', $ticked->name),
            );
        }

        // Saving with one of them dropped removes that assignment and keeps the other.
        $this->actingAs($admin)
            ->put(route('admin.tournaments.update', $tournament), [
                'event_id' => $event->id,
                'name' => 'Alpha Cup',
                'format' => Tournament::FORMAT_SINGLE_ELIM,
                'point_rule_id' => $rule->id,
                'seeding_method' => Tournament::SEEDING_MANUAL,
                'handlers' => [$two->id],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame([$two->id], $tournament->fresh()->handlers()->pluck('users.id')->all());

        // Saving with none sent clears it altogether.
        $this->actingAs($admin)
            ->put(route('admin.tournaments.update', $tournament), [
                'event_id' => $event->id,
                'name' => 'Alpha Cup',
                'format' => Tournament::FORMAT_SINGLE_ELIM,
                'point_rule_id' => $rule->id,
                'seeding_method' => Tournament::SEEDING_MANUAL,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame([], $tournament->fresh()->handlers()->pluck('users.id')->all());
    }

    public function test_a_handler_cannot_assign_handlers(): void
    {
        $this->deploy();

        $event = $this->event();
        $rule = $this->rule();
        $a = $this->tournament('Alpha Cup', $event, $rule, Tournament::STATUS_SETUP);

        $handler = $this->handler();
        $other = $this->handler('Handler Two');
        $a->handlers()->attach($handler);

        $this->actingAs($handler);

        // Its own tournament's edit form is refused on the permission, so there is
        // no form to send: the section is never rendered for a handler.
        $this->get(route('admin.tournaments.edit', $a))->assertForbidden();

        $this->put(route('admin.tournaments.update', $a), [
            'event_id' => $event->id,
            'name' => 'Alpha Cup',
            'format' => Tournament::FORMAT_SINGLE_ELIM,
            'point_rule_id' => $rule->id,
            'seeding_method' => Tournament::SEEDING_MANUAL,
            'handlers' => [$handler->id, $other->id],
        ])->assertForbidden();

        $this->post(route('admin.tournaments.store'), [
            'event_id' => $event->id,
            'name' => 'Smuggled Cup',
            'format' => Tournament::FORMAT_SINGLE_ELIM,
            'point_rule_id' => $rule->id,
            'seeding_method' => Tournament::SEEDING_MANUAL,
            'handlers' => [$handler->id],
        ])->assertForbidden();

        // The pivot is exactly as it was: one row, the assignment it started with.
        $this->assertSame([$handler->id], $a->fresh()->handlers()->pluck('users.id')->all());
        $this->assertDatabaseCount('tournament_handler', 1);
        $this->assertDatabaseMissing('tournaments', ['name' => 'Smuggled Cup']);
    }

    public function test_the_policy_refuses_a_handler_the_right_to_assign_even_with_the_update_permission(): void
    {
        $this->deploy();

        $event = $this->event();
        $rule = $this->rule();
        $a = $this->tournament('Alpha Cup', $event, $rule, Tournament::STATUS_SETUP);

        $handler = $this->handler();
        $a->handlers()->attach($handler);

        // Granted the edit permission by hand, which the seeded handler role does
        // not hold. Running a tournament still does not mean deciding who else does.
        $handler->role->permissions()->syncWithoutDetaching(
            \App\Models\Permission::whereIn('slug', ['tournaments.update', 'tournaments.create'])->pluck('id'),
        );

        $handler = $handler->fresh()->load('role');

        $this->assertTrue($handler->hasPermission('tournaments.update'));
        $this->assertFalse($handler->can('assignHandlers', $a));
        $this->assertFalse($handler->can('assignHandlers', new Tournament));
    }

    public function test_a_crafted_handler_payload_is_rejected_by_validation(): void
    {
        $this->deploy();

        $event = $this->event();
        $rule = $this->rule();
        $admin = $this->userWithRole('super-admin');

        // Not a handler account at all.
        $plainUser = $this->userWithRole('administrator');

        // A handler whose account has been switched off.
        $disabled = $this->userWithRole('handler', ['is_handler' => true, 'is_active' => false]);

        foreach ([$plainUser, $disabled] as $rejected) {
            $name = sprintf('Crafted %d', $rejected->id);

            $this->actingAs($admin)
                ->post(route('admin.tournaments.store'), [
                    'event_id' => $event->id,
                    'name' => $name,
                    'format' => Tournament::FORMAT_SINGLE_ELIM,
                    'point_rule_id' => $rule->id,
                    'seeding_method' => Tournament::SEEDING_MANUAL,
                    'handlers' => [$rejected->id],
                ])
                ->assertSessionHasErrors('handlers.0');

            $this->assertDatabaseMissing('tournaments', ['name' => $name]);
        }

        $this->assertDatabaseCount('tournament_handler', 0);
    }

    /* ---------------------------------------------------------------------
     | No regression for the people who run the place
     * ------------------------------------------------------------------ */

    public function test_an_administrator_still_sees_and_edits_every_tournament(): void
    {
        $this->deploy();

        $event = $this->event();
        $rule = $this->rule();
        $a = $this->tournament('Alpha Cup', $event, $rule, Tournament::STATUS_SETUP);
        $b = $this->tournament('Bravo Cup', $event, $rule, Tournament::STATUS_SETUP);

        // Assigned to a handler, which must make no difference to an administrator.
        $a->handlers()->attach($this->handler());

        $admin = $this->userWithRole('administrator');

        $this->actingAs($admin);

        $list = $this->get(route('admin.tournaments.index'));
        $list->assertOk();
        $list->assertSee('Alpha Cup');
        $list->assertSee('Bravo Cup');

        foreach ([$a, $b] as $tournament) {
            $this->get(route('admin.tournaments.show', $tournament))->assertOk();
            $this->get(route('admin.tournaments.edit', $tournament))->assertOk();
        }

        $this->put(route('admin.tournaments.update', $b), [
            'event_id' => $event->id,
            'name' => 'Bravo Cup Renamed',
            'format' => Tournament::FORMAT_SINGLE_ELIM,
            'point_rule_id' => $rule->id,
            'seeding_method' => Tournament::SEEDING_MANUAL,
        ])->assertSessionHasNoErrors();

        $this->assertSame('Bravo Cup Renamed', $b->fresh()->name);

        // And the assignment on A was not wiped by a save that said nothing about it
        // on B.
        $this->assertDatabaseCount('tournament_handler', 1);
    }

    public function test_a_super_admin_is_never_narrowed_by_an_assignment(): void
    {
        $this->deploy();

        $event = $this->event();
        $rule = $this->rule();
        $a = $this->tournament('Alpha Cup', $event, $rule, Tournament::STATUS_SETUP);
        $b = $this->tournament('Bravo Cup', $event, $rule, Tournament::STATUS_SETUP);

        $a->handlers()->attach($this->handler());

        $response = $this->actingAs($this->userWithRole('super-admin'))
            ->get(route('admin.tournaments.index'));

        $response->assertOk();
        $response->assertSee('Alpha Cup');
        $response->assertSee('Bravo Cup');

        $this->get(route('admin.tournaments.show', $b))->assertOk();
    }
}
