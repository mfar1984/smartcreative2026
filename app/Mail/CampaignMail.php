<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One campaign message, already rendered.
 *
 * Not queued itself: the job that dispatches it is. Queueing per message here as
 * well would put the same work on the queue twice, and the job needs to know the
 * outcome of the send so it can mark the recipient.
 */
class CampaignMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $renderedSubject,
        public string $renderedHtml,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->renderedSubject);
    }

    /**
     * The HTML is handed over already built, rather than through a view.
     *
     * Links have to be rewritten and the tracking pixel inserted per recipient,
     * which happens before this object exists. Passing a view would mean doing
     * that work twice.
     */
    public function build(): self
    {
        return $this->html($this->renderedHtml);
    }
}
