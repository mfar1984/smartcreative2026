<?php

namespace Tests\Feature\Tournament;

use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\EventRegistration;
use App\Models\Permission;
use App\Models\PointRule;
use App\Models\Role;
use App\Models\Tournament;
use App\Models\TournamentEntrant;
use App\Models\TournamentMatch;
use App\Models\TournamentMatchEntrant;
use App\Models\TournamentStage;
use App\Models\User;
use App\Support\ParticipantOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * How the player ledger reaches the server, and why there are two ways.
 *
 * THE CEILING THIS IS ABOUT. One field per figure is how a form posts, and it is how
 * this one posted until the screenshot reader began filling every panel in at once.
 * Seventeen squads of five with nine personal figures each, plus a played box per
 * person and the squad rows above them, is well past PHP's max_input_vars of 1000 —
 * and past it a request is TRUNCATED WITHOUT A WORD. The last squads never arrive and
 * the result saved looks complete, which is the worst possible way to lose a figure.
 *
 * So the browser now folds the whole ledger into one JSON field. The array shape is
 * still read, unchanged, because a browser with no JavaScript still posts it — and
 * because every test written before this one still has to pass untouched.
 *
 * WHICH WINS WHEN BOTH ARRIVE: the JSON, and the array is not consulted at all. The
 * JSON is written at the moment of sending from those same inputs, so it is the
 * complete ledger by construction; an array arriving beside it can only be a partial
 * copy, which is exactly what truncation produces. Merging a complete record with a
 * truncated one is how a figure gets counted twice.
 */
class ScoreLedgerWireTest extends TestCase
{
    use RefreshDatabase;

    /** The nine personal figures the owner's live profile declares. */
    private const FIGURES = [
        'elims', 'knock_outs', 'bonus', 'assists', 'rescue',
        'damage', 'health_restored', 'survival_time', 'travel_distance',
    ];

    /* ---------------------------------------------------------------------
     | A full lobby, in one input variable
     * ------------------------------------------------------------------ */

    public function test_a_full_size_lobby_round_trips_with_nothing_dropped(): void
    {
        [$match, $entrants, $people] = $this->lobby(17, 5);

        $ledger = $this->ledgerFor($entrants, $people);

        /*
         | What the field-by-field shape costs for this lobby: one variable per figure,
         | plus the played box and the hidden 0 in front of it, per person, plus the
         | squad rows and the form's own handful. PHP stops reading at 1000 by default,
         | and this is inside a single squad of that ceiling — eighteen squads is past
         | it, and so is a tenth personal figure, and the owner's lobby is seventeen.
         |
         | The compact field costs ONE, whatever the lobby is.
         */
        $asFields = $this->fieldCount(17, 5);

        $this->assertGreaterThan(900, $asFields);
        $this->assertGreaterThan(1000, $this->fieldCount(18, 5), 'One more squad is past the ceiling.');

        $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->put(route('admin.tournaments.matches.score.save', $match), [
                'lines' => $this->linesFor($entrants),

                // One variable for the whole ledger, which is the point.
                'players_json' => json_encode($ledger),
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        // Every squad, every player, every one of the nine figures.
        $this->assertSame(17 * 5, $this->rowsOf($match)->count());

        foreach ($entrants as $seed => $entrantId) {
            foreach ($people[$seed] as $index => $personId) {
                $row = $this->rowOf($match, $personId);

                $this->assertNotNull($row, "A player's row was dropped on the way in.");

                foreach (self::FIGURES as $offset => $key) {
                    $this->assertSame(
                        $this->figure($seed, $index, $offset),
                        $row->input($key),
                        sprintf('%s for squad %d player %d did not arrive.', $key, $seed, $index),
                    );
                }
            }
        }

        // And the figures still reached the team rows beside them.
        $this->assertSame(17, $match->fresh()->entrants()->whereNotNull('inputs')->count());
    }

    public function test_the_field_by_field_shape_still_saves_identically(): void
    {
        [$match, $entrants, $people] = $this->lobby(2, 3);

        $ledger = $this->ledgerFor($entrants, $people);

        $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->put(route('admin.tournaments.matches.score.save', $match), [
                'lines' => $this->linesFor($entrants),

                // The shape a browser with no JavaScript posts, which is the shape
                // this form posted for its whole life before the compact one existed.
                'players' => $ledger,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2 * 3, $this->rowsOf($match)->count());

        foreach ($entrants as $seed => $entrantId) {
            foreach ($people[$seed] as $index => $personId) {
                $row = $this->rowOf($match, $personId);

                foreach (self::FIGURES as $offset => $key) {
                    $this->assertSame($this->figure($seed, $index, $offset), $row->input($key));
                }

                $this->assertTrue($row->took_part);
            }
        }
    }

    public function test_the_compact_shape_wins_and_the_array_beside_it_is_not_counted(): void
    {
        [$match, $entrants, $people] = $this->lobby(1, 1);

        $personId = $people[1][0];

        $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->put(route('admin.tournaments.matches.score.save', $match), [
                'lines' => $this->linesFor($entrants),

                // The complete ledger, as the browser writes it at the moment of
                // sending.
                'players_json' => json_encode([
                    $entrants[1] => [$personId => ['took_part' => 1, 'elims' => 7]],
                ]),

                // And a disagreeing copy of the same row beside it, which is what a
                // truncated request looks like.
                'players' => [
                    $entrants[1] => [$personId => ['took_part' => 1, 'elims' => 99]],
                ],
            ])
            ->assertSessionHasNoErrors();

        $row = $this->rowOf($match, $personId);

        // Seven. Not 99, and emphatically not 106: one shape is chosen by presence
        // and the other is never read, so there is no path to a figure counted twice.
        $this->assertSame(7, $row->input('elims'));
    }

    public function test_a_compact_ledger_that_did_not_arrive_whole_refuses_the_save(): void
    {
        [$match, $entrants, $people] = $this->lobby(1, 1);

        $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->put(route('admin.tournaments.matches.score.save', $match), [
                'lines' => $this->linesFor($entrants),

                // Cut off part way, which is what a truncated POST body produces.
                'players_json' => '{"' . $entrants[1] . '":{"' . $people[1][0] . '":{"elims":',
            ])
            ->assertSessionHasErrors('score');

        // Nothing saved, rather than a result missing the figures somebody typed.
        $this->assertSame(0, $this->rowsOf($match)->count());
        $this->assertNull($match->fresh()->scored_at);
    }

    public function test_an_empty_compact_field_falls_back_to_the_field_by_field_shape(): void
    {
        [$match, $entrants, $people] = $this->lobby(1, 1);

        /*
         | The state the form is rendered in. The hidden field is on the page and empty
         | until the submit handler fills it, so a browser that never runs the script
         | posts an empty one beside the real fields — and those fields must still be
         | read exactly as they always were.
         */
        $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->put(route('admin.tournaments.matches.score.save', $match), [
                'lines' => $this->linesFor($entrants),
                'players_json' => '',
                'players' => [
                    $entrants[1] => [$people[1][0] => ['took_part' => 1, 'elims' => 4]],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(4, $this->rowOf($match, $people[1][0])->input('elims'));
    }

    public function test_a_player_the_ledger_says_nothing_about_is_left_exactly_as_they_are(): void
    {
        [$match, $entrants, $people] = $this->lobby(1, 2);

        $line = $match->entrants()->sole();

        // On file already, and deliberately marked as not having played.
        $row = $line->players()->create([
            'event_participant_id' => $people[1][1],
            'took_part' => false,
            'inputs' => ['elims' => 3],
        ]);

        $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->put(route('admin.tournaments.matches.score.save', $match), [
                'lines' => $this->linesFor($entrants),

                // Only the other player, which is what a panel that was opened for one
                // squad and left alone for the rest sends.
                'players_json' => json_encode([
                    $entrants[1] => [$people[1][0] => ['took_part' => 1, 'elims' => 5]],
                ]),
            ])
            ->assertSessionHasNoErrors();

        $row->refresh();

        $this->assertFalse($row->took_part, 'A save decided who played for a row it was not sent.');
        $this->assertSame(3, $row->input('elims'));
        $this->assertSame(5, $this->rowOf($match, $people[1][0])->input('elims'));
    }

    /* ---------------------------------------------------------------------
     | Fixtures
     * ------------------------------------------------------------------ */

    /**
     * How many input variables the field-by-field shape posts for one lobby.
     *
     * Three team figures a squad, nine personal figures a person with a played box
     * and the hidden 0 that pairs with it, and the form's own csrf, method and two
     * truncation guards.
     */
    private function fieldCount(int $squads, int $players): int
    {
        return ($squads * 3) + ($squads * $players * (count(self::FIGURES) + 2)) + 5;
    }

    /** A figure nobody could produce by accident, so a crossed wire is obvious. */
    private function figure(int $seed, int $index, int $offset): int
    {
        return ($seed * 100) + ($index * 10) + $offset;
    }

    /**
     * The whole ledger, in the shape both wire formats carry.
     *
     * @param  array<int, int>  $entrants
     * @param  array<int, array<int, int>>  $people
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function ledgerFor(array $entrants, array $people): array
    {
        $ledger = [];

        foreach ($entrants as $seed => $entrantId) {
            foreach ($people[$seed] as $index => $personId) {
                $row = ['took_part' => 1];

                foreach (self::FIGURES as $offset => $key) {
                    $row[$key] = (string) $this->figure($seed, $index, $offset);
                }

                $ledger[$entrantId][$personId] = $row;
            }
        }

        return $ledger;
    }

    /**
     * The squad rows, which have to be valid for the ledger to be reached at all.
     *
     * @param  array<int, int>  $entrants
     * @return array<int, array<string, mixed>>
     */
    private function linesFor(array $entrants): array
    {
        $lines = [];

        foreach ($entrants as $seed => $entrantId) {
            $lines[$entrantId] = [
                'placement' => $seed,
                'kills' => $seed,
                'players_present' => 5,
            ];
        }

        return $lines;
    }

    private function rowsOf(TournamentMatch $match)
    {
        return $match->fresh()->entrants()->with('players')->get()->flatMap->players;
    }

    private function rowOf(TournamentMatch $match, int $personId)
    {
        return $this->rowsOf($match)->firstWhere('event_participant_id', $personId);
    }

    /**
     * A battle royale lobby of $squads squads of $players, tracking personal figures.
     *
     * @return array{0: TournamentMatch, 1: array<int, int>, 2: array<int, array<int, int>>}
     */
    private function lobby(int $squads, int $players): array
    {
        $event = Event::create([
            'slug' => 'esport-' . uniqid(),
            'title' => 'PUBG Ledger Test',
            'category' => 'E-Sport',
            'starts_at' => now()->addWeek()->toDateString(),
            'ends_at' => now()->addWeek()->toDateString(),
            'status' => Event::STATUS_OPEN,
            'registration_mode' => Event::MODE_MANAGER,
            'seats_total' => 200,
            'min_players' => 1,
        ]);

        $rule = PointRule::create([
            'name' => 'Battle royale ' . uniqid(),
            'kind' => PointRule::KIND_BATTLE_ROYALE,
            'squad_size' => 5,
            'track_players' => PointRule::TRACK_OPTIONAL,
            'components' => [
                ['key' => 'placement', 'label' => 'Placement', 'type' => 'table', 'source' => 'placement', 'values' => ['1' => 10, '2' => 6]],
                ['key' => 'kills', 'label' => 'Kills', 'type' => 'per_unit', 'source' => 'kills', 'value' => 1],
            ],
            'inputs' => [
                ['key' => 'placement', 'label' => 'Placement', 'type' => 'integer', 'min' => 1, 'required' => true, 'unique_in_match' => true],
                ['key' => 'kills', 'label' => 'Kills', 'type' => 'integer', 'min' => 0, 'required' => true],
                ['key' => 'players_present', 'label' => 'Players', 'type' => 'integer', 'min' => 0, 'max_from' => 'squad_size', 'required' => true],
            ],
            'tiebreak' => ['kills'],

            'player_components' => array_map(
                fn (string $key) => ['key' => $key, 'label' => $key, 'type' => 'per_unit', 'source' => $key, 'value' => $key === 'elims' ? 1 : 0],
                self::FIGURES,
            ),
            'player_inputs' => array_map(
                fn (string $key) => ['key' => $key, 'label' => $key, 'type' => 'integer', 'min' => 0, 'required' => false],
                self::FIGURES,
            ),
            'player_tiebreak' => ['elims'],
        ]);

        $tournament = Tournament::create([
            'event_id' => $event->id,
            'name' => 'Ledger Tournament',
            'format' => Tournament::FORMAT_BATTLE_ROYALE,
            'point_rule_id' => $rule->id,
            'status' => Tournament::STATUS_ONGOING,
            'seeding_method' => Tournament::SEEDING_MANUAL,
        ]);

        $stage = TournamentStage::create([
            'tournament_id' => $tournament->id,
            'name' => 'Qualifiers',
            'type' => TournamentStage::TYPE_LOBBY,
            'sequence' => 1,
            'advance_count' => 0,
            'match_count' => 1,
        ]);

        $match = TournamentMatch::create([
            'tournament_id' => $tournament->id,
            'tournament_stage_id' => $stage->id,
            'status' => TournamentMatch::STATUS_SCHEDULED,
        ]);

        $entrants = [];
        $people = [];

        for ($seed = 1; $seed <= $squads; $seed++) {
            $registration = EventRegistration::create([
                'event_id' => $event->id,
                'reference' => EventRegistration::nextReference(),
                'mode' => Event::MODE_MANAGER,
                'team_name' => 'Squad ' . $seed,
                'status' => EventRegistration::STATUS_CONFIRMED,
                'payment_status' => EventRegistration::PAYMENT_PAID,
                'amount' => 0,
            ]);

            for ($index = 0; $index < $players; $index++) {
                $people[$seed][$index] = (int) EventParticipant::create([
                    'event_registration_id' => $registration->id,
                    'role' => ParticipantOptions::ROLE_PLAYER,
                    'full_name' => sprintf('Squad %d Player %d', $seed, $index + 1),
                    'ign_name' => sprintf('S%dP%d', $seed, $index + 1),
                    'ign_player_id' => sprintf('90%02d%02d', $seed, $index),
                    'ic_number' => sprintf('9501%02d%04d', $seed, $index),
                    'phone' => '0123456789',
                ])->id;
            }

            $entrant = TournamentEntrant::create([
                'tournament_id' => $tournament->id,
                'event_registration_id' => $registration->id,
                'seed' => $seed,
                'status' => TournamentEntrant::STATUS_ACTIVE,
            ]);

            TournamentMatchEntrant::create([
                'tournament_match_id' => $match->id,
                'tournament_entrant_id' => $entrant->id,
                'slot' => $seed,
            ]);

            $entrants[$seed] = (int) $entrant->id;
        }

        return [$match->fresh(), $entrants, $people];
    }

    /**
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

        foreach (array_unique(['admin.access', ...$permissions]) as $index => $slug) {
            $ids[] = Permission::firstOrCreate(
                ['slug' => $slug],
                ['name' => $slug, 'group' => 'Tournaments', 'module' => 'Tournaments', 'action' => 'view', 'sort_order' => $index],
            )->id;
        }

        $role->permissions()->sync($ids);

        return User::create([
            'name' => 'Staff',
            'username' => 'staff-' . uniqid(),
            'email' => uniqid() . '@example.com',
            'password' => 'secret-password',
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }
}
