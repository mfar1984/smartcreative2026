<?php

namespace App\Mail;

use App\Models\EventParticipant;
use App\Models\PlayerMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Somebody has written to a competitor through the public website.
 *
 * Goes to the office and only to the office. This is the one place the competitor's
 * full record and the sender's message sit side by side, which is the point: whoever
 * reads it has to be able to tell who is being asked after before deciding whether to
 * pass anything on.
 *
 * replyTo is the sender, so hitting reply answers the person who wrote in rather than
 * the competitor they wrote about.
 */
class PlayerEnquiryReceived extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public PlayerMessage $playerMessage,
        public EventParticipant $participant,
        public string $publicLabel,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Message for a competitor: ' . $this->publicLabel,
            replyTo: [
                new Address($this->playerMessage->email, $this->playerMessage->name),
            ],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.player-enquiry',
        );
    }
}
