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
