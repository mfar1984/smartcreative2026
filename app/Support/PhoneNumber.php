<?php

namespace App\Support;

/**
 * Turning what people type into what an SMS gateway will accept.
 *
 * Forms collect telephone numbers the way people say them: 017-859 1411,
 * 0178591411, +60 17 859 1411. An SMS gateway wants international digits with no
 * punctuation and no leading zero, so 0178591411 has to become 60178591411
 * before it goes anywhere.
 *
 * The stored value is deliberately left as typed. Numbers are shown back to
 * counter staff who read them off the same forms and cards, and rewriting them
 * into 60... would make the screen disagree with the paperwork. Conversion
 * happens at the gateway boundary instead, which is the only place that needs it.
 */
class PhoneNumber
{
    /** Malaysia. The only country this site registers people from. */
    public const DEFAULT_COUNTRY_CODE = '60';

    /**
     * A Malaysian subscriber number is 9 or 10 digits after the country code:
     * 60 12 345 6789 (mobile) through to 60 3 1234 5678 (Kuala Lumpur landline).
     */
    private const MIN_NATIONAL_DIGITS = 9;
    private const MAX_NATIONAL_DIGITS = 11;

    /**
     * International digits ready to hand to a gateway, or null when the input
     * cannot be trusted to be a real number.
     *
     * Null rather than a guess: sending to a number assembled out of something
     * unparseable either fails, or worse, reaches a stranger.
     */
    public static function toInternational(?string $input, string $countryCode = self::DEFAULT_COUNTRY_CODE): ?string
    {
        $digits = self::digitsOnly($input);

        if ($digits === '') {
            return null;
        }

        // 0060123456789, the international access code written out. Strip it
        // before anything else or the 00 is mistaken for part of the number.
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, $countryCode)) {
            // Already international: 60123456789.
            $national = substr($digits, strlen($countryCode));
        } elseif (str_starts_with($digits, '0')) {
            // National form: 0123456789. The trunk zero is not dialled from
            // outside the country, so it goes.
            $national = substr($digits, 1);
        } else {
            // Bare subscriber number: 123456789. Taken as national, which is
            // what someone omitting the zero means.
            $national = $digits;
        }

        $national = ltrim($national, '0');

        if (strlen($national) < self::MIN_NATIONAL_DIGITS || strlen($national) > self::MAX_NATIONAL_DIGITS) {
            return null;
        }

        return $countryCode . $national;
    }

    /**
     * The same thing with a leading plus, for tel: and wa.me links.
     */
    public static function toE164(?string $input, string $countryCode = self::DEFAULT_COUNTRY_CODE): ?string
    {
        $international = self::toInternational($input, $countryCode);

        return $international === null ? null : '+' . $international;
    }

    public static function isSendable(?string $input): bool
    {
        return self::toInternational($input) !== null;
    }

    /**
     * Everything that is not a digit removed, so punctuation and spacing in any
     * arrangement come out the same.
     */
    private static function digitsOnly(?string $input): string
    {
        return preg_replace('/\D+/', '', (string) $input) ?? '';
    }
}
