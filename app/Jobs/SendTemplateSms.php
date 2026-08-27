<?php

namespace App\Jobs;

use App\Models\EventNotification;
use App\Services\Messaging\InfobipGateway;
use App\Services\Messaging\MessagingException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One text message produced from an editable template.
 *
 * Queued for the same reason the email is: a squad of eight means several HTTP
 * round trips to Infobip, and the person submitting the form should not wait on
 * them.
 *
 * The body arrives already rendered, so an operator editing the template
 * afterwards cannot change what an already queued message says.
 */
class SendTemplateSms implements ShouldQueue
{
    use Queueable;

    /**
     * Three attempts, then leave it in failed_jobs. A gateway refusing a number
     * will refuse it every time, so hammering it helps nobody.
     */
    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(
        public string $destination,
        public string $body,
        public ?int $notificationId = null,
    ) {
    }

    public function handle(InfobipGateway $gateway): void
    {
        $result = $gateway->send($this->destination, $this->body);

        if ($this->notificationId === null) {
            return;
        }

        // Accepted by the gateway, not confirmed on a handset. The message id is
        // kept so the delivery report can find this row later and say which of the
        // two actually happened.
        EventNotification::whereKey($this->notificationId)->update([
            'status' => EventNotification::STATUS_SENT,
            'sent_at' => now(),
            'provider_message_id' => $result->messageId,
            'reason' => $result->summary(),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $reason = $exception instanceof MessagingException
            ? $exception->getMessage()
            : ($exception?->getMessage() ?? 'Unknown failure.');

        Log::warning('Template SMS could not be delivered.', [
            'notification' => $this->notificationId,
            'destination' => $this->destination,
            'error' => $reason,
        ]);

        if ($this->notificationId === null) {
            return;
        }

        EventNotification::whereKey($this->notificationId)->update([
            'status' => EventNotification::STATUS_FAILED,
            'reason' => $reason,
        ]);
    }
}
