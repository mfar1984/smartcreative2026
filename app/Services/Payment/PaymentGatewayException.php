<?php

namespace App\Services\Payment;

use RuntimeException;

/**
 * A gateway could not be used.
 *
 * Carries two messages: one safe to show a visitor, and the detail worth
 * logging. Gateway responses can quote credentials or internal ids, so they are
 * never rendered straight onto a public page.
 */
class PaymentGatewayException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $publicMessage = 'The payment service could not be reached. Please try again in a moment.',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function publicMessage(): string
    {
        return $this->publicMessage;
    }

    public static function notConfigured(string $label): self
    {
        return new self(
            sprintf('%s is selected but its credentials are incomplete.', $label),
            'Online payment is not switched on yet. Please contact the organiser to settle the fee.',
        );
    }

    public static function unsupported(string $label): self
    {
        return new self(
            sprintf('No driver is implemented for %s.', $label),
            'Online payment is not available for this event yet. Please contact the organiser to settle the fee.',
        );
    }
}
