<?php

namespace App\Services\Shipping;

use App\Support\ShippingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The OAuth half of the EasyParcel Next Gen integration.
 *
 * Next Gen supports the authorization code grant only, so a person has to sign in
 * to EasyParcel once and allow this application access. What comes back is an
 * access token good for ten hours and a refresh token good for about a year, and
 * everything after that is this class keeping the pair current.
 *
 * There is no sandbox switch anywhere here on purpose. The environment is decided
 * by which EasyParcel account is used at the login step, not by the endpoint and
 * not by the application, so the same Client ID reaches sandbox or live depending
 * only on who authorised it. Testing means connecting the sandbox account; going
 * live means disconnecting and connecting the live one.
 *
 * Reference: https://easyparcel.github.io/OpenAPI
 */
class EasyParcelOAuth
{
    private const TIMEOUT_SECONDS = 15;

    /** Where the state is parked between the redirect out and the callback back. */
    public const STATE_SESSION_KEY = 'easyparcel.oauth.state';

    /* ---------------------------------------------------------------------
     | Sending a person out to authorise
     * ------------------------------------------------------------------ */

    /**
     * A fresh state value to hand to the authorisation URL and hold in the session.
     *
     * Returned rather than generated inside authorizeUrl() so the caller stores the
     * same value it sends. Generating it in two places would guarantee a mismatch.
     */
    public function freshState(): string
    {
        return Str::random(40);
    }

    /**
     * Where to send the administrator to sign in and grant access.
     *
     * The redirect_uri has to match one of the Allowed Redirect URIs registered on
     * the application in the EasyParcel developer hub, character for character.
     */
    public function authorizeUrl(string $redirectUri, string $state): string
    {
        if (! ShippingSettings::hasApplication()) {
            throw ShippingException::notConfigured();
        }

        return ShippingSettings::authorizeUrl() . '?' . http_build_query([
            'client_id' => ShippingSettings::clientId(),
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);
    }

    /* ---------------------------------------------------------------------
     | Turning what comes back into a stored connection
     * ------------------------------------------------------------------ */

    /**
     * Exchange the authorisation code for a token pair and store it.
     *
     * @return array<string, mixed> the token payload, for logging the account back
     */
    public function exchangeCode(string $code, string $redirectUri): array
    {
        $payload = $this->requestToken([
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]);

        $this->store($payload);

        return $payload;
    }

    /**
     * Trade the refresh token for a new access token.
     *
     * @return array<string, mixed>
     */
    public function refresh(): array
    {
        $refreshToken = ShippingSettings::refreshToken();

        if (blank($refreshToken) || ! ShippingSettings::isConnected()) {
            throw ShippingException::reauthorisationRequired();
        }

        $payload = $this->requestToken([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        $this->store($payload);

        return $payload;
    }

    /**
     * A usable access token, refreshing first when the stored one is spent.
     *
     * This is what the quotation client should ask for rather than reading the
     * setting directly, so a ten hour old token cannot silently produce a failed
     * checkout.
     */
    public function accessToken(): string
    {
        if (! ShippingSettings::hasApplication()) {
            throw ShippingException::notConfigured();
        }

        if (! ShippingSettings::isConnected()) {
            throw ShippingException::notConnected();
        }

        if (ShippingSettings::accessTokenNeedsRefresh()) {
            $this->refresh();
        }

        $token = ShippingSettings::accessToken();

        if (blank($token)) {
            throw ShippingException::notConnected();
        }

        return $token;
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    /**
     * POST to the token endpoint and return the decoded body.
     *
     * Sent form encoded, which is what RFC 6749 specifies for this endpoint; the
     * EasyParcel documentation lists the parameters without naming an encoding.
     * If that assumption is ever wrong the refusal arrives as an HTTP status with a
     * body, which refused() puts in front of the administrator rather than
     * swallowing.
     *
     * @param  array<string, string>  $params
     * @return array<string, mixed>
     */
    private function requestToken(array $params): array
    {
        $basic = ShippingSettings::basicAuthorization();

        if ($basic === null) {
            throw ShippingException::notConfigured();
        }

        try {
            $response = Http::asForm()
                ->withHeaders([
                    // Documented as base64 of client_id:client_secret. Built by
                    // ShippingSettings so the secret is read in one place.
                    'Authorization' => 'Basic ' . $basic,
                    'Accept' => 'application/json',
                ])
                ->timeout(self::TIMEOUT_SECONDS)
                ->post(ShippingSettings::tokenUrl(), $params);
        } catch (ConnectionException $e) {
            throw ShippingException::unreachable($e->getMessage(), $e);
        }

        if ($response->failed()) {
            /*
             | The grant type is logged but never the code, the refresh token or the
             | Authorization header. A token in a log file is a credential sitting in
             | plain text for as long as the log is kept.
             */
            Log::warning('EasyParcel token request failed.', [
                'grant_type' => $params['grant_type'] ?? null,
                'status' => $response->status(),
            ]);

            throw ShippingException::refused($response->status(), Str::limit((string) $response->body(), 400));
        }

        $payload = $response->json();

        if (! is_array($payload) || blank($payload['access_token'] ?? null)) {
            throw ShippingException::malformed('no access_token in the response body');
        }

        return $payload;
    }

    /**
     * Persist a token payload.
     *
     * The absolute expiry is preferred over the lifetime in seconds where both are
     * given, because the lifetime is relative to a clock on their side and this
     * server's clock may not agree with it. Where neither can be read, the expiry
     * is stored as null, which ShippingSettings treats as due for refresh.
     *
     * @param  array<string, mixed>  $payload
     */
    private function store(array $payload): void
    {
        ShippingSettings::storeTokens(
            accessToken: (string) $payload['access_token'],
            refreshToken: is_string($payload['refresh_token'] ?? null) ? $payload['refresh_token'] : null,
            accessExpiresAt: $this->expiry($payload, 'expires_at', 'expires_in'),
            refreshExpiresAt: $this->expiry($payload, 'refresh_token_expires_at', 'refresh_token_expires_in'),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function expiry(array $payload, string $absoluteKey, string $lifetimeKey): ?CarbonImmutable
    {
        $absolute = $payload[$absoluteKey] ?? null;

        if (is_string($absolute) && $absolute !== '') {
            try {
                return CarbonImmutable::parse($absolute);
            } catch (Throwable) {
                // Falls through to the lifetime below rather than failing the whole
                // exchange over a date this application could not read.
            }
        }

        $lifetime = $payload[$lifetimeKey] ?? null;

        return is_numeric($lifetime) && (int) $lifetime > 0
            ? CarbonImmutable::now()->addSeconds((int) $lifetime)
            : null;
    }
}
