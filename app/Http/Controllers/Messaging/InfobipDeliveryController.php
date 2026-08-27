<?php

namespace App\Http\Controllers\Messaging;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\EventNotification;
use App\Support\SmsSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Infobip telling us what actually happened to a text message.
 *
 * Accepting a message and delivering it are different events, sometimes hours
 * apart and sometimes never. This is the only way to know which: a handset that is
 * switched off, out of credit or simply not that number looks identical from our
 * side at the moment of sending.
 *
 * Infobip does not sign these, so the secret in the URL is the whole
 * authentication. It is handed over per message in notifyUrl and never appears
 * anywhere a stranger could read it.
 *
 * Reference: https://www.infobip.com/docs (SMS delivery reports)
 */
class InfobipDeliveryController extends Controller
{
    /**
     * Status groups Infobip reports, and what each one means for us.
     *
     * Anything not listed is stored as it arrived rather than guessed at, so a
     * group added by Infobip later is readable instead of silently mishandled.
     */
    private const ARRIVED = 'DELIVERED';

    private const FAILED_GROUPS = ['UNDELIVERABLE', 'EXPIRED', 'REJECTED'];

    /**
     * Answer a browser that opened the address, without doing anything.
     *
     * Exists because the URL is shown on the settings screen with a copy button
     * next to it, which invites somebody to paste it in and see whether it works.
     * Without this they get a stack trace about the GET method, which looks like a
     * fault and answers nothing.
     *
     * Reads nothing and writes nothing. The secret is still checked, so a wrong
     * one gets the same 404 as a wrong POST and the endpoint is not confirmed to
     * anybody guessing.
     */
    public function status(Request $request, string $secret): JsonResponse
    {
        if (! hash_equals(SmsSettings::webhookSecret(), $secret)) {
            throw new NotFoundHttpException();
        }

        return response()->json([
            'status' => 'ready',
            'message' => 'This address is working. Infobip posts delivery reports here; '
                . 'opening it in a browser is a GET, which is why nothing else happens.',
            'accepts' => 'POST',
        ]);
    }

    /**
     * Take a delivery report.
     */
    public function report(Request $request, string $secret): JsonResponse
    {
        // Compared in constant time. A plain === leaks the position of the first
        // wrong character to anybody timing the responses.
        if (! hash_equals(SmsSettings::webhookSecret(), $secret)) {
            Log::warning('An SMS delivery report arrived with the wrong secret.', [
                'ip' => $request->ip(),
            ]);

            // Not a 403: an unknown URL is a less useful answer to somebody
            // probing than a refusal that confirms the endpoint exists.
            throw new NotFoundHttpException();
        }

        $results = $request->input('results');

        if (! is_array($results)) {
            // Answered 200 rather than refused. A malformed body is not something
            // retrying will fix, and Infobip would keep trying.
            Log::info('An SMS delivery report carried no results.', ['body' => $request->all()]);

            return response()->json(['handled' => 0]);
        }

        $handled = 0;

        foreach ($results as $result) {
            if (is_array($result) && $this->apply($result)) {
                $handled++;
            }
        }

        return response()->json(['handled' => $handled]);
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $result
     */
    private function apply(array $result): bool
    {
        $messageId = $result['messageId'] ?? null;

        if (! is_string($messageId) || $messageId === '') {
            return false;
        }

        $group = strtoupper((string) data_get($result, 'status.groupName', ''));

        // The error object carries the real reason a message failed; the status
        // description only says which bucket it fell into.
        $detail = (string) (
            data_get($result, 'error.description')
            ?: data_get($result, 'status.description')
            ?: 'No description given.'
        );

        $doneAt = $this->timestamp($result['doneAt'] ?? null);
        $arrived = $group === self::ARRIVED;

        $columns = [
            'delivery_status' => $group !== '' ? $group : 'UNKNOWN',
            'delivery_detail' => \Illuminate\Support\Str::limit($detail, 250),
            'delivered_at' => $arrived ? ($doneAt ?? now()) : null,
        ];

        // Transactional notifications first, then campaigns. A message id belongs
        // to one or the other, never both.
        $notification = EventNotification::where('provider_message_id', $messageId)->first();

        if ($notification !== null) {
            $notification->update($columns + [
                // A refusal is worth promoting to a failure: the row saying "sent"
                // while the gateway says it was rejected would be misleading, and
                // this is the only place that ever learns the difference.
                'status' => in_array($group, self::FAILED_GROUPS, true)
                    ? EventNotification::STATUS_FAILED
                    : $notification->status,
                'reason' => $detail,
            ]);

            return true;
        }

        $recipient = CampaignRecipient::where('provider_message_id', $messageId)->first();

        if ($recipient === null) {
            // A report for something we do not recognise. Logged rather than
            // ignored: it usually means a test message, but a pattern of them
            // would mean message ids are not being stored.
            Log::info('An SMS delivery report matched nothing.', [
                'message_id' => $messageId,
                'group' => $group,
            ]);

            return false;
        }

        $recipient->update($columns + [
            'status' => in_array($group, self::FAILED_GROUPS, true)
                ? CampaignRecipient::STATUS_FAILED
                : $recipient->status,
            'reason' => $detail,
        ]);

        // A promotion to failed changes the campaign totals, which are counted
        // rather than incremented so they cannot drift.
        if (in_array($group, self::FAILED_GROUPS, true)) {
            $this->recountCampaign($recipient->campaign_id);
        }

        return true;
    }

    private function recountCampaign(int $campaignId): void
    {
        // Tests excluded, for the same reason the send job excludes them: the
        // operator's own check is not one of the messages the campaign reports on.
        $counts = CampaignRecipient::where('campaign_id', $campaignId)
            ->where('is_test', false)
            ->selectRaw('SUM(status = ?) as sent, SUM(status = ?) as failed', [
                CampaignRecipient::STATUS_SENT,
                CampaignRecipient::STATUS_FAILED,
            ])
            ->first();

        Campaign::whereKey($campaignId)->update([
            'sent_count' => (int) $counts->sent,
            'failed_count' => (int) $counts->failed,
        ]);
    }

    /**
     * Infobip sends an ISO timestamp. Parsed defensively because a report is not
     * worth discarding over a date we could not read.
     */
    private function timestamp(mixed $value): ?\Illuminate\Support\Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
