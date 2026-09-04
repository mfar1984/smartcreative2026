<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Payment\RegistrationPaymentController;
use App\Http\Requests\StoreEventRegistrationRequest;
use App\Models\CampaignContact;
use App\Models\Event;
use App\Models\EventAddonVariant;
use App\Models\EventRegistration;
use App\Services\EventNotifier;
use App\Services\Messaging\StaffAlerts;
use App\Support\AddonOrder;
use App\Support\ParticipantOptions;
use App\Support\PaymentSettings;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RegistrationController extends Controller
{
    /** Where logos uploaded with a registration live on the public disk. */
    private const LOGO_DIRECTORY = 'registration-logos';

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
            ->with(['addons' => fn ($query) => $query->active(), 'addons.variants'])
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

        // Stored before the transaction opens: a file write cannot be rolled back
        // with the database, so doing it inside would risk holding a lock while
        // waiting on disk. An orphaned file is cleaned up below if the entry is
        // then refused.
        $logoPath = $request->hasFile('logo')
            ? $request->file('logo')->store(self::LOGO_DIRECTORY, 'public')
            : null;

        $outcome = DB::transaction(function () use ($request, $event, $participants, $headCount, $logoPath) {
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

            $order = AddonOrder::build($locked, $request->input('addons'));

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
                'status' => EventRegistration::STATUS_PENDING,
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

            $participants = array_map(function (array $person) use ($consentIp) {
                $consented = (bool) ($person['marketing_consent'] ?? false);

                return $person + [
                    'consent_recorded_at' => $consented ? now() : null,
                    'consent_ip' => $consented ? $consentIp : null,
                ];
            }, $participants);

            $registration->participants()->createMany($participants);

            if ($order->hasLines()) {
                $registration->addonLines()->createMany($order->lines);
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

            return ['registration' => $registration];
        });

        if (isset($outcome['error'])) {
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

        // Anything with a balance goes to the payment page rather than straight
        // to a confirmation, because nothing has been collected yet.
        if ($registration->awaitingPayment()) {
            // Signed, because the page shows the invoice and the reference is a
            // guessable sequence.
            return redirect()->to(RegistrationPaymentController::urlFor($registration));
        }

        return redirect()
            ->route('registration')
            ->with('registration_reference', $registration->reference)
            ->with('registration_status', $this->confirmationMessage($registration, $event));
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
            $event = Event::query()->publiclyListed()->where('slug', $slug)->first();

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

    private function scoped(string $tab): Builder
    {
        $query = Event::query()->publiclyListed();

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
