<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePlayerMessageRequest;
use App\Mail\PlayerEnquiryReceived;
use App\Models\EventParticipant;
use App\Models\PlayerMessage;
use App\Models\Setting;
use App\Support\PlayerProfile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Passing a message to a competitor without handing over their details.
 *
 * The public profile shows a masked address. Tapping it opens this form, and what
 * arrives at the office carries the competitor's full record alongside whatever was
 * written, so somebody can decide whether to pass it on. The person who wrote it is
 * never shown an address, a telephone number or a legal name, and nothing in the
 * response to this request contains one either.
 *
 * Separate from TournamentPublicController on purpose: that class is the read side and
 * says so. This one writes.
 */
class PlayerMessageController extends Controller
{
    /**
     * Where enquiries go, as set in General Setup.
     *
     * Read rather than hard-coded, because an organiser who changes the address on the
     * settings screen expects messages to follow it. The fallback matches the default
     * on that screen so a site that never saved its settings still delivers.
     */
    private function recipient(): string
    {
        return Setting::read('general.contact_email', 'event@smartcreative.my')
            ?: 'event@smartcreative.my';
    }

    public function store(StorePlayerMessageRequest $request, EventParticipant $participant)
    {
        /*
         | The same gate the profile page applies, checked again here rather than
         | trusted from the form. Without it, a posted id would reach anybody who has
         | ever registered, including people who were never drawn into a tournament and
         | so have no public presence at all.
         |
         | Assembling the profile also yields the name to put in the subject line, so
         | this is not a check paid for twice.
         */
        $profile = PlayerProfile::build($participant);

        abort_if($profile === null, 404);

        // Nobody to pass a message on to. The form is not offered in this case, so
        // reaching here means the address was removed between the page being drawn and
        // the form being sent.
        abort_if(! $profile['can_message'], 404);

        /*
         | Saved before anything is sent, matching the contact form: a message that was
         | written is worth keeping even when the mail server refuses it, because the
         | office can still act on it.
         */
        $message = PlayerMessage::create([
            ...$request->validated(),
            'event_participant_id' => $participant->id,
            'ip_address' => $request->ip(),
        ]);

        try {
            Mail::to($this->recipient())->send(new PlayerEnquiryReceived(
                $message,
                $participant->loadMissing(['registration.event']),
                $profile['label'],
            ));
        } catch (Throwable $exception) {
            // Logged and swallowed, for the same reason the contact form does it: the
            // message is saved, and telling somebody their note failed when it is
            // sitting in the database would be wrong.
            Log::error('Player enquiry notification could not be sent.', [
                'player_message_id' => $message->id,
                'exception' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('player', $participant)
            ->with('player_message_status', 'Thank you. Your message has been passed to the organiser, who will follow it up.');
    }
}
