<?php

namespace App\Support\Tournament;

use App\Models\Tournament;
use App\Models\TournamentEntrant;
use Illuminate\Support\Str;

/**
 * Which step a tournament is on, and what is stopping the next one.
 *
 * This exists because the operator asked for the flow to be understandable step by
 * step. A screen that greys out a button without saying why leaves somebody
 * guessing; every step here carries a blocker in plain words, and the screen prints
 * it rather than hiding the control.
 */
final class TournamentProgress
{
    /**
     * @return array<int, array{
     *     key: string, label: string, detail: string,
     *     done: bool, current: bool, blocker: string|null
     * }>
     */
    public function steps(Tournament $tournament): array
    {
        $entrants = $tournament->entrants()->count();
        $active = $tournament->entrants()->where('status', TournamentEntrant::STATUS_ACTIVE)->count();
        $unseeded = $tournament->entrants()
            ->where('status', TournamentEntrant::STATUS_ACTIVE)
            ->whereNull('seed')
            ->count();

        $steps = [
            [
                'key' => 'created',
                'label' => 'Tournament created',
                'detail' => sprintf(
                    '%s · %s · %s',
                    $tournament->event?->title ?? 'no event',
                    $tournament->formatLabel(),
                    $tournament->pointRule?->name ?? 'no point rule',
                ),
                'done' => $tournament->exists,
                'blocker' => null,
            ],
            [
                'key' => 'entrants',
                'label' => 'Entrants added',
                'detail' => $entrants === 0
                    ? 'Nobody yet'
                    : sprintf('%d %s', $entrants, Str::plural('entrant', $entrants)),
                'done' => $active >= 2,
                'blocker' => match (true) {
                    $entrants === 0 => 'No entrants yet. Import the event\'s paid entries, or add one by hand.',
                    $active < 2 => sprintf('Only %d active entrant. A tournament needs at least two.', $active),
                    default => null,
                },
            ],
            [
                'key' => 'seeded',
                'label' => 'Seeds arranged',
                'detail' => $unseeded > 0
                    ? sprintf('%d without a seed', $unseeded)
                    : $tournament->seedingLabel(),
                'done' => $active >= 2 && $unseeded === 0,
                'blocker' => match (true) {
                    $active < 2 => 'Add the entrants first.',
                    $unseeded > 0 => sprintf(
                        '%d %s still without a seed. Arrange them by hand, or draw at random.',
                        $unseeded,
                        Str::plural('entrant', $unseeded),
                    ),
                    default => null,
                },
            ],
            [
                'key' => 'draw',
                'label' => 'Draw generated',
                'detail' => $tournament->hasDraw()
                    ? 'Generated ' . $tournament->draw_generated_at->format('d M Y, g:i a')
                    : 'Not generated',
                'done' => $tournament->hasDraw(),
                'blocker' => match (true) {
                    $active < 2 => 'Add the entrants first.',
                    $unseeded > 0 => 'Arrange the seeds first.',
                    ! $tournament->hasDraw() => 'Add a stage, then press Generate Draw.',
                    default => null,
                },
            ],
            [
                'key' => 'scored',
                'label' => 'Results entered',
                'detail' => $tournament->hasDraw() ? 'Waiting on the Matches screen' : 'No fixtures yet',
                'done' => false,
                'blocker' => $tournament->hasDraw()
                    ? null
                    : 'Generate the draw first.',
            ],
            [
                'key' => 'completed',
                'label' => 'Every stage finished',
                'detail' => $tournament->status === Tournament::STATUS_COMPLETED
                    || $tournament->isPublished()
                        ? 'Finished'
                        : 'Not yet',
                'done' => in_array($tournament->status, [
                    Tournament::STATUS_COMPLETED,
                    Tournament::STATUS_PUBLISHED,
                ], true),
                'blocker' => 'Every match has to be scored first.',
            ],
            [
                'key' => 'published',
                'label' => 'Podium published',
                'detail' => $tournament->isPublished()
                    ? 'Live on the website'
                    : 'Not on the website',
                'done' => $tournament->isPublished(),
                'blocker' => $tournament->isPublished()
                    ? null
                    : 'Publish from the Hall of Fame screen once the tournament is finished.',
            ],
        ];

        /*
         | The current step is the first one not done. Marked here rather than in the
         | view so the same answer is available to anything else that needs it.
         */
        $foundCurrent = false;

        foreach ($steps as $index => $step) {
            $isCurrent = ! $foundCurrent && ! $step['done'];
            $steps[$index]['current'] = $isCurrent;
            $foundCurrent = $foundCurrent || $isCurrent;

            // A step already done has nothing blocking it, whatever the match said.
            if ($step['done']) {
                $steps[$index]['blocker'] = null;
            }
        }

        return $steps;
    }

    /**
     * The one sentence to show at the top of the screen.
     */
    public function nextAction(Tournament $tournament): ?string
    {
        foreach ($this->steps($tournament) as $step) {
            if ($step['current']) {
                return $step['blocker'];
            }
        }

        return null;
    }

    /**
     * Whether the draw may be generated right now, and why not if it may not.
     *
     * @return array{allowed: bool, reason: string|null}
     */
    public function canGenerateDraw(Tournament $tournament): array
    {
        $active = $tournament->entrants()->where('status', TournamentEntrant::STATUS_ACTIVE)->count();

        $reason = match (true) {
            $tournament->hasDraw() => 'A draw already exists. Discard it before generating another.',
            $active < 2 => 'At least two active entrants are needed.',
            $tournament->entrants()
                ->where('status', TournamentEntrant::STATUS_ACTIVE)
                ->whereNull('seed')->count() > 0 => 'Every active entrant needs a seed first.',
            default => null,
        };

        return ['allowed' => $reason === null, 'reason' => $reason];
    }
}
