<?php

namespace App\Mail;

use App\Models\WifiCredential;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One competitor's Wi-Fi login, sent to them.
 *
 * Queued, because this goes out to a whole event at once. Two hundred SMTP round trips
 * inside one request would time out somewhere in the middle and leave nobody able to say
 * which half had been sent.
 *
 * Only ever sent after the router has a copy. An email carrying a login that does not work
 * yet is worse than no email: the competitor tries it, it fails, and from then on they
 * believe the password is wrong no matter how many times they are told otherwise.
 */
class WifiCredentialIssued extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public WifiCredential $credential,
        public string $eventTitle,
        public string $recipientName,
        public ?string $networkName = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            // Names the event rather than the site, because somebody who entered one
            // tournament months ago should be able to tell at a glance which day this is
            // for, and these are only useful on one day.
            subject: sprintf('Your Wi-Fi login for %s', $this->eventTitle),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.wifi-credential',
        );
    }
}
