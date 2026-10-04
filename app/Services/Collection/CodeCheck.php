<?php

namespace App\Services\Collection;

use App\Models\CollectionVerification;

/**
 * The outcome of entering a code.
 *
 * A value object rather than an exception, because a wrong code is an ordinary
 * thing that happens at a counter and not a fault. Only the service constructs one.
 *
 * Every sentence this returns is safe to show and safe to log: none of them quotes
 * the code that was submitted, or the one that was expected.
 */
class CodeCheck
{
    public const VERIFIED = 'verified';

    /** Nothing live for this collection: never sent, already used, or replaced. */
    public const NONE = 'none';

    public const EXPIRED = 'expired';

    /** Wrong, and that was the last try. The code is now dead. */
    public const BURNED = 'burned';

    /** Wrong, with tries left. */
    public const WRONG = 'wrong';

    public function __construct(
        public readonly string $outcome,
        public readonly ?CollectionVerification $verification = null,
        public readonly int $attemptsLeft = 0,
    ) {
    }

    public function isVerified(): bool
    {
        return $this->outcome === self::VERIFIED;
    }

    /**
     * Why it was refused, in words a counter can act on.
     */
    public function reason(): string
    {
        return match ($this->outcome) {
            self::VERIFIED => 'The code was accepted.',

            self::NONE => 'There is no code waiting for this collection. Send one first, or use the override if SMS is not getting through.',

            self::EXPIRED => 'That code has expired. Send a new one.',

            self::BURNED => 'That was wrong, and it was the last try, so the code is now dead. Send a new one.',

            self::WRONG => sprintf(
                'That code is wrong. %s left before it stops working.',
                $this->attemptsLeft === 1 ? '1 try' : $this->attemptsLeft . ' tries',
            ),

            default => 'The code could not be checked.',
        };
    }
}
