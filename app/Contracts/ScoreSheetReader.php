<?php

namespace App\Contracts;

use App\Services\Vision\ScoreSheetException;
use App\Services\Vision\ScoreSheetImage;
use App\Services\Vision\ScoreSheetReading;

/**
 * Reads a results table off a photograph.
 *
 * One method, because swapping the supplier is the only reason this interface
 * exists. Every read is a paid call to somebody else's model, and the day that
 * supplier is changed should be the day one class is written, not the day a
 * controller is edited. Nothing above this line knows whose model answered.
 */
interface ScoreSheetReader
{
    /**
     * @param  array<int, ScoreSheetImage>  $images  parts of ONE results table
     * @param  array<int, string>  $names  the competitors in this fixture, which the
     *                                     reader is told so it spells a team the way
     *                                     the tournament does
     *
     * @throws ScoreSheetException
     */
    public function read(array $images, array $names): ScoreSheetReading;
}
