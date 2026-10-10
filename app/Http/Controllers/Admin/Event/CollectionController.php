<?php

namespace App\Http\Controllers\Admin\Event;

use App\Http\Controllers\Controller;
use App\Models\CollectionHandover;
use App\Models\Event;
use App\Models\EventAddon;
use App\Models\EventParticipant;
use App\Models\EventRegistration;
use App\Models\EventRegistrationAddon;
use App\Services\AdminLogger;
use App\Services\Collection\CollectionCodeException;
use App\Services\Collection\CollectionVerifier;
use App\Services\Messaging\MessagingException;
use App\Services\Registration\ParticipantSizeWriter;
use App\Support\HandoverSheet;
use App\Support\LocalTime;
use App\Support\ParticipantSizes;
use App\Support\PaymentFigures;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The counter that hands the shirts out.
 *
 * A sibling of Attendance and deliberately not part of it. Arriving and collecting
 * are two different facts about the same afternoon: somebody can walk in and leave
 * without their shirt, or send a brother for it having never turned up themselves.
 * Folding one into the other would make both unanswerable, so this screen writes
 * nothing to event_attendances and reads nothing from it.
 *
 * ONE ROW IS ONE PERSON AND ONE ITEM
 *
 * Not one registration. A grouping of six takes six shirts in up to six sizes and
 * they do not arrive together, so a registration-level row could only be collected
 * all at once or not at all — and would be unable to answer "who took mine" when
 * one of the six says months later that they never received it.
 *
 * WHAT MONEY THIS MOVES
 *
 * None. Not amount, not amount_paid, not payment_status, not refunded_amount, and no
 * row in any ledger. Handing goods over is not a payment event: the shirt was paid
 * for when the entry was made, and the only writes on this screen are a choice
 * (through ParticipantSizeWriter, which carries the same promise) and a handover row.
 *
 * WHAT STOCK THIS MOVES
 *
 * None, on its own. stock_taken counts the units committed to people, and a shirt
 * reaching a pair of hands does not change how many were promised. It moves when a
 * choice is set or changed, inside ParticipantSizeWriter, and nowhere else.
 *
 * THE TWO WAYS PAST A REFUSAL
 *
 * Both are recorded, both demand a reason, and both exist because the alternative is
 * worse than the risk. A counter with no way through does not stop: it records the
 * collector as somebody they are not, and then the record is a lie that reads like
 * the truth.
 *
 *   SMS            a third party whose code will not arrive. Stadium signal is poor
 *                  and numbers get mistyped. Marked on the record as handed over
 *                  without SMS verification.
 *   PAYMENT        an entry that still owes money. Six people owing RM 240.00 having
 *                  paid RM 40.00 will turn up on the day with their shirts already
 *                  ordered, and the counter cannot take the balance. Recorded in its
 *                  own column so "why was this handed over unpaid" stays a question
 *                  the database can answer.
 *
 * Gated on attendance.view to read and attendance.update to write: the same desk, the
 * same staff and the same shift as Attendance, both permissions already exist and are
 * already granted, so nothing needs re-seeding. The CSV carries names and identity
 * card numbers out of the building, so it sits behind participants.export, which is
 * the permission that already governs exactly that.
 */
class CollectionController extends Controller
{
    private const PER_PAGE = 25;

    /** Whether a row has gone out yet. */
    public const STATES = [
        'outstanding' => 'Not collected',
        'collected' => 'Collected',
    ];

    /** Where an entry stands on paying for what it ordered. */
    public const PAYMENTS = [
        'paid' => 'Paid in full',
        'partial' => 'Partly paid',
        'owing' => 'Still owes money',
    ];

    public function index(Request $request)
    {
        $filters = $this->filters($request);

        /*
         | Every event that hands something over, with its catalogue.
         |
         | Loaded whole rather than per event in scope, because the same collection
         | answers two questions: which events the filter may offer, and which add-on
         | ids each one hands over. Three queries for both, and the set is small — an
         | event only appears here once somebody ticked "handed over at the event" on
         | one of its add-ons.
         */
        /*
         | visibleTo is the ONE place this screen is narrowed for a monitoring account,
         | and it is enough because everything else on it is derived from this
         | collection: the event picker, the add-on ids per event, the rows, the four
         | figures and the scope caption all read $handedOver, whose keys are these
         | events' ids. Narrowing the source rather than each consumer is what stops a
         | figure and a list disagreeing about whose afternoon they describe.
         */
        $handoverEvents = Event::query()
            ->visibleTo($request->user())
            ->whereHas('addons', fn (Builder $addons) => $addons->where('is_handed_over', true))
            ->with('addons.variants')
            ->orderByDesc('starts_at')
            ->get();

        $scope = $filters['event'] !== ''
            ? $handoverEvents->where('id', (int) $filters['event'])
            : $handoverEvents;

        // eventId => the ids it hands over, and the subset a counter may also record
        // a choice for. Both read from the one definition rather than re-derived here.
        $handedOver = [];
        $choices = [];

        foreach ($scope as $event) {
            $handedOver[(int) $event->id] = HandoverSheet::addonIdsFor($event);
            $choices[(int) $event->id] = ParticipantSizes::addonsFor($event)
                ->filter(fn (EventAddon $addon) => $addon->isHandedOver())
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $base = $this->filtered($filters, $handedOver, $choices);

        $participants = (clone $base)
            // By entry first, so a family's rows sit together and one dialog covers
            // them. Then by id, which is the order they were entered in.
            ->orderBy('event_participants.event_registration_id')
            ->orderBy('event_participants.id')
            ->with([
                'registration.event.addons.variants',
                'registration.participants',
                'registration.addonLines.handover',
            ])
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.event.collection', [
            'participants' => $participants,

            /*
             | The entries represented on this page, each carrying its own dialog.
             |
             | Taken off the loaded rows rather than queried again, and deliberately
             | the whole entry rather than only the people on screen: a grouping split
             | across a page boundary must still offer the counter all six of its rows
             | in one press, because all six people are standing there.
             */
            'entries' => $participants
                ->pluck('registration')
                ->filter()
                ->unique('id')
                ->values(),

            'figures' => $this->figures($base, $handedOver, $choices),

            'events' => $handoverEvents->pluck('title', 'id')->all(),
            'states' => self::STATES,
            'payments' => self::PAYMENTS,

            'search' => $filters['search'],
            'eventId' => $filters['event'],
            'state' => $filters['state'],
            'choice' => $filters['choice'],
            'payment' => $filters['payment'],
            'isFiltered' => $filters['search'] !== ''
                || $filters['event'] !== ''
                || $filters['state'] !== ''
                || $filters['choice'] !== ''
                || $filters['payment'] !== '',

            // What the figures and the rows cover, named under them the way the
            // Participants screen names its money scope.
            'scopeLabel' => $this->scopeLabel($filters, $handoverEvents),

            'canCollect' => $request->user()->hasPermission('attendance.update'),
            'canExport' => $request->user()->hasPermission('participants.export'),
        ]);
    }

    /**
     * Text a one-time code to somebody collecting on another person's behalf.
     *
     * Bound to the registration rather than to one row, because one code covers a
     * batch: a representative taking all six of a grouping's shirts reads out one
     * code, not six. That also means a code texted for one entry is no use against
     * another — there is simply no live row to match it, which is the property
     * CollectionVerifier gives us by binding the code to a target.
     *
     * Answers JSON because the dialog stays open. The operator types the collector's
     * details, presses this, and reads the gateway's own answer in place rather than
     * losing what they have already filled in with a queue waiting.
     *
     * Sent straight through the gateway, never queued: the queue drains from cron once
     * a minute, which is useless at a counter, and the gateway accepting a message is
     * not the same fact as a handset receiving one.
     *
     * No code is in the response. Nothing in the application can hand the digits back.
     */
    public function sendCode(Request $request, EventRegistration $registration, CollectionVerifier $verifier)
    {
        $data = $request->validate([
            'collector_name' => ['required', 'string', 'max:190'],
            'collector_ic' => ['required', 'string', 'max:30'],
            'collector_phone' => ['required', 'string', 'max:30'],
        ], [
            'collector_name.required' => 'Name the person collecting. The record is worthless without it.',
            'collector_ic.required' => 'Their identity card number is required.',
            'collector_phone.required' => 'A telephone number is required: it is where the code goes.',
        ]);

        $registration->loadMissing(['event.addons.variants', 'participants', 'addonLines.handover']);

        $outstanding = HandoverSheet::outstandingFor($registration);

        /*
         | Nothing left to collect. Checked before a code is issued so a double press
         | on an entry that has already gone out cannot text anybody.
         */
        if ($outstanding === []) {
            return response()->json([
                'ok' => false,
                'message' => sprintf(
                    'There is nothing left to hand over on %s, so no code was sent.',
                    $registration->reference,
                ),
            ], 422);
        }

        try {
            $issued = $verifier->issue(
                $registration,
                $data['collector_phone'],
                $this->codeSubject($registration, $outstanding),
            );
        } catch (CollectionCodeException $e) {
            // Our own rules: the cooldown, or too many codes to one handset. Not a
            // fault, so it reads as a wait rather than a failure.
            return response()->json(['ok' => false, 'message' => $e->publicMessage], 422);
        } catch (MessagingException $e) {
            /*
             | The gateway refused, could not be reached, or is not configured. The
             | public message only: the real one can quote the account base URL.
             */
            return response()->json([
                'ok' => false,
                'message' => $e->publicMessage.' If it will not go through, open "The code will not go through" below and say why.',
            ], 422);
        }

        AdminLogger::activity('collection.code', sprintf(
            'Texted a collection code for %s to %s for %s.',
            $registration->reference,
            $issued->sms->destination,
            $data['collector_name'],
        ));

        return response()->json(['ok' => true, 'message' => $issued->summary()]);
    }

    /**
     * Record that one or more of an entry's items were physically handed over.
     *
     * POST only, and nothing in the body says which entry: that comes from the route
     * binding, and every row is matched against the sheet rebuilt here from the
     * database. A key naming another entry's person or another event's item therefore
     * matches nothing rather than being honoured.
     *
     * The order of the checks is the order the counter hits them, and each one leaves
     * the record untouched when it refuses:
     *
     *   1. a choice typed at the desk is recorded first, so a missing size does not
     *      block the queue and the row it unblocks is collectable in the same press
     *   2. rows already collected are dropped, so a double press writes nothing
     *   3. a row with nothing on the entry's invoice is refused, because there is no
     *      record to hand over against
     *   4. money owed needs an explicit reason, neither silently allowed nor flatly
     *      refused
     *   5. a third party needs a verified code, or an explicit reason
     *
     * Gated on attendance.update.
     */
    public function handOver(
        Request $request,
        EventRegistration $registration,
        ParticipantSizeWriter $writer,
        CollectionVerifier $verifier,
    ) {
        $validated = $request->validate([
            'rows' => ['required', 'array', 'min:1'],
            'rows.*' => ['string', 'max:40'],

            /*
             | Absent means the person named on the row is taking their own, which is
             | the common case and the one the identity card on the record already
             | verifies.
             */
            'collector' => ['nullable', Rule::in(array_keys(CollectionHandover::KINDS))],

            'collector_name' => ['required_if:collector,other', 'string', 'max:190'],
            'collector_ic' => ['required_if:collector,other', 'string', 'max:30'],
            'collector_phone' => ['required_if:collector,other', 'string', 'max:30'],

            // Never logged, never echoed, never put in an error message.
            'code' => ['nullable', 'string', 'max:12'],

            'override_reason' => ['nullable', 'string', 'max:255'],
            'payment_override_reason' => ['nullable', 'string', 'max:255'],

            // Shaped [participantId][addonId] => variantId. Read by the writer, which
            // resolves every id against this entry and this event's own catalogue.
            'sizes' => ['nullable', 'array'],
        ], [
            'rows.required' => 'Tick at least one person before confirming a handover.',
            'collector_name.required_if' => 'Name the person collecting. A record that does not say who took the goods answers nothing later.',
            'collector_ic.required_if' => 'Their identity card number is required.',
            'collector_phone.required_if' => 'A telephone number is required: it is where the code goes.',
        ]);

        $registration->loadMissing(['event.addons.variants', 'participants', 'addonLines.handover']);

        /*
         | The choice, taken at the desk.
         |
         | First, because it is what makes the row collectable at all: somebody who
         | never answered the size link has no line, and a handover needs one to hang
         | off. Straight through ParticipantSizeWriter, so the stock moves exactly once
         | and an option priced differently from the one on record is refused rather
         | than silently re-pricing what the entry owes.
         |
         | Narrowed to the pairs this screen is actually about before the writer sees
         | it. The writer would fence a crafted id to this entry and this event's own
         | catalogue anyway, but a counter handing out shirts has no business setting
         | an answer on an insurance line, and the narrowest payload is the one with
         | the least to argue about later.
         */
        $sizes = $this->submittedChoices($request->input('sizes'), HandoverSheet::rowsFor($registration));

        if ($sizes !== []) {
            $outcome = $writer->apply($registration, $sizes, $request->user());

            if ($outcome['refused'] !== []) {
                // Nothing is handed over on a refused choice: the operator is looking
                // at a person whose record would be wrong either way.
                return back()->withInput()->withErrors($outcome['refused']);
            }

            // The writer drops the lines it rewrote, so the sheet below is rebuilt
            // from what was actually written rather than from what the page drew.
            $registration->loadMissing(['addonLines.handover']);
        }

        $sheet = collect(HandoverSheet::rowsFor($registration))->keyBy('key');

        $requested = array_values(array_unique(array_map(
            fn ($key) => (string) $key,
            $validated['rows'],
        )));

        $rows = array_values(array_filter(array_map(
            fn (string $key) => $sheet->get($key),
            $requested,
        )));

        if ($rows === []) {
            return back()->withInput()->withErrors([
                'rows' => sprintf(
                    'Nothing on %s matched what was ticked. Reload the screen and try again.',
                    $registration->reference,
                ),
            ]);
        }

        /*
         | Already collected, and therefore nothing to do. A warning rather than an
         | error, because a double press, a reload or two people working the same queue
         | is the ordinary way to arrive here. The unique index on the handover table
         | would refuse the second write anyway; this is what keeps that refusal from
         | reading as a fault, and from writing a second trail entry.
         */
        $already = array_values(array_filter($rows, fn (array $row) => $row['handover'] !== null));
        $rows = array_values(array_filter($rows, fn (array $row) => $row['handover'] === null));

        if ($rows === []) {
            return back()->with('warning', sprintf(
                '%s on %s %s already recorded as collected. Nothing was changed.',
                $this->namesOf($already),
                $registration->reference,
                count($already) === 1 ? 'was' : 'were',
            ));
        }

        /*
         | A row with no line has nothing on the entry's invoice, so there is nothing
         | to hand over against. For an item with sizes that is simply a choice nobody
         | has made yet, and the dialog can take it; for anything else the entry never
         | bought one and the fix is on the registration, not here.
         */
        $unrecorded = array_values(array_filter($rows, fn (array $row) => $row['line'] === null));

        if ($unrecorded !== []) {
            return back()->withInput()->withErrors([
                'rows' => sprintf(
                    '%s %s no %s recorded on %s. %s',
                    $this->namesOf($unrecorded),
                    count($unrecorded) === 1 ? 'has' : 'have',
                    strtolower((string) $unrecorded[0]['addon']->name),
                    $registration->reference,
                    $unrecorded[0]['collects_choice']
                        ? 'Choose their option in this dialog and confirm again.'
                        : 'Nothing on this entry covers it, so it has to be put right on the registration first.',
                ),
            ]);
        }

        /*
         | Money owed. Neither silently allowed nor flatly refused.
         |
         | owesBalance() rather than isPaid(): it already excludes a free entry, a
         | cancelled one and a rounding error, and it is the same test the payment
         | chase-up reads, so this screen and that one cannot disagree about who owes.
         */
        $paymentOverride = trim((string) ($validated['payment_override_reason'] ?? ''));

        if (! $registration->owesBalance()) {
            // Not recorded when it was not needed: an override on a settled entry
            // would mark a clean record as an exception.
            $paymentOverride = '';
        } elseif ($paymentOverride === '') {
            return back()->withInput()->withErrors([
                'payment_override_reason' => sprintf(
                    '%s still owes %s of %s. Say why the goods are going out anyway, or take the payment first.',
                    $registration->reference,
                    $registration->outstandingAmountLabel(),
                    PaymentFigures::money((float) $registration->amount),
                ),
            ]);
        }

        $kind = ($validated['collector'] ?? CollectionHandover::KIND_BUYER) === CollectionHandover::KIND_OTHER
            ? CollectionHandover::KIND_OTHER
            : CollectionHandover::KIND_BUYER;

        $override = trim((string) ($validated['override_reason'] ?? ''));
        $verification = null;

        if ($kind === CollectionHandover::KIND_OTHER && $override === '') {
            /*
             | A blank box is answered before verify() is asked, so an empty submit
             | does not spend one of the code's tries. Submitting nothing is not a
             | guess.
             */
            if (preg_replace('/\D+/', '', (string) ($validated['code'] ?? '')) === '') {
                return back()->withInput()->withErrors([
                    'code' => 'Enter the six-digit code the collector was texted, or say why you are handing it over without one.',
                ]);
            }

            $pending = $verifier->pendingFor($registration);

            /*
             | The number on the record has to be the number the code reached.
             | Without this a code could go to one handset and the handover be written
             | against somebody else's details. Checked before verify() so a mismatch
             | does not cost an attempt.
             */
            if ($pending !== null
                && $pending->phone !== PhoneNumber::toInternational((string) $validated['collector_phone'])) {
                return back()->withInput()->withErrors([
                    'collector_phone' => 'The code for this entry went to a different number. Correct the number, or send a new code to this one.',
                ]);
            }

            $check = $verifier->verify($registration, (string) ($validated['code'] ?? ''));

            if (! $check->isVerified()) {
                // Nothing is handed over on a failed code.
                return back()->withInput()->withErrors(['code' => $check->reason()]);
            }

            $verification = $check->verification;
        }

        $named = $kind === CollectionHandover::KIND_OTHER
            ? [
                'name' => trim((string) ($validated['collector_name'] ?? '')),
                'ic' => trim((string) ($validated['collector_ic'] ?? '')),
                'phone' => trim((string) ($validated['collector_phone'] ?? '')),
            ]
            : null;

        $user = $request->user();
        $written = [];

        /*
         | The batch, as one transaction. One collector and one verification across
         | however many rows were ticked, so six shirts leaving in one pair of hands
         | leave six records naming that pair of hands — which is the whole point: when
         | an absent member says later that they never got theirs, the record says who
         | did take it.
         */
        DB::transaction(function () use ($rows, $named, $verification, $override, $paymentOverride, $user, &$written) {
            foreach ($rows as $row) {
                /** @var EventRegistrationAddon $line */
                $line = $row['line'];

                /*
                 | Re-read under a lock. The unique index on the collectable pair is
                 | what makes a second handover impossible; this is what makes two
                 | simultaneous presses queue up behind each other instead of one of
                 | them failing on the index.
                 */
                $existing = CollectionHandover::query()
                    ->where('collectable_type', $line->getMorphClass())
                    ->where('collectable_id', $line->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    continue;
                }

                $collector = $named ?? [
                    'name' => $row['participant']->full_name,
                    'ic' => $row['participant']->ic_number,
                    'phone' => $row['participant']->phone,
                ];

                /*
                 | Self or not, decided per row off the identity card rather than off
                 | the radio button. The representative case is one participant taking
                 | the whole grouping's shirts, and their own row genuinely is a person
                 | collecting their own: recording it as a third-party handover would
                 | misdescribe it, and recording the other five as self-collection
                 | would be a great deal worse.
                 */
                $rowKind = $named === null || $this->sameCard($row['participant']->ic_number, $named['ic'])
                    ? CollectionHandover::KIND_BUYER
                    : CollectionHandover::KIND_OTHER;

                $handover = CollectionHandover::create([
                    'collectable_type' => $line->getMorphClass(),
                    'collectable_id' => $line->getKey(),
                    'collector_kind' => $rowKind,
                    'collector_name' => $collector['name'],
                    'collector_ic' => $collector['ic'],
                    'collector_phone' => $collector['phone'],
                    'collection_verification_id' => $verification?->id,
                    'verified_at' => $verification?->verified_at,
                    'override_reason' => $override === '' ? null : $override,
                    'payment_override_reason' => $paymentOverride === '' ? null : $paymentOverride,
                    'confirmed_by' => $user?->id,
                    'confirmed_by_label' => $user?->logLabel(),
                    'collected_at' => now(),
                ]);

                /*
                 | On the line, because the line is the thing collected. No code and no
                 | hash: this trail is read by people and neither would tell them
                 | anything.
                 */
                AdminLogger::audit($line, 'collected', null, [
                    'participant' => $row['participant']->full_name,
                    'ic_number' => $row['participant']->ic_number,
                    'reference' => $row['registration']->reference,
                    'item' => $row['addon']->name,
                    'option' => $row['option'],
                    'collector_kind' => $rowKind,
                    'collector_name' => $collector['name'],
                    'sms_verified' => $verification !== null,
                    'override_reason' => $override === '' ? null : $override,
                    'payment_override_reason' => $paymentOverride === '' ? null : $paymentOverride,
                    'collected_at' => $handover->collected_at?->toDateTimeString(),
                ]);

                $written[] = $row;
            }
        });

        if ($written === []) {
            return back()->with('warning', sprintf(
                'Those items on %s were already recorded as collected. Nothing was changed.',
                $registration->reference,
            ));
        }

        $collectorLabel = $named === null
            ? 'each person themselves'
            : $named['name'];

        AdminLogger::activity('collection.handed-over', sprintf(
            'Handed over %d %s on %s to %s.%s%s',
            count($written),
            count($written) === 1 ? 'item' : 'items',
            $registration->reference,
            $collectorLabel,
            $override === '' ? '' : ' No SMS verification: '.$override,
            $paymentOverride === '' ? '' : ' Money still owed: '.$paymentOverride,
        ));

        return back()->with($override === '' && $paymentOverride === '' ? 'status' : 'warning', trim(sprintf(
            '%s handed over on %s to %s at %s.%s%s',
            $this->namesOf($written),
            $registration->reference,
            $collectorLabel,
            LocalTime::format(now()),
            $override === '' ? '' : ' Marked as handed over without SMS verification.',
            $paymentOverride === '' ? '' : sprintf(' Marked as handed over with %s still owed.', $registration->outstandingAmountLabel()),
        )));
    }

    /**
     * The list as a CSV, grouped by option.
     *
     * The printed fallback. Venue signal is unreliable and a counter that loses the
     * screen still has to hand four hundred shirts out, so the file is ordered the way
     * the boxes are stacked: every S together, then every M, with the people who still
     * owe a choice at the end of their item where they cannot be missed.
     *
     * Scoped to one event, like the Participants export it follows. A single file
     * holding every identity card number this organisation has ever collected is a
     * different risk from one event's, so the request is refused rather than quietly
     * widened.
     *
     * Behind participants.export, the permission that already governs taking
     * participant detail out of the system.
     */
    public function export(Request $request)
    {
        $filters = $this->filters($request);

        if ($filters['event'] === '') {
            return back()->with('warning', 'Choose an event before exporting. One file covering every event would carry more personal data than any single job needs.');
        }

        /*
         | visibleTo as well as the request scope, for the reason the other two exports
         | say it too: this is the query that writes the file.
         */
        /** @var Event $event */
        $event = Event::query()
            ->visibleTo($request->user())
            ->with('addons.variants')
            ->whereKey($filters['event'])
            ->firstOrFail();

        $handedOver = [(int) $event->id => HandoverSheet::addonIdsFor($event)];
        $choices = [
            (int) $event->id => ParticipantSizes::addonsFor($event)
                ->filter(fn (EventAddon $addon) => $addon->isHandedOver())
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all(),
        ];

        /*
         | Buffered rather than streamed row by row, because grouping by option means
         | the order cannot be known until every row has been read. Bounded by the one
         | event the request is scoped to, and a row is a handful of short strings.
         */
        $rows = [];

        $this->filtered($filters, $handedOver, $choices)
            ->with(['registration.event.addons.variants', 'registration.addonLines.handover'])
            ->chunkById(100, function ($people) use (&$rows) {
                foreach ($people as $participant) {
                    foreach (HandoverSheet::rowsForParticipant($participant) as $row) {
                        $rows[] = $row;
                    }
                }
            }, 'event_participants.id', 'id');

        usort($rows, function (array $a, array $b) {
            return [
                (int) $a['addon']->sort_order,
                (string) $a['addon']->name,
                // Blank last: "no option recorded" is the group a counter has to deal
                // with by hand, and it belongs at the bottom of its item rather than
                // sorted to the top by an empty string.
                $a['option'] === null ? 1 : 0,
                (string) ($a['option'] ?? ''),
                (string) $a['participant']->full_name,
            ] <=> [
                (int) $b['addon']->sort_order,
                (string) $b['addon']->name,
                $b['option'] === null ? 1 : 0,
                (string) ($b['option'] ?? ''),
                (string) $b['participant']->full_name,
            ];
        });

        AdminLogger::activity('collection.export', sprintf(
            'Exported the collection list for %s (%d %s).',
            $event->title,
            count($rows),
            count($rows) === 1 ? 'row' : 'rows',
        ));

        $header = [
            'Item', 'Option', 'Participant', 'Identity Card', 'Reference', 'Entry',
            'Payment', 'Outstanding', 'Collected', 'Collected At',
            'Collected By', 'Collector IC', 'Collector Phone', 'Verification', 'Recorded By',
        ];

        return response()->streamDownload(function () use ($rows, $header) {
            $handle = fopen('php://output', 'wb');

            // Byte order mark. Malaysian names carry characters Excel reads as
            // mojibake without it, which ruins the file for anybody who opens it by
            // double clicking, which is everybody.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, $header);

            foreach ($rows as $row) {
                fputcsv($handle, $this->exportRow($row));
            }

            fclose($handle);
        }, sprintf('collection-%s-%s.csv', $event->slug, now()->format('Ymd-His')), [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /* ---------------------------------------------------------------------
     | The query
     * ------------------------------------------------------------------ */

    /**
     * The filters, read once and validated down to values that exist.
     *
     * @return array{search: string, event: string, state: string, choice: string, payment: string}
     */
    private function filters(Request $request): array
    {
        $state = (string) $request->query('state', '');
        $payment = (string) $request->query('payment', '');

        return [
            'search' => trim((string) $request->query('q')),
            'event' => trim((string) $request->query('event')),
            'state' => array_key_exists($state, self::STATES) ? $state : '',
            'choice' => $request->query('choice') === 'missing' ? 'missing' : '',
            'payment' => array_key_exists($payment, self::PAYMENTS) ? $payment : '',
        ];
    }

    /**
     * THE one place the filters on this screen are expressed.
     *
     * The rows, the running counts and the CSV are all built from it, because the
     * alternative has already cost this project once: a screen filtered to one event
     * reporting another event's figures beside it. Two queries describing the same
     * intent is how they drift.
     *
     * The unit is a participant, not a line and not a registration. A line would hide
     * the people who never answered the size link, who are exactly the ones standing
     * at the desk; a registration cannot be collected one person at a time.
     *
     * @param  array{search: string, event: string, state: string, choice: string, payment: string}  $filters
     * @param  array<int, array<int, int>>  $handedOver  eventId => add-on ids it hands over
     * @param  array<int, array<int, int>>  $choices  eventId => add-on ids a counter may choose for
     * @return Builder<EventParticipant>
     */
    private function filtered(array $filters, array $handedOver, array $choices): Builder
    {
        $eventIds = array_keys($handedOver);
        $search = $filters['search'];

        return EventParticipant::query()
            ->whereHas('registration', function (Builder $entry) use ($eventIds, $filters) {
                /*
                 | Only events that hand something over, and never a cancelled entry:
                 | nobody is waiting for one, so listing it would put a queue position
                 | in front of a counter that has nothing to give them.
                 |
                 | An empty list matches nothing, which is how an event with no
                 | handed-over add-ons draws an empty screen rather than an error.
                 */
                $entry->whereIn('event_id', $eventIds)
                    ->where('status', '!=', EventRegistration::STATUS_CANCELLED);

                match ($filters['payment']) {
                    'paid' => $entry->where('payment_status', EventRegistration::PAYMENT_PAID),
                    'partial' => $entry->where('payment_status', EventRegistration::PAYMENT_PARTIAL),
                    'owing' => $entry->where('payment_status', '!=', EventRegistration::PAYMENT_PAID),
                    default => $entry,
                };
            })
            /*
             | Identity card, name and reference, which is what a counter types or
             | scans. Prefixed with the table because the figures below join this query
             | to the registrations to count rows per event.
             */
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $inner) use ($search) {
                $inner->where('event_participants.full_name', 'like', "%{$search}%")
                    ->orWhere('event_participants.ic_number', 'like', "%{$search}%")
                    ->orWhereHas('registration', fn (Builder $entry) => $entry
                        ->where('reference', 'like', "%{$search}%")
                        ->orWhere('team_name', 'like', "%{$search}%"));
            }))
            ->when($filters['state'] !== '', fn (Builder $query) => $this->byState(
                $query,
                $handedOver,
                $filters['state'] === 'collected',
            ))
            ->when($filters['choice'] === 'missing', fn (Builder $query) => $this->missingChoice($query, $choices));
    }

    /**
     * Narrow to people whose items have all gone out, or who still have one waiting.
     *
     * Grouped by event because what counts as "all of them" is a property of the
     * event: one event hands out a shirt, another a shirt and a cap. Still one SQL
     * statement — a handful of EXISTS clauses, and in the ordinary case where the
     * screen is filtered to one event handing out one thing, exactly one.
     *
     * @param  Builder<EventParticipant>  $query
     * @param  array<int, array<int, int>>  $handedOver
     */
    private function byState(Builder $query, array $handedOver, bool $collected): void
    {
        $query->where(function (Builder $outer) use ($handedOver, $collected) {
            foreach ($handedOver as $eventId => $addonIds) {
                $outer->orWhere(function (Builder $perEvent) use ($eventId, $addonIds, $collected) {
                    $perEvent->whereHas('registration', fn (Builder $entry) => $entry->where('event_id', $eventId));

                    if ($collected) {
                        // Every one of this event's items has a line for them carrying
                        // a handover.
                        foreach ($addonIds as $addonId) {
                            $perEvent->whereHas('addonLines', fn (Builder $line) => $line
                                ->where('event_addon_id', $addonId)
                                ->whereHas('handover'));
                        }

                        return;
                    }

                    // At least one still waiting, which is what a counter queue is.
                    $perEvent->where(function (Builder $any) use ($addonIds) {
                        foreach ($addonIds as $addonId) {
                            $any->orWhereDoesntHave('addonLines', fn (Builder $line) => $line
                                ->where('event_addon_id', $addonId)
                                ->whereHas('handover'));
                        }
                    });
                });
            }

            // No event hands anything over, so nothing matches. Never widens the set.
            $outer->orWhereRaw('1 = 0');
        });
    }

    /**
     * Narrow to people who have still chosen no option for something they are owed.
     *
     * The same two shapes ParticipantSizes already recognises: a line that exists and
     * names no option, and no line at all. Both read as outstanding here, because both
     * mean the counter has nothing to put in their hands.
     *
     * @param  Builder<EventParticipant>  $query
     * @param  array<int, array<int, int>>  $choices
     */
    private function missingChoice(Builder $query, array $choices): void
    {
        $query->where(function (Builder $outer) use ($choices) {
            foreach ($choices as $eventId => $addonIds) {
                if ($addonIds === []) {
                    continue;
                }

                $outer->orWhere(function (Builder $perEvent) use ($eventId, $addonIds) {
                    $perEvent->whereHas('registration', fn (Builder $entry) => $entry->where('event_id', $eventId))
                        ->where(function (Builder $any) use ($addonIds) {
                            foreach ($addonIds as $addonId) {
                                $any->orWhereDoesntHave('addonLines', fn (Builder $line) => $line
                                    ->where('event_addon_id', $addonId)
                                    ->whereNotNull('event_addon_variant_id'));
                            }
                        });
                });
            }

            $outer->orWhereRaw('1 = 0');
        });
    }

    /**
     * Handed over against total, and how many choices are still owed.
     *
     * Aggregates over the list's own query, so the figures describe exactly the rows
     * the filters describe and switching a filter cannot make the counter appear to
     * change what it has done. Three queries plus one per item that collects a
     * choice, whatever the page holds — nothing is hydrated to be counted.
     *
     * @param  Builder<EventParticipant>  $base
     * @param  array<int, array<int, int>>  $handedOver
     * @param  array<int, array<int, int>>  $choices
     * @return array{total: int, collected: int, outstanding: int, missing: int}
     */
    private function figures(Builder $base, array $handedOver, array $choices): array
    {
        /*
         | How many people the filters leave, per event. A row is a person and an item,
         | so the row count is that figure multiplied by what each event hands over —
         | which is why it has to be counted per event rather than in one lump.
         |
         | Down to the base query builder before it runs, because pluck() on an
         | Eloquent builder rewrites the select list and would throw the aggregate
         | away.
         */
        $people = (clone $base)
            ->join('event_registrations', 'event_registrations.id', '=', 'event_participants.event_registration_id')
            ->selectRaw('event_registrations.event_id as event_id, count(*) as people')
            ->groupBy('event_registrations.event_id')
            ->toBase()
            ->get();

        $total = 0;
        $choiceTotal = 0;

        foreach ($people as $group) {
            $eventId = (int) $group->event_id;
            $count = (int) $group->people;

            $total += $count * count($handedOver[$eventId] ?? []);
            $choiceTotal += $count * count($choices[$eventId] ?? []);
        }

        $handedOverIds = $this->flatten($handedOver);
        $choiceIds = $this->flatten($choices);

        $collected = $handedOverIds === []
            ? 0
            : $this->handoverCount((clone $base)->select('event_participants.id'), $handedOverIds);

        /*
         | Choices on record, counted one item at a time with count(distinct person).
         | A single count over two columns would need count(distinct a, b), which MySQL
         | has and SQLite does not, and the test suite runs on SQLite.
         */
        $chosen = 0;

        foreach ($choiceIds as $addonId) {
            $chosen += EventRegistrationAddon::query()
                ->where('event_addon_id', $addonId)
                ->whereNotNull('event_addon_variant_id')
                ->whereIn('event_participant_id', (clone $base)->select('event_participants.id'))
                ->distinct()
                ->count('event_participant_id');
        }

        return [
            'total' => $total,
            'collected' => $collected,
            'outstanding' => max(0, $total - $collected),
            'missing' => max(0, $choiceTotal - $chosen),
        ];
    }

    /**
     * Every add-on id in a per-event map, once each.
     *
     * @param  array<int, array<int, int>>  $byEvent
     * @return array<int, int>
     */
    private function flatten(array $byEvent): array
    {
        $ids = [];

        foreach ($byEvent as $addonIds) {
            foreach ($addonIds as $addonId) {
                $ids[(int) $addonId] = (int) $addonId;
            }
        }

        return array_values($ids);
    }

    /**
     * How many of the rows in scope already have a handover against them.
     *
     * @param  Builder<EventParticipant>  $participantIds
     * @param  array<int, int>  $handedOverIds
     */
    private function handoverCount(Builder $participantIds, array $handedOverIds): int
    {
        $lineIds = EventRegistrationAddon::query()
            ->select('event_registration_addons.id')
            ->whereIn('event_addon_id', $handedOverIds)
            ->whereIn('event_participant_id', $participantIds);

        return CollectionHandover::query()
            ->where('collectable_type', (new EventRegistrationAddon)->getMorphClass())
            ->whereIn('collectable_id', $lineIds)
            ->count();
    }

    /* ---------------------------------------------------------------------
     | Wording
     * ------------------------------------------------------------------ */

    /**
     * What the figures and the rows cover, for the caption under them.
     *
     * @param  array{search: string, event: string, state: string, choice: string, payment: string}  $filters
     * @param  \Illuminate\Support\Collection<int, Event>  $events
     */
    private function scopeLabel(array $filters, $events): string
    {
        $parts = [
            $filters['event'] !== ''
                ? Str::limit((string) ($events->firstWhere('id', (int) $filters['event'])?->title ?? 'Chosen event'), 40)
                : 'All events handing something over',
        ];

        if ($filters['state'] !== '') {
            $parts[] = self::STATES[$filters['state']];
        }

        if ($filters['payment'] !== '') {
            $parts[] = self::PAYMENTS[$filters['payment']];
        }

        if ($filters['choice'] === 'missing') {
            $parts[] = 'No option recorded';
        }

        if ($filters['search'] !== '') {
            $parts[] = 'matching "'.$filters['search'].'"';
        }

        return implode(' · ', $parts);
    }

    /**
     * The people on a set of rows, named, for a sentence an operator reads.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function namesOf(array $rows): string
    {
        $names = collect($rows)
            ->map(fn (array $row) => (string) $row['participant']->full_name)
            ->unique()
            ->values();

        if ($names->count() <= 3) {
            return $names->join(', ', ' and ');
        }

        return sprintf(
            '%s and %d others',
            $names->take(2)->join(', '),
            $names->count() - 2,
        );
    }

    /**
     * What the text message says the code is for.
     *
     * Names the item and the entry, so somebody holding two codes can tell them
     * apart. CollectionVerifier trims it to fit one segment.
     *
     * @param  array<int, array<string, mixed>>  $outstanding
     */
    private function codeSubject(EventRegistration $registration, array $outstanding): string
    {
        $items = collect($outstanding)
            ->map(fn (array $row) => (string) $row['addon']->name)
            ->unique();

        return sprintf(
            '%s on %s',
            $items->count() === 1 ? $items->first() : 'the items',
            $registration->reference,
        );
    }

    /**
     * The submitted choices, cut down to the ones this screen hands over.
     *
     * Shaped [participantId][addonId] => variantId on the way in and on the way out,
     * because that is what ParticipantSizeWriter reads. The sheet decides which pairs
     * survive: a key naming somebody on another entry, an item this event does not
     * hand over, or an item with no options to choose between is not ignored so much
     * as never looked at.
     *
     * @param  mixed  $submitted
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<int, mixed>>
     */
    private function submittedChoices($submitted, array $rows): array
    {
        if (! is_array($submitted) || $submitted === []) {
            return [];
        }

        $choices = [];

        foreach ($rows as $row) {
            if (! $row['collects_choice']) {
                continue;
            }

            $participantId = (int) $row['participant']->id;
            $addonId = (int) $row['addon']->id;

            $value = data_get($submitted, $participantId.'.'.$addonId);

            if (blank($value)) {
                continue;
            }

            $choices[$participantId][$addonId] = $value;
        }

        return $choices;
    }

    /**
     * Whether two identity card numbers are the same card.
     *
     * Digits only, because the same card is written 900101-01-0001 by one person and
     * 900101010001 by the next, and a self-collection recorded as a third-party
     * handover over a pair of dashes would be a record nobody could read.
     */
    private function sameCard(?string $left, ?string $right): bool
    {
        $left = preg_replace('/\D+/', '', (string) $left) ?? '';
        $right = preg_replace('/\D+/', '', (string) $right) ?? '';

        return $left !== '' && $left === $right;
    }

    /**
     * One row of the CSV, in the same order as the header.
     *
     * @param  array<string, mixed>  $row
     * @return array<int, string>
     */
    private function exportRow(array $row): array
    {
        /** @var EventRegistration $registration */
        $registration = $row['registration'];
        /** @var CollectionHandover|null $handover */
        $handover = $row['handover'];

        return [
            (string) $row['addon']->name,
            (string) ($row['option'] ?? 'Not recorded'),
            (string) $row['participant']->full_name,
            (string) $row['participant']->ic_number,
            (string) $registration->reference,
            $registration->displayName(),
            $registration->paymentStatusLabel(),
            $registration->outstandingAmountLabel(),
            $handover === null ? 'No' : 'Yes',
            $handover?->collected_at === null ? '' : LocalTime::format($handover->collected_at),
            (string) ($handover?->collector_name ?? ''),
            (string) ($handover?->collector_ic ?? ''),
            (string) ($handover?->collector_phone ?? ''),
            $handover === null ? '' : $this->assurance($handover),
            (string) ($handover?->confirmed_by_label ?? ''),
        ];
    }

    /**
     * How sure we are about who took it, in words.
     *
     * Written here rather than read off the model, because the model's own wording is
     * the shop's: it talks about a buyer and an order. The fact is the same one.
     */
    private function assurance(CollectionHandover $handover): string
    {
        if ($handover->isVerified()) {
            return 'SMS code verified';
        }

        if ($handover->byBuyer()) {
            return 'Identity card checked at the counter';
        }

        return 'Handed over without SMS verification: '.($handover->override_reason ?? '');
    }
}
