<?php

namespace App\Services\Vision;

/**
 * What one read of the Star of the Match screen produced.
 *
 * A card the reader could not put against any award in the profile goes to
 * $unknown rather than onto the nearest one. The profile is operator-editable: a
 * screen showing four awards where three are configured is a profile somebody has
 * not finished, and the honest answer is to say which card was left out.
 */
class MatchAwardReading
{
    /**
     * @param  array<int, MatchAwardCard>  $cards
     * @param  array<int, array{label: string, player: string, reason: string}>  $unknown
     */
    public function __construct(
        public readonly array $cards,
        public readonly array $unknown = [],
    ) {
    }
}
