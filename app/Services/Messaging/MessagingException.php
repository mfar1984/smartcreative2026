<?php

namespace App\Services\Messaging;

use RuntimeException;

/**
 * A message could not be handed to its gateway.
 *
 * Carries two messages for the same reason the payment one does: a gateway's own
 * response can quote an API key fragment, an account id or a base URL, none of
 * which belong on a page. The public message is deliberately vague; the real one
 * goes to the log and to the operator pressing Send Test, who is the person
 * trying to diagnose it.
 */
class MessagingException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $publicMessage = 'The message could not be sent. Please try again in a moment.',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function notConfigured(string $label): self
    {
        return new self(
            sprintf('%s is not configured.', $label),
            'Messaging is not set up yet.',
        );
    }

    public static function noDriver(string $label): self
    {
        return new self(
            sprintf('%s is selected but this application has no driver for it.', $label),
            'This messaging provider is not supported yet.',
        );
    }

    public static function unusableNumber(string $raw): self
    {
        return new self(
            sprintf('"%s" could not be read as a telephone number.', $raw),
            'That telephone number could not be used.',
        );
    }
}
