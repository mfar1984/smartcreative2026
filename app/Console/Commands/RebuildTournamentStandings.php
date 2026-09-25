<?php

namespace App\Console\Commands;

use App\Models\Tournament;
use App\Support\Tournament\PlayerStandingsCalculator;
use App\Support\Tournament\StandingsCalculator;
use Illuminate\Console\Command;

/**
 * Work the standings out again from the matches.
 *
 * Standings are a stored table, rebuilt from the match rows every time a score is
 * saved. That is the only thing that triggers it, which leaves one gap: a tournament
 * whose draw has been made but whose first result has not been entered has no
 * standings at all, so the public table is empty when it should be listing every team
 * on nil points. The same gap opens after any change to how standings are derived,
 * because the existing rows were written by the old rules.
 *
 * This closes it without anybody having to enter and then clear a score.
 *
 * It reads the match rows and rewrites the two derived tables. It does not read, write
 * or touch a score, a fixture, an entrant's status, or anything else. Running it
 * twice produces the same answer as running it once, because the figures are counted
 * from source rather than incremented.
 */
class RebuildTournamentStandings extends Command
{
    protected $signature = 'tournament:standings
                            {tournament? : The id of one tournament, or leave it out and pass --all}
                            {--all : Every tournament that has left setup}';

    protected $description = 'Work tournament standings out again from the match results, without touching any score';

    public function handle(
        StandingsCalculator $standings,
        PlayerStandingsCalculator $players,
    ): int {
        $tournaments = $this->resolve();

        if ($tournaments->isEmpty()) {
            $this->components->error('No tournament matched. Pass an id, or --all.');

            return self::FAILURE;
        }

        foreach ($tournaments as $tournament) {
            if ($tournament->pointRule === null) {
                $this->components->warn(sprintf(
                    '%s (#%d) has no point rule, so there is nothing to work out.',
                    $tournament->name,
                    $tournament->id,
                ));

                continue;
            }

            $standings->recalculate($tournament);
            $players->recalculate($tournament->fresh());

            $this->components->info(sprintf(
                '%s (#%d): %d team %s, %d player %s.',
                $tournament->name,
                $tournament->id,
                $teams = $tournament->standings()->count(),
                str('row')->plural($teams),
                $people = $tournament->playerStandings()->count(),
                str('row')->plural($people),
            ));
        }

        $this->newLine();
        $this->components->info('Scores were not read and not changed.');

        return self::SUCCESS;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Tournament>
     */
    private function resolve()
    {
        $id = $this->argument('tournament');

        if ($id !== null) {
            return Tournament::query()->with('pointRule')->whereKey($id)->get();
        }

        if (! $this->option('all')) {
            return collect();
        }

        /*
         | Everything past setup. A tournament still being set up has no draw and no
         | results, so rebuilding it would write nothing and say it had done something.
         */
        return Tournament::query()
            ->with('pointRule')
            ->where('status', '!=', Tournament::STATUS_SETUP)
            ->orderBy('id')
            ->get();
    }
}
