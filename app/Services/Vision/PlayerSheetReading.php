<?php

namespace App\Services\Vision;

/**
 * What one read of the per-player result screen produced.
 *
 * $field is the profile's own key for the figure the elimination column belongs to,
 * answered by the reader against the labels it was given. Empty when nothing was
 * read at all, which is the wrong-screenshot case and not a profile to go and fix.
 *
 * Rejected blocks are carried rather than dropped, the same way a rejected results
 * row is. A block whose rank would not read, or that holds a figure that is not a
 * number, is a squad the operator must see: one that quietly disappeared is a squad
 * that quietly scored nothing.
 */
class PlayerSheetReading
{
    /**
     * @param  array<int, PlayerSheetBlock>  $blocks  every block read, from every image
     * @param  array<int, array{read_as: string, reason: string}>  $rejected
     */
    public function __construct(
        public readonly array $blocks,
        public readonly string $field = '',
        public readonly array $rejected = [],
    ) {
    }
}
