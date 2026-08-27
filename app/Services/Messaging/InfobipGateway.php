<?php

namespace App\Services\Messaging;

use App\Support\PhoneNumber;
use App\Support\SmsSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Infobip SMS.
 *
 * Each account gets its own host, so the base URL is a setting rather than a
 * constant. Authentication is Infobip's own "App {key}" scheme, which is why
 * Http::withToken() is not used: that helper hardcodes the Bearer prefix.
 *
 * Reference: https://www.infobip.com/docs (SMS, API authentication)
 */
class InfobipGateway
{
    /**
     * The long-standing send endpoint.
     *
     * Chosen over the newer /sms/3/messages because it is available on every
     * account including trials, and its response shape has been stable for
     * years. The request body differs between the two, so moving to v3 means
     * changing payload() and readOutcome() together.
     */
    private const SEND_PATH = '/sms/2/text/advanced';

    /** Read only. Used to prove credentials without sending anything. */
    private const BALANCE_PATH = '/account/1/balance';

    private const TIMEOUT_SECONDS = 20;

    public function label(): string
    {
        return SmsSettings::PROVIDERS[SmsSettings::PROVIDER_INFOBIP];
    }

    public function isConfigured(): bool
    {
        return filled(SmsSettings::baseUrl())
            && filled(SmsSettings::apiKey())
            && filled(SmsSettings::senderId());
    }

    /**
     * Send one message, and report what Infobip said about it.
     *
     * Throws rather than returning false: every caller either wants to record
     * the failure against a notification row or show it to an operator, and both
     * need the reason.
     */
    public function send(string $rawNumber, string $text): SmsResult
    {
        if (! $this->isConfigured()) {
            throw MessagingException::notConfigured($this->label());
        }

        $destination = PhoneNumber::toInternational($rawNumber);

        if ($destination === null) {
            throw MessagingException::unusableNumber($rawNumber);
        }

        $message = [
            'from' => SmsSettings::senderId(),
            'destinations' => [['to' => $destination]],
            'text' => $text,
        ];

        /*
        | Where Infobip should report the real outcome. Set per message rather
        | than configured once in their portal, so an installation works as soon
        | as the credentials are in and nothing else has to be set up by hand.
        |
        | Only added when the address is reachable from outside. A local URL would
        | have Infobip retry against something it can never talk to, and the
        | retries are wasted effort on both sides.
        */
        $notifyUrl = $this->notifyUrl();

        if ($notifyUrl !== null) {
            $message['notifyUrl'] = $notifyUrl;
            $message['notifyContentType'] = 'application/json';
        }

        $response = $this->call('post', self::SEND_PATH, ['messages' => [$message]]);

        return $this->readOutcome($response, $destination);
    }

    /**
     * The delivery report address, or null when it could not be reached.
     *
     * A hostname without a dot is a development machine, and Infobip cannot post
     * to it. Skipping it there keeps the local logs clean of failures that mean
     * nothing.
     */
    private function notifyUrl(): ?string
    {
        $url = SmsSettings::deliveryReportUrl();
        $host = parse_url($url, PHP_URL_HOST) ?: '';

        if (! str_contains($host, '.') || str_ends_with($host, '.localhost') || $host === '127.0.0.1') {
            return null;
        }

        return $url;
    }

    /**
     * Ask Infobip for the account balance.
     *
     * Exists so credentials can be proved without spending money or texting a
     * stranger: a wrong base URL, a wrong key or a wrong auth header all fail
     * here exactly as they would on a send.
     *
     * @return array{balance: float|null, currency: string|null}
     */
    public function checkCredentials(): array
    {
        if (blank(SmsSettings::baseUrl()) || blank(SmsSettings::apiKey())) {
            throw MessagingException::notConfigured($this->label());
        }

        $response = $this->call('get', self::BALANCE_PATH);

        return [
            'balance' => is_numeric($response->json('balance')) ? (float) $response->json('balance') : null,
            'currency' => is_string($response->json('currency')) ? $response->json('currency') : null,
        ];
    }

    /* ---------------------------------------------------------------------
     | Transport
     * ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $payload
     */
    private function call(string $method, string $path, array $payload = []): \Illuminate\Http\Client\Response
    {
        $url = SmsSettings::baseUrl() . $path;

        try {
            $request = Http::withHeaders([
                // Infobip's scheme, not Bearer. withToken() would send
                // "Bearer <key>" and every request would come back 401.
                'Authorization' => 'App ' . SmsSettings::apiKey(),
            ])
                ->acceptJson()
                ->asJson()
                ->timeout(self::TIMEOUT_SECONDS);

            $response = $method === 'get'
                ? $request->get($url)
                : $request->post($url, $payload);
        } catch (ConnectionException $e) {
            throw new MessagingException(
                'Could not reach Infobip: ' . $e->getMessage(),
                'The SMS gateway could not be reached.',
                $e,
            );
        }

        if ($response->failed()) {
            // Logged rather than shown: the body quotes the account and can echo
            // back part of the request.
            Log::warning('Infobip request failed.', [
                'path' => $path,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new MessagingException(
                sprintf('Infobip returned HTTP %d: %s', $response->status(), $this->reason($response)),
                'The SMS gateway refused the request.',
            );
        }

        return $response;
    }

    /**
     * Infobip accepts a request and reports per message status inside it, so a
     * 200 does not by itself mean the message was taken.
     */
    private function readOutcome(\Illuminate\Http\Client\Response $response, string $destination): SmsResult
    {
        $message = $response->json('messages.0');

        if (! is_array($message)) {
            throw new MessagingException(
                'Infobip accepted the request but described no message.',
                'The SMS gateway gave an unexpected answer.',
            );
        }

        $group = (string) data_get($message, 'status.groupName', '');
        $description = (string) data_get($message, 'status.description', 'No description given.');
        $messageId = data_get($message, 'messageId');

        // PENDING is the normal answer: the message is queued at Infobip and a
        // delivery report follows later. REJECTED is a refusal to even try.
        if (in_array($group, ['REJECTED', 'UNDELIVERABLE'], true)) {
            Log::warning('Infobip rejected a message.', [
                'destination' => $destination,
                'group' => $group,
                'description' => $description,
            ]);

            throw new MessagingException(
                sprintf('Infobip rejected the message: %s', $description),
                'The SMS gateway would not accept that message.',
            );
        }

        return new SmsResult(
            destination: $destination,
            messageId: is_string($messageId) ? $messageId : null,
            statusGroup: $group !== '' ? $group : 'UNKNOWN',
            description: $description,
        );
    }

    /**
     * The most useful sentence out of an error body, for the operator running a
     * test. Falls back to the raw body when the shape is unfamiliar.
     */
    private function reason(\Illuminate\Http\Client\Response $response): string
    {
        $text = $response->json('requestError.serviceException.text')
            ?? $response->json('requestError.serviceException.messageId')
            ?? null;

        if (is_string($text) && $text !== '') {
            return $text;
        }

        return \Illuminate\Support\Str::limit($response->body(), 300);
    }
}
