<?php

namespace App\Support;

use App\Models\Setting;
use Carbon\CarbonImmutable;
use Throwable;

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

    /**
     * Where the Next Gen API lives.
     *
     * One host for both sandbox and live. That is not an oversight: the API decides
     * the environment from the EasyParcel account that authorised the connection,
     * not from the endpoint and not from the application. The same Client ID and
     * Secret serve both, so there is nothing here to switch.
     *
     * This replaced a demo/live setting that pointed at connect.easyparcel.my and
     * demo.connect.easyparcel.my. Those belong to the Classic API, which takes a
     * single API key rather than OAuth, and an account is on one generation or the
     * other.
     */
    public const BASE_URL = 'https://api.easyparcel.com';

    /** The dated path segment the quotation and order endpoints sit under. */
    public const API_VERSION = '2026-06';

    /**
     * Malaysian states, stored by full name, mapped to their ISO 3166-2 code.
     *
     * The full name is what an administrator picks and what an order records,
     * because it is what a person reads on an address. The code is what the
     * quotation endpoint wants in subdivision_code, so the mapping happens here
     * rather than storing a code that would be wrong if the API changed
     * conventions again.
     *
     * @var array<string, string>
     */
    public const SUBDIVISION_CODES = [
        'Johor' => 'MY-01',
        'Kedah' => 'MY-02',
        'Kelantan' => 'MY-03',
        'Melaka' => 'MY-04',
        'Negeri Sembilan' => 'MY-05',
        'Pahang' => 'MY-06',
        'Penang' => 'MY-07',
        'Perak' => 'MY-08',
        'Perlis' => 'MY-09',
        'Selangor' => 'MY-10',
        'Terengganu' => 'MY-11',
        'Sabah' => 'MY-12',
        'Sarawak' => 'MY-13',
        'Kuala Lumpur' => 'MY-14',
        'Labuan' => 'MY-15',
        'Putrajaya' => 'MY-16',
    ];

    /**
     * Malaysian states, stored by full name.
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
     * The switch alone is not enough. Without an application to authenticate as,
     * every call is certain to be refused, so a switch with no credentials behind
     * it counts as off and checkout falls back instead.
     */
    public static function easyParcelEnabled(): bool
    {
        return self::get('easyparcel_enabled') === '1' && self::hasApplication();
    }

    /**
     * Whether the OAuth application is filled in.
     *
     * Both halves are needed: the id identifies the application on the login
     * redirect, and the secret signs the token exchange. One without the other
     * cannot complete a connection.
     */
    public static function hasApplication(): bool
    {
        return filled(self::get('easyparcel_client_id'))
            && filled(self::get('easyparcel_client_secret'));
    }

    public static function clientId(): ?string
    {
        return self::get('easyparcel_client_id');
    }

    public static function clientSecret(): ?string
    {
        return self::get('easyparcel_client_secret');
    }

    /**
     * The Basic credential the token endpoint expects.
     *
     * Documented as base64 of "client_id:client_secret". Returns null rather than
     * a half formed header when either part is missing.
     */
    public static function basicAuthorization(): ?string
    {
        if (! self::hasApplication()) {
            return null;
        }

        return base64_encode(self::clientId() . ':' . self::clientSecret());
    }

    public static function authorizeUrl(): string
    {
        return self::BASE_URL . '/oauth/login';
    }

    public static function tokenUrl(): string
    {
        return self::BASE_URL . '/oauth/token';
    }

    public static function quotationUrl(): string
    {
        return self::BASE_URL . '/open_api/' . self::API_VERSION . '/shipment/quotations';
    }

    /**
     * The ISO 3166-2 code for a stored state name, or null when it is not one of
     * the sixteen. Null is a refusal to guess: a quotation sent with the wrong
     * subdivision would come back priced for somewhere else.
     */
    public static function subdivisionCode(?string $state): ?string
    {
        return self::SUBDIVISION_CODES[(string) $state] ?? null;
    }

    /* ---------------------------------------------------------------------
     | The authorised connection
     |
     | These are written by the OAuth flow, never by the settings form, and they
     | are deliberately absent from IntegrationController::SCHEMA. A field in the
     | schema is read from the request on every save, so listing a token there
     | would blank the connection every time somebody pressed Save on an unrelated
     | part of the tab.
     |
     | Both tokens are stored encrypted. Note that a changed APP_KEY therefore
     | breaks the connection rather than corrupting it: Setting::plainValue()
     | returns null on a failed decrypt, which reads here as "not connected", and
     | the fix is to press Connect again.
     * ------------------------------------------------------------------ */

    /** How long before expiry a token is treated as due for refresh. */
    private const REFRESH_MARGIN_SECONDS = 300;

    public static function accessToken(): ?string
    {
        return self::get('easyparcel_access_token');
    }

    public static function refreshToken(): ?string
    {
        return self::get('easyparcel_refresh_token');
    }

    public static function accessTokenExpiresAt(): ?CarbonImmutable
    {
        return self::timestamp('easyparcel_access_expires_at');
    }

    public static function refreshTokenExpiresAt(): ?CarbonImmutable
    {
        return self::timestamp('easyparcel_refresh_expires_at');
    }

    public static function connectedAt(): ?CarbonImmutable
    {
        return self::timestamp('easyparcel_connected_at');
    }

    /**
     * Whether an account has been authorised at all.
     *
     * Judged on the refresh token, not the access token. The access token lasts
     * ten hours and is expected to be stale most of the time; the refresh token is
     * what makes a new one obtainable, so it is the refresh token that decides
     * whether there is still a connection to speak of.
     */
    public static function isConnected(): bool
    {
        if (blank(self::refreshToken())) {
            return false;
        }

        $expiry = self::refreshTokenExpiresAt();

        return $expiry === null || $expiry->isFuture();
    }

    /**
     * Whether the access token needs replacing before the next call.
     *
     * True when it is missing, when its expiry is unknown, or when it is inside
     * the margin. An unknown expiry counts as due rather than usable: refreshing
     * unnecessarily costs one request, whereas assuming a stale token is good
     * costs the quotation.
     */
    public static function accessTokenNeedsRefresh(): bool
    {
        if (blank(self::accessToken())) {
            return true;
        }

        $expiry = self::accessTokenExpiresAt();

        return $expiry === null
            || $expiry->subSeconds(self::REFRESH_MARGIN_SECONDS)->isPast();
    }

    /**
     * Record a freshly issued pair.
     *
     * The refresh token is only overwritten when the response carried one. The
     * documented refresh response may return a new refresh token, and treating a
     * missing one as a blank would throw away the only means of reconnecting.
     */
    public static function storeTokens(
        string $accessToken,
        ?string $refreshToken,
        ?CarbonImmutable $accessExpiresAt,
        ?CarbonImmutable $refreshExpiresAt,
    ): void {
        self::put('easyparcel_access_token', $accessToken, secret: true);
        self::put('easyparcel_access_expires_at', $accessExpiresAt?->toIso8601String());

        if (filled($refreshToken)) {
            self::put('easyparcel_refresh_token', $refreshToken, secret: true);
            self::put('easyparcel_refresh_expires_at', $refreshExpiresAt?->toIso8601String());
        }

        if (self::connectedAt() === null) {
            self::put('easyparcel_connected_at', CarbonImmutable::now()->toIso8601String());
        }
    }

    /**
     * Forget the connection, leaving the application credentials alone.
     *
     * Disconnecting is not the same as removing the integration: the Client ID and
     * Secret still identify this application, and the usual reason to disconnect is
     * to authorise a different EasyParcel account with the same application, such
     * as swapping sandbox for live.
     */
    public static function forgetTokens(): void
    {
        foreach ([
            'easyparcel_access_token',
            'easyparcel_refresh_token',
            'easyparcel_access_expires_at',
            'easyparcel_refresh_expires_at',
            'easyparcel_connected_at',
        ] as $key) {
            self::put($key, null);
        }
    }

    private static function put(string $key, ?string $value, bool $secret = false): void
    {
        Setting::write(self::GROUP . '.' . $key, $value, self::GROUP, $secret);
    }

    /**
     * A stored timestamp, or null when it is missing or unreadable. An unparseable
     * value is treated as absent rather than thrown, because a settings screen
     * must stay openable.
     */
    private static function timestamp(string $key): ?CarbonImmutable
    {
        $value = self::get($key);

        if (blank($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
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
        if (self::get('easyparcel_enabled') === '1' && ! self::hasApplication()) {
            return 'EasyParcel is switched on but the Client ID and Client Secret are not both filled in, so nothing can connect and every order is charged the flat rate.';
        }

        if (self::easyParcelEnabled() && ! self::isConnected()) {
            return 'EasyParcel credentials are saved but no account is connected yet. Press Connect Account on the Shipping tab; until then every order is charged the flat rate.';
        }

        if (self::easyParcelEnabled() && self::senderAddress() === null) {
            return 'EasyParcel is connected but the collection address is incomplete. A quotation needs an origin postcode and state, so every order will fall back to the flat rate.';
        }

        if (self::easyParcelEnabled()) {
            return 'EasyParcel is connected. Live rates are not requested at checkout yet, so orders are charged the flat rate until that is built.';
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
