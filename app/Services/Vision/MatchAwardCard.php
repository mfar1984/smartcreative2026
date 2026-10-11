<?php

namespace App\Services\Vision;

/**
 * One card off the Star of the Match screen.
 *
 * $award is the key of the profile award this card was put against, which is the
 * reader's answer and not a decision taken here. $player is the only identity the
 * screen carries: the in-game nickname printed at the top of the card. There is no
 * real name on it and no account id, so that nickname is all the matching has.
 *
 * $figures holds only the fields the card actually showed. Every card on that screen
 * carries a different set of stats — one leads on eliminations, the next on health
 * restored — so a field this card said nothing about is absent rather than zero.
 *
 * $unreadable names the fields whose printed value could not be turned into a
 * number. Carried rather than dropped, because a figure nobody could read is
 * something the operator must see.
 */
class MatchAwardCard
{
    /**
     * @param  array<string, int|float>  $figures  keyed by the profile's field key
     * @param  array<int, string>  $unreadable  field keys whose value made no sense
     */
    public function __construct(
        public readonly string $award,
        public readonly string $label,
        public readonly string $player,
        public readonly array $figures,
        public readonly array $unreadable = [],
    ) {
    }
}
