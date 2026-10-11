<?php

namespace App\Services\Vision;

/**
 * One team block off the per-player result screen: a rank number, and the players
 * printed under it.
 *
 * FOUR ANSWERS COME OUT OF THIS ONE THING, which is why it is worth its own class.
 * The rank is the squad's placement, the eliminations added up are the squad's
 * kills, each line is one player's own figure, and rank 1 is the chicken dinner.
 * Nothing here decides any of that; it carries what was printed so the matcher can.
 */
class PlayerSheetBlock
{
    /**
     * @param  array<int, PlayerSheetTally>  $players
     */
    public function __construct(
        public readonly int $rank,
        public readonly array $players,
    ) {
    }

    /**
     * The squad's kill count: its players' eliminations added up.
     *
     * Every line in the block counts, including a line nobody could be found for. The
     * screen is the evidence for what the squad did, and leaving a stranger's three
     * eliminations out would report the squad as having fewer than it has.
     */
    public function kills(): int
    {
        return array_sum(array_map(fn (PlayerSheetTally $player) => $player->eliminations, $this->players));
    }

    /**
     * What was printed in this block, for a block nobody could be found for.
     *
     * The operator has the image open beside the dialog, so the raw text is what lets
     * them see which block was left out rather than being told a number.
     */
    public function raw(): string
    {
        return sprintf(
            '#%d — %s',
            $this->rank,
            implode(', ', array_map(
                fn (PlayerSheetTally $player) => $player->name . ' ' . $player->eliminations,
                $this->players,
            )),
        );
    }
}
