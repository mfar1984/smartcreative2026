<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Settings for the shop.
 *
 * Same shape as BrandingSettings: keys are stored as "shop.<name>" with "shop" in
 * the group column, reads are memoised for the request, and flush() is called
 * after a save so the redirect draws the new values.
 *
 * Every key here changes something a visitor can see today. There is deliberately
 * nothing about shipping rates or checkout: those belong with the checkout, and a
 * settings screen that configures a feature nobody can reach is worse than an
 * empty one, because it looks like the feature exists.
 */
final class ShopSettings
{
    private const GROUP = 'shop';

    /**
     * Key => default. The defaults are what the storefront shows before anybody
     * opens the settings screen, so the shop is presentable on day one.
     *
     * @var array<string, string>
     */
    public const DEFAULTS = [
        'enabled' => '0',
        'heading' => 'Shop',
        'intro' => 'Medals, trophies, apparel and event merchandise.',
        'per_page' => '12',
        'enquiry_note' => 'Online checkout is not open yet. Send us the item and quantity you want and we will reply with a total and payment instructions.',
        'hide_sold_out' => '0',
        'show_stock_count' => '1',
        'low_stock_threshold' => '5',
    ];

    /** @var array<string, string|null> */
    private static array $cache = [];

    /* ---------------------------------------------------------------------
     | Reading
     * ------------------------------------------------------------------ */

    public static function get(string $key, ?string $default = null): ?string
    {
        if (! array_key_exists($key, self::$cache)) {
            self::$cache[$key] = Setting::read(self::GROUP . '.' . $key);
        }

        $value = self::$cache[$key];

        if ($value === null || $value === '') {
            return $default ?? (self::DEFAULTS[$key] ?? null);
        }

        return $value;
    }

    /**
     * Every value, filled in with defaults. Used by the settings form so a field
     * is never blank when a sensible default exists.
     *
     * @return array<string, string>
     */
    public static function all(): array
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

    /**
     * Whether the storefront lists anything.
     *
     * Defaults to off. A shop that opens itself the moment the code is deployed
     * would put an empty catalogue on the live site.
     */
    public static function isOpen(): bool
    {
        return self::get('enabled') === '1';
    }

    public static function heading(): string
    {
        return (string) self::get('heading');
    }

    public static function intro(): string
    {
        return (string) self::get('intro');
    }

    /**
     * Clamped rather than trusted. A pasted 5000 would try to render the whole
     * catalogue in one response.
     */
    public static function perPage(): int
    {
        return max(4, min(48, (int) self::get('per_page')));
    }

    public static function enquiryNote(): string
    {
        return (string) self::get('enquiry_note');
    }

    /** Whether sold out products drop off the storefront entirely. */
    public static function hidesSoldOut(): bool
    {
        return self::get('hide_sold_out') === '1';
    }

    /** Whether visitors see "4 left" rather than just "in stock". */
    public static function showsStockCount(): bool
    {
        return self::get('show_stock_count') === '1';
    }

    /** The default filled into a new product's low stock field. */
    public static function lowStockThreshold(): int
    {
        return max(0, min(9999, (int) self::get('low_stock_threshold')));
    }

    /* ---------------------------------------------------------------------
     | Writing
     * ------------------------------------------------------------------ */

    /**
     * Write one key. Kept here so the group name lives in one place.
     */
    public static function put(string $key, ?string $value): void
    {
        Setting::write(self::GROUP . '.' . $key, $value, self::GROUP);
    }

    /**
     * Forget the per-request cache after a save.
     */
    public static function flush(): void
    {
        self::$cache = [];
    }
}
