<?php

namespace App\Services\Vision;

/**
 * What one read produced.
 *
 * Rejected rows are carried rather than dropped. A cell the reader could not turn
 * into a number is the one thing an operator must see, because a row that quietly
 * disappeared is a team that quietly scored nothing.
 */
class ScoreSheetReading
{
    /**
     * @param  array<int, ScoreSheetRow>  $rows
     * @param  array<int, array{name: string, reason: string}>  $rejected
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $rejected = [],
    ) {
    }
}
