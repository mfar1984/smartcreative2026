<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Config;

/**
 * The SMTP profile saved on the Integration screen.
 *
 * Until this class existed those settings were written to the database and then
 * ignored: mail went out using whatever was in .env, so an administrator could
 * change the host on screen and see no effect. apply() closes that gap.
 *
 * Anything left blank falls through to the .env value rather than blanking the
 * configuration, so a half filled profile cannot break sending.
 */
class MailSettings
{
    private const GROUP = 'integration.email';

    /** Mailers the settings screen offers. */
    private const ALLOWED_MAILERS = ['smtp', 'log', 'sendmail'];

    /**
     * Cached for the life of the request. Mail may be sent more than once in a
     * single run, and the profile cannot change midway.
     *
     * @var array<string, string|null>|null
     */
    private static ?array $cache = null;

    /**
     * @return array<string, string|null>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $values = [];

        foreach (Setting::readGroup(self::GROUP) as $key => $value) {
            // Stored keys are fully qualified, so the group prefix comes off.
            $values[str_replace(self::GROUP . '.', '', $key)] = $value;
        }

        return self::$cache = $values;
    }

    public static function get(string $key): ?string
    {
        $value = self::all()[$key] ?? null;

        return $value === '' ? null : $value;
    }

    /** Whether anything has been saved on the Integration screen at all. */
    public static function isConfigured(): bool
    {
        return collect(self::all())->filter(fn ($value) => filled($value))->isNotEmpty();
    }

    public static function mailer(): ?string
    {
        $mailer = self::get('mailer');

        return in_array($mailer, self::ALLOWED_MAILERS, true) ? $mailer : null;
    }

    /**
     * Push the saved profile into the live mail configuration.
     *
     * Called once, when the mailer is first resolved, so a request that sends no
     * mail never pays for the query.
     */
    public static function apply(): void
    {
        if (! self::isConfigured()) {
            return;
        }

        $mailer = self::mailer();

        if ($mailer !== null) {
            Config::set('mail.default', $mailer);
        }

        // The transport specific keys only make sense for SMTP.
        if (($mailer ?? config('mail.default')) === 'smtp') {
            self::setIfPresent('mail.mailers.smtp.host', self::get('host'));
            self::setIfPresent('mail.mailers.smtp.port', self::get('port'));
            self::setIfPresent('mail.mailers.smtp.username', self::get('username'));
            self::setIfPresent('mail.mailers.smtp.password', self::get('password'));
            self::setIfPresent('mail.mailers.smtp.scheme', self::scheme());
        }

        self::setIfPresent('mail.from.address', self::get('from_address'));
        self::setIfPresent('mail.from.name', self::get('from_name'));
    }

    /**
     * Laravel 12 configures SMTP transport security through 'scheme' rather than
     * the older 'encryption' key, so the stored choice is translated.
     *
     * smtps means TLS from the first byte, which is what port 465 expects.
     * Anything else is plain smtp, where STARTTLS is negotiated if the server
     * offers it.
     */
    public static function scheme(): ?string
    {
        return match (self::get('encryption')) {
            'smtps' => 'smtps',
            'tls', 'none' => 'smtp',
            default => null,
        };
    }

    /**
     * What mail will actually go out as, after apply() has run.
     *
     * @return array<string, string|null>
     */
    public static function effective(): array
    {
        $mailer = config('mail.default');

        return [
            'Mailer' => $mailer,
            'Scheme' => config("mail.mailers.{$mailer}.scheme"),
            'Host' => config("mail.mailers.{$mailer}.host"),
            'Port' => (string) config("mail.mailers.{$mailer}.port"),
            'Username' => config("mail.mailers.{$mailer}.username"),
            'From Address' => config('mail.from.address'),
            'From Name' => config('mail.from.name'),
        ];
    }

    /** Forget the cached profile, so a save takes effect without a new request. */
    public static function flush(): void
    {
        self::$cache = null;
    }

    private static function setIfPresent(string $key, ?string $value): void
    {
        if (filled($value)) {
            Config::set($key, $value);
        }
    }
}
