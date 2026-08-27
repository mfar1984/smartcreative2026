<?php

namespace App\Http\Controllers\Campaign;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignLink;
use App\Models\CampaignLinkClick;
use App\Models\CampaignRecipient;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The public side of campaign tracking.
 *
 * Reached by strangers with no session, from links inside email, so every action
 * is identified by an unguessable token on the recipient row rather than by an id.
 *
 * Nothing here is allowed to fail loudly. An unknown token means a message that
 * was deleted or a link somebody kept for a year, and answering with an error page
 * would be worse than quietly doing nothing.
 */
class TrackingController extends Controller
{
    /**
     * A 1x1 transparent GIF, so an open can be counted.
     *
     * Written out as bytes rather than served from disk: it must return even when
     * the token is unknown, and a missing file would turn that into a broken image
     * in somebody's inbox.
     */
    private const PIXEL = "\x47\x49\x46\x38\x39\x61\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00"
        . "\xff\xff\xff\x21\xf9\x04\x01\x00\x00\x00\x00\x2c\x00\x00\x00\x00"
        . "\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00\x3b";

    /**
     * Record that a message was opened.
     */
    public function open(string $token): Response
    {
        $recipient = CampaignRecipient::where('token', $token)->first();

        if ($recipient !== null) {
            $this->recordOpen($recipient);
        }

        return response(self::PIXEL, 200, [
            'Content-Type' => 'image/gif',
            'Content-Length' => (string) strlen(self::PIXEL),
            // Without this a client caches the image and a second open is never
            // seen, which would flatten the count to one per recipient forever.
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Count a click, then send the reader on.
     *
     * The destination is read out of campaign_links by id and never out of the
     * request. That is the whole point: a tracker that redirected to a URL handed
     * to it would let anybody publish a link on this domain that lands wherever
     * they like, which is how a phishing page borrows a trusted name.
     */
    public function click(Request $request, string $token, CampaignLink $link)
    {
        $recipient = CampaignRecipient::where('token', $token)->first();

        // The link must belong to the campaign this recipient was sent, otherwise
        // a token from one campaign could be paired with a link from another.
        if ($recipient !== null && $link->campaign_id === $recipient->campaign_id) {
            $this->recordClick($recipient, $link, $request->ip());
        }

        return redirect()->away($link->url);
    }

    /**
     * Show the unsubscribe confirmation.
     *
     * A page with a button rather than acting on this request. Mail clients
     * prefetch links to show previews and to scan for threats, and a GET that
     * changed data would unsubscribe people who never pressed anything.
     */
    public function unsubscribeForm(string $token)
    {
        $recipient = CampaignRecipient::with(['campaign', 'contact'])->where('token', $token)->first();

        if ($recipient === null) {
            throw new NotFoundHttpException();
        }

        return view('campaign.unsubscribe', [
            'recipient' => $recipient,
            'contact' => $recipient->contact,
            'alreadyDone' => $recipient->contact?->unsubscribed_at !== null,
        ]);
    }

    /**
     * Actually stop sending to them.
     */
    public function unsubscribe(Request $request, string $token)
    {
        $recipient = CampaignRecipient::with(['campaign', 'contact'])->where('token', $token)->first();

        if ($recipient === null) {
            throw new NotFoundHttpException();
        }

        $contact = $recipient->contact;

        if ($contact !== null && $contact->unsubscribed_at === null) {
            DB::transaction(function () use ($contact, $recipient) {
                $contact->unsubscribe(sprintf(
                    'Used the link in "%s"',
                    $recipient->campaign?->name ?? 'a campaign',
                ));

                $recipient->update(['unsubscribed_at' => now()]);

                // Counted on the campaign so a report can show what a message cost
                // in goodwill as well as what it earned. The suppression above still
                // happens for a test, because somebody who asked to be left alone is
                // left alone whichever link they used; only the figure is spared.
                if (! $recipient->is_test) {
                    Campaign::whereKey($recipient->campaign_id)->increment('unsubscribed_count');
                }
            });
        }

        return view('campaign.unsubscribed', [
            'contact' => $contact,
        ]);
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    private function recordOpen(CampaignRecipient $recipient): void
    {
        DB::transaction(function () use ($recipient) {
            $isFirst = $recipient->opened_at === null;

            $recipient->increment('open_count');

            if ($isFirst) {
                $recipient->update(['opened_at' => now()]);

                // Only the first open moves the campaign figure, so the rate is
                // "how many people opened it" rather than "how many times". A test
                // is left out: the operator reading their own check is not a rate.
                if (! $recipient->is_test) {
                    Campaign::whereKey($recipient->campaign_id)->increment('opened_count');
                }
            }
        });
    }

    private function recordClick(CampaignRecipient $recipient, CampaignLink $link, ?string $ip): void
    {
        DB::transaction(function () use ($recipient, $link, $ip) {
            CampaignLinkClick::create([
                'campaign_link_id' => $link->id,
                'campaign_recipient_id' => $recipient->id,
                'clicked_at' => now(),
                'ip_address' => $ip,
            ]);

            $link->increment('click_count');

            $isFirst = $recipient->clicked_at === null;
            $recipient->increment('click_count');

            if ($isFirst) {
                $recipient->update(['clicked_at' => now()]);

                if (! $recipient->is_test) {
                    Campaign::whereKey($recipient->campaign_id)->increment('clicked_count');
                }
            }

            // A click proves the message was seen, which an image based open count
            // can miss entirely when images are blocked. Counted as an open too,
            // otherwise a report can show more clicks than opens.
            if ($recipient->opened_at === null) {
                $this->recordOpen($recipient);
            }
        });
    }
}
