<?php

namespace App\Http\Controllers;

use App\Models\EventRegistration;
use App\Models\SecurityEvent;
use App\Services\Registration\ParticipantSizeWriter;
use App\Services\Security\SecurityEventRecorder;
use App\Support\ParticipantSizes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Confirming the shirt size of everybody on one registration.
 *
 * Why this page exists: the registration form only started asking for a size part way
 * through an event's entries. Everybody who registered before that was charged for a
 * shirt and never asked which size, so the organiser cannot order or hand out the
 * shirts. A size cannot be guessed and must not be invented, so it is collected from
 * the person who registered.
 *
 * WHAT IT IS NOT
 *
 * A payment page. Nothing here reads, writes or asks for money, and it is deliberately
 * not gated on the payment state: a paid, unpaid, failed or part-paid entry all still
 * need a size. These registrants have been receiving payment reminders, so the page and
 * the email that carries it both say plainly that nothing is owed on this.
 *
 * HOW IT IS REACHED
 *
 * A signed link in an email, with no login and no account, the same pattern as the
 * payment page and the shop's delivery confirmation. The signature covers the whole
 * URL including the reference, so editing the reference to reach another registration
 * invalidates it; the registration is resolved from the signed route and never from
 * anything posted. An invalid or expired signature is answered with a page that says so
 * rather than an exception, which is why the check is here instead of on the route: the
 * `signed` middleware throws, and a stack trace is not an answer to give a participant.
 */
class ParticipantSizeController extends Controller
{
    /**
     * How long a size confirmation link stays valid.
     *
     * The same thirty days the payment link uses. Long enough for somebody to come
     * back to it at the weekend, short enough that a link forwarded or left in an
     * inbox does not stay live for the next event.
     */
    private const LINK_DAYS = 30;

    /**
     * The signed URL for one registration's size page.
     *
     * The only way this URL is ever built, so the email, the redirect after a
     * submission and the form's own action cannot disagree about what is signed.
     */
    public static function urlFor(EventRegistration $registration): string
    {
        return URL::temporarySignedRoute(
            'registration.sizes',
            now()->addDays(self::LINK_DAYS),
            ['reference' => $registration->reference],
        );
    }

    public function show(Request $request, string $reference)
    {
        if (! $request->hasValidSignature()) {
            return $this->expired();
        }

        return view('pages.registration-sizes', $this->viewData($this->find($reference)));
    }

    /**
     * Record what was chosen.
     *
     * Posts to the same signed URL the page was opened with, so the registration is
     * still resolved from the signature rather than from a hidden field. Every id in
     * the payload is checked against this registration's own people and its event's own
     * catalogue inside the writer, and no price, amount or total is read from the
     * request at all.
     */
    public function store(Request $request, string $reference, ParticipantSizeWriter $writer)
    {
        if (! $request->hasValidSignature()) {
            return $this->expired();
        }

        $registration = $this->find($reference);

        $submitted = $request->input('sizes');

        $result = $writer->apply($registration, is_array($submitted) ? $submitted : []);

        // A fresh signature rather than back(): the page must stay reachable after a
        // refusal, and a referer is not something to depend on.
        $redirect = redirect()->to(self::urlFor($registration));

        if ($result['refused'] !== []) {
            return $redirect->withErrors($result['refused']);
        }

        if ($result['recorded'] === 0) {
            return $redirect->with('status', ParticipantSizes::needsSizes($registration->fresh())
                ? 'Nothing was changed. Choose a size for each person and submit again.'
                : 'Nothing was changed. Every size on this registration is already recorded.');
        }

        return $redirect->with('status', sprintf(
            '%d %s recorded. Thank you — nothing is owed and there is nothing further to do.',
            $result['recorded'],
            $result['recorded'] === 1 ? 'size' : 'sizes',
        ));
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    private function find(string $reference): EventRegistration
    {
        return EventRegistration::query()
            ->with(['event.addons.variants', 'participants', 'addonLines'])
            ->where('reference', $reference)
            ->firstOrFail();
    }

    /**
     * The answer to a link that has run out or been tampered with.
     *
     * 403 with a page on it. Nothing about the registration is named, because at this
     * point we have no evidence the person holding the link is entitled to it.
     *
     * Recorded in the Security Log by hand, because this refusal is a RESPONSE rather
     * than an exception — see the class docblock for why — so the hook in
     * bootstrap/app.php that catches every other refused signature never sees it.
     * The response below is returned unchanged either way; the recorder swallows its
     * own failures.
     */
    private function expired()
    {
        SecurityEventRecorder::record(
            SecurityEvent::TYPE_INVALID_SIGNATURE,
            SecurityEvent::SEVERITY_WARNING,
            'A size confirmation link was refused: the signature did not check out, or it had expired.',
        );

        return response()->view('pages.registration-sizes-expired', [
            'pageTitle' => 'Link Expired',
            'pageSubtitle' => 'This size confirmation link is no longer valid',
        ], 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function viewData(EventRegistration $registration): array
    {
        return [
            'pageTitle' => 'Confirm Shirt Size',
            'pageSubtitle' => 'Registration ' . $registration->reference,

            'registration' => $registration,
            'event' => $registration->event,

            // Everybody on the entry against every size they could choose, with
            // whatever is already recorded selected.
            'rows' => ParticipantSizes::sheetFor($registration),

            // The signed URL the form posts back to, built the one way it is built.
            'action' => self::urlFor($registration),
        ];
    }
}
