<?php

namespace App\Support\Tournament\Draw;

use App\Models\TournamentStage;
use Illuminate\Support\Collection;

/**
 * Turns a list of entrants into fixtures.
 *
 * One implementation per shape of competition. They all take the same two things
 * and write matches, so the caller does not branch on format.
 */
interface DrawGenerator
{
    /**
     * Write every fixture for this stage.
     *
     * @param  Collection<int, \App\Models\TournamentEntrant>  $entrants  seeded, in seed order
     */
    public function generate(TournamentStage $stage, Collection $entrants): void;

    /**
     * Why this generator cannot draw the given entrants, or null when it can.
     *
     * Checked before anything is written, so a refusal leaves no half-built bracket.
     *
     * @param  Collection<int, \App\Models\TournamentEntrant>  $entrants
     */
    public function refusal(TournamentStage $stage, Collection $entrants): ?string;
}
