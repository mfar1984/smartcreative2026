<?php

namespace Database\Seeders;

use App\Models\PointRule;
use Illuminate\Database\Seeder;

/**
 * Four starting profiles, one per scoring family.
 *
 * These are examples, not fixtures the code depends on. Every value here can be
 * changed on screen, and nothing in the application reads a profile by name.
 *
 * Matched on name so re-seeding does not duplicate them, and so an operator who
 * has adjusted the PMPL values keeps their edit rather than having it overwritten.
 */
class PointRuleSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->profiles() as $profile) {
            $rule = PointRule::firstOrCreate(['name' => $profile['name']], $profile);

            /*
            | Profiles seeded before player scoring existed have nulls in the four
            | player columns. Fill those in, and only those, so an operator who has
            | already adjusted their placement table or kill value keeps that edit.
            | The team side is never written to here.
            */
            if ($rule->wasRecentlyCreated || $rule->player_components !== null) {
                continue;
            }

            $rule->forceFill([
                'track_players' => $profile['track_players'],
                'player_components' => $profile['player_components'],
                'player_inputs' => $profile['player_inputs'],
                'player_tiebreak' => $profile['player_tiebreak'],
            ])->save();
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function profiles(): array
    {
        return [
            /*
            | The scoring the organiser uses today, from their own spreadsheet.
            | Placement 10/6/5/4/3/2/1/1 then zero, one point a kill, a WWCD worth
            | nothing but counted first at a tie, and a penalty for turning up short.
            */
            [
                'name' => 'PMPL / PMGC Official',
                'kind' => PointRule::KIND_BATTLE_ROYALE,
                'squad_size' => 4,
                'components' => [
                    [
                        'key' => 'placement',
                        'label' => 'Placement',
                        'type' => PointRule::TYPE_TABLE,
                        'source' => 'placement',
                        'values' => [
                            '1' => 10, '2' => 6, '3' => 5, '4' => 4,
                            '5' => 3, '6' => 2, '7' => 1, '8' => 1,
                            '9' => 0, '10' => 0, '11' => 0, '12' => 0,
                            '13' => 0, '14' => 0, '15' => 0, '16' => 0,
                        ],
                    ],
                    [
                        'key' => 'kills',
                        'label' => 'Kills',
                        'type' => PointRule::TYPE_PER_UNIT,
                        'source' => 'kills',
                        'value' => 1,
                    ],
                    [
                        'key' => 'wwcd',
                        'label' => 'WWCD',
                        'type' => PointRule::TYPE_BONUS,
                        'when' => ['source' => 'placement', 'equals' => 1],
                        'value' => 0,
                    ],
                    [
                        'key' => 'squad_penalty',
                        'label' => 'Squad Penalty',
                        'type' => PointRule::TYPE_PENALTY_TABLE,
                        'source' => 'players_present',
                        'values' => ['4' => 0, '3' => -1, '2' => -2, '1' => -3],
                        'disqualify_at' => 0,
                    ],
                ],
                'inputs' => [
                    [
                        'key' => 'placement',
                        'label' => 'Placement',
                        'type' => 'integer',
                        'min' => 1,
                        'required' => true,
                        'unique_in_match' => true,
                    ],
                    [
                        'key' => 'kills',
                        'label' => 'Kills',
                        'type' => 'integer',
                        'min' => 0,
                        'required' => true,
                    ],
                    [
                        'key' => 'players_present',
                        'label' => 'Players',
                        'type' => 'integer',
                        'min' => 0,
                        'max_from' => 'squad_size',
                        'required' => true,
                    ],
                ],
                'tiebreak' => ['wwcd', 'placement', 'kills'],

                /*
                | The player ledger. Separate from the four components above and it
                | does not resemble them: a squad earns from placement and a WWCD,
                | while a person earns from what they did themselves. Placement has
                | no meaning for one player, so it is not here.
                |
                | Knocks and damage carry `value => 0`. A per_unit component returns
                | its count alongside its points, so a zero value records the figure
                | for the tie-break without turning it into a score. It is the same
                | mechanism that makes a WWCD worth nothing yet compared first.
                */
                'track_players' => PointRule::TRACK_OPTIONAL,
                'player_components' => [
                    [
                        'key' => 'kills',
                        'label' => 'Kills',
                        'type' => PointRule::TYPE_PER_UNIT,
                        'source' => 'kills',
                        'value' => 1,
                    ],
                    [
                        'key' => 'knocks',
                        'label' => 'Knocks',
                        'type' => PointRule::TYPE_PER_UNIT,
                        'source' => 'knocks',
                        'value' => 0,
                    ],
                    [
                        'key' => 'damage',
                        'label' => 'Damage',
                        'type' => PointRule::TYPE_PER_UNIT,
                        'source' => 'damage',
                        'value' => 0,
                    ],
                ],
                'player_inputs' => [
                    [
                        'key' => 'kills',
                        'label' => 'Kills',
                        'type' => 'integer',
                        'min' => 0,
                        'required' => true,
                    ],
                    [
                        'key' => 'knocks',
                        'label' => 'Knocks',
                        'type' => 'integer',
                        'min' => 0,
                        'required' => false,
                    ],
                    [
                        'key' => 'damage',
                        'label' => 'Damage',
                        'type' => 'integer',
                        'min' => 0,
                        'required' => false,
                    ],
                ],
                'player_tiebreak' => ['kills', 'damage', 'knocks'],
            ],

            /*
            | A bracket needs only the series result. Whoever took more games in the
            | series advances, so one component carries everything.
            */
            [
                'name' => 'Mobile Legends BO3',
                'kind' => PointRule::KIND_BRACKET,
                'squad_size' => 5,
                'components' => [
                    [
                        'key' => 'games_won',
                        'label' => 'Games Won',
                        'type' => PointRule::TYPE_PER_UNIT,
                        'source' => 'games_won',
                        'value' => 1,
                    ],
                ],
                'inputs' => [
                    [
                        'key' => 'games_won',
                        'label' => 'Games Won',
                        'type' => 'integer',
                        'min' => 0,
                        'max' => 3,
                        'required' => true,
                    ],
                ],
                'tiebreak' => ['games_won'],

                /*
                | MLBB tracks a player by what they did in the lane, which has nothing
                | to do with the series result above. Deaths are recorded at zero so
                | they show on the leaderboard without subtracting from anyone.
                */
                'track_players' => PointRule::TRACK_OPTIONAL,
                'player_components' => [
                    [
                        'key' => 'kills',
                        'label' => 'Kills',
                        'type' => PointRule::TYPE_PER_UNIT,
                        'source' => 'kills',
                        'value' => 1,
                    ],
                    [
                        'key' => 'assists',
                        'label' => 'Assists',
                        'type' => PointRule::TYPE_PER_UNIT,
                        'source' => 'assists',
                        'value' => 0.5,
                    ],
                    [
                        'key' => 'deaths',
                        'label' => 'Deaths',
                        'type' => PointRule::TYPE_PER_UNIT,
                        'source' => 'deaths',
                        'value' => 0,
                    ],
                ],
                'player_inputs' => [
                    ['key' => 'kills', 'label' => 'Kills', 'type' => 'integer', 'min' => 0, 'required' => true],
                    ['key' => 'deaths', 'label' => 'Deaths', 'type' => 'integer', 'min' => 0, 'required' => false],
                    ['key' => 'assists', 'label' => 'Assists', 'type' => 'integer', 'min' => 0, 'required' => false],
                ],
                'player_tiebreak' => ['kills', 'assists', 'deaths'],
            ],

            /*
            | Racket sports are brackets too, but the points inside a match are worth
            | recording: they decide a tie and they are what a dispute is argued over.
            | points_scored is worth nothing on its own, which is why its value is
            | zero while it still sits in the tie-break list.
            */
            [
                'name' => 'Badminton 3 Sets',
                'kind' => PointRule::KIND_BRACKET,
                'squad_size' => null,
                'components' => [
                    [
                        'key' => 'sets_won',
                        'label' => 'Sets Won',
                        'type' => PointRule::TYPE_PER_UNIT,
                        'source' => 'sets_won',
                        'value' => 1,
                    ],
                    [
                        'key' => 'points_scored',
                        'label' => 'Points Scored',
                        'type' => PointRule::TYPE_PER_UNIT,
                        'source' => 'points_scored',
                        'value' => 0,
                    ],
                ],
                'inputs' => [
                    [
                        'key' => 'sets_won',
                        'label' => 'Sets Won',
                        'type' => 'integer',
                        'min' => 0,
                        'max' => 3,
                        'required' => true,
                    ],
                    [
                        'key' => 'points_scored',
                        'label' => 'Total Points',
                        'type' => 'integer',
                        'min' => 0,
                        'required' => false,
                    ],
                ],
                'tiebreak' => ['sets_won', 'points_scored'],

                /*
                | Off. In singles the competitor already is the person, so a second
                | ledger would record the same figures twice. Turn it on for doubles
                | if the pair's individual points are wanted.
                */
                'track_players' => PointRule::TRACK_OFF,
                'player_components' => [],
                'player_inputs' => [],
                'player_tiebreak' => [],
            ],

            /*
            | A judged sport. The trimmed mean is what stops one generous or one harsh
            | judge deciding the result on their own.
            */
            [
                'name' => 'Aerobic 5 Judges',
                'kind' => PointRule::KIND_JUDGED,
                'squad_size' => null,
                'components' => [
                    [
                        'key' => 'judges',
                        'label' => 'Judges',
                        'type' => PointRule::TYPE_AGGREGATE,
                        'source' => 'judges',
                        'method' => 'trimmed_mean',
                    ],
                    [
                        'key' => 'deductions',
                        'label' => 'Deductions',
                        'type' => PointRule::TYPE_PER_UNIT,
                        'source' => 'faults',
                        'value' => -0.5,
                    ],
                ],
                'inputs' => [
                    [
                        'key' => 'judges',
                        'label' => 'Judge Marks',
                        'type' => 'marks',
                        'count' => 5,
                        'min' => 0,
                        'max' => 10,
                        'step' => 0.5,
                        'required' => true,
                    ],
                    [
                        'key' => 'faults',
                        'label' => 'Faults',
                        'type' => 'integer',
                        'min' => 0,
                        'required' => false,
                    ],
                ],
                'tiebreak' => ['judges', 'deductions'],

                // Off: a judged routine is scored as one performance, not per person.
                'track_players' => PointRule::TRACK_OFF,
                'player_components' => [],
                'player_inputs' => [],
                'player_tiebreak' => [],
            ],

            /*
            | A race. Placement is worked out from the times rather than typed, so the
            | only thing asked for is the time itself.
            */
            [
                'name' => 'Run & Ride Series',
                'kind' => PointRule::KIND_RACE,
                'squad_size' => null,
                'components' => [
                    [
                        'key' => 'placement',
                        'label' => 'Placement',
                        'type' => PointRule::TYPE_TABLE,
                        'source' => 'placement',
                        'values' => [
                            '1' => 25, '2' => 18, '3' => 15, '4' => 12, '5' => 10,
                            '6' => 8, '7' => 6, '8' => 4, '9' => 2, '10' => 1,
                        ],
                    ],
                ],
                'inputs' => [
                    [
                        'key' => 'finish_time',
                        'label' => 'Finish Time',
                        'type' => 'duration',
                        'placeholder' => 'hh:mm:ss',
                        'required' => false,
                    ],
                ],
                'tiebreak' => ['placement'],

                // Off: the runner is the competitor. Nothing to break out.
                'track_players' => PointRule::TRACK_OFF,
                'player_components' => [],
                'player_inputs' => [],
                'player_tiebreak' => [],
            ],
        ];
    }
}
