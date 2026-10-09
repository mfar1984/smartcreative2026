<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells one account that it was just signed in to from an address never seen
 * before, and what to do if that was not them.
 *
 * Queued, because it is sent in the middle of somebody signing in. Signing in
 * must not wait on SMTP, and it must not fail because a mail server is down:
 * LoginLocationService also wraps the dispatch, so a queue that cannot be
 * reached is logged and the sign in carries on.
 *
 * Plain on purpose. No banner, no buttons, no marketing: this is the kind of
 * message somebody reads once, quickly, while deciding whether to worry.
 */
class NewSignInLocation extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  string  $signedInAt  Already formatted on the office clock, because
     *                              the queue worker renders this and has no idea
     *                              which timezone the request came from.
     */
    public function __construct(
        public User $user,
        public string $ipAddress,
        public string $signedInAt,
        public ?string $userAgent = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            // Says what happened in the subject line itself, so it is useful even
            // on a locked phone screen where only the subject is visible.
            subject: 'New sign-in to your ' . config('app.name') . ' admin account',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.new-sign-in-location',
        );
    }
}
