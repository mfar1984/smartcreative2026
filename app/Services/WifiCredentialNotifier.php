<?php

namespace App\Services;

use App\Mail\WifiCredentialIssued;
use App\Models\Event;
use App\Models\WifiCredential;
use App\Support\MailSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Telling competitors what their Wi-Fi login is.
 *
 * Refuses to send anything the router has not got a copy of, and that refusal is the
 * point of this class rather than a precaution inside it. A login that has been issued but
 * not provisioned is a correct password that fails, and a competitor who tries one and is
 * turned away will not believe the password afterwards however many times it is repeated.
 * So the order is enforced here: fetch first, send second.
 *
 * Sends once. `delivered_at` is set as each message is accepted for queueing, so pressing
 * the button again reaches the people who were missed rather than mailing the whole event a
 * second time.
 */
class WifiCredentialNotifier
{
    /**
     * Email every competitor on an event who has a working login and has not been told.
     *
     * @return array{sent: int, no_email: int, not_provisioned: int, failed: int}
     */
    public function sendFor(Event $event): array
    {
        // The saved SMTP profile lives in the database rather than in config, so it has
        // to be applied before anything is queued.
        MailSettings::apply();

        $outcome = ['sent' => 0, 'no_email' => 0, 'not_provisioned' => 0, 'failed' => 0];

        $outcome['not_provisioned'] = WifiCredential::query()
            ->where('event_id', $event->id)
            ->live()
            ->forAttending()
            ->undelivered()
            ->whereNull('provisioned_at')
            ->count();

        WifiCredential::query()
            ->where('event_id', $event->id)
            ->live()
            ->forAttending()
            ->undelivered()
            // Only what the router already knows about. Everything else is counted
            // above and reported back rather than quietly skipped.
            ->whereNotNull('provisioned_at')
            ->with(['participant', 'event'])
            ->chunkById(100, function (Collection $credentials) use ($event, &$outcome) {
                foreach ($credentials as $credential) {
                    $participant = $credential->participant;

                    if ($participant === null || blank($participant->email)) {
                        // Not a failure. `event_participants.email` is optional, and a
                        // competitor without one is exactly who the printed slips at the
                        // counter are for.
                        $outcome['no_email']++;

                        continue;
                    }

                    if ($this->send($credential, $event, $participant->email, (string) $participant->full_name)) {
                        $outcome['sent']++;
                    } else {
                        $outcome['failed']++;
                    }
                }
            });

        return $outcome;
    }

    /**
     * Queue one message and record that it went.
     *
     * Marked delivered on acceptance into the queue, not on arrival. Nothing here can know
     * whether a mail server accepted it hours later, and the alternative — leaving the row
     * unmarked — means the next press sends a second copy to somebody who already has one.
     * A duplicate is worse than a gap here, because a gap is visible on the admin screen
     * and gets covered by the counter slip.
     */
    private function send(WifiCredential $credential, Event $event, string $address, string $name): bool
    {
        try {
            Mail::to($address, $name !== '' ? $name : null)->queue(new WifiCredentialIssued(
                credential: $credential,
                eventTitle: (string) $event->title,
                recipientName: $name !== '' ? $name : 'there',
            ));

            $credential->forceFill(['delivered_at' => now()])->save();

            return true;
        } catch (Throwable $exception) {
            Log::error('A Wi-Fi login could not be queued.', [
                'credential' => $credential->id,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
