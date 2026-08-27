<?php

namespace App\Support\Tournament;

use App\Models\Tournament;
use App\Models\TournamentEntrant;
use App\Models\TournamentMatch;
use App\Models\TournamentMatchPlayer;
use App\Models\TournamentPlayerStanding;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Personal leaderboards, worked out from the player lines.
 *
 * The second ledger. This class reads `tournament_match_players` and writes
 * `tournament_player_standings`, and touches neither `tournament_standings` nor
 * `tournament_champions`. Nothing it computes can change who wins the tournament.
 *
 * That is not a detail. It is what makes player scoring optional: an operator who
 * skips the player rows at two in the morning still gets a correct podium.
 *
 * Counted from source rows every time, never incremented, for the same reason as
 * the team calculator.
 */
final class PlayerStandingsCalculator
{
    /**
     * Rebuild every player standings row for one tournament.
     *
     * One row per player per stage, plus one row per player with a null stage
     * holding their whole-tournament total.
     */
    public function recalculate(Tournament $tournament): void
    {
        $tournament->loadMissing('pointRule');
        $rule = $tournament->pointRule;

        if ($rule === null || ! $rule->tracksPlayers()) {
            // Tracking was switched off. Clear what is there rather than leaving a
            // leaderboard nobody can reach and nobody is updating.
            $tournament->playerStandings()->delete();

            return;
        }

        $tiebreak = $rule->player_tiebreak ?? [];

        DB::transaction(function () use ($tournament, $tiebreak) {
            $tournament->playerStandings()->delete();

            $lines = $this->linesFor($tournament);

            if ($lines->isEmpty()) {
                return;
            }

            $dqEntrantIds = $tournament->entrants()
                ->where('status', TournamentEntrant::STATUS_DISQUALIFIED)
                ->pluck('id')
                ->all();

            // One table per stage.
            foreach ($lines->groupBy(fn (TournamentMatchPlayer $line) => $line->matchEntrant->match->tournament_stage_id) as $stageId => $stageLines) {
                $rows = $this->rank($this->buildRows($stageLines, $dqEntrantIds), $tiebreak);
                $this->persist($tournament, (int) $stageId, $rows);
            }

            // And one across the whole tournament, which is what an MVP is decided on.
            $overall = $this->rank($this->buildRows($lines, $dqEntrantIds), $tiebreak);
            $this->persist($tournament, null, $overall);
        });
    }

    /**
     * Player lines from matches that actually finished.
     *
     * A generated fixture already holds empty rows, so only completed matches count,
     * and only players marked as having taken part.
     *
     * @return Collection<int, TournamentMatchPlayer>
     */
    private function linesFor(Tournament $tournament): Collection
    {
        return TournamentMatchPlayer::query()
            ->where('took_part', true)
            ->whereNotNull('inputs')
            ->whereHas('matchEntrant.match', fn ($q) => $q
                ->where('tournament_id', $tournament->id)
                ->whereIn('status', [TournamentMatch::STATUS_COMPLETED, TournamentMatch::STATUS_WALKOVER]))
            ->with([
                'matchEntrant:id,tournament_match_id,tournament_entrant_id',
                'matchEntrant.match:id,tournament_stage_id',
                'participant:id,event_registration_id,full_name,ign_player_id',
            ])
            ->get();
    }

    /**
     * Total each player's components across the matches they played.
     *
     * @param  Collection<int, TournamentMatchPlayer>  $lines
     * @param  array<int, int>  $dqEntrantIds
     * @return array<int, array<string, mixed>>
     */
    private function buildRows(Collection $lines, array $dqEntrantIds): array
    {
        $rows = [];

        foreach ($lines->groupBy('event_participant_id') as $participantId => $own) {
            $totals = [];
            $counts = [];
            $played = 0;

            foreach ($own as $line) {
                $played++;

                foreach ($line->component_points ?? [] as $key => $value) {
                    $totals[$key] = ($totals[$key] ?? 0) + (float) $value;
                }

                foreach ($line->component_counts ?? [] as $key => $value) {
                    $counts[$key] = ($counts[$key] ?? 0) + (int) $value;
                }
            }

            /*
             | The entrant a player last appeared for. A player cannot legitimately
             | appear for two entrants in one tournament, and the controller refuses
             | it, but reading the last one keeps this class from throwing if history
             | already holds such a row.
             */
            $entrantId = (int) $own->last()->matchEntrant->tournament_entrant_id;
            $participant = $own->last()->participant;

            $rows[] = [
                'participant_id' => (int) $participantId,
                'entrant_id' => $entrantId,
                'display_name' => $participant?->full_name ?? 'Unknown player',
                'ign' => $participant?->ign_player_id,
                'matches_played' => $played,
                'component_totals' => $totals,
                'component_counts' => $counts,
                'total_points' => round(array_sum($totals), 3),

                /*
                 | The squad was thrown out; the person's own figures stay. Their
                 | personal record is a record of what they did, and it is marked so a
                 | reader knows the context rather than being quietly deleted.
                 */
                'entrant_is_disqualified' => in_array($entrantId, $dqEntrantIds, true),
            ];
        }

        return $rows;
    }

    /**
     * Order the leaderboard by points, then by each `player_tiebreak` key in turn.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $tiebreak
     * @return array<int, array<string, mixed>>
     */
    private function rank(array $rows, array $tiebreak): array
    {
        usort($rows, function (array $a, array $b) use ($tiebreak) {
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

            return strcmp($a['display_name'], $b['display_name']);
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
        }

        return $rows;
    }

    /**
     * A count if the profile keeps one for that key, otherwise the points total.
     *
     * Damage is a count worth no points, which is how it breaks a tie without being
     * a score. Same treatment the team side gives a WWCD.
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
        $parts = [(string) $row['total_points']];

        foreach ($tiebreak as $key) {
            $parts[] = (string) $this->tiebreakValue($row, $key);
        }

        return implode('|', $parts);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function persist(Tournament $tournament, ?int $stageId, array $rows): void
    {
        foreach ($rows as $row) {
            TournamentPlayerStanding::create([
                'tournament_id' => $tournament->id,
                'tournament_stage_id' => $stageId,
                'tournament_entrant_id' => $row['entrant_id'],
                'event_participant_id' => $row['participant_id'],
                'display_name' => $row['display_name'],
                'ign' => $row['ign'],
                'matches_played' => $row['matches_played'],
                'component_totals' => $row['component_totals'],
                'component_counts' => $row['component_counts'],
                'total_points' => $row['total_points'],
                'rank' => $row['rank'],
                'entrant_is_disqualified' => $row['entrant_is_disqualified'],
            ]);
        }
    }
}
