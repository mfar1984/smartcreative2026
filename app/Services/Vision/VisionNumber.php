<?php

namespace App\Services\Vision;

/**
 * A figure read off a game screen, turned into a number or refused.
 *
 * Shared by both readers because it is the same game and the same font. Two things
 * go wrong on these screens and both are repaired here, once.
 *
 * THE FONT. The digit 1 is drawn as a serif capital I, so a rank arrives as I6 and
 * a kill count as II. The reader is asked for the characters it sees rather than for
 * a number, which is what lets the repair happen in one place where a value that is
 * still not a number afterwards can be refused instead of invented.
 *
 * THE SEPARATORS. Four figures and up are printed grouped: 9,617 travelled and
 * 1,321 seconds survived. The comma is a thousands separator on this screen and
 * never a decimal point, so it is removed rather than translated — reading 9,617 as
 * 9.617 would put a distance of nearly ten kilometres on a card as nine metres.
 */
class VisionNumber
{
    /** Longer than this is not a figure off a results screen. */
    private const MAX_DIGITS = 7;

    /**
     * A whole number, or null if what was read is not one.
     */
    public static function whole(string $raw): ?int
    {
        $value = self::tidy($raw);

        return preg_match('/^\d{1,4}$/', $value) === 1 ? (int) $value : null;
    }

    /**
     * One figure off an award card, to the precision the profile declared for it.
     *
     * A field the profile marks as decimal may carry a point; one that does not is a
     * count, and a point in it is a misread rather than a fraction.
     */
    public static function figure(string $raw, bool $decimal = false): int|float|null
    {
        $value = self::tidy($raw);

        if ($decimal) {
            return preg_match('/^\d{1,' . self::MAX_DIGITS . '}(\.\d{1,3})?$/', $value) === 1
                ? round((float) $value, 3)
                : null;
        }

        return preg_match('/^\d{1,' . self::MAX_DIGITS . '}$/', $value) === 1 ? (int) $value : null;
    }

    /**
     * What is left of a cell once the font and the grouping are accounted for.
     *
     * Only the glyphs that are actually confused, and only in a value already known
     * to be numeric: I, l and a bare pipe for the 1 they are standing in for.
     */
    private static function tidy(string $raw): string
    {
        $value = strtr($raw, [
            'I' => '1',
            'i' => '1',
            'l' => '1',
            '|' => '1',
        ]);

        // Spaces and separators go. Everything else stays, so that a cell holding a
        // word is still refused below rather than quietly becoming a number.
        return (string) preg_replace('/[\s,\x{00A0}\x{2009}\x{202F}\']+/u', '', $value);
    }
}
