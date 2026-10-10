<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * One place that decides whether two holder entries are the same person.
 *
 * WHY A KEY RATHER THAN A NAME
 *
 * A holder is typed by hand, and the whole point of grouping by holder is to answer
 * "whose codes ran out and whose are untouched". Group on the name and "Ahmad Bin Ali"
 * and "ahmad bin  ali" become two representatives with half an allocation each — a
 * report that only LOOKS grouped, which is worse than no grouping at all.
 *
 * So the key is the strongest identifier that was given, in this order:
 *
 *   ic      an identity card number is the one value that is genuinely unique to a
 *           person, so it wins whenever it is present. Compared with the punctuation
 *           stripped, because 900101-13-5566 and 900101135566 are one number.
 *   email   next best. Lowercased, because addresses are not case sensitive in
 *           practice and nobody expects them to be.
 *   name    the fallback. Trimmed, inner whitespace collapsed, lowercased.
 *
 * The key is for COMPARISON only. What the operator typed is stored and displayed
 * untouched apart from trimming, because a name is theirs to spell.
 *
 * WHOSE DATA THIS IS
 *
 * A holder is the REPRESENTATIVE who hands codes out, not the participant who redeems
 * one. Their IC and phone exist so the office can trace a code back to whoever was
 * given it. A redeemer's details are a separate thing entirely and are never put
 * through here.
 */
class CouponHolderIdentity
{
    /** What an untagged code groups under, in words. */
    public const UNASSIGNED = 'Not assigned';

    /**
     * A typed value as it should be stored: trimmed, inner whitespace collapsed.
     *
     * Case is preserved. The operator is naming a person, and folding that to one
     * case would print somebody's name back at them wrong.
     */
    public static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value === '' ? null : $value;
    }

    /**
     * The stable grouping key for one holder, or null when nothing was given.
     *
     * Null means "not a holder": a row with all four fields empty is refused at
     * validation rather than stored, so a null key never reaches the database.
     */
    public static function key(?string $ic, ?string $email, ?string $fullName): ?string
    {
        $ic = self::digits($ic);

        if ($ic !== null) {
            return 'ic:'.$ic;
        }

        $email = self::clean($email);

        if ($email !== null) {
            return 'email:'.Str::lower($email);
        }

        $name = self::clean($fullName);

        return $name === null ? null : 'name:'.Str::lower($name);
    }

    /**
     * How a holder reads on screen and in a CSV: the name, or the best thing there is.
     *
     * Never blank. A holder with only a phone number still has to be identifiable in
     * a grouped report, so the phone is what labels them.
     */
    public static function label(?string $fullName, ?string $email = null, ?string $ic = null, ?string $phone = null): string
    {
        return self::clean($fullName)
            ?? self::clean($email)
            ?? self::clean($ic)
            ?? self::clean($phone)
            ?? self::UNASSIGNED;
    }

    /**
     * An IC reduced to its digits, for comparison only.
     *
     * Stored as typed; only the key strips the dashes, so 900101-13-5566 and
     * 900101135566 are recognised as the same person.
     */
    private static function digits(?string $ic): ?string
    {
        $ic = preg_replace('/[^0-9A-Za-z]/', '', (string) $ic) ?? '';

        return $ic === '' ? null : Str::lower($ic);
    }
}
