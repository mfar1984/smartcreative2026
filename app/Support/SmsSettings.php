<?php

namespace App\Support;

use App\Models\Setting;

/**
 * The SMS gateway profile saved on the Integration screen.
 *
 * Infobip is the only provider with a driver behind it. The others stay on the
 * screen as names because an operator who has one of those accounts should see
 * that the option exists rather than wonder, but selecting one and asking to
 * send raises an exception rather than pretending a message went out.
 */
class SmsSettings
{
    public const PROVIDER_NONE = 'none';
    public const PROVIDER_INFOBIP = 'infobip';

    /** Kept in step with the options on the Integration screen. */
    public const PROVIDERS = [
        self::PROVIDER_NONE => 'Not configured',
        self::PROVIDER_INFOBIP => 'Infobip',
        'onewaysms' => 'onewaySMS (no driver yet)',
        'isms' => 'iSMS (no driver yet)',
        'twilio' => 'Twilio (no driver yet)',
    ];

    /**
     * What each provider needs before it could send anything.
     *
     * Per provider rather than shared: Infobip authenticates with an account
     * specific base URL and an API key, where the older Malaysian gateways use a
     * username and secret. A single list would mark a complete Infobip profile
     * as incomplete.
     *
     * @var array<string, array<int, string>>
     */
    private const REQUIRED = [
        self::PROVIDER_INFOBIP => ['base_url', 'api_key', 'sender_id'],
        'onewaysms' => ['sender_id', 'username', 'api_secret'],
        'isms' => ['sender_id', 'username', 'api_secret'],
        'twilio' => ['sender_id', 'username', 'api_secret'],
    ];

    /**
     * Which notifications may go out over SMS.
     *
     * The toggle key => the template keys it governs. Grouped by moment rather
     * than one toggle per template, because the manager and player wording of
     * the same moment is one decision, not two.
     *
     * @var array<string, array<int, string>>
     */
    public const ALERTS = [
        'notify_registration' => ['registration.manager', 'registration.player'],
        'notify_payment' => ['payment.manager', 'payment.player'],
        'notify_payment_reminder' => ['payment.reminder'],
    ];

    private const GROUP = 'integration.sms';

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

    public static function isInfobip(): bool
    {
        return self::provider() === self::PROVIDER_INFOBIP;
    }

    /** Whether the operator has switched the channel on at all. */
    public static function isEnabled(): bool
    {
        return self::get('enabled') === '1';
    }

    public static function senderId(): ?string
    {
        return self::get('sender_id');
    }

    public static function apiKey(): ?string
    {
        return self::get('api_key');
    }

    /**
     * The secret that makes the delivery report endpoint safe to expose.
     *
     * Infobip does not sign delivery reports, so an endpoint anybody could find
     * would let a stranger mark our messages as delivered or failed. The secret
     * travels in the notifyUrl we hand over per message, which means nothing has
     * to be configured in the Infobip portal.
     *
     * Generated on first use rather than in a seeder, so an installation that
     * never sends SMS never carries one.
     */
    public static function webhookSecret(): string
    {
        $secret = self::get('webhook_secret');

        if (filled($secret)) {
            return $secret;
        }

        $secret = \Illuminate\Support\Str::random(48);

        // Stored as a secret so it is encrypted at rest, the same as the API key.
        // get() reads straight through to the database, so the next call finds it.
        Setting::write(self::GROUP . '.webhook_secret', $secret, self::GROUP, true);

        return $secret;
    }

    /** Where Infobip should post delivery reports for our messages. */
    public static function deliveryReportUrl(): string
    {
        return route('sms.infobip.delivery', ['secret' => self::webhookSecret()]);
    }

    /**
     * The account base URL as a usable https address.
     *
     * Infobip hands out the host on its own, so an operator pastes
     * "r4eee.api.infobip.com" without a scheme and a bare Http::post() against
     * that would fail. Normalised here rather than trusting the paste.
     */
    public static function baseUrl(): ?string
    {
        $value = trim((string) self::get('base_url'));

        if ($value === '') {
            return null;
        }

        $value = rtrim($value, '/');

        if (! str_starts_with($value, 'http://') && ! str_starts_with($value, 'https://')) {
            $value = 'https://' . $value;
        }

        return $value;
    }

    /**
     * Whether the credentials for the selected provider are complete.
     *
     * Complete credentials are not the same as a working account: only a real
     * send proves that, which is what the Send Test button is for.
     */
    public static function isReady(): bool
    {
        $provider = self::provider();

        if ($provider === self::PROVIDER_NONE) {
            return false;
        }

        foreach (self::REQUIRED[$provider] ?? [] as $key) {
            if (blank(self::get($key))) {
                return false;
            }
        }

        return true;
    }

    /** Whether a message could actually be handed to a gateway right now. */
    public static function canSend(): bool
    {
        return self::isEnabled() && self::isReady() && self::hasDriver();
    }

    /** Whether the selected provider is one this application can talk to. */
    public static function hasDriver(): bool
    {
        return self::provider() === self::PROVIDER_INFOBIP;
    }

    /**
     * Whether a given template may be sent over SMS.
     *
     * Two gates, deliberately. This one is the channel decision made on the
     * Integration screen; the template's own is_active flag is the wording
     * decision made on Event > Settings. Either can hold a message back.
     */
    public static function allowsTemplate(string $templateKey): bool
    {
        foreach (self::ALERTS as $toggle => $keys) {
            if (in_array($templateKey, $keys, true)) {
                return self::get($toggle) === '1';
            }
        }

        // A template nobody assigned to an alert group is not sent, rather than
        // sent by default: adding a template should not silently start texting
        // people.
        return false;
    }

    public static function summary(): string
    {
        if (self::provider() === self::PROVIDER_NONE) {
            return 'No SMS gateway is configured.';
        }

        if (! self::hasDriver()) {
            return sprintf('%s is selected, but this application has no driver for it, so nothing can be sent.', self::providerLabel());
        }

        if (! self::isReady()) {
            return sprintf('%s is selected but its credentials are incomplete.', self::providerLabel());
        }

        if (! self::isEnabled()) {
            return sprintf('%s is configured but the channel is switched off.', self::providerLabel());
        }

        $enabled = collect(self::ALERTS)
            ->keys()
            ->filter(fn (string $toggle) => self::get($toggle) === '1')
            ->count();

        return $enabled === 0
            ? sprintf('%s is ready, but no alerts are switched on.', self::providerLabel())
            : sprintf('%s is ready, sending %d of %d alert types.', self::providerLabel(), $enabled, count(self::ALERTS));
    }
}
