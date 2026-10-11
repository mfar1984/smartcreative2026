<?php

namespace App\Contracts;

use App\Services\Vision\MatchAwardReading;
use App\Services\Vision\ScoreSheetException;
use App\Services\Vision\ScoreSheetImage;

/**
 * Reads the game's Star of the Match screen.
 *
 * A sibling of ScoreSheetReader rather than another method on it: the two read
 * different screens and want different answers, and a results table has nothing to
 * say about who was the best companion. They share the supplier, not the question —
 * GeminiClient is the one place that knows whose model answers either of them.
 *
 * The award definitions go WITH the image. Nothing in here knows an award is called
 * Going All Out: that is a label somebody typed into a scoring profile, and the
 * reader is asked to put the cards it sees against the labels it was given.
 */
interface MatchAwardReader
{
    /**
     * @param  array<int, ScoreSheetImage>  $images  the Star of the Match screen
     * @param  array<int, array{key: string, label: string, fields: array<int, array{key: string, label: string}>}>  $awards
     *         what this scoring profile hands out, which is what the cards are matched against
     * @param  array<int, string>  $nicknames  the in-game names in this fixture, so a
     *                                         half-legible card is spelled the way the
     *                                         entry list spells it
     *
     * @throws ScoreSheetException
     */
    public function read(array $images, array $awards, array $nicknames): MatchAwardReading;
}
