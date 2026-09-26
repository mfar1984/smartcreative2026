<?php

namespace App\Support;

/**
 * A competitor's own details, reduced to what may be shown in public.
 *
 * A player profile has to be recognisable to the person it belongs to and to their
 * team-mates, and useless to anybody else. So enough of each value is shown for
 * somebody to say "that is me" and no more.
 *
 * Every method here is one-way. Nothing reconstructs the original, and no caller is
 * given the unmasked value: the public controllers select these columns, pass them
 * through here, and the full value only ever reaches staff by email.
 *
 * Deliberately not EventTemplateRenderer::maskCard(), which hides the last four
 * digits and nothing else. That one exists so an organiser can email somebody their
 * own number to check, where showing most of it is the point. This is the opposite
 * job: the reader is a stranger.
 */
final class PublicIdentity
{
    /** Shown where a value was never collected. */
    public const NONE = '—';

    /**
     * An identity card with the telling parts removed.
     *
     * A Malaysian card is YYMMDD-PB-###G. The first four digits are the year and
     * month of birth and the last two end in the digit that gives away sex, so those
     * are the ones hidden. What is left is the day of the month, the place-of-birth
     * pair and two of the four serial digits, which is plenty for somebody to
     * recognise their own number and not enough to reproduce it.
     *
     * Anything shorter than eight digits is hidden outright. A short number has no
     * middle worth showing, and a foreign document is not laid out like a Malaysian
     * one, so guessing at which part is safe would be guessing.
     */
    public static function card(?string $card): string
    {
        $digits = self::digits($card);
        $length = mb_strlen($digits);

        if ($length === 0) {
            return self::NONE;
        }

        if ($length < 8) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', 4)
            . mb_substr($digits, 4, $length - 6)
            . str_repeat('*', 2);
    }

    /**
     * A telephone number with both ends removed.
     *
     * The leading digits are the network prefix, which is the same for millions of
     * people and so worth nothing, and the trailing digits are what would make the
     * number dialable. The middle is what somebody recognises.
     */
    public static function phone(?string $phone): string
    {
        $digits = self::digits($phone);
        $length = mb_strlen($digits);

        if ($length === 0) {
            return self::NONE;
        }

        if ($length < 7) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', 3)
            . mb_substr($digits, 3, $length - 6)
            . str_repeat('*', 3);
    }

    /**
     * An address reduced to its shape.
     *
     * The number of stars is fixed rather than matching what it replaces, because the
     * length of a local part is itself a clue when somebody is guessing at an
     * address they half know.
     *
     * The top level domain is left whole. It identifies nobody, and hiding it would
     * make the result unreadable as an address.
     */
    public static function email(?string $email): string
    {
        $email = trim((string) $email);

        if ($email === '') {
            return self::NONE;
        }

        $at = mb_strrpos($email, '@');

        // Not an address at all. Nothing here can be masked safely, so nothing is
        // shown. Registration validates the field, so this is a guard rather than a
        // case that is expected to happen.
        if ($at === false || $at === 0 || $at === mb_strlen($email) - 1) {
            return self::NONE;
        }

        return self::maskLocalPart(mb_substr($email, 0, $at))
            . '@'
            . self::maskDomain(mb_substr($email, $at + 1));
    }

    /**
     * First letter, then the last two, so a long address stays recognisable.
     */
    private static function maskLocalPart(string $local): string
    {
        if (mb_strlen($local) <= 3) {
            return mb_substr($local, 0, 1) . '***';
        }

        return mb_substr($local, 0, 1) . '***' . mb_substr($local, -2);
    }

    /**
     * First and last letter of the name, with everything after the first dot kept.
     *
     * So gmail.com reads as g***l.com, which anybody recognises, and a company
     * domain keeps its suffix without naming the company.
     */
    private static function maskDomain(string $domain): string
    {
        $dot = mb_strpos($domain, '.');

        if ($dot === false) {
            return mb_strlen($domain) <= 2 ? '***' : mb_substr($domain, 0, 1) . '***' . mb_substr($domain, -1);
        }

        $name = mb_substr($domain, 0, $dot);
        $rest = mb_substr($domain, $dot);

        if (mb_strlen($name) <= 2) {
            return '***' . $rest;
        }

        return mb_substr($name, 0, 1) . '***' . mb_substr($name, -1) . $rest;
    }

    private static function digits(?string $value): string
    {
        return (string) preg_replace('/\D/', '', (string) $value);
    }
}
