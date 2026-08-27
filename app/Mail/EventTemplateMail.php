<?php

namespace App\Mail;

use App\Models\EventNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * One message produced from an editable template.
 *
 * The subject and body arrive already rendered, so this class does no
 * substitution: the wording was settled before it was queued, which means an
 * organiser editing a template cannot change what an already queued message
 * says.
 *
 * ShouldQueue because a squad of eight means nine messages, and the person
 * submitting the form should not wait on nine SMTP round trips.
 */
class EventTemplateMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Give up after three tries rather than hammering a mail server that is
     * refusing, and leave the job in failed_jobs where it can be seen.
     */
    public int $tries = 3;

    /** Wait longer between each retry, in case the server is briefly busy. */
    public array $backoff = [60, 300];

    public function __construct(
        public string $renderedSubject,
        public string $renderedBody,
        public ?int $notificationId = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->renderedSubject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.event-template',
            with: ['body' => $this->renderedBody],
        );
    }

    /**
     * Mark the record as sent once the transport has accepted it.
     *
     * Runs inside the queue worker, which is the only place that knows the send
     * actually happened.
     */
    public function build(): self
    {
        return $this->withSymfonyMessage(function () {
            if ($this->notificationId === null) {
                return;
            }

            EventNotification::whereKey($this->notificationId)->update([
                'status' => EventNotification::STATUS_SENT,
                'sent_at' => now(),
            ]);
        });
    }

    /**
     * Record a failure so it shows on the registration rather than only in
     * failed_jobs, where nobody would look.
     */
    public function failed(\Throwable $exception): void
    {
        Log::warning('Event notification could not be delivered.', [
            'notification' => $this->notificationId,
            'subject' => $this->renderedSubject,
            'error' => $exception->getMessage(),
        ]);

        if ($this->notificationId === null) {
            return;
        }

        EventNotification::whereKey($this->notificationId)->update([
            'status' => EventNotification::STATUS_FAILED,
            'reason' => $exception->getMessage(),
        ]);
    }
}
