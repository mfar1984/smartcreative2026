<?php

namespace App\Jobs;

use App\Mail\CampaignMail;
use App\Models\Campaign;
use App\Models\CampaignContact;
use App\Models\CampaignRecipient;
use App\Services\Campaign\CampaignRenderer;
use App\Services\Messaging\InfobipGateway;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Send one campaign message to one recipient.
 *
 * One job per recipient rather than one job for the whole campaign. A blast of
 * two thousand in a single job would time out halfway with no record of where it
 * stopped, and retrying it would send the first half twice. Per recipient, a
 * failure costs one message and the row says which.
 */
class SendCampaignMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /**
     * Deliberately low. A campaign is not urgent, and a recipient whose address
     * is wrong will be wrong on the third attempt too.
     */
    public array $backoff = [120];

    public function __construct(public int $recipientId)
    {
    }

    public function handle(CampaignRenderer $renderer, InfobipGateway $sms): void
    {
        $recipient = CampaignRecipient::with(['campaign', 'contact'])->find($this->recipientId);

        if ($recipient === null || $recipient->status !== CampaignRecipient::STATUS_QUEUED) {
            // Already handled, or the campaign was deleted underneath us.
            return;
        }

        $campaign = $recipient->campaign;

        if ($campaign === null) {
            return;
        }

        // Re-checked at the moment of sending, not only when the list was built.
        // Somebody who unsubscribed while the queue was draining must not receive
        // the message that was already waiting for them.
        if ($recipient->contact?->isSuppressed()) {
            $this->finish($recipient, CampaignRecipient::STATUS_SKIPPED, $recipient->contact->suppressionReason());

            return;
        }

        try {
            $campaign->isEmail()
                ? $this->sendEmail($campaign, $recipient, $renderer)
                : $this->sendSms($campaign, $recipient, $renderer, $sms);
        } catch (Throwable $exception) {
            Log::warning('Campaign message failed.', [
                'campaign' => $campaign->id,
                'recipient' => $recipient->id,
                'error' => $exception->getMessage(),
            ]);

            $this->finish($recipient, CampaignRecipient::STATUS_FAILED, $exception->getMessage());

            // Swallowed rather than rethrown. The row records the failure, and
            // letting it bubble would put the same message back on the queue to
            // fail again for the same reason.
            return;
        }

        $this->finish($recipient, CampaignRecipient::STATUS_SENT, null);
    }

    public function failed(?Throwable $exception): void
    {
        CampaignRecipient::whereKey($this->recipientId)
            ->where('status', CampaignRecipient::STATUS_QUEUED)
            ->update([
                'status' => CampaignRecipient::STATUS_FAILED,
                'reason' => $exception?->getMessage() ?? 'The job did not complete.',
            ]);

        $this->refreshCampaignCounts();
    }

    /* ---------------------------------------------------------------------
     | Channels
     * ------------------------------------------------------------------ */

    private function sendEmail(Campaign $campaign, CampaignRecipient $recipient, CampaignRenderer $renderer): void
    {
        // Links are read back rather than re-extracted, so every recipient's
        // tracked addresses point at the same rows and the click totals add up.
        $links = $campaign->links->keyBy('url');

        Mail::to($recipient->address)->send(new CampaignMail(
            renderedSubject: $renderer->subjectFor($campaign, $recipient),
            renderedHtml: $renderer->renderEmail($campaign, $recipient, $links),
        ));
    }

    private function sendSms(
        Campaign $campaign,
        CampaignRecipient $recipient,
        CampaignRenderer $renderer,
        InfobipGateway $sms,
    ): void {
        $result = $sms->send($recipient->address, $renderer->renderSms($campaign, $recipient));

        // Kept so the delivery report can find this row. Without it a report
        // arrives with a message id that matches nothing.
        $recipient->provider_message_id = $result->messageId;
    }

    /* ---------------------------------------------------------------------
     | Bookkeeping
     * ------------------------------------------------------------------ */

    private function finish(CampaignRecipient $recipient, string $status, ?string $reason): void
    {
        // provider_message_id is set on the model by sendSms() rather than passed
        // in, so it is saved here alongside the outcome rather than in a second
        // write.
        $recipient->fill([
            'status' => $status,
            'reason' => $reason,
            'sent_at' => $status === CampaignRecipient::STATUS_SENT ? now() : null,
        ])->save();

        $this->refreshCampaignCounts();
    }

    /**
     * Recount the campaign from its recipients.
     *
     * Counted rather than incremented because jobs run in parallel: two workers
     * incrementing the same column at the same moment lose one of the two.
     * Counting is a heavier query but it cannot drift.
     */
    private function refreshCampaignCounts(): void
    {
        $recipient = CampaignRecipient::find($this->recipientId);

        if ($recipient === null) {
            return;
        }

        // Tests are excluded. The operator checking their own wording is not one of
        // the people the campaign was for, and counting them would report a send
        // that never happened.
        $counts = DB::table('campaign_recipients')
            ->where('campaign_id', $recipient->campaign_id)
            ->where('is_test', false)
            ->selectRaw('
                COUNT(*) as total,
                SUM(status = ?) as sent,
                SUM(status = ?) as failed,
                SUM(status = ?) as queued
            ', [
                CampaignRecipient::STATUS_SENT,
                CampaignRecipient::STATUS_FAILED,
                CampaignRecipient::STATUS_QUEUED,
            ])
            ->first();

        /*
         | Only a campaign already sending can finish.
         |
         | A draft that has had a test sent to it is still a draft, and the guard is
         | here rather than left to the is_test filter alone because a status change is
         | irreversible from the screen: a draft wrongly marked sent cannot be edited,
         | sent or deleted, so the operator has no way back.
         */
        $finished = (int) $counts->total > 0 && (int) $counts->queued === 0;

        Campaign::whereKey($recipient->campaign_id)
            ->where('status', Campaign::STATUS_SENDING)
            ->update([
                'sent_count' => (int) $counts->sent,
                'failed_count' => (int) $counts->failed,
                // Checked here rather than by a scheduled sweep so the status is right
                // the moment the last one lands.
                'status' => $finished ? Campaign::STATUS_SENT : Campaign::STATUS_SENDING,
                'finished_at' => $finished ? now() : null,
            ]);
    }
}
