<?php

namespace App\Services\Vision;

/**
 * One team row the reader could make whole numbers of.
 *
 * The placement points column is not here. It is worked out from the point rule,
 * is not an input on the form, and reading it would invite somebody to trust a
 * figure the engine is meant to produce.
 */
class ScoreSheetRow
{
    public function __construct(
        public readonly string $name,
        public readonly int $placement,
        public readonly int $kills,
    ) {
    }
}
