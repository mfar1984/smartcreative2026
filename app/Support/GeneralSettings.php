<?php

namespace App\Support;

use App\Models\Setting;

/**
 * The company facts saved on the General Config screen.
 *
 * Until this class existed those settings were written to the database and then
 * ignored: the name, registration number, address, email and telephone shown to
 * visitors were literals in HomeController, ContactController, the footer, the top
 * header and the policy layout. An administrator could edit the address, see
 * "saved", and the public site would not move. This closes that gap the same way
 * MailSettings closed it for SMTP.
 *
 * Follows the shape of BrandingSettings and ShopSettings: a group constant, static
 * readers, defaults in one place, and flush() after a save. The whole group is
 * fetched in a single query and memoised for the request, because these values are
 * read by several partials on every public page and a read per partial would put
 * five queries on the home page instead of one.
 */
final class GeneralSettings
{
    /** Same group as the rest of the General Config screen. */
    private const GROUP = 'general';

    /**
     * The six time formats the owner may choose, stored key => PHP date() string.
     *
     * The key is a stable identifier saved to the settings row; the sample shown in
     * the dropdown lives in the view. Only a key from this list is ever stored, and
     * only the string on the right is ever handed to date(), so a request can never
     * push an arbitrary format string through to the formatter.
     *
     * The first two are the owner's unusual request — 24-hour hour with an AM/PM
     * suffix — kept literally as asked. 'A' is uppercase AM/PM, 'a' is lowercase.
     *
     * @var array<string, string>
     */
    public const TIME_FORMATS = [
        '13:00 PM' => 'H:i A',
        '13:00:00 PM' => 'H:i:s A',
        '1:00:00 PM' => 'g:i:s A',
        '1:00 PM' => 'g:i A',
        '13:00' => 'H:i',
        '13:00:00' => 'H:i:s',
    ];

    /**
     * The nine date formats the owner may choose, stored key => PHP date() string.
     *
     * Same contract as TIME_FORMATS: the key is stored, the value is the only thing
     * ever handed to date().
     *
     * @var array<string, string>
     */
    public const DATE_FORMATS = [
        '13 September 2026' => 'j F Y',
        '13 Sep 2026' => 'j M Y',
        '13-Sep-2026' => 'j-M-Y',
        '13-Sep-26' => 'j-M-y',
        '13/09/2026' => 'd/m/Y',
        '13-09-2026' => 'd-m-Y',
        '13-09-26' => 'd-m-y',
        '13.09.2026' => 'd.m.Y',
        '13.09.26' => 'd.m.y',
    ];

    /**
     * The format strings used when nothing has been chosen.
     *
     * These are LocalTime's historical literals — date 'd M Y' and time 'g:i a' —
     * so an installation with an empty settings table formats exactly as it did
     * before this screen gained the two fields. None of the dropdown choices maps
     * to these strings exactly: the closest date choice is 'j M Y' (leading-zero
     * difference, d vs j) and the closest time choice is 'g:i A' (uppercase AM/PM).
     * The fallback is kept as the literals rather than a dropdown choice precisely
     * so first-deploy output does not move.
     */
    public const DEFAULT_DATE_FORMAT = 'd M Y';

    public const DEFAULT_TIME_FORMAT = 'g:i a';

    /**
     * Key => the value used when nothing has been saved.
     *
     * These are the literals the public site carried before it read anything, so an
     * installation with an empty settings table renders exactly what it rendered
     * before, rather than a row of blanks. Also fills the settings form itself.
     *
     * @var array<string, string>
     */
    public const DEFAULTS = [
        'site_name' => 'Smart Digital Creative Management & Resources',
        'tagline' => 'Innovate, Create & Manage',
        'contact_email' => 'event@smartcreative.my',
        'contact_phone' => '019-866 6898',
        'whatsapp' => '019-866 6898',
        'registration_no' => '202303326459 / 003562257-U',
        'address' => "Suite: 33-01, 33rd Floor\nMenara Keck Seng\n203 Jalan Bukit Bintang\n55100 Kuala Lumpur, Malaysia",
        'timezone' => 'Asia/Kuala_Lumpur',
    ];

    /**
     * The whole group, read once per request.
     *
     * @var array<string, string|null>|null
     */
    private static ?array $cache = null;

    /** Resolved separately because zone() is called once per rendered table cell. */
    private static ?string $timezone = null;

    /* ---------------------------------------------------------------------
     | Reading
     * ------------------------------------------------------------------ */

    /**
     * Every stored value in the group, with the group prefix taken off the keys.
     *
     * @return array<string, string|null>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $values = [];

        foreach (Setting::readGroup(self::GROUP) as $key => $value) {
            $values[str_replace(self::GROUP . '.', '', $key)] = $value;
        }

        return self::$cache = $values;
    }

    /**
     * One value, or the shipped default when it is missing or blank.
     *
     * Blank is treated as missing on purpose, and it is the one judgement call in
     * this class. A row saved as an empty string cannot be told apart from "we have
     * no telephone number" on the current screen, and a public page that states the
     * company's address is worse off showing nothing than showing the address it
     * showed yesterday. Same rule as ShopSettings::get().
     */
    public static function get(string $key): ?string
    {
        $value = self::all()[$key] ?? null;

        if ($value === null || trim($value) === '') {
            return self::DEFAULTS[$key] ?? null;
        }

        return $value;
    }

    /**
     * The values the settings form shows, defaults filled in.
     *
     * @return array<string, string>
     */
    public static function formValues(): array
    {
        $values = [];

        foreach (array_keys(self::DEFAULTS) as $key) {
            $values[$key] = (string) self::get($key);
        }

        // The two display-format keys are deliberately kept out of DEFAULTS: their
        // "not chosen" state is an empty <select> that means "use the historical
        // default", not a company fact that renders to a visitor. The form still
        // needs their stored key (or '' for not chosen) to pre-select the dropdown.
        $values['date_format'] = self::dateFormatKey();
        $values['time_format'] = self::timeFormatKey();

        return $values;
    }

    /* ---------------------------------------------------------------------
     | Typed accessors
     * ------------------------------------------------------------------ */

    /** The legal entity name, as one string. */
    public static function siteName(): string
    {
        return (string) self::get('site_name');
    }

    public static function tagline(): string
    {
        return (string) self::get('tagline');
    }

    public static function registrationNo(): string
    {
        return (string) self::get('registration_no');
    }

    public static function contactEmail(): string
    {
        return (string) self::get('contact_email');
    }

    public static function contactPhone(): string
    {
        return (string) self::get('contact_phone');
    }

    public static function whatsapp(): string
    {
        return (string) self::get('whatsapp');
    }

    /** The address exactly as it was typed, one line per row. */
    public static function address(): string
    {
        return (string) self::get('address');
    }

    /**
     * The address as lines, for markup that puts a <br> between them.
     *
     * Blank rows are dropped so a stray newline at the end of the textarea cannot
     * render an empty line on the public page.
     *
     * @return array<int, string>
     */
    public static function addressLines(): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\r\n|\r|\n/', self::address()) ?: []),
            fn (string $line) => $line !== '',
        ));
    }

    /**
     * The clock timestamps are read on.
     *
     * Falls through to config rather than to the literal above, so an installation
     * that sets APP_DISPLAY_TIMEZONE and never opens the settings screen keeps the
     * zone it configured. Checked against the real zone list because Carbon throws
     * on an unknown name, and a settings row can be edited by other tools.
     */
    public static function timezone(): string
    {
        if (self::$timezone !== null) {
            return self::$timezone;
        }

        $zone = (string) self::get('timezone');

        if (! in_array($zone, \DateTimeZone::listIdentifiers(), true)) {
            $zone = (string) config('app.display_timezone', self::DEFAULTS['timezone']);
        }

        return self::$timezone = $zone;
    }

    /**
     * The stored date_format key, or '' when nothing valid is chosen.
     *
     * Only a key present in DATE_FORMATS counts as chosen; anything else (blank, or
     * a stray value edited in by another tool) is treated as "not chosen" so the
     * caller falls back to the historical literal rather than to garbage.
     */
    public static function dateFormatKey(): string
    {
        $key = (string) (self::all()['date_format'] ?? '');

        return array_key_exists($key, self::DATE_FORMATS) ? $key : '';
    }

    /** The stored time_format key, or '' when nothing valid is chosen. */
    public static function timeFormatKey(): string
    {
        $key = (string) (self::all()['time_format'] ?? '');

        return array_key_exists($key, self::TIME_FORMATS) ? $key : '';
    }

    /**
     * The PHP date() string for the chosen date format, or the historical literal.
     *
     * Read off the memoised group, so this is not a database read per row.
     */
    public static function dateFormat(): string
    {
        $key = self::dateFormatKey();

        return $key === '' ? self::DEFAULT_DATE_FORMAT : self::DATE_FORMATS[$key];
    }

    /** The PHP date() string for the chosen time format, or the historical literal. */
    public static function timeFormat(): string
    {
        $key = self::timeFormatKey();

        return $key === '' ? self::DEFAULT_TIME_FORMAT : self::TIME_FORMATS[$key];
    }

    /* ---------------------------------------------------------------------
     | Links
     * ------------------------------------------------------------------ */

    /** The contact number as a tel: href. */
    public static function contactPhoneLink(): string
    {
        $msisdn = self::msisdn(self::contactPhone());

        return $msisdn === null ? '' : 'tel:+' . $msisdn;
    }

    /** The WhatsApp number as a wa.me link. */
    public static function whatsappLink(): string
    {
        $msisdn = self::msisdn(self::whatsapp());

        return $msisdn === null ? '' : 'https://wa.me/' . $msisdn;
    }

    /**
     * A Malaysian number as bare international digits.
     *
     * The screen takes the number the way it is written locally, "019-866 6898",
     * and tel: and wa.me both need "60198666898". Country code 60 with the trunk
     * zero dropped, which is the rule the contact page used to carry as a comment
     * beside a hardcoded string.
     */
    private static function msisdn(?string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw);

        if ($digits === '' || $digits === null) {
            return null;
        }

        if (str_starts_with($digits, '60')) {
            return $digits;
        }

        return '60' . ltrim($digits, '0');
    }

    /* ---------------------------------------------------------------------
     | Writing
     * ------------------------------------------------------------------ */

    /** Forget the cached group after a save, so the redirect draws the new values. */
    public static function flush(): void
    {
        self::$cache = null;
        self::$timezone = null;
    }
}
