<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Postage settings for shop orders.
 *
 * Same shape as PaymentSettings: keys live under "integration.shipping.<name>" and
 * are read straight through Setting, with no cache, because a settings screen that
 * shows a stale value is worse than one extra query.
 *
 * EasyParcel quotes live rates, but a checkout cannot wait on somebody else's API
 * being up. Everything here therefore has a flat rate answer as well, and the flat
 * rate is the fallback rather than an alternative feature.
 */
final class ShippingSettings
{
    private const GROUP = 'integration.shipping';

    public const MODE_DEMO = 'demo';
    public const MODE_LIVE = 'live';

    /**
     * Malaysian states, stored by full name.
     *
     * Deliberately not courier codes. EasyParcel has two API generations with
     * different conventions, and storing a code would tie the saved address to
     * whichever one was current when it was typed. The mapping happens where the
     * request is built.
     *
     * @var array<string, string>
     */
    public const STATES = [
        'Johor' => 'Johor',
        'Kedah' => 'Kedah',
        'Kelantan' => 'Kelantan',
        'Melaka' => 'Melaka',
        'Negeri Sembilan' => 'Negeri Sembilan',
        'Pahang' => 'Pahang',
        'Penang' => 'Penang',
        'Perak' => 'Perak',
        'Perlis' => 'Perlis',
        'Selangor' => 'Selangor',
        'Terengganu' => 'Terengganu',
        'Kuala Lumpur' => 'Kuala Lumpur',
        'Putrajaya' => 'Putrajaya',
        'Labuan' => 'Labuan',
        'Sabah' => 'Sabah',
        'Sarawak' => 'Sarawak',
    ];

    /**
     * The states that cost more to reach.
     *
     * Labuan sits with Sabah and Sarawak because couriers price it as East
     * Malaysia, not because of where it is administered from.
     *
     * @var array<int, string>
     */
    public const EAST_MALAYSIA = ['Sabah', 'Sarawak', 'Labuan'];

    /* ---------------------------------------------------------------------
     | Reading
     * ------------------------------------------------------------------ */

    public static function get(string $key, ?string $default = null): ?string
    {
        return Setting::read(self::GROUP . '.' . $key, $default);
    }

    /* ---------------------------------------------------------------------
     | EasyParcel
     * ------------------------------------------------------------------ */

    /**
     * Whether EasyParcel should be asked at all.
     *
     * A switch with no key behind it counts as off, so checkout falls back rather
     * than making a call that is certain to be refused.
     */
    public static function easyParcelEnabled(): bool
    {
        return self::get('easyparcel_enabled') === '1' && filled(self::get('easyparcel_api_key'));
    }

    public static function easyParcelMode(): string
    {
        return self::get('easyparcel_mode', self::MODE_DEMO) === self::MODE_LIVE
            ? self::MODE_LIVE
            : self::MODE_DEMO;
    }

    public static function easyParcelApiKey(): ?string
    {
        return self::get('easyparcel_api_key');
    }

    /**
     * Base URL for the mode in use.
     *
     * The demo host is served over plain HTTP by EasyParcel, which is why the mode
     * and the key are checked together before anything is sent: a live key must
     * never travel over the demo endpoint.
     */
    public static function easyParcelBaseUrl(): string
    {
        return self::easyParcelMode() === self::MODE_LIVE
            ? 'https://connect.easyparcel.my/'
            : 'http://demo.connect.easyparcel.my/';
    }

    /* ---------------------------------------------------------------------
     | Collection address
     * ------------------------------------------------------------------ */

    /**
     * Where parcels are collected from, or null unless enough of it is filled in.
     *
     * Rate checking works off the postcode and state, so those two decide whether
     * the address is usable. Asking EasyParcel for a quote from nowhere would fail
     * on every order.
     *
     * @return array{name: string, phone: string, address_1: string, address_2: string, postcode: string, city: string, state: string}|null
     */
    public static function senderAddress(): ?array
    {
        $postcode = self::get('sender_postcode');
        $state = self::get('sender_state');

        if (blank($postcode) || blank($state)) {
            return null;
        }

        return [
            'name' => (string) self::get('sender_name'),
            'phone' => (string) self::get('sender_phone'),
            'address_1' => (string) self::get('sender_address_1'),
            'address_2' => (string) self::get('sender_address_2'),
            'postcode' => (string) $postcode,
            'city' => (string) self::get('sender_city'),
            'state' => (string) $state,
        ];
    }

    /* ---------------------------------------------------------------------
     | Flat rates
     * ------------------------------------------------------------------ */

    public static function flatRateWest(): float
    {
        return round((float) self::get('flat_rate_west', '0'), 2);
    }

    public static function flatRateEast(): float
    {
        return round((float) self::get('flat_rate_east', '0'), 2);
    }

    /**
     * Null when free shipping is switched off, which is different from a threshold
     * of zero: zero would make everything free.
     */
    public static function freeShippingThreshold(): ?float
    {
        $value = self::get('free_shipping_threshold');

        return blank($value) ? null : round((float) $value, 2);
    }

    public static function note(): ?string
    {
        return self::get('shipping_note');
    }

    /* ---------------------------------------------------------------------
     | Quoting
     * ------------------------------------------------------------------ */

    public static function isEastMalaysia(?string $state): bool
    {
        return in_array((string) $state, self::EAST_MALAYSIA, true);
    }

    /**
     * The flat rate for a destination, before the free shipping threshold.
     */
    public static function flatRateFor(?string $state): float
    {
        return self::isEastMalaysia($state) ? self::flatRateEast() : self::flatRateWest();
    }

    /**
     * What to charge for postage on an order.
     *
     * Only the flat rates are consulted here. Live EasyParcel quoting is a separate
     * piece of work, and when it arrives it will sit in front of this rather than
     * replace it, because this is also the answer when that call fails.
     *
     * @param  float  $goodsTotal  the order total before postage
     */
    public static function quote(?string $state, float $goodsTotal): float
    {
        $threshold = self::freeShippingThreshold();

        if ($threshold !== null && $goodsTotal >= $threshold) {
            return 0.0;
        }

        return self::flatRateFor($state);
    }

    /**
     * One honest sentence about what shipping would do right now, for the settings
     * screen. Mirrors SmsSettings::summary().
     */
    public static function summary(): string
    {
        if (self::easyParcelEnabled() && self::senderAddress() === null) {
            return 'EasyParcel is switched on but the collection address is incomplete, so every order will fall back to the flat rate.';
        }

        if (self::easyParcelEnabled()) {
            return sprintf(
                'EasyParcel is switched on in %s mode. Live rates are not requested yet, so orders are charged the flat rate until that is built.',
                self::easyParcelMode(),
            );
        }

        if (self::flatRateWest() === 0.0 && self::flatRateEast() === 0.0) {
            return 'No postage is charged: both flat rates are zero and EasyParcel is off.';
        }

        return sprintf(
            'Orders are charged a flat %s for Peninsular Malaysia and %s for Sabah and Sarawak.',
            PaymentFigures::money(self::flatRateWest()),
            PaymentFigures::money(self::flatRateEast()),
        );
    }
}
