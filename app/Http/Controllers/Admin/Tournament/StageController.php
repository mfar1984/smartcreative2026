<?php

namespace App\Http\Controllers\Admin\Tournament;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use App\Models\TournamentStage;
use App\Services\AdminLogger;
use App\Support\Tournament\Draw\DrawFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Stages and the draw.
 *
 * Generating is behind its own permission because one press writes every fixture in
 * a bracket, which is a very different act from creating the tournament.
 */
class StageController extends Controller
{
    public function store(Request $request, Tournament $tournament)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(array_keys(TournamentStage::TYPES))],
            'advance_count' => ['nullable', 'integer', 'min:0', 'max:512'],
            'match_count' => ['nullable', 'integer', 'min:1', 'max:32'],
            'best_of' => ['array'],
            'best_of.*' => ['nullable', 'integer', 'min:1', 'max:9'],
        ], [
            'name.required' => 'Name the stage, so the Matches screen can say which one a fixture belongs to.',
        ]);

        $bestOf = collect($data['best_of'] ?? [])
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->mapWithKeys(fn ($value, $round) => [(string) (int) $round => (int) $value])
            ->all();

        $stage = $tournament->stages()->create([
            'name' => $data['name'],
            'type' => $data['type'],
            'sequence' => (int) $tournament->stages()->max('sequence') + 1,
            'advance_count' => $data['advance_count'] ?? 0,
            'match_count' => $data['match_count'] ?? 1,
            'best_of' => $bestOf === [] ? ['1' => 1] : $bestOf,
            'status' => TournamentStage::STATUS_PENDING,
        ]);

        AdminLogger::activity('tournaments.update', sprintf(
            'Added stage %s to tournament %s.',
            $stage->name,
            $tournament->name,
        ));

        return back()->with('status', sprintf('Stage %s added. Generate its draw when the seeds are ready.', $stage->name));
    }

    public function destroy(Tournament $tournament, TournamentStage $stage)
    {
        $this->assertOwnership($tournament, $stage);

        if ($stage->hasDraw()) {
            return back()->withErrors([
                'stage' => 'This stage has a draw. Discard the draw before removing the stage.',
            ]);
        }

        $name = $stage->name;
        $stage->delete();

        AdminLogger::activity('tournaments.update', sprintf(
            'Removed stage %s from tournament %s.',
            $name,
            $tournament->name,
        ));

        return back()->with('status', sprintf('Stage %s removed.', $name));
    }

    /**
     * Write every fixture for a stage.
     */
    public function generate(Request $request, Tournament $tournament, TournamentStage $stage, DrawFactory $factory)
    {
        $this->assertOwnership($tournament, $stage);

        try {
            $count = $factory->generate($stage, $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['stage' => $e->getMessage()]);
        }

        AdminLogger::activity('tournaments.matches.generate', sprintf(
            'Generated %d fixtures for stage %s of tournament %s.',
            $count,
            $stage->name,
            $tournament->name,
        ));

        AdminLogger::audit($tournament, 'tournament.draw_generated', null, [
            'stage' => $stage->name,
            'type' => $stage->type,
            'matches' => $count,
        ]);

        return back()->with('status', sprintf(
            '%d %s written for %s. Times are spaced by %d minutes and can be changed.',
            $count,
            Str::plural('fixture', $count),
            $stage->name,
            (int) $tournament->setting('buffer_minutes', 15),
        ));
    }

    /**
     * Throw a draw away so the stage can be drawn again.
     */
    public function discard(Tournament $tournament, TournamentStage $stage, DrawFactory $factory)
    {
        $this->assertOwnership($tournament, $stage);

        try {
            $factory->discard($stage);
        } catch (RuntimeException $e) {
            return back()->withErrors(['stage' => $e->getMessage()]);
        }

        AdminLogger::activity('tournaments.matches.generate', sprintf(
            'Discarded the draw for stage %s of tournament %s.',
            $stage->name,
            $tournament->name,
        ));

        AdminLogger::audit($tournament, 'tournament.draw_discarded', null, ['stage' => $stage->name]);

        return back()->with('status', sprintf('The draw for %s was discarded.', $stage->name));
    }

    private function assertOwnership(Tournament $tournament, TournamentStage $stage): void
    {
        if ($stage->tournament_id !== $tournament->id) {
            abort(404);
        }
    }
}
