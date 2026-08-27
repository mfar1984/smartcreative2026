<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Proof that the saved SMTP profile can actually deliver.
 *
 * The body repeats the settings it went out with, so a recipient can tell which
 * profile produced it. Without that, a test that arrives says only "something
 * works", which is not the question being asked.
 */
class TestEmail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, string|null>  $profile  the live mail config
     */
    public function __construct(
        public array $profile,
        public string $sentBy,
        public string $siteName,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            // Timestamped so a second test is obviously a second test, rather
            // than looking like the first one still sitting in the inbox.
            subject: sprintf('Test email from %s at %s', $this->siteName, now()->format('d M Y, g:i:s a')),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.test',
        );
    }
}
