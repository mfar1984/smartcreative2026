<?php

namespace App\Support;

use App\Models\Setting;

/**
 * The Telegram bot profile saved on the Integration screen.
 *
 * Telegram here is a staff channel, not a customer one. There is a single group
 * id, so every alert lands in the same room: the office watching activity as it
 * happens. Participants never see any of it, which is why the wording of these
 * messages is fixed in code rather than editable alongside the participant
 * templates. Nobody outside the organisation reads them.
 */
class TelegramSettings
{
    /**
     * Alert toggle => what it covers.
     *
     * Listed so the summary can count them without repeating the key names.
     *
     * @var array<string, string>
     */
    public const ALERTS = [
        'notify_enquiry' => 'Contact enquiries',
        'notify_registration' => 'Registrations',
        'notify_payment' => 'Payments received',
        'notify_attendance' => 'Counter changes',
    ];

    private const GROUP = 'integration.telegram';

    public static function get(string $key, ?string $default = null): ?string
    {
        return Setting::read(self::GROUP . '.' . $key, $default);
    }

    public static function isEnabled(): bool
    {
        return self::get('enabled') === '1';
    }

    public static function botToken(): ?string
    {
        return self::get('bot_token');
    }

    public static function botUsername(): ?string
    {
        return self::get('bot_username');
    }

    public static function chatId(): ?string
    {
        return self::get('chat_id');
    }

    /** Whether the bot could be reached: token and destination both present. */
    public static function isReady(): bool
    {
        return filled(self::botToken()) && filled(self::chatId());
    }

    /** Whether anything would actually be posted right now. */
    public static function canSend(): bool
    {
        return self::isEnabled() && self::isReady();
    }

    /**
     * Whether one particular alert is switched on.
     *
     * The master switch is checked too, so a caller only has to ask this one
     * question rather than remembering to check both.
     */
    public static function alerts(string $toggle): bool
    {
        return self::canSend() && self::get($toggle) === '1';
    }

    public static function summary(): string
    {
        if (! self::isReady()) {
            return 'No Telegram bot is configured.';
        }

        if (! self::isEnabled()) {
            return 'The bot is configured but alerts are switched off.';
        }

        $on = collect(self::ALERTS)
            ->keys()
            ->filter(fn (string $toggle) => self::get($toggle) === '1')
            ->count();

        return $on === 0
            ? 'The bot is ready, but no alerts are switched on.'
            : sprintf('Posting %d of %d alert types.', $on, count(self::ALERTS));
    }
}
