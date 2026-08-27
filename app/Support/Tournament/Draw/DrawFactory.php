<?php

namespace App\Support\Tournament\Draw;

use App\Models\Tournament;
use App\Models\TournamentEntrant;
use App\Models\TournamentMatch;
use App\Models\TournamentStage;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Picks the generator a stage needs, then schedules what it wrote.
 *
 * The caller never branches on format. It asks for a draw and gets fixtures, or a
 * refusal in words before anything is written.
 */
final class DrawFactory
{
    /**
     * Why this stage cannot be drawn, or null when it can.
     */
    public function refusal(TournamentStage $stage): ?string
    {
        if ($stage->hasDraw()) {
            return 'This stage already has a draw. Discard it before generating another.';
        }

        $entrants = $this->entrantsFor($stage);

        return $this->generatorFor($stage)->refusal($stage, $entrants);
    }

    /**
     * Draw the stage and schedule its fixtures.
     *
     * @return int how many matches were written
     */
    public function generate(TournamentStage $stage, ?int $userId = null): int
    {
        $refusal = $this->refusal($stage);

        if ($refusal !== null) {
            throw new RuntimeException($refusal);
        }

        $entrants = $this->entrantsFor($stage);

        $this->generatorFor($stage)->generate($stage, $entrants);

        $stage->update([
            'status' => TournamentStage::STATUS_ONGOING,
            'drawn_at' => now(),
            'drawn_by' => $userId,
        ]);

        $this->schedule($stage);

        $count = $stage->matches()->count();

        /*
         | The tournament moves to ongoing on its first draw. Done here rather than in
         | the controller because it is part of what generating a draw means, and
         | forgetting it would leave a tournament that is being played sitting in Setup.
         */
        if ($stage->tournament->draw_generated_at === null) {
            $stage->tournament->update([
                'status' => Tournament::STATUS_ONGOING,
                'draw_generated_at' => now(),
            ]);
        }

        return $count;
    }

    /**
     * Throw away an undrawn stage's fixtures so it can be drawn again.
     *
     * Refused once anything has been scored: a bracket with results in it is a record
     * of what was played, and rebuilding it would silently discard that.
     */
    public function discard(TournamentStage $stage): void
    {
        $scored = $stage->matches()
            ->whereIn('status', [TournamentMatch::STATUS_COMPLETED, TournamentMatch::STATUS_WALKOVER])
            ->whereNotNull('scored_at')
            ->count();

        if ($scored > 0) {
            throw new RuntimeException(sprintf(
                '%d %s already scored in this stage, so the draw cannot be discarded.',
                $scored,
                $scored === 1 ? 'match has been' : 'matches have been',
            ));
        }

        $stage->matches()->delete();
        $stage->groups()->delete();

        $stage->update([
            'status' => TournamentStage::STATUS_PENDING,
            'drawn_at' => null,
            'drawn_by' => null,
        ]);
    }

    /**
     * Give every fixture a time, spaced by the tournament's own buffer.
     *
     * Matches inside one group or lobby run one after another; different groups run in
     * parallel, because two lobbies play at the same time on separate rooms. Every time
     * can be edited afterwards, so this is a starting point rather than a decision.
     */
    private function schedule(TournamentStage $stage): void
    {
        $buffer = max(5, (int) $stage->tournament->setting('buffer_minutes', 15));
        $start = $stage->tournament->event?->starts_at?->copy()->setTime(9, 0) ?? now()->addDay()->setTime(9, 0);

        $matches = $stage->matches()->get()->groupBy(fn (TournamentMatch $match) => $match->tournament_group_id ?? 'bracket');

        foreach ($matches as $group) {
            $cursor = $start->copy();

            foreach ($group->sortBy([['round', 'asc'], ['position', 'asc']]) as $match) {
                $match->update(['scheduled_at' => $cursor->copy()]);
                $cursor->addMinutes($buffer);
            }
        }
    }

    /**
     * The entrants a stage draws from.
     *
     * The first stage takes everybody active. A later stage takes whoever the previous
     * stage advanced, which StageAdvancer records by writing them in.
     *
     * @return Collection<int, TournamentEntrant>
     */
    private function entrantsFor(TournamentStage $stage): Collection
    {
        return $stage->tournament
            ->entrants()
            ->where('status', TournamentEntrant::STATUS_ACTIVE)
            ->orderByRaw('seed IS NULL, seed')
            ->get();
    }

    private function generatorFor(TournamentStage $stage): DrawGenerator
    {
        return match ($stage->type) {
            TournamentStage::TYPE_GROUP => new GroupStageGenerator(),
            TournamentStage::TYPE_LOBBY => new LobbyGenerator(),
            TournamentStage::TYPE_HEAT => new HeatGenerator(),
            TournamentStage::TYPE_BRACKET => $stage->tournament->format === Tournament::FORMAT_DOUBLE_ELIM
                ? new DoubleEliminationGenerator()
                : new SingleEliminationGenerator(),
            default => throw new RuntimeException(sprintf('No generator for a %s stage.', $stage->type)),
        };
    }
}
