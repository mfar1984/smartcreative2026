<?php

namespace App\Services\Messaging;

use App\Support\TelegramSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Posts staff alerts into one Telegram group.
 *
 * Every method that fires from a real user action swallows its failure and logs
 * it. An office notification is worth less than the thing it is reporting: a
 * registration must not be lost because a bot token expired.
 *
 * Reference: https://core.telegram.org/bots/api#sendmessage
 */
class TelegramNotifier
{
    private const BASE_URL = 'https://api.telegram.org';

    private const TIMEOUT_SECONDS = 10;

    /** Telegram refuses anything longer. */
    private const MAX_LENGTH = 4096;

    /**
     * Post a message, swallowing any failure.
     *
     * @param  string|null  $alert  the toggle that governs this message, or null to post regardless
     * @return bool  whether it went out
     */
    public function post(string $text, ?string $alert = null): bool
    {
        if ($alert !== null && ! TelegramSettings::alerts($alert)) {
            return false;
        }

        if ($alert === null && ! TelegramSettings::canSend()) {
            return false;
        }

        try {
            $this->send($text);

            return true;
        } catch (MessagingException $e) {
            Log::warning('Telegram alert could not be posted.', [
                'alert' => $alert,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Post a message and let failures through.
     *
     * Used by the Send Test button, where the whole point is seeing the error.
     */
    public function send(string $text): void
    {
        if (! TelegramSettings::isReady()) {
            throw MessagingException::notConfigured('Telegram');
        }

        $url = sprintf('%s/bot%s/sendMessage', self::BASE_URL, TelegramSettings::botToken());

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout(self::TIMEOUT_SECONDS)
                ->post($url, [
                    'chat_id' => TelegramSettings::chatId(),
                    'text' => \Illuminate\Support\Str::limit($text, self::MAX_LENGTH - 3),
                    // HTML rather than Markdown: the alerts carry team names and
                    // references full of underscores and asterisks, which
                    // Markdown would treat as formatting and mangle.
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ]);
        } catch (ConnectionException $e) {
            throw new MessagingException(
                'Could not reach Telegram: ' . $e->getMessage(),
                'Telegram could not be reached.',
                $e,
            );
        }

        if ($response->failed() || $response->json('ok') !== true) {
            // The URL holds the bot token, so it is never logged. Telegram's own
            // description is safe and is the only useful part.
            $description = $response->json('description');

            Log::warning('Telegram sendMessage failed.', [
                'status' => $response->status(),
                'description' => is_string($description) ? $description : null,
                'chat_id' => TelegramSettings::chatId(),
            ]);

            throw new MessagingException(
                sprintf(
                    'Telegram returned HTTP %d: %s',
                    $response->status(),
                    is_string($description) ? $description : 'no description given',
                ),
                'Telegram refused the message.',
            );
        }
    }

    /**
     * Confirm the token works and report who the bot is.
     *
     * getMe touches no chat, so it separates "the token is wrong" from "the bot
     * is not in that group", which are the two things that actually go wrong and
     * which a single failed send cannot tell apart.
     *
     * @return array{username: string|null, name: string|null}
     */
    public function checkBot(): array
    {
        if (blank(TelegramSettings::botToken())) {
            throw MessagingException::notConfigured('Telegram');
        }

        $url = sprintf('%s/bot%s/getMe', self::BASE_URL, TelegramSettings::botToken());

        try {
            $response = Http::acceptJson()->timeout(self::TIMEOUT_SECONDS)->get($url);
        } catch (ConnectionException $e) {
            throw new MessagingException(
                'Could not reach Telegram: ' . $e->getMessage(),
                'Telegram could not be reached.',
                $e,
            );
        }

        if ($response->failed() || $response->json('ok') !== true) {
            throw new MessagingException(
                sprintf('Telegram rejected the bot token: %s', $response->json('description') ?? 'no description given'),
                'The bot token was refused.',
            );
        }

        return [
            'username' => $response->json('result.username'),
            'name' => $response->json('result.first_name'),
        ];
    }
}
