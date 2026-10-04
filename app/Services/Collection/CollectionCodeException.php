<?php

namespace App\Services\Collection;

use App\Support\LocalTime;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * A code was not issued, and it was this application's decision rather than the
 * gateway's.
 *
 * Separate from MessagingException, which means the gateway refused or could not be
 * reached. The two read differently to the person at the counter: one says "wait",
 * the other says "the SMS route is not working, use the override".
 *
 * Carries a public message like MessagingException does, for the same reason, and
 * carries no code — nothing in this class or its message ever quotes one.
 */
class CollectionCodeException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $publicMessage,
        public readonly ?Carbon $retryAt = null,
    ) {
        parent::__construct($message);
    }

    public static function cooldown(Carbon $retryAt, int $minutes): self
    {
        return new self(
            sprintf('A code was sent less than %d minutes ago.', $minutes),
            sprintf(
                'A code was already texted for this collection. Another can be sent from %s. Use the override below if the first one never arrived.',
                LocalTime::format($retryAt, 'g:i a'),
            ),
            $retryAt,
        );
    }

    public static function numberFlooded(int $limit, int $minutes): self
    {
        return new self(
            sprintf('More than %d codes went to this number inside %d minutes.', $limit, $minutes),
            sprintf(
                'This telephone number has had %d codes in the last %d minutes, which is as many as it may have. Wait, or use the override below.',
                $limit,
                $minutes,
            ),
        );
    }
}
