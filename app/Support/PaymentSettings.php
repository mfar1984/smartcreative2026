<?php

namespace App\Support;

use App\Models\Setting;

class PaymentSettings
{
    public const PROVIDER_NONE = 'none';
    public const PROVIDER_CHIP = 'chip';
    public const PROVIDER_BILLPLZ = 'billplz';
    public const PROVIDER_TOYYIBPAY = 'toyyibpay';
    public const PROVIDER_STRIPE = 'stripe';

    /**
     * Provider slug => label, kept in step with the Integration screen.
     */
    public const PROVIDERS = [
        self::PROVIDER_NONE => 'Not configured',
        self::PROVIDER_CHIP => 'CHIP (chip-in.asia)',
        self::PROVIDER_BILLPLZ => 'Billplz',
        self::PROVIDER_TOYYIBPAY => 'toyyibPay',
        self::PROVIDER_STRIPE => 'Stripe',
    ];

    /**
     * Settings each provider must have before it can take a payment.
     *
     * Keys are prefixed per provider so switching gateway never mixes one
     * set of credentials with another.
     *
     * @var array<string, array<int, string>>
     */
    public const REQUIRED_CREDENTIALS = [
        self::PROVIDER_CHIP => ['chip_brand_id', 'chip_api_key'],
        self::PROVIDER_BILLPLZ => ['billplz_secret_key', 'billplz_collection_id'],
        self::PROVIDER_TOYYIBPAY => ['toyyibpay_secret_key', 'toyyibpay_category_code'],
        self::PROVIDER_STRIPE => ['stripe_secret_key'],
    ];

    private const GROUP = 'integration.payments';

    public static function get(string $key, ?string $default = null): ?string
    {
        return Setting::read(self::GROUP . '.' . $key, $default);
    }

    public static function provider(): string
    {
        return self::get('provider', self::PROVIDER_NONE) ?: self::PROVIDER_NONE;
    }

    public static function providerLabel(): string
    {
        return self::PROVIDERS[self::provider()] ?? self::provider();
    }

    public static function isChip(): bool
    {
        return self::provider() === self::PROVIDER_CHIP;
    }

    public static function mode(): string
    {
        return self::get('mode', 'sandbox') ?: 'sandbox';
    }

    public static function isLive(): bool
    {
        return self::mode() === 'live';
    }

    public static function currency(): string
    {
        return self::get('currency', 'MYR') ?: 'MYR';
    }

    /* ---------------------------------------------------------------------
     | CHIP
     * ------------------------------------------------------------------ */

    public static function chipBrandId(): ?string
    {
        return self::get('chip_brand_id');
    }

    public static function chipApiKey(): ?string
    {
        return self::get('chip_api_key');
    }

    /**
     * Public key CHIP signs its webhooks with. Not a secret, so it is stored
     * in the clear and may safely be shown back on the settings screen.
     */
    public static function chipWebhookPublicKey(): ?string
    {
        return self::get('chip_webhook_public_key');
    }

    /* ---------------------------------------------------------------------
     | Readiness
     * ------------------------------------------------------------------ */

    /**
     * Whether a paid event could actually be charged right now.
     *
     * Used by the event form to warn when a fee is set but no gateway is ready,
     * which would otherwise leave registrants with an unpayable invoice.
     */
    public static function isReady(): bool
    {
        $provider = self::provider();

        if ($provider === self::PROVIDER_NONE) {
            return false;
        }

        foreach (self::REQUIRED_CREDENTIALS[$provider] ?? [] as $key) {
            if (blank(self::get($key))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether incoming webhooks can be trusted.
     *
     * Without the public key a signature cannot be checked, so callbacks have
     * to be refused rather than taken on faith.
     */
    public static function canVerifyWebhooks(): bool
    {
        return self::isChip() && filled(self::chipWebhookPublicKey());
    }

    /**
     * Short sentence describing the current state, for display in the admin.
     */
    public static function summary(): string
    {
        if (self::provider() === self::PROVIDER_NONE) {
            return 'No payment gateway is configured.';
        }

        if (! self::isReady()) {
            return sprintf('%s is selected but its credentials are incomplete.', self::providerLabel());
        }

        return sprintf('%s, %s mode, %s.', self::providerLabel(), self::mode(), self::currency());
    }
}
