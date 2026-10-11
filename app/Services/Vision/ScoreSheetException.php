<?php

namespace App\Services\Vision;

use RuntimeException;

/**
 * A screenshot could not be turned into a result.
 *
 * Two messages, the same way the messaging and payment gateways do it: a reader's
 * own response quotes the model id, the endpoint and sometimes part of the request,
 * none of which belongs on a page. The real one goes to the log; the public one
 * tells the operator what to do instead, which is nearly always "type it in".
 */
class ScoreSheetException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $publicMessage = 'The screenshot could not be read. Type the figures in instead.',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function notConfigured(): self
    {
        return new self(
            'No screenshot reader credentials are configured.',
            'The screenshot reader is not set up yet. Add GEMINI_API_KEY to the server and try again.',
        );
    }

    public static function noModel(): self
    {
        return new self(
            'No screenshot reader model is configured.',
            'The screenshot reader has no model set. Set GEMINI_MODEL on the server and try again.',
        );
    }

    /**
     * Asked to read award cards for a profile that hands out no awards.
     *
     * The control is not drawn on such a fixture, but a POST does not need a button,
     * and there is nothing for a card to be put against.
     */
    public static function noAwards(): self
    {
        return new self(
            'The scoring profile defines no match awards.',
            'This scoring profile hands out no Star of the Match awards, so there is nothing to read a screenshot into. Add them to the profile first.',
        );
    }

    /**
     * The model answered, but not with what it was asked for.
     *
     * A schema was declared on the request, so this is a model that ignored it or an
     * answer cut off part way. Either way there is nothing to read, and guessing at
     * half an answer is how a figure nobody checked reaches a card.
     */
    public static function unusableAnswer(): self
    {
        return new self(
            'Gemini did not answer with what it was asked for.',
            'The screenshot reader gave an answer that could not be used. Try a clearer image, or type the figures in.',
        );
    }

    /**
     * Two rows claiming the same finishing position.
     *
     * The whole read is refused rather than the second row dropped. A table with
     * two sixteenths in it was misread somewhere, and which of the two is wrong is
     * not something to guess at when the answer feeds the standings.
     */
    public static function duplicatePlacement(int $placement, string $first, string $second): self
    {
        return new self(
            sprintf('Placement %d was read twice: "%s" and "%s".', $placement, $first, $second),
            sprintf(
                'The screenshot gives position %d to both "%s" and "%s", so it was misread. Nothing was filled in.',
                $placement,
                $first,
                $second,
            ),
        );
    }

}
