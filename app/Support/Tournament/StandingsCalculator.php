<?php

namespace App\Support\Tournament;

use App\Models\Tournament;
use App\Models\TournamentEntrant;
use App\Models\TournamentMatch;
use App\Models\TournamentMatchEntrant;
use App\Models\TournamentStage;
use App\Models\TournamentStanding;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Works standings out from the match results.
 *
 * Counted from source rows every time, never incremented. Incrementing a stored
 * counter loses writes when two referees save at the same moment, and the drift is
 * silent: the table simply disagrees with the matches and nothing says so. Counting
 * is heavier and cannot go wrong.
 *
 * Scoped to one tournament per call, so recalculating one cannot touch another that
 * is being played at the same time.
 */
final class StandingsCalculator
{
    public function __construct(private readonly ScoringEngine $engine)
    {
    }

    /**
     * Rebuild every standings row for one tournament.
     */
    public function recalculate(Tournament $tournament): void
    {
        $tournament->loadMissing('pointRule');
        $rule = $tournament->pointRule;

        if ($rule === null) {
            return;
        }

        DB::transaction(function () use ($tournament, $rule) {
            $tournament->standings()->delete();

            foreach ($tournament->stages as $stage) {
                $this->recalculateStage($tournament, $stage, $rule);
            }
        });
    }

    /**
     * One stage, group by group.
     *
     * A group stage produces one table per group; a bracket produces one table for
     * the whole stage, because there are no groups to divide it by.
     */
    private function recalculateStage(Tournament $tournament, TournamentStage $stage, $rule): void
    {
        $lines = TournamentMatchEntrant::query()
            ->whereHas('match', fn ($q) => $q
                ->where('tournament_stage_id', $stage->id)
                ->whereIn('status', [TournamentMatch::STATUS_COMPLETED, TournamentMatch::STATUS_WALKOVER]))
            ->with(['match:id,tournament_group_id,winner_entrant_id,status'])
            ->whereNotNull('tournament_entrant_id')
            ->get();

        // Every active entrant appears even before playing, so a table is not empty
        // for the first hour of a tournament.
        $entrants = $tournament->entrants()
            ->whereIn('status', [
                TournamentEntrant::STATUS_ACTIVE,
                TournamentEntrant::STATUS_ELIMINATED,
                TournamentEntrant::STATUS_DISQUALIFIED,
                TournamentEntrant::STATUS_WITHDRAWN,
            ])
            ->get()
            ->keyBy('id');

        $groupIds = $stage->groups->pluck('id');

        if ($groupIds->isEmpty()) {
            $groupIds = collect([null]);
        }

        foreach ($groupIds as $groupId) {
            $groupLines = $groupId === null
                ? $lines
                : $lines->where('match.tournament_group_id', $groupId);

            // Who belongs in this group's table: whoever has a line in it. A bracket
            // stage has no groups, so everybody who played is in the one table.
            $entrantIds = $groupId === null
                ? $entrants->keys()
                : $groupLines->pluck('tournament_entrant_id')->unique()->values();

            if ($entrantIds->isEmpty()) {
                continue;
            }

            $rows = $this->buildRows($groupLines, $entrantIds, $entrants, $rule);
            $rows = $this->rank($rows, $rule->tiebreak ?? []);

            $this->persist($tournament, $stage, $groupId, $rows, (int) $stage->advance_count);
        }
    }

    /**
     * Total each entrant's components across the matches they played.
     *
     * @param  Collection<int, TournamentMatchEntrant>  $lines
     * @param  Collection<int, int>  $entrantIds
     * @param  Collection<int, TournamentEntrant>  $entrants
     * @return array<int, array<string, mixed>>
     */
    private function buildRows(Collection $lines, Collection $entrantIds, Collection $entrants, $rule): array
    {
        $byEntrant = $lines->groupBy('tournament_entrant_id');
        $rows = [];

        foreach ($entrantIds as $entrantId) {
            $entrant = $entrants->get($entrantId);

            if ($entrant === null) {
                continue;
            }

            $own = $byEntrant->get($entrantId, collect());

            $totals = [];
            $counts = [];
            $played = 0;
            $won = 0;
            $lost = 0;
            $anyDisqualified = false;

            foreach ($own as $line) {
                $played++;

                foreach ($line->component_points ?? [] as $key => $value) {
                    $totals[$key] = ($totals[$key] ?? 0) + (float) $value;
                }

                foreach ($line->component_counts ?? [] as $key => $value) {
                    $counts[$key] = ($counts[$key] ?? 0) + (int) $value;
                }

                $anyDisqualified = $anyDisqualified || $line->is_disqualified;

                if ($line->match?->winner_entrant_id !== null) {
                    $line->match->winner_entrant_id === $entrantId ? $won++ : $lost++;
                }
            }

            /*
             | An entrant disqualified from the whole tournament stays on the table
             | marked DQ rather than disappearing. Removing them would make the table
             | disagree with the matches that were actually played.
             */
            $isDq = $entrant->status === TournamentEntrant::STATUS_DISQUALIFIED || $anyDisqualified;

            $rows[] = [
                'entrant_id' => $entrantId,
                'played' => $played,
                'won' => $won,
                'lost' => $lost,
                'component_totals' => $totals,
                'component_counts' => $counts,
                'total_points' => round(array_sum($totals), 3),
                'is_disqualified' => $isDq,
            ];
        }

        return $rows;
    }

    /**
     * Order the table and mark who is still genuinely level.
     *
     * Total points first, then each tie-break key in the order the profile lists
     * them. A key may be a count (kills, WWCD) or a points total; counts are checked
     * first because that is what a tie-break on kills actually means.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $tiebreak
     * @return array<int, array<string, mixed>>
     */
    private function rank(array $rows, array $tiebreak): array
    {
        usort($rows, function (array $a, array $b) use ($tiebreak) {
            // Disqualified entrants sink to the bottom whatever they scored.
            if ($a['is_disqualified'] !== $b['is_disqualified']) {
                return $a['is_disqualified'] ? 1 : -1;
            }

            if ($a['total_points'] !== $b['total_points']) {
                return $b['total_points'] <=> $a['total_points'];
            }

            foreach ($tiebreak as $key) {
                $left = $this->tiebreakValue($a, $key);
                $right = $this->tiebreakValue($b, $key);

                if ($left !== $right) {
                    return $right <=> $left;
                }
            }

            return 0;
        });

        $rank = 0;
        $seen = 0;
        $previous = null;

        foreach ($rows as $index => $row) {
            $seen++;
            $signature = $this->signature($row, $tiebreak);

            if ($signature !== $previous) {
                $rank = $seen;
                $previous = $signature;
            }

            $rows[$index]['rank'] = $rank;
            $rows[$index]['is_tied'] = false;
        }

        /*
         | Anybody sharing a rank is level after every tie-break the profile lists, so
         | the screen says the organiser has to settle it rather than presenting an
         | order the system invented.
         */
        $counts = array_count_values(array_column($rows, 'rank'));

        foreach ($rows as $index => $row) {
            $rows[$index]['is_tied'] = ($counts[$row['rank']] ?? 1) > 1;
        }

        return $rows;
    }

    /**
     * The number a tie-break key compares.
     *
     * A count if the profile keeps one for that key, otherwise the points total. Kills
     * and WWCD are counts; placement is a points total.
     *
     * @param  array<string, mixed>  $row
     */
    private function tiebreakValue(array $row, string $key): float
    {
        if (array_key_exists($key, $row['component_counts'])) {
            return (float) $row['component_counts'][$key];
        }

        return (float) ($row['component_totals'][$key] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $tiebreak
     */
    private function signature(array $row, array $tiebreak): string
    {
        $parts = [$row['is_disqualified'] ? 'dq' : 'ok', (string) $row['total_points']];

        foreach ($tiebreak as $key) {
            $parts[] = (string) $this->tiebreakValue($row, $key);
        }

        return implode('|', $parts);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function persist(Tournament $tournament, TournamentStage $stage, ?int $groupId, array $rows, int $advanceCount): void
    {
        foreach ($rows as $row) {
            TournamentStanding::create([
                'tournament_id' => $tournament->id,
                'tournament_stage_id' => $stage->id,
                'tournament_group_id' => $groupId,
                'tournament_entrant_id' => $row['entrant_id'],
                'played' => $row['played'],
                'won' => $row['won'],
                'lost' => $row['lost'],
                'component_totals' => $row['component_totals'],
                'component_counts' => $row['component_counts'],
                'total_points' => $row['total_points'],
                'rank' => $row['rank'],
                'is_disqualified' => $row['is_disqualified'],

                // Advancing needs a real position, so a tie on the cut line does not
                // silently promote both.
                'advances' => $advanceCount > 0
                    && ! $row['is_disqualified']
                    && $row['rank'] <= $advanceCount,

                'is_tied' => $row['is_tied'],
            ]);
        }
    }
}
