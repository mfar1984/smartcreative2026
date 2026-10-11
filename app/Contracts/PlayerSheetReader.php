<?php

namespace App\Contracts;

use App\Services\Vision\PlayerSheetReading;
use App\Services\Vision\ScoreSheetException;
use App\Services\Vision\ScoreSheetImage;

/**
 * Reads the game's own per-player result screen.
 *
 * The third of these, and a sibling rather than another method, for the reason the
 * first two are siblings: a different screen, asked a different question. This one
 * is a list of team blocks, each carrying a rank number and the players in it with
 * an elimination count each — four answers off one image, where the results table
 * gives two and the award screen gives cards.
 *
 * There are NO TEAM NAMES on that screen. Only nicknames with clan prefixes, and
 * the prefixes are not dependable: one squad prints D2W and another D2WGx. So a
 * block is identified by its players or not at all, which is why this reader is
 * handed the fixture's nicknames and never a team list.
 *
 * The personal field definitions go WITH the image, the way the award definitions
 * do. Nothing in here knows a figure is called Elims: that is a label somebody
 * typed into a scoring profile, and the reader is asked which of those labels the
 * screen's elimination column belongs to.
 */
interface PlayerSheetReader
{
    /**
     * @param  array<int, ScoreSheetImage>  $images  parts of ONE result screen, which
     *                                               shows about three blocks at a time
     * @param  array<int, array{key: string, label: string}>  $fields  the personal figures
     *         this profile keeps, which is what the elimination column is matched against
     * @param  array<int, string>  $nicknames  the in-game names in this fixture, so a
     *                                         half-legible handle is spelled the way the
     *                                         entry list spells it
     *
     * @throws ScoreSheetException
     */
    public function read(array $images, array $fields, array $nicknames): PlayerSheetReading;
}
