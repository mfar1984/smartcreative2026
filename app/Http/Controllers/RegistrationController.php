<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Payment\RegistrationPaymentController;
use App\Http\Requests\StoreEventRegistrationRequest;
use App\Models\CampaignContact;
use App\Models\Coupon;
use App\Models\Event;
use App\Models\EventAddonVariant;
use App\Models\EventRegistration;
use App\Services\Coupon\CouponAvailability;
use App\Services\Coupon\CouponOutcome;
use App\Services\Coupon\RegistrationCouponWriter;
use App\Services\EventNotifier;
use App\Services\Messaging\StaffAlerts;
use App\Support\AddonOrder;
use App\Support\ParticipantOptions;
use App\Support\PaymentSettings;
use App\Support\WifiCredentials;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RegistrationController extends Controller
{
    public function __construct(
        private readonly CouponAvailability $coupons,
        private readonly RegistrationCouponWriter $couponWriter,
    ) {
    }

    /** Where logos uploaded with a registration live on the public disk. */
    private const LOGO_DIRECTORY = 'registration-logos';

    /**
     * Where identity card photographs live, and the disk they live on.
     *
     * The `local` disk, whose root is outside the published directory, and deliberately
     * not the public one the logo uses. A logo is meant to be seen, so it sits where the
     * web server hands it straight out to anybody who works out the URL. A photograph of a
     * government identity document must never be reachable that way, whatever the filename
     * is: obscurity is not access control, and these files outlive the event.
     *
     * Reading one goes through an admin route that checks who is asking.
     */
    private const IC_DISK = 'local';

    private const IC_DIRECTORY = 'participant-ic';

    /**
     * Public tabs, each matching one value returned by Event::lifecycle().
     *
     * Building the tabs on the same rule the card badge uses keeps a card from
     * ever appearing under a heading that contradicts it.
     */
    public const TABS = [
        'open' => ['label' => 'Open Registration', 'lifecycle' => 'upcoming'],
        'ongoing' => ['label' => 'Ongoing', 'lifecycle' => 'ongoing'],
        'past' => ['label' => 'Past Events', 'lifecycle' => 'completed'],
    ];

    /**
     * Display the events, split across the three lifecycle tabs.
     */
    public function index(Request $request)
    {
        // A deep link names an event, not a tab, so work out which tab holds it
        // and open there. Otherwise honour the tab in the query string.
        $requestedSlug = old('event_slug', $request->query('register'));
        $tab = $this->resolveTab($request->query('tab'), $requestedSlug);

        $events = $this->scoped($tab)
            // The modal prices its add-on picker from these, so they are loaded
            // once here rather than per card.
            ->with([
                'addons' => fn ($query) => $query->active(),
                'addons.variants',

                // The ticked batches, loaded here so deciding whether each modal
                // shows a Voucher Code box is one query rather than one per event.
                'coupons',
            ])
            ->orderBy($tab === 'past' ? 'ends_at' : 'starts_at', $tab === 'past' ? 'desc' : 'asc')
            ->get();

        return view('pages.registration', [
            'pageTitle' => 'Registration',
            'pageSubtitle' => 'Browse our events and secure your place',

            'tabs' => $this->tabsWithCounts(),
            'activeTab' => $tab,
            'events' => $events,

            // Only reopen a modal for an event actually on this tab.
            'openSlug' => $events->contains('slug', $requestedSlug) ? $requestedSlug : null,

            /*
             | The usable coupons ticked on each event, keyed by event id.
             |
             | Whether a Voucher Code box appears at all, and the owner's rule in one
             | place: no tick, no box. Worked out here rather than in the view so the
             | expired and used-up batches are filtered by the same code the submit
             | path checks against.
             */
            'eventCoupons' => $events->mapWithKeys(
                fn (Event $event) => [$event->id => $this->coupons->forEvent($event)]
            ),

            // Roles are decided by each event's mode, not chosen by the
            // visitor, so no role list is handed to the view.
            'genders' => ParticipantOptions::GENDERS,
            'races' => ParticipantOptions::RACES,
            'states' => ParticipantOptions::STATES,
            'countries' => ParticipantOptions::COUNTRIES,
        ]);
    }

    /**
     * Deep link to a single event. Kept so existing links and the admin
     * "View on site" button keep working; it hands off to the list with that
     * event's modal open on whichever tab holds it.
     */
    public function show(string $slug)
    {
        return redirect()->route('registration', ['register' => $slug]);
    }

    /**
     * Put each person's identity card photographs on the private disk.
     *
     * Read by position because the request has already aligned the uploads with the rows.
     * Both sides are handled independently: validation insists on both when the event asks
     * for them, and a half-filled pair here means the event does not, in which case whatever
     * did arrive is still worth keeping against the right person.
     *
     * @return array<int, array<string, string>>  position => column => stored path
     */
    private function storeIdentityCards(StoreEventRegistrationRequest $request, int $count): array
    {
        $stored = [];

        for ($index = 0; $index < $count; $index++) {
            $row = [];

            foreach (['ic_front' => 'ic_front_path', 'ic_back' => 'ic_back_path'] as $field => $column) {
                $file = $request->file("participants.{$index}.{$field}");

                if ($file !== null) {
                    // store() names the file from a hash of its contents, so nothing of the
                    // competitor's own filename — which is often their name or card number —
                    // ends up on disk.
                    $row[$column] = $file->store(self::IC_DIRECTORY, self::IC_DISK);
                }
            }

            if ($row !== []) {
                $stored[$index] = $row;
            }
        }

        return $stored;
    }

    /**
     * Remove identity card photographs that ended up belonging to nobody.
     *
     * @param  array<int, array<string, string>>  $stored
     */
    private function discardIdentityCards(array $stored): void
    {
        foreach ($stored as $row) {
            foreach ($row as $path) {
                Storage::disk(self::IC_DISK)->delete($path);
            }
        }
    }

    /**
     * Store a submitted registration.
     *
     * Seats are re-checked inside the transaction with a locking read, because
     * the form validation ran before this request took its turn.
     */
    public function store(
        StoreEventRegistrationRequest $request,
        Event $event,
        EventNotifier $notifier,
        StaffAlerts $alerts,
    ) {
        $participants = $request->validated()['participants'];

        // How many people are named, which is not the same as how many places
        // they occupy. A squad entry takes one place however many players it
        // names, the same way it pays one fee: see Event::seatsForEntry() and
        // Event::registrationAmount().
        $headCount = count($participants);

        // Keyed by position so the answers can be matched back to the people once
        // they have been written and have ids.
        $participants = array_values($participants);

        // Stored before the transaction opens: a file write cannot be rolled back
        // with the database, so doing it inside would risk holding a lock while
        // waiting on disk. An orphaned file is cleaned up below if the entry is
        // then refused.
        $logoPath = $request->hasFile('logo')
            ? $request->file('logo')->store(self::LOGO_DIRECTORY, 'public')
            : null;

        /*
         | Identity card photographs, stored before the transaction for the same reason as
         | the logo: a file write cannot be rolled back alongside the database, so holding a
         | row lock while waiting on disk buys nothing and costs concurrency.
         |
         | Keyed by position, which is only safe because the request has already moved the
         | uploads onto the same row numbers as the data they belong to. Left to itself
         | $_FILES keeps the numbering the browser sent while the rows are renumbered around
         | any blank block the registrant left behind, and pairing the two by position then
         | files one person's identity document under another person's name.
         */
        $icPaths = $this->storeIdentityCards($request, count($participants));

        $outcome = DB::transaction(function () use ($request, $event, $participants, $headCount, $logoPath, $icPaths) {
            /** @var Event $locked */
            $locked = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            // Asked of the locked row rather than the one the form was built
            // from, so the mode read here is the mode that is about to be
            // charged against.
            $seatsWanted = $locked->seatsForEntry($headCount);

            if ($locked->seats_total > 0 && $seatsWanted > $locked->seatsLeft()) {
                // A squad wants exactly one place, so there is no smaller entry it
                // could retry with. Only an individual entry can usefully be told
                // to name fewer people.
                return ['error' => $locked->isManagerMode()
                    ? 'The last place was taken while you were filling in the form.'
                    : 'The remaining places were taken while you were filling in the form. Please try again with fewer people.'];
            }

            // Re-price against locked catalogue rows. Validation ran before this
            // request took its turn, so the last shirt may have gone since.
            $locked->setRelation('addons', $locked->addons()
                ->with(['variants' => fn ($query) => $query->lockForUpdate()])
                ->get());

            $order = AddonOrder::build($locked, $request->input('addons'), $participants);

            if (! $order->isValid()) {
                return ['error' => reset($order->errors) ?: 'One of the extras is no longer available. Please try again.'];
            }

            $fee = $locked->registrationAmount();
            $addonsTotal = $order->total();
            $total = round($fee + $addonsTotal, 2);

            $registration = EventRegistration::create([
                'event_id' => $locked->id,
                'reference' => EventRegistration::nextReference(),
                'mode' => $locked->registration_mode,
                'team_name' => $request->input('team_name'),
                'logo_path' => $logoPath,
                /*
                 | Nothing to pay means nothing to wait for, so a free entry is
                 | confirmed on arrival rather than left pending.
                 |
                 | STATUS_CONFIRMED is otherwise set in exactly one place,
                 | RegistrationPaymentUpdater, which runs when a payment reaches
                 | paid. A free entry never goes near it, so it used to be born
                 | "Paid" and "Pending" and stay that way for ever: the screen
                 | showed an amber badge on an entry that owed nothing and had
                 | nothing outstanding to chase.
                 */
                'status' => $total <= 0
                    ? EventRegistration::STATUS_CONFIRMED
                    : EventRegistration::STATUS_PENDING,
                'payment_status' => $total <= 0
                    ? EventRegistration::PAYMENT_PAID
                    : EventRegistration::PAYMENT_UNPAID,
                // Flat charge per registration, regardless of party size, plus
                // whatever extras were chosen.
                'registration_fee' => $fee,
                'addons_total' => $addonsTotal,
                'amount' => $total,
                'notes' => $request->input('notes'),
                'ip_address' => $request->ip(),
            ]);

            // Stamped here rather than in the request so the time and address
            // recorded are the ones the entry was actually saved with.
            $consentIp = $request->ip();

            /*
             | Answers are lifted out before the people are written. They belong on
             | their own table, and leaving the key in would rely on mass assignment
             | quietly discarding it, which is not a guarantee worth depending on.
             */
            $answers = [];

            $participants = array_map(function (array $person, int $position) use ($consentIp, &$answers) {
                $answers[$position] = (array) ($person['answers'] ?? []);

                /*
                 | Both of these belong on other tables. The add-on choices were
                 | already turned into order lines by AddonOrder, so the copy here
                 | has done its work and would only be discarded by mass assignment
                 | if it were left in.
                 */
                unset($person['answers'], $person['addons']);

                $consented = (bool) ($person['marketing_consent'] ?? false);

                return $person + [
                    'consent_recorded_at' => $consented ? now() : null,
                    'consent_ip' => $consented ? $consentIp : null,
                ];
            }, $participants, array_keys($participants));

            /*
             | Attach each pair of identity card photographs to the person it belongs to.
             |
             | Matched on the submitted array's own keys rather than on position after
             | re-indexing, because the two are not the same thing once a row the registrant
             | removed has been dropped out of the middle. Getting this wrong would file one
             | competitor's identity document against another's name.
             */
            foreach ($icPaths as $index => $paths) {
                if (isset($participants[$index])) {
                    $participants[$index] += $paths;
                }
            }

            $saved = $registration->participants()->createMany($participants);

            $this->recordAnswers($locked, $saved, $answers);

            /*
             | Wi-Fi logins, when the event offers them.
             |
             | Inside the transaction and immediately after the roster is written, so a
             | credential cannot exist without its participant nor a participant be left
             | without one. Issuing on registration rather than on payment because an
             | event may be free, and then there is no payment to wait for.
             |
             | The relation is set by hand first. It was loaded before these rows
             | existed, so asking for it here would either come back empty or cost
             | another query for people already in memory.
             */
            if ($locked->offersWifi()) {
                $registration->setRelation('participants', $saved);
                $registration->setRelation('event', $locked);

                WifiCredentials::issueFor($registration);
            }

            if ($order->hasLines()) {
                $registration->addonLines()->createMany(
                    $this->attachParticipants($order->lines, $saved)
                );
            }

            // Stock moves now rather than on payment, so a held place cannot be
            // sold twice while a payer is still at the gateway.
            foreach ($order->variantQuantities() as $variantId => $quantity) {
                EventAddonVariant::query()->whereKey($variantId)->increment('stock_taken', $quantity);
            }

            // $seatsWanted, not $headCount: one place for a squad, one per person
            // for an individual event. Skipped at zero so a mode that charges
            // nothing cannot write a pointless update.
            if ($seatsWanted > 0) {
                $locked->increment('seats_taken', $seatsWanted);
            }

            /*
             | The coupon, claimed here and nowhere else.
             |
             | Inside the same transaction that writes the entry, and after the
             | add-on lines, so the charge it reduces is the charge that was actually
             | worked out. A claim made earlier would be against a figure that had not
             | been settled yet; one made afterwards could spend a code against an
             | entry the transaction then rolled back.
             |
             | A refusal is NOT an error. The outcome is carried out of here and the
             | entry stands at the normal price: losing the race for the last code
             | must cost a visitor the discount, never their place.
             */
            $coupon = $this->claimCoupon($registration, $locked, (string) $request->input('voucher_code'));

            return ['registration' => $registration, 'coupon' => $coupon];
        });

        if (isset($outcome['error'])) {
            /*
             | The entry was refused, so the identity card photographs belong to nobody.
             |
             | Deleted rather than left for a cleanup job. These are images of government
             | identity documents: holding one with no row pointing at it means holding
             | somebody's document with no record of whose it is or why, which is worse than
             | the wasted disk space an orphaned logo costs.
             */
            $this->discardIdentityCards($icPaths);

            // Nothing was saved, so the uploaded file has nothing pointing at it.
            if ($logoPath !== null) {
                Storage::disk('public')->delete($logoPath);
            }

            return back()->withInput()->withErrors(['participants' => $outcome['error']]);
        }

        /** @var EventRegistration $registration */
        $registration = $outcome['registration'];

        // Raised after the commit, not inside it. Queued work must not be able
        // to reach a registration the transaction went on to roll back, and a
        // notification problem must not undo an entry that is already saved.
        try {
            $notifier->registrationSubmitted($registration);
        } catch (Throwable $exception) {
            Log::error('Registration was saved but the notifications could not be raised.', [
                'registration' => $registration->reference,
                'error' => $exception->getMessage(),
            ]);
        }

        // Fold these people into the campaign contact list, after the commit for
        // the same reason as the notifications. Swallowed on failure: the contact
        // list can be rebuilt from the participant rows, and losing a registration
        // over it would be absurd.
        try {
            $registration->loadMissing('participants');

            foreach ($registration->participants as $person) {
                CampaignContact::absorb(
                    email: $person->email,
                    phone: $person->phone,
                    name: $person->full_name,
                    consented: (bool) $person->marketing_consent,
                    source: CampaignContact::SOURCE_REGISTRATION,
                    ip: $person->consent_ip,
                    eventId: $registration->event_id,
                );
            }
        } catch (Throwable $exception) {
            Log::error('Registration saved but the contact list could not be updated.', [
                'registration' => $registration->reference,
                'error' => $exception->getMessage(),
            ]);
        }

        // Tells the office. Swallows its own failures.
        $alerts->registrationReceived($registration);

        /** @var CouponOutcome|null $coupon */
        $coupon = $outcome['coupon'] ?? null;

        // Anything with a balance goes to the payment page rather than straight
        // to a confirmation, because nothing has been collected yet. A coupon that
        // covered the charge in full leaves nothing owed, so the entry falls past
        // this and never goes near the gateway.
        if ($registration->awaitingPayment()) {
            // Signed, because the page shows the invoice and the reference is a
            // guessable sequence.
            return redirect()
                ->to(RegistrationPaymentController::urlFor($registration))
                ->with('coupon_status', $this->couponMessage($coupon));
        }

        return redirect()
            ->route('registration')
            ->with('registration_reference', $registration->reference)
            ->with('registration_status', $this->confirmationMessage($registration, $event))
            ->with('coupon_status', $this->couponMessage($coupon));
    }

    /* ---------------------------------------------------------------------
     | Coupons
     * ------------------------------------------------------------------ */

    /**
     * Claim the typed code against this entry, or say why it could not be.
     *
     * Returns null when nothing was typed, so "no coupon" and "a coupon that was
     * refused" stay different facts: one has nothing to report and the other has to
     * tell the visitor what happened to the discount they expected.
     *
     * Two checks, and both are needed. The lookup decides whether the code belongs to
     * a batch actually ticked on THIS event — without it a code for another event
     * would be spent here. The claim then re-reads the expiry and the remaining count
     * under a lock, which is the only thing that can decide the last code safely.
     */
    private function claimCoupon(EventRegistration $registration, Event $event, string $typed): ?CouponOutcome
    {
        $typed = trim($typed);

        if ($typed === '') {
            return null;
        }

        /*
         | The batches on offer, which may well be none: nothing ticked, or everything
         | ticked has expired or run out. An empty list is handed to the lookup rather
         | than short-circuited, because the lookup is what can tell an expired code
         | from a spent one from a code for another event — and a visitor who typed a
         | code deserves to be told which of those it was.
         */
        $lookup = $this->coupons->lookup($typed, $this->coupons->forEvent($event), Coupon::KIND_EVENT);

        if (! $lookup->succeeded()) {
            return CouponOutcome::failed($lookup->status);
        }

        // Handed over rather than reloaded: this is the locked row the charge was
        // worked out against, and the writer reads the per-participant rule off it.
        $registration->setRelation('event', $event);

        return $this->couponWriter->applyCode($registration, $typed);
    }

    /**
     * What to tell the visitor about their coupon, or null when there is nothing.
     *
     * Every outcome gets its own words, taken from CouponOutcome so the wording is
     * the same wherever a code is refused. A failure is phrased as the normal price
     * applying rather than as an error, because the entry itself went through.
     */
    private function couponMessage(?CouponOutcome $coupon): ?string
    {
        if ($coupon === null) {
            return null;
        }

        return $coupon->succeeded()
            ? sprintf('Coupon applied: %s', $coupon->message())
            : $coupon->message();
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    /**
     * Pick the tab to show: the one holding the requested event when there is
     * one, otherwise the tab asked for, otherwise Open Registration.
     */
    private function resolveTab(?string $tab, ?string $slug): string
    {
        if (filled($slug)) {
            $event = Event::query()->publiclyListed()->with(['posters', 'questions'])->where('slug', $slug)->first();

            if ($event !== null) {
                foreach (self::TABS as $candidate => $definition) {
                    if ($definition['lifecycle'] === $event->lifecycle()) {
                        return $candidate;
                    }
                }
            }
        }

        return array_key_exists((string) $tab, self::TABS) ? (string) $tab : 'open';
    }

    /**
     * Swap the participant index on each order line for the real id.
     *
     * AddonOrder runs during validation, before anybody has been written, so a per
     * person line can only say "the third person on this form". The people exist by
     * the time this runs, and createMany returns them in the order they were given.
     *
     * The index is removed either way, because it is not a column and leaving it
     * would rely on mass assignment quietly discarding it.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @param  \Illuminate\Support\Collection<int, EventParticipant>  $saved
     * @return array<int, array<string, mixed>>
     */
    private function attachParticipants(array $lines, $saved): array
    {
        $people = $saved->values();

        return array_map(function (array $line) use ($people) {
            $index = $line['participant_index'] ?? null;

            unset($line['participant_index']);

            if ($index !== null) {
                // Null rather than guessing if the index somehow has no person, so a
                // line is never attributed to the wrong one.
                $line['event_participant_id'] = $people[$index]->id ?? null;
            }

            return $line;
        }, $lines);
    }

    /**
     * Record what each person answered, with the wording they were shown.
     *
     * The snapshot is the point. An answer that read its question back through a
     * relation would change meaning every time the organiser edited their terms,
     * and a consent record that can be rewritten afterwards is evidence of
     * nothing. Same reasoning as the snapshots on shop_order_items, and it matters
     * more here.
     *
     * Every question is written, ticked or not. A missing row and a "no" are
     * different facts, and only one of them can be told apart later.
     *
     * @param  \Illuminate\Support\Collection<int, EventParticipant>  $saved
     * @param  array<int, array<int|string, mixed>>  $answers  keyed by position
     */
    private function recordAnswers(Event $event, $saved, array $answers): void
    {
        $questions = $event->questions;

        if ($questions->isEmpty()) {
            return;
        }

        $now = now();

        foreach ($saved->values() as $position => $participant) {
            $given = $answers[$position] ?? [];
            $rows = [];

            foreach ($questions as $question) {
                $ticked = filter_var($given[$question->id] ?? false, FILTER_VALIDATE_BOOLEAN);

                $rows[] = $question->snapshot() + [
                    'answered' => $ticked,
                    // Only stamped for a yes. "When did they decline" is not a fact
                    // this needs, and a timestamp on a no would imply otherwise.
                    'answered_at' => $ticked ? $now : null,
                ];
            }

            $participant->answers()->createMany($rows);
        }
    }

    private function scoped(string $tab): Builder
    {
        /*
         | Posters are eager loaded because the card asks posterUrl() and the
         | gallery asks for the list, both of which read the relation. Without this
         | a page of events costs one query per card.
         |
         | Harmless on the count() calls in tabsWithCounts(), which never hydrate a
         | model, so the load is not paid for there.
         */
        $query = Event::query()->publiclyListed()->with(['posters', 'questions']);

        return match ($tab) {
            'ongoing' => $query->ongoing(),
            'past' => $query->completed(),
            default => $query->notStarted(),
        };
    }

    /**
     * Tab definitions with a live row count for each.
     *
     * @return array<string, array<string, mixed>>
     */
    private function tabsWithCounts(): array
    {
        $tabs = [];

        foreach (self::TABS as $slug => $definition) {
            $tabs[$slug] = $definition + ['count' => $this->scoped($slug)->count()];
        }

        return $tabs;
    }

    /**
     * Only reached when nothing is owed; anything with a balance is sent to the
     * payment page instead.
     */
    private function confirmationMessage(EventRegistration $registration, Event $event): string
    {
        if ($registration->isFree()) {
            return sprintf(
                'Thank you. Your registration for %s is recorded under reference %s. We will be in touch with the details.',
                $event->title,
                $registration->reference,
            );
        }

        return sprintf(
            'Thank you. Your registration for %s is recorded under reference %s. The amount due is %s.',
            $event->title,
            $registration->reference,
            $registration->amountLabel(),
        );
    }
}
