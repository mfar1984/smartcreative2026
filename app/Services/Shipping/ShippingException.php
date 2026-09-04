<?php

namespace App\Services\Shipping;

use RuntimeException;

/**
 * EasyParcel could not be reached, or refused what it was asked.
 *
 * Carries two messages for the same reason MessagingException does: the courier's
 * own response can quote a client id, a token fragment or an internal host, none
 * of which belong on a page. The technical message goes to the log and to the
 * administrator who pressed Connect, who is the person trying to diagnose it. The
 * public one is what a shop buyer would ever see.
 */
class ShippingException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $publicMessage = 'Postage could not be quoted just now.',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function notConfigured(): self
    {
        return new self(
            'EasyParcel has no Client ID and Client Secret saved, so there is nothing to authenticate as.',
            'Shipping is not set up yet.',
        );
    }

    public static function notConnected(): self
    {
        return new self(
            'No EasyParcel account has been authorised, so there is no access token to send.',
            'Shipping is not connected yet.',
        );
    }

    /**
     * The refresh token is gone or expired, so nothing can be renewed without a
     * person authorising again. Separated from notConnected() because the remedy
     * differs: this one cannot be recovered from in the background.
     */
    public static function reauthorisationRequired(): self
    {
        return new self(
            'The EasyParcel refresh token is missing or expired, so a new access token cannot be obtained without authorising again.',
            'Shipping needs to be reconnected.',
        );
    }

    public static function unreachable(string $detail, ?\Throwable $previous = null): self
    {
        return new self(
            'Could not reach EasyParcel: ' . $detail,
            'The courier service could not be reached.',
            $previous,
        );
    }

    /**
     * A refusal that came back with a status. The body is included because it is
     * the only thing that makes a misconfiguration diagnosable, and it is shown
     * only to an administrator.
     */
    public static function refused(int $status, string $body): self
    {
        return new self(
            sprintf('EasyParcel returned HTTP %d: %s', $status, $body === '' ? 'no body' : $body),
            'The courier service refused the request.',
        );
    }

    public static function malformed(string $detail): self
    {
        return new self(
            'EasyParcel replied in a shape this application does not recognise: ' . $detail,
            'The courier service gave an unexpected answer.',
        );
    }
}
