<?php

namespace App\Support\Tournament\Draw;

use App\Models\TournamentEntrant;
use App\Models\TournamentGroup;
use App\Models\TournamentMatch;
use App\Models\TournamentStage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Battle royale. Everybody in a lobby plays at once, several times, and the points
 * add up.
 *
 * A lobby holds at most sixteen squads because that is what the game holds. More
 * than sixteen entrants means more than one lobby, and the top few from each carry
 * on, which is the qualifier shape the organiser described for 32 and 64 teams.
 *
 * Maps come from the rotation in the tournament's own settings, cycled across the
 * matches, so the same map is not played five times.
 */
final class LobbyGenerator implements DrawGenerator
{
    private const LOBBY_CAPACITY = 16;

    public function refusal(TournamentStage $stage, Collection $entrants): ?string
    {
        if ($entrants->isEmpty()) {
            return 'A lobby needs at least one entrant.';
        }

        if (($stage->match_count ?? 0) < 1) {
            return 'Set how many matches each lobby plays. Three to five is usual.';
        }

        return null;
    }

    public function generate(TournamentStage $stage, Collection $entrants): void
    {
        $lobbyCount = (int) ceil($entrants->count() / self::LOBBY_CAPACITY);
        $rotation = $this->rotation($stage);

        DB::transaction(function () use ($stage, $entrants, $lobbyCount, $rotation) {
            $buckets = $this->snake($entrants->values(), $lobbyCount);

            foreach ($buckets as $index => $members) {
                $lobby = TournamentGroup::create([
                    'tournament_stage_id' => $stage->id,
                    'name' => $lobbyCount === 1 ? 'Lobby' : 'Lobby ' . ($index + 1),
                    'sequence' => $index + 1,
                ]);

                for ($number = 1; $number <= $stage->match_count; $number++) {
                    $match = TournamentMatch::create([
                        'tournament_id' => $stage->tournament_id,
                        'tournament_stage_id' => $stage->id,
                        'tournament_group_id' => $lobby->id,

                        // No round, because there is no tree. The position is the match
                        // number within the lobby.
                        'round' => null,
                        'position' => $number,
                        'best_of' => 1,
                        'map' => $rotation === [] ? null : $rotation[($number - 1) % count($rotation)],
                        'status' => TournamentMatch::STATUS_SCHEDULED,
                    ]);

                    // Every squad in the lobby is on every match, so the score form can
                    // list all sixteen lines the moment it opens.
                    foreach ($members as $member) {
                        $match->entrants()->create([
                            'tournament_entrant_id' => $member->id,
                            'slot' => null,
                        ]);
                    }
                }
            }
        });
    }

    /**
     * Split into lobbies in a snake so the lobbies are of comparable strength.
     *
     * @param  Collection<int, TournamentEntrant>  $entrants
     * @return array<int, Collection<int, TournamentEntrant>>
     */
    private function snake(Collection $entrants, int $lobbyCount): array
    {
        /*
         | A loop rather than array_fill: that would put one Collection instance in
         | every slot, so pushing into one would push into all and every lobby would
         | hold all thirty-two squads.
         */
        $buckets = [];

        for ($i = 0; $i < $lobbyCount; $i++) {
            $buckets[$i] = collect();
        }

        $index = 0;
        $forward = true;

        foreach ($entrants as $entrant) {
            $buckets[$index]->push($entrant);

            if ($forward) {
                $index++;

                if ($index === $lobbyCount) {
                    $index = $lobbyCount - 1;
                    $forward = false;
                }

                continue;
            }

            $index--;

            if ($index < 0) {
                $index = 0;
                $forward = true;
            }
        }

        return $buckets;
    }

    /**
     * @return array<int, string>
     */
    private function rotation(TournamentStage $stage): array
    {
        $rotation = $stage->tournament?->setting('map_rotation', []) ?? [];

        return array_values(array_filter(is_array($rotation) ? $rotation : []));
    }
}
