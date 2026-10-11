<?php

namespace App\Support\Tournament;

use App\Services\Vision\PlayerSheetBlock;
use App\Services\Vision\ScoreSheetException;

/**
 * Ties the blocks read off the per-player result screen to the squads and the people
 * in one fixture.
 *
 * Knows nothing about Eloquent or about who read the image, the same way the other
 * two matchers do not, which is what lets the real-world handles be exercised
 * without a database or a network call.
 *
 * HOW A SQUAD IS IDENTIFIED, and it is the whole of why this class exists. That
 * screen prints no team names at all, and the clan prefixes on the handles are not
 * dependable — one squad prints D2W and another D2WGx. So the block is identified
 * by its PLAYERS: each handle is matched to a participant on an entry, every
 * participant carries the entry they are named on, and the entry is in this fixture
 * as one squad. A block whose players point at two different squads, or at none at
 * all, is handed back unmatched with what was printed in it. It is never assigned by
 * guesswork, because a squad's placement is the figure every other squad's standing
 * moves against.
 *
 * WHAT IT WILL NOT TOUCH. Whether somebody played. A squad may have five people on
 * its entry and four on the screen, and the fourth-and-fifth question is not one an
 * image answers: a player who turned up and got no kills is not the player who did
 * not turn up. Registered players no block mentioned are listed for the operator and
 * their took_part is left exactly as it is.
 */
class PlayerSheetMatcher
{
    /**
     * @param  array<int, array{id: int, name: string, current: array<string, mixed>}>  $entrants
     *         the squads in this fixture, with what is already on their line
     * @param  array<int, array{id: int, entrant_id: int, name: string, ign: string, current: int|float|null}>  $players
     *         everybody on those squads' entries, with what is already recorded for them
     */
    public function __construct(
        private readonly array $entrants,
        private readonly array $players,
    ) {
    }

    /**
     * @param  array<int, PlayerSheetBlock>  $blocks  every block read, from every image, in order
     * @return array{
     *     teams: array<int, array<string, mixed>>,
     *     unmatched: array<int, array<string, mixed>>,
     *     uncovered: array<int, array{entrant_id: int, name: string}>,
     *     clashes: array<int, array<string, mixed>>,
     * }
     *
     * @throws ScoreSheetException
     */
    public function match(array $blocks): array
    {
        $names = new PlayerNicknameMatcher($this->players);

        // Keyed by squad, because the panel for the top ranks is printed on every one
        // of these screenshots and the merge happens here rather than in the browser.
        $matched = [];
        $unmatched = [];
        $clashes = [];
        $claimed = [];

        foreach ($blocks as $block) {
            [$rows, $entrantId, $reason] = $this->resolve($block, $names);

            if ($entrantId === null) {
                $unmatched[] = [
                    'read_as' => $block->raw(),
                    'placement' => $block->rank,
                    'kills' => $block->kills(),
                    'reason' => $reason,
                ];

                continue;
            }

            $kills = $block->kills();

            /*
             | The same squad read twice, which is exactly what the repeated top-ranks
             | panel produces. A second reading that agrees is a duplicate and is
             | dropped without a word; one that disagrees is reported and the row is
             | flagged, because which of two readings is right is not a decision to
             | take on somebody's behalf.
             */
            if (isset($matched[$entrantId])) {
                $kept = $matched[$entrantId];

                if ($kept['placement'] === $block->rank
                    && $kept['kills'] === $kills
                    && $this->figures($kept['players']) === $this->figures($rows)) {
                    continue;
                }

                $clashes[] = [
                    'name' => $kept['name'],
                    'kept' => ['placement' => $kept['placement'], 'kills' => $kept['kills']],
                    'also_read' => ['placement' => $block->rank, 'kills' => $kills],
                ];

                $matched[$entrantId]['conflict'] = true;

                continue;
            }

            /*
             | Two different squads on one position. A lobby cannot do that, so the
             | screen was misread somewhere, and filling in the blocks that happen to
             | look fine would be filling in half of something known to be wrong.
             */
            if (isset($claimed[$block->rank])) {
                throw ScoreSheetException::duplicatePlacement(
                    $block->rank,
                    $matched[$claimed[$block->rank]]['name'],
                    $this->entrant($entrantId)['name'],
                );
            }

            $claimed[$block->rank] = $entrantId;
            $entrant = $this->entrant($entrantId);

            // The squad is only as certain as the handle that identified it, and a
            // line nobody was found for leaves the block short of its own total.
            $confidence = $this->confidence($rows);

            $matched[$entrantId] = [
                'entrant_id' => $entrantId,
                'name' => (string) $entrant['name'],
                'read_as' => $block->raw(),
                'placement' => $block->rank,

                // The squad's kills are its players' eliminations added up. Not asked
                // for separately anywhere on this screen, and not typed: it is the one
                // figure the screen gives twice over.
                'kills' => $kills,

                // Top of the lobby takes the chicken dinner, the same proposal the
                // results table reader makes, and the operator still presses Save.
                'winner' => $block->rank === 1,

                'confidence' => $confidence,
                'low_confidence' => $confidence < ScoreSheetMatcher::REVIEW_BELOW
                    || $this->hasStranger($rows),
                'conflict' => false,
                'current' => $entrant['current'] ?? [],
                'players' => $rows,

                // Registered and not on the screen. Listed, never decided: took_part
                // is the operator's to set, and the image does not answer it.
                'absent' => $this->absent($entrantId, $rows),
            ];
        }

        $uncovered = [];

        foreach ($this->entrants as $entrant) {
            if (isset($matched[(int) $entrant['id']])) {
                continue;
            }

            $uncovered[] = [
                'entrant_id' => (int) $entrant['id'],
                'name' => (string) $entrant['name'],
            ];
        }

        return [
            'teams' => array_values($matched),
            'unmatched' => $unmatched,
            'uncovered' => $uncovered,
            'clashes' => $clashes,
        ];
    }

    /**
     * One block's player lines, and the single squad they point at.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: int|null, 2: string}
     */
    private function resolve(PlayerSheetBlock $block, PlayerNicknameMatcher $names): array
    {
        $rows = [];
        $squads = [];

        foreach ($block->players as $tally) {
            [$player, $confidence, $reason] = $names->best($tally->name);

            $rows[] = [
                // Null is the honest answer and the dialog shows it as one. The figure
                // still comes through, so an operator who recognises the handle can
                // type it in themselves.
                'id' => $player === null ? null : (int) $player['id'],

                'name' => $player === null ? '' : (string) $player['name'],
                'ign' => $player === null ? '' : (string) $player['ign'],
                'read_as' => $tally->name,
                'value' => $tally->eliminations,
                'current' => $player === null ? null : $this->current((int) $player['id']),
                'confidence' => $confidence,
                'low_confidence' => $player !== null && $confidence < ScoreSheetMatcher::REVIEW_BELOW,
                'reason' => $reason,
            ];

            if ($player !== null) {
                $squads[(int) $player['entrant_id']] = true;
            }
        }

        $ids = array_keys($squads);

        if ($ids === []) {
            return [$rows, null, 'none of the players in it is named on an entry in this fixture'];
        }

        /*
         | Two squads in one block. Either a handle was misread into somebody else's
         | squad or the wrong fixture is open, and both are things to look at rather
         | than average out: whichever squad the block was given, one of them would
         | receive a placement it did not earn.
         */
        if (count($ids) > 1) {
            return [
                $rows,
                null,
                sprintf(
                    'its players are named on two different entries in this fixture (%s), so which squad it is cannot be told',
                    implode(' and ', array_map(fn (int $id) => $this->entrant($id)['name'], $ids)),
                ),
            ];
        }

        return [$rows, $ids[0], ''];
    }

    /**
     * Registered players this block said nothing about.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{id: int, name: string, ign: string}>
     */
    private function absent(int $entrantId, array $rows): array
    {
        $seen = array_filter(array_column($rows, 'id'));
        $absent = [];

        foreach ($this->players as $player) {
            if ((int) $player['entrant_id'] !== $entrantId
                || in_array((int) $player['id'], $seen, true)) {
                continue;
            }

            $absent[] = [
                'id' => (int) $player['id'],
                'name' => (string) $player['name'],
                'ign' => (string) $player['ign'],
            ];
        }

        return $absent;
    }

    /**
     * The figures one block proposes, for telling a duplicate from a disagreement.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function figures(array $rows): array
    {
        $figures = [];

        foreach ($rows as $row) {
            // Keyed on the handle as it was read where nobody was found for it, so two
            // readings of one stranger still compare as the same line.
            $figures[$row['id'] === null ? 'read:' . $row['read_as'] : 'id:' . $row['id']] = (int) $row['value'];
        }

        ksort($figures);

        return $figures;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function confidence(array $rows): float
    {
        $scores = array_map(
            fn (array $row) => (float) $row['confidence'],
            array_values(array_filter($rows, fn (array $row) => $row['id'] !== null)),
        );

        return $scores === [] ? 0.0 : min($scores);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function hasStranger(array $rows): bool
    {
        foreach ($rows as $row) {
            if ($row['id'] === null) {
                return true;
            }
        }

        return false;
    }

    private function current(int $playerId): int|float|null
    {
        foreach ($this->players as $player) {
            if ((int) $player['id'] === $playerId) {
                return $player['current'] ?? null;
            }
        }

        return null;
    }

    /**
     * @return array{id: int, name: string, current: array<string, mixed>}
     */
    private function entrant(int $id): array
    {
        foreach ($this->entrants as $entrant) {
            if ((int) $entrant['id'] === $id) {
                return $entrant;
            }
        }

        return ['id' => $id, 'name' => 'Competitor', 'current' => []];
    }
}
