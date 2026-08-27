<?php

namespace App\Services\Messaging;

/**
 * What the gateway said about one message it accepted.
 *
 * Accepted is not delivered. Infobip queues the message and reports the real
 * outcome later through a delivery report, so this records the handover and
 * nothing stronger. Anything that claims delivery from this object would be
 * claiming more than is known.
 */
class SmsResult
{
    public function __construct(
        public readonly string $destination,
        public readonly ?string $messageId,
        public readonly string $statusGroup,
        public readonly string $description,
    ) {
    }

    /** A one line summary for a log entry or a flash message. */
    public function summary(): string
    {
        return sprintf(
            '%s: %s (%s)',
            $this->destination,
            $this->description,
            $this->statusGroup,
        );
    }
}
