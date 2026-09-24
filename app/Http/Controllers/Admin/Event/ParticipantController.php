<?php

namespace App\Http\Controllers\Admin\Event;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateParticipantRequest;
use App\Models\Event;
use App\Models\EventAddonVariant;
use App\Models\EventParticipant;
use App\Models\EventParticipantAnswer;
use App\Models\EventParticipantChange;
use App\Models\EventRegistration;
use App\Models\EventRegistrationPayment;
use App\Services\AdminLogger;
use App\Services\EventNotifier;
use App\Services\Payment\PaymentGatewayException;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\RegistrationPaymentUpdater;
use App\Services\Payment\RegistrationTally;
use App\Support\EventTemplates;
use App\Support\GatewayPaymentRecord;
use App\Support\ParticipantOptions;
use App\Support\PaymentFigures;
use App\Support\PaymentSettings;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Everyone who has registered, across every event.
 *
 * A row is one registration rather than one person, because that is the unit
 * that carries a payment: a squad of five is one entry with one amount owed.
 * The people on it are listed inside the row and in full on the detail page.
 */
class ParticipantController extends Controller
{
    /**
     * Tab slug => label and icon.
     *
     * The first two split by how the entry was made, the last two by whether it
     * has been paid for, so the same registration can appear under one of each
     * pair. That is deliberate: they answer different questions.
     */
    public const TABS = [
        'individual' => ['label' => 'Individual', 'icon' => 'users'],
        'team' => ['label' => 'Team', 'icon' => 'identification'],
        'paid' => ['label' => 'Paid', 'icon' => 'credit-card'],
        'unpaid' => ['label' => 'Unpaid', 'icon' => 'lock'],
    ];

    private const PER_PAGE = 20;

    /** Where transfer slips attached to hand-recorded payments live. */
    private const PAYMENT_PROOF_DIRECTORY = 'registration-payment-proof';

    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly RegistrationPaymentUpdater $updater,
    ) {
    }

    public function index(Request $request)
    {
        $tab = $this->resolveTab($request->query('tab'));

        $search = trim((string) $request->query('q'));
        $eventId = trim((string) $request->query('event'));

        $registrations = $this->scoped($tab)
            // checkouts is loaded for the tally dialog, which lists the purchases on
            // record. Without it the list would query once per row.
            ->with(['event', 'participants', 'addonLines', 'checkouts'])
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $inner) use ($search) {
                $inner->where('reference', 'like', "%{$search}%")
                    ->orWhere('team_name', 'like', "%{$search}%")
                    ->orWhere('payment_reference', 'like', "%{$search}%")
                    // Searching a person's name has to reach through to the
                    // people on the registration, not just its own columns.
                    ->orWhereHas('participants', fn (Builder $people) => $people
                        ->where('full_name', 'like', "%{$search}%")
                        ->orWhere('ic_number', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"));
            }))
            ->when($eventId !== '', fn (Builder $query) => $query->where('event_id', $eventId))
            ->latest()
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $counts = $this->counts();

        return view('admin.event.participants', [
            'tabs' => collect(self::TABS)
                ->map(fn (array $definition, string $slug) => $definition + ['count' => $counts[$slug] ?? 0])
                ->all(),
            'activeTab' => $tab,
            'registrations' => $registrations,

            // Resolved once here rather than per row: the table can hold twenty
            // registrations and the answer is the same for all of them.
            'canNotify' => $request->user()->hasPermission('participants.notify'),
            'canDelete' => $request->user()->hasPermission('participants.delete'),
            'canRecordPayment' => $request->user()->hasPermission('payments.record'),
            'canTally' => $request->user()->hasPermission('payments.tally'),
            'canExport' => $request->user()->hasPermission('participants.export'),
            'canTransfer' => $request->user()->hasPermission('participants.transfer'),

            /*
             | Events an entry could be moved to. Only those still accepting entries,
             | because moving one onto a finished event would create something the
             | organiser cannot run. The mode is carried so the dialog can say which
             | shape each one takes rather than leaving the refusal until submit.
             */
            'transferTargets' => Event::query()
                ->whereIn('status', Event::REGISTERABLE)
                ->orderBy('title')
                ->get(['id', 'title', 'registration_mode', 'fee']),

            // Only events that actually have entries, so the filter never offers
            // a choice that returns nothing.
            'events' => Event::query()
                ->whereHas('registrations')
                ->orderBy('title')
                ->pluck('title', 'id')
                ->all(),

            'search' => $search,
            'eventId' => $eventId,
            'isFiltered' => $search !== '' || $eventId !== '',
            /*
             | Read through PaymentFigures rather than summed here.
             |
             | These two were counted inline, which meant they answered slightly
             | different questions from the same figures on the Payments screens:
             | free entries were included, refunds were not subtracted, and now that
             | part-paid entries exist the difference would have grown into showing
             | money as both collected and outstanding at once.
             */
            'totals' => [
                'collected' => PaymentFigures::collected(),
                'outstanding' => PaymentFigures::outstanding(),
            ],
        ]);
    }

    /**
     * One registration in full, including whatever the gateway holds about its
     * payment.
     */
    public function show(Request $request, EventRegistration $registration)
    {
        $registration->load(['event', 'participants.answers', 'addonLines', 'notifications.triggeredBy', 'payments.recordedBy']);

        $reachedGateway = $this->refreshPayment($registration);

        return view('admin.event.participant-show', [
            'registration' => $registration,
            'event' => $registration->event,

            // Which templates can be sent again by hand. Only the email ones:
            // there is no SMS transport wired up yet, so offering it would be a
            // button that does nothing.
            'resendable' => collect(EventTemplates::keys())
                ->mapWithKeys(fn (string $key) => [$key => EventTemplates::definition($key)['label']])
                ->all(),

            'canNotify' => $request->user()->hasPermission('participants.notify'),
            'canDelete' => $request->user()->hasPermission('participants.delete'),

            // Correcting one person and taking one person off are separate acts:
            // the first fixes a record, the second destroys one.
            'canUpdatePerson' => $request->user()->hasPermission('participants.update'),
            'canRemovePerson' => $request->user()->hasPermission('participants.remove'),

            // The gateway record, verbatim. Null when there has never been one.
            'payment' => GatewayPaymentRecord::make($registration->payment_details),

            // Whether what is on screen came from the gateway just now, or from
            // the last time it answered. The page says which.
            'reachedGateway' => $reachedGateway,
            'gatewayLabel' => PaymentSettings::providerLabel(),
        ]);
    }

    /**
     * Send one of the templates again, by hand.
     *
     * Exists because a bounce is invisible to the registrant: they simply never
     * hear from us. Someone correcting an address needs a way to make the
     * message go out again without re-registering the team.
     */
    public function resend(Request $request, EventRegistration $registration, EventNotifier $notifier)
    {
        $validated = $request->validate([
            'template_key' => ['required', 'string', Rule::in(EventTemplates::keys())],
        ]);

        $key = $validated['template_key'];

        $queued = $notifier->resend($registration, $key, $request->user()?->id);

        $label = EventTemplates::definition($key)['label'];

        if ($queued === 0) {
            // Nothing went out. Usually the template is switched off, or nobody
            // on the entry has an address. The log panel below says which.
            return back()->with('warning', sprintf(
                '%s was not sent. Check the template is switched on and that there is an email address on file.',
                $label,
            ));
        }

        return back()->with('status', sprintf(
            '%s queued to %d recipient%s.',
            $label,
            $queued,
            $queued === 1 ? '' : 's',
        ));
    }

    /**
     * Chase an entry that has not been paid for.
     *
     * Kept as a button rather than a schedule: when to lean on somebody is a
     * judgement about the event, not something to automate.
     */
    public function remind(Request $request, EventRegistration $registration, EventNotifier $notifier)
    {
        /*
         | owesBalance() rather than awaitingPayment(): a part-paid entry still owes
         | something and is exactly the kind worth chasing, but it is not one the
         | gateway should be offered, which is what awaitingPayment() answers.
         */
        if (! $registration->owesBalance()) {
            return back()->with('warning', sprintf(
                'Nothing to chase on %s: it is %s.',
                $registration->reference,
                $registration->isFree() ? 'free of charge' : strtolower($registration->paymentStatusLabel()),
            ));
        }

        $queued = $notifier->paymentReminder($registration, $request->user()?->id);

        if ($queued === 0) {
            return back()->with('warning', sprintf(
                'No reminder went out for %s. Check the Payment Reminder template is switched on, and that the registrant has an email address.',
                $registration->reference,
            ));
        }

        AdminLogger::activity(
            'participants.remind',
            sprintf('Sent a payment reminder for %s.', $registration->reference),
        );

        return back()->with('status', sprintf(
            'Payment reminder queued for %s (%s outstanding).',
            $this->registrant($registration)?->full_name ?? $registration->reference,
            $registration->outstandingAmountLabel(),
        ));
    }

    /**
     * Record money that arrived outside the gateway.
     *
     * The case this exists for: an entrant transferred the fee, the site failed
     * before their entry was confirmed, and the office is holding a receipt no
     * machine has ever seen. The alternatives were leaving a paying entrant marked
     * unpaid or inventing a gateway reference, and both are worse.
     *
     * Nothing here observed the money. Every figure this moves rests on a person
     * asserting they saw it, which is why it carries its own permission, is logged
     * as an assertion, and asks when the money arrived rather than assuming it was
     * now.
     */
    public function recordPayment(Request $request, EventRegistration $registration)
    {
        if ($registration->isFree()) {
            return back()->withInput()->withErrors([
                'record_payment' => sprintf('%s is free of charge, so there is no payment to record.', $registration->reference),
            ]);
        }

        if (! $registration->owesBalance()) {
            return back()->withInput()->withErrors([
                'record_payment' => sprintf(
                    'Nothing is owed on %s: it is %s.',
                    $registration->reference,
                    strtolower($registration->paymentStatusLabel()),
                ),
            ]);
        }

        $outstanding = $registration->outstandingAmount();

        $validated = $request->validate([
            // Split into two boxes because that is how somebody reads a receipt.
            // Recombined below into the one datetime the ledger stores.
            'received_date' => ['required', 'date', 'before_or_equal:today'],
            'received_time' => ['required', 'date_format:H:i'],

            'reference' => ['nullable', 'string', 'max:190'],
            'note' => ['nullable', 'string', 'max:255'],

            /*
             | The transfer slip or screenshot. Optional and staying optional: cash
             | across a counter has no slip, and refusing the payment without a file
             | would push somebody into either not recording it or attaching something
             | irrelevant.
             |
             | mimes checks the type guessed from the file's own contents rather than
             | the extension it arrived with, so a renamed file is caught.
             */
            'proof' => [
                'nullable',
                'file',
                'mimes:' . EventRegistrationPayment::PROOF_MIMES,
                'max:' . EventRegistrationPayment::PROOF_MAX_KB,
            ],

            'settlement' => ['required', 'in:full,partial'],

            /*
             | Only read when partial was chosen, and capped at the balance. An
             | overpayment is a different problem with a different answer, and
             | accepting one here would push the entry's outstanding figure negative
             | and quietly reduce what everybody else on the event owes.
             */
            'amount' => ['nullable', 'required_if:settlement,partial', 'numeric', 'min:0.01', 'max:' . $outstanding],
        ], [
            'received_date.required' => 'Enter the date the money arrived.',
            'received_date.before_or_equal' => 'The money cannot have arrived in the future.',
            'received_time.required' => 'Enter the time the money arrived.',
            'received_time.date_format' => 'Enter the time as HH:MM, for example 14:30.',
            'proof.mimes' => 'Attach a PDF or a picture: PDF, PNG, JPG or JPEG.',
            'proof.max' => 'That file is too large. Please keep it under 8 MB.',
            'settlement.required' => 'Say whether this settles the whole balance or part of it.',
            'amount.required_if' => 'Enter how much arrived.',
            'amount.max' => sprintf(
                'That is more than is owed. The outstanding balance on this entry is %s.',
                PaymentFigures::money($outstanding),
            ),
        ]);

        $amount = $validated['settlement'] === 'full'
            ? $outstanding
            : round((float) $validated['amount'], 2);

        $receivedAt = sprintf('%s %s:00', Carbon::parse($validated['received_date'])->toDateString(), $validated['received_time']);

        $proof = $request->file('proof');

        $this->updater->recordManualPayment(
            registration: $registration,
            amount: $amount,
            receivedAt: $receivedAt,
            reference: $validated['reference'] ?? null,
            note: $validated['note'] ?? null,
            proofPath: $proof?->store(self::PAYMENT_PROOF_DIRECTORY, 'public'),
            proofName: $proof === null ? null : $this->displayFileName($proof->getClientOriginalName()),
        );

        $registration->refresh();

        return back()->with('status', sprintf(
            '%s recorded on %s. %s',
            PaymentFigures::money($amount),
            $registration->reference,
            $registration->isPaid()
                ? 'It is now paid in full and the entry is confirmed.'
                : sprintf('%s is still outstanding.', $registration->outstandingAmountLabel()),
        ));
    }

    /**
     * Delete one registration, giving back the capacity it was holding.
     *
     * Child rows go with it through cascading foreign keys. The two things the
     * database cannot work out on its own are the counters: seats on the event
     * and stock on each add-on size. Nothing else in the system decrements
     * either, so if this did not do it the event would quietly lose capacity
     * every time an entry was removed, and an add-on size could never be edited
     * again because stock_taken would stay above the real figure.
     */
    public function destroy(EventRegistration $registration)
    {
        // A settled payment is a financial record, and the money still sits with
        // the gateway. Refunding and cancelling is the honest path; deleting
        // would leave the books disagreeing with the gateway's dashboard.
        /*
         | Judged on whether money moved, not on the payment_status flag.
         |
         | A free entry is marked paid the instant it is submitted, because nothing
         | is owed. Reading that flag as "settled financial record" meant a free
         | entry could never be deleted, on the grounds that the books would
         | disagree with the gateway. There are no books and no gateway on an entry
         | that cost nothing.
         */
        if ($registration->hasMoneyOnRecord()) {
            return back()->withInput()->withErrors([
                'registration' => sprintf(
                    '%s cannot be deleted because %s has been taken for it. Refund it at the gateway first, or leave it for the record.',
                    $registration->reference,
                    $registration->amountLabel(),
                ),
            ]);
        }

        $registration->load(['event', 'participants', 'addonLines', 'payments']);

        $reference = $registration->reference;
        $name = $registration->displayName();
        $logoPath = $registration->logo_path;
        $headCount = $registration->participants->count();

        /*
         | Collected before the delete. The receipt rows go with the registration
         | through the cascading foreign key, and once they are gone there is nothing
         | left to tell us which files on disk belonged to them.
         */
        $proofPaths = $registration->payments
            ->pluck('proof_path')
            ->filter()
            ->all();

        AdminLogger::audit($registration, 'deleted', [
            'reference' => $reference,
            'event' => $registration->event?->title,
            'team_name' => $registration->team_name,
            'people' => $headCount,
            'amount' => $registration->amount,
            'payment_status' => $registration->payment_status,
            'people_named' => $registration->participants->pluck('full_name')->all(),
        ], null);

        /*
         | Given back in the same unit it was taken in. A squad occupied one place
         | however many players it named, so deleting it frees one, not seven.
         | Returning the head count would have handed the event six places it never
         | had, and the message would have claimed it too.
         |
         | Read from the loaded event rather than the locked row below because the
         | mode decides the unit and the mode is not what the lock protects; the
         | lock is there for the arithmetic on the counter.
         */
        $seatsHeld = $registration->event?->seatsForEntry($headCount) ?? 0;

        DB::transaction(function () use ($registration, $seatsHeld) {
            // Locked and clamped the same way the public form takes them, so two
            // administrators deleting at once cannot push the count negative.
            if ($registration->event_id !== null && $seatsHeld > 0) {
                $event = Event::query()->whereKey($registration->event_id)->lockForUpdate()->first();

                if ($event !== null) {
                    $event->seats_taken = max(0, $event->seats_taken - $seatsHeld);
                    $event->save();
                }
            }

            // Extras were taken out of stock at submission, not at payment, so
            // they have to go back regardless of whether anything was ever paid.
            foreach ($registration->addonLines as $line) {
                // Null when the size has since been removed from the catalogue;
                // there is nothing left to credit.
                if ($line->event_addon_variant_id === null) {
                    continue;
                }

                $variant = EventAddonVariant::query()
                    ->whereKey($line->event_addon_variant_id)
                    ->lockForUpdate()
                    ->first();

                if ($variant !== null) {
                    $variant->stock_taken = max(0, $variant->stock_taken - (int) $line->quantity);
                    $variant->save();
                }
            }

            $registration->delete();
        });

        // After the commit: a file cannot be brought back if the transaction
        // rolls back, so it is only removed once the row is definitely gone.
        if (filled($logoPath)) {
            Storage::disk('public')->delete($logoPath);
        }

        // Same reasoning as the logo: after the row is definitely gone, because a
        // rolled back transaction would otherwise leave the record pointing at a
        // file that had already been removed.
        if ($proofPaths !== []) {
            Storage::disk('public')->delete($proofPaths);
        }

        AdminLogger::activity(
            'participants.delete',
            sprintf(
                'Deleted registration %s (%s), %d people, releasing %d place(s).',
                $reference,
                $name,
                $headCount,
                $seatsHeld,
            ),
        );

        return redirect()
            ->route('admin.event.participants')
            ->with('status', sprintf(
                'Registration %s deleted. %d %s released back to the event.',
                $reference,
                $seatsHeld,
                $seatsHeld === 1
                    ? $registration->event?->seatUnit() ?? 'place'
                    : $registration->event?->seatUnitPlural() ?? 'places',
            ));
    }

    /**
     * Move a whole entry to a different event.
     *
     * For the entry filed against the wrong event, which happens when two of them
     * are open at once and look alike. The alternative is deleting and asking seven
     * people to type their details again.
     *
     * Four things are refused rather than guessed at:
     *
     * Money. Moving a paid entry changes what it should have cost, and this cannot
     * refund the difference or collect it. Refund at the gateway and re-enter.
     *
     * A different mode. A squad entry has a manager and players; an individual entry
     * has neither. Moving between the two would leave roles that the target event
     * does not use.
     *
     * A head count outside the target's bounds. A squad of seven does not fit an
     * event that caps at five, and pretending otherwise produces an entry the
     * organiser cannot run.
     *
     * No room. The target's capacity is checked under a lock, the same way the
     * public form checks it, because two administrators moving entries at once must
     * not both be told yes.
     *
     * Two things are dropped, and the message says how many. Add-on lines point at
     * the old event's catalogue and answers point at its questions; neither exists
     * on the target. Carrying them would leave rows referring to a shirt nobody is
     * selling and a question nobody asked.
     */
    public function transfer(Request $request, EventRegistration $registration)
    {
        $data = $request->validate([
            'event_id' => ['required', 'integer', 'exists:events,id'],
        ], [
            'event_id.required' => 'Choose the event to move this entry to.',
        ]);

        $registration->loadMissing(['event', 'participants']);

        if ($registration->hasMoneyOnRecord()) {
            return back()->withInput()->withErrors(['transfer' => sprintf(
                '%s cannot be moved because %s has been taken for it. Refund it at the gateway, then enter it on the other event.',
                $registration->reference,
                $registration->amountLabel(),
            )]);
        }

        if ((int) $data['event_id'] === (int) $registration->event_id) {
            return back()->withErrors(['transfer' => 'That is the event it is already on.']);
        }

        $headCount = $registration->participants->count();
        $from = $registration->event;

        $outcome = DB::transaction(function () use ($registration, $data, $headCount, $from) {
            /** @var Event $target */
            $target = Event::query()->whereKey($data['event_id'])->lockForUpdate()->firstOrFail();

            if ($target->registration_mode !== $registration->mode) {
                return ['error' => sprintf(
                    '%s takes %s entries and this one is %s. The two shapes are not interchangeable.',
                    $target->title,
                    $target->isManagerMode() ? 'squad' : 'individual',
                    $registration->mode === Event::MODE_MANAGER ? 'a squad' : 'individual',
                )];
            }

            [$min, $max] = $target->playerBounds();

            if ($target->isManagerMode()) {
                // The manager occupies one of the rows, so the playing count is the
                // head count less one unless they also play.
                $players = $registration->participants
                    ->filter(fn (EventParticipant $person) => $person->isPlaying())
                    ->count();

                if ($players < $min || ($max !== null && $players > $max)) {
                    return ['error' => sprintf(
                        '%s takes between %d and %s players and this entry has %d.',
                        $target->title,
                        $min,
                        $max === null ? 'any number of' : $max,
                        $players,
                    )];
                }
            }

            $wanted = $target->seatsForEntry($headCount);

            if ($target->seats_total > 0 && $wanted > $target->seatsLeft()) {
                return ['error' => sprintf('%s is fully booked.', $target->title)];
            }

            // Give the place back before taking the new one, so an event cannot
            // appear to hold the same entry twice while this runs.
            if ($from !== null) {
                $released = $from->seatsForEntry($headCount);

                Event::query()->whereKey($from->id)->lockForUpdate()->first()?->forceFill([
                    'seats_taken' => max(0, $from->seats_taken - $released),
                ])->save();
            }

            if ($wanted > 0) {
                $target->increment('seats_taken', $wanted);
            }

            $droppedAddons = $registration->addonLines()->count();
            $droppedAnswers = EventParticipantAnswer::query()
                ->whereIn('event_participant_id', $registration->participants->pluck('id'))
                ->count();

            $registration->addonLines()->delete();

            EventParticipantAnswer::query()
                ->whereIn('event_participant_id', $registration->participants->pluck('id'))
                ->delete();

            /*
             | The amount is rewritten from the target's fee. Extras are gone, and
             | the entry fee is the target's now, not the one it arrived with. Only
             | reachable when no money moved, so nothing is being written over a
             | figure somebody actually paid.
             */
            $fee = $target->registrationAmount();

            $registration->forceFill([
                'event_id' => $target->id,
                'registration_fee' => $fee,
                'addons_total' => 0,
                'amount' => $fee,
                'status' => $fee <= 0
                    ? EventRegistration::STATUS_CONFIRMED
                    : EventRegistration::STATUS_PENDING,
                'payment_status' => $fee <= 0
                    ? EventRegistration::PAYMENT_PAID
                    : EventRegistration::PAYMENT_UNPAID,
            ])->save();

            return [
                'target' => $target,
                'dropped_addons' => $droppedAddons,
                'dropped_answers' => $droppedAnswers,
            ];
        });

        if (isset($outcome['error'])) {
            return back()->withInput()->withErrors(['transfer' => $outcome['error']]);
        }

        /** @var Event $target */
        $target = $outcome['target'];

        AdminLogger::activity('participants.transfer', sprintf(
            'Moved %s from %s to %s.',
            $registration->reference,
            $from?->title ?? 'an unknown event',
            $target->title,
        ));

        AdminLogger::audit($registration, 'transferred', [
            'event' => $from?->title,
            'amount' => $from?->registrationAmount(),
        ], [
            'event' => $target->title,
            'amount' => $target->registrationAmount(),
            'addon_lines_dropped' => $outcome['dropped_addons'],
            'answers_dropped' => $outcome['dropped_answers'],
        ]);

        $notes = [];

        if ($outcome['dropped_addons'] > 0) {
            $notes[] = sprintf(
                '%d extra %s removed, because they belonged to the old event',
                $outcome['dropped_addons'],
                $outcome['dropped_addons'] === 1 ? 'line was' : 'lines were',
            );
        }

        if ($outcome['dropped_answers'] > 0) {
            $notes[] = sprintf(
                '%d %s cleared, because the questions belonged to the old event',
                $outcome['dropped_answers'],
                $outcome['dropped_answers'] === 1 ? 'answer was' : 'answers were',
            );
        }

        return back()->with('status', sprintf(
            '%s moved to %s.%s',
            $registration->reference,
            $target->title,
            $notes === [] ? '' : ' ' . ucfirst(implode('. ', $notes)) . '.',
        ));
    }

    /**
     * Correct the details of one person already named on an entry.
     *
     * A misspelt name, a mistyped card number, a phone number that has changed
     * since. Until now the only way to fix any of it was to delete the whole entry
     * and ask everybody to type their details again, or to use the counter's swap,
     * which is for a different person arriving and deliberately wipes the fields
     * that described the outgoing one.
     *
     * Deliberately does not change who this person is on the entry. Their role and
     * whether a manager also plays decide the squad's playing count, which the
     * event's own bounds are measured against; moving that is a different act from
     * fixing a spelling and would need the whole entry re-checked.
     *
     * Every field is compared before and after and only the differences are
     * recorded, so an audit row is a list of what actually changed rather than a
     * copy of the whole person.
     */
    public function updateParticipant(UpdateParticipantRequest $request, EventRegistration $registration, EventParticipant $participant)
    {
        $participant->loadMissing(['registration.event']);

        $fields = array_keys($request->validated());

        $before = $participant->only($fields);

        $participant->fill($request->validated());

        // Nothing was typed differently, so there is nothing to write and nothing
        // worth putting in the log either.
        if (! $participant->isDirty()) {
            return redirect()
                ->route('admin.event.participants.show', $registration)
                ->with('status', sprintf('Nothing was changed for %s.', $participant->full_name));
        }

        $changed = array_keys($participant->getDirty());

        $participant->save();

        $after = $participant->only($fields);

        AdminLogger::activity('participants.person-updated', sprintf(
            'Updated %s on %s: %s.',
            $participant->full_name,
            $registration->reference,
            implode(', ', $changed),
        ));

        AdminLogger::audit(
            $participant,
            'updated',
            array_intersect_key($before, array_flip($changed)),
            array_intersect_key($after, array_flip($changed)),
        );

        return redirect()
            ->route('admin.event.participants.show', $registration)
            ->with('status', sprintf(
                '%s updated. %d %s changed.',
                $participant->full_name,
                count($changed),
                count($changed) === 1 ? 'field' : 'fields',
            ));
    }

    /**
     * Take one person off an entry that is staying.
     *
     * The same act the counter performs, offered here because a filing mistake is
     * usually noticed on the record rather than at the desk. It reuses the model's
     * own rules about who may be taken off: nobody who has checked in, not the
     * manager, and never the last person on an entry, because an entry describing
     * no one would still hold its place with nothing left on screen to undo it.
     *
     * The change row is written before the delete. Once the participant row is gone
     * its id cannot be recorded, and that row is the only surviving trace that this
     * person was ever named.
     *
     * No place is given back on a squad event. The place belongs to the entry, and
     * the entry is still coming; releasing one every time a squad dropped a player
     * is what handed a thirty two team event extra capacity.
     */
    public function removeParticipant(Request $request, EventRegistration $registration, EventParticipant $participant)
    {
        $participant->loadMissing(['registration.event', 'attendance']);

        if ((int) $participant->event_registration_id !== (int) $registration->id) {
            return back()->withErrors(['participant' => 'That person is not on this registration.']);
        }

        // Asked of the model so this screen and the counter cannot drift apart on
        // who may be taken off.
        $blocked = $participant->removalBlockedReason();

        if ($blocked !== null) {
            return back()->withErrors(['participant' => sprintf(
                '%s cannot be removed. %s',
                $participant->full_name,
                $blocked,
            )]);
        }

        $reason = trim((string) $request->input('reason')) ?: null;
        $name = $participant->full_name;
        $card = $participant->ic_number;

        $before = $participant->only([
            'role', 'also_plays', 'full_name', 'ic_number',
            'ign_player_id', 'ign_server_id', 'ign_name',
            'address_line_1', 'address_line_2', 'postcode', 'city', 'state',
            'country', 'phone', 'email', 'gender', 'race', 'date_of_birth',
            'emergency_contact_name', 'emergency_contact_phone',
        ]);

        DB::transaction(function () use ($registration, $participant, $before, $name, $card, $reason, $request) {
            EventParticipantChange::create([
                'event_id' => $registration->event_id,
                'event_registration_id' => $registration->id,
                'event_participant_id' => $participant->id,
                'type' => EventParticipantChange::TYPE_REMOVED,
                'previous_name' => $name,
                'previous_ic' => $card,
                // Nobody arrives in their place. That is what separates this from a
                // substitution.
                'new_name' => null,
                'new_ic' => null,
                'details_before' => $before,
                'details_after' => null,
                'reason' => $reason,
                'changed_by' => $request->user()->id,
            ]);

            /*
             | Only an individual event gets a place back. seatsForEntry() answers
             | what a whole entry occupies, which is not the question here: this is
             | one person leaving an entry that stays, and on a squad event the squad
             | still holds its single place.
             */
            $event = $registration->event;

            if ($event !== null && ! $event->isManagerMode()) {
                Event::query()->whereKey($event->id)->lockForUpdate()->first()?->forceFill([
                    'seats_taken' => max(0, $event->seats_taken - 1),
                ])->save();
            }

            // Their answers and their own add-on lines go with them: the answers by
            // cascade, the add-on lines explicitly, because a shirt size belongs to
            // the person who is no longer coming.
            $registration->addonLines()
                ->where('event_participant_id', $participant->id)
                ->delete();

            $participant->delete();
        });

        AdminLogger::activity('participants.person-removed', sprintf(
            'Removed %s (%s) from %s.',
            $name,
            $card,
            $registration->reference,
        ));

        return redirect()
            ->route('admin.event.participants.show', $registration)
            ->with('status', sprintf(
                '%s removed from %s.%s',
                $name,
                $registration->reference,
                $this->playerShortfallNote($registration),
            ));
    }

    /**
     * A note about the entry now being under the event's minimum, or an empty
     * string.
     *
     * Said rather than enforced. Refusing the removal would leave the record
     * describing somebody who is not coming, which is worse than a squad that is
     * one short and known to be. Whoever runs the tournament decides what to do.
     */
    private function playerShortfallNote(EventRegistration $registration): string
    {
        $minimum = $registration->event?->min_players;

        if ($minimum === null || $minimum < 1) {
            return '';
        }

        // Counted through the playing scope, so a manager who also plays keeps the
        // squad above its minimum instead of the count reading one short.
        $remaining = $registration->participants()->playing()->count();

        if ($remaining >= $minimum) {
            return '';
        }

        return sprintf(
            ' Note: this entry now has %d %s, below this event\'s minimum of %d.',
            $remaining,
            $remaining === 1 ? 'player' : 'players',
            $minimum,
        );
    }

    /**
     * The participant list as a CSV, one row per person.
     *
     * One row per person, not per registration. The payment export already gives a
     * row per entry and names only whoever pays, which answers a money question.
     * This answers the other one: who is actually coming, what size they wear, and
     * what card number to check at the counter. A squad of seven is seven rows.
     *
     * Scoped to one event on purpose. A single file holding every identity card
     * number this organisation has ever collected is a different risk from one
     * event's, so the request is refused rather than quietly widened.
     *
     * Uses the same filters as the screen it was pressed from, so the file matches
     * what was on display. A button that exports a different set from the one being
     * looked at is a button that surprises people.
     */
    public function export(Request $request)
    {
        $eventId = trim((string) $request->query('event'));

        if ($eventId === '') {
            return back()->with('error', 'Choose an event before exporting. One file covering every event would carry more personal data than any single job needs.');
        }

        /** @var Event $event */
        $event = Event::query()->whereKey($eventId)->firstOrFail();

        /*
         | A missing or unknown tab means everybody, not the first tab.
         |
         | resolveTab() falls back to "individual" because a screen has to show
         | something, and that default would be wrong here: on a squad event it
         | matches nothing, so the header button would hand back an empty file
         | rather than the seven people it is pointing at.
         */
        $requested = (string) $request->query('tab', '');
        $tab = array_key_exists($requested, self::TABS) ? $requested : null;
        $search = trim((string) $request->query('q'));

        $registrations = ($tab === null ? EventRegistration::query() : $this->scoped($tab))
            ->where('event_id', $event->id)
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $inner) use ($search) {
                $inner->where('reference', 'like', "%{$search}%")
                    ->orWhere('team_name', 'like', "%{$search}%")
                    ->orWhereHas('participants', fn (Builder $people) => $people
                        ->where('full_name', 'like', "%{$search}%")
                        ->orWhere('ic_number', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"));
            }))
            ->orderBy('id');

        /*
         | In-game columns only where the event asks for them, and one column per
         | question the event added. A fixed header would leave empty columns on most
         | events and no column at all for anything an organiser invented.
         */
        $ignFields = $event->ignFieldsAsked();
        $questions = $event->questions;

        $header = array_merge(
            ['Reference', 'Team / Entry', 'Mode', 'Role', 'Also Plays'],
            ['Full Name', 'Identity Card', 'Date of Birth', 'Age', 'Gender', 'Race'],
            ['Telephone', 'Email'],
            array_values($ignFields),
            ['Address 1', 'Address 2', 'Postcode', 'City', 'State', 'Country'],
            ['Emergency Contact', 'Emergency Telephone'],
            ['Extras Chosen'],
            $questions->pluck('title')->all(),
            ['Marketing Consent', 'Checked In At', 'Entry Status', 'Payment Status', 'Submitted'],
        );

        AdminLogger::activity(
            'participants.export',
            sprintf(
                'Exported the %s participant list for %s.',
                $tab === null ? 'full' : $tab,
                $event->title,
            ),
        );

        return response()->streamDownload(function () use ($registrations, $header, $ignFields, $questions) {
            $handle = fopen('php://output', 'wb');

            // Byte order mark. Malaysian names carry characters Excel reads as
            // mojibake without it, which ruins the file for anybody who opens it by
            // double clicking, which is everybody.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, $header);

            /*
             | Chunked, with the relations loaded per chunk rather than up front. A
             | popular event is hundreds of people, and holding all of them plus
             | their answers and add-on lines in memory to write a file is needless.
             */
            $registrations
                ->with(['participants.answers', 'participants.attendance', 'addonLines'])
                ->chunk(50, function ($rows) use ($handle, $ignFields, $questions) {
                    foreach ($rows as $registration) {
                        foreach ($registration->participants as $person) {
                            fputcsv($handle, $this->exportRow($registration, $person, $ignFields, $questions));
                        }
                    }
                });

            fclose($handle);
        }, sprintf('participants-%s-%s.csv', $event->slug, now()->format('Ymd-His')), [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * One person as a row of cells, in the same order as the header.
     *
     * @param  array<string, string>  $ignFields
     * @param  \Illuminate\Support\Collection<int, \App\Models\EventQuestion>  $questions
     * @return array<int, string>
     */
    private function exportRow(
        EventRegistration $registration,
        EventParticipant $person,
        array $ignFields,
        $questions,
    ): array {
        $ign = [];

        foreach (array_keys($ignFields) as $field) {
            $ign[] = (string) ($person->{$field} ?? '');
        }

        // Extras recorded against this person, which is where a shirt size lives.
        $extras = $registration->addonLines
            ->where('event_participant_id', $person->id)
            ->map(fn ($line) => filled($line->variant_label)
                ? sprintf('%s: %s', $line->name, $line->variant_label)
                : $line->name)
            ->implode('; ');

        /*
         | Matched on the question id, falling back to the stored title. An answer
         | whose question has since been deleted keeps its own copy of the wording,
         | so it can still be placed under the right heading while that heading
         | exists.
         */
        $answers = [];

        foreach ($questions as $question) {
            $answer = $person->answers->firstWhere('event_question_id', $question->id)
                ?? $person->answers->firstWhere('question_title', $question->title);

            $answers[] = $answer === null ? '' : $answer->answerLabel();
        }

        return array_merge(
            [
                $registration->reference,
                (string) ($registration->team_name ?? ''),
                // The stored word, capitalised, which is what the Mode column on
                // the screen shows. Event::MODES holds a sentence meant for a
                // dropdown and would be unreadable in a spreadsheet cell.
                ucfirst((string) $registration->mode),
                $person->roleLabel(),
                $person->also_plays ? 'Yes' : '',
            ],
            [
                $person->full_name,
                // In full, not masked. Somebody at the counter checks this against a
                // card in a person's hand, and half a number cannot be checked.
                $person->ic_number,
                $person->date_of_birth?->format('Y-m-d') ?? '',
                (string) ($person->age() ?? ''),
                (string) ($person->gender ?? ''),
                (string) ($person->race ?? ''),
            ],
            [
                (string) ($person->phone ?? ''),
                (string) ($person->email ?? ''),
            ],
            $ign,
            [
                (string) ($person->address_line_1 ?? ''),
                (string) ($person->address_line_2 ?? ''),
                (string) ($person->postcode ?? ''),
                (string) ($person->city ?? ''),
                (string) ($person->state ?? ''),
                (string) ($person->country ?? ''),
            ],
            [
                (string) ($person->emergency_contact_name ?? ''),
                (string) ($person->emergency_contact_phone ?? ''),
            ],
            [$extras],
            $answers,
            [
                $person->marketing_consent ? 'Yes' : 'No',
                $person->attendance?->created_at?->format('Y-m-d H:i') ?? '',
                $registration->statusLabel(),
                $registration->paymentStatusLabel(),
                $registration->created_at?->format('Y-m-d H:i') ?? '',
            ],
        );
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    /**
     * Whoever holds the payment: the manager of a squad, or the single person on
     * a solo entry.
     */
    private function registrant(EventRegistration $registration): ?EventParticipant
    {
        return $registration->participants->firstWhere('role', ParticipantOptions::ROLE_MANAGER)
            ?? $registration->participants->sortBy('id')->first();
    }

    /**
     * Pull the payment record from the gateway and keep a copy.
     *
     * Unlike the public payment page this refreshes even for a settled payment,
     * because an administrator opening the record wants the current position,
     * including a refund raised at the gateway rather than here.
     *
     * @return bool whether the gateway answered
     */
    private function refreshPayment(EventRegistration $registration): bool
    {
        if (blank($registration->payment_reference)) {
            return false;
        }

        try {
            return $this->updater->syncFromGateway($registration, $this->gateways->active()) !== null;
        } catch (PaymentGatewayException) {
            // No usable gateway. The stored snapshot is still shown.
            return false;
        }
    }

    /**
     * Reconcile this entry against every purchase the gateway holds for it.
     *
     * Exists because the two sides can disagree and when they do the money is real
     * while the record is wrong. A payer who presses Pay twice creates two purchases;
     * whichever settles, the entry can be left pointing at the other and reading
     * "failed" with the money already in the account.
     *
     * Believes only the gateway. It cannot mark anything paid on a person's word,
     * which is what separates it from recording a payment by hand, and is why it is
     * safe to offer wherever an entry looks wrong.
     */
    public function tally(Request $request, EventRegistration $registration, RegistrationTally $tally)
    {
        if ($registration->isFree()) {
            return back()->withInput()->withErrors([
                'tally' => sprintf('%s is free of charge, so there is nothing at the gateway to compare it against.', $registration->reference),
            ]);
        }

        $validated = $request->validate([
            /*
             | A purchase id typed in from the gateway's own dashboard. For the case
             | this feature was written for: a purchase the application never recorded,
             | because the attempt that settled was overwritten before this was
             | tracked. Everything after that date is found without it.
             */
            'purchase_id' => ['nullable', 'string', 'max:190'],
        ], [
            'purchase_id.max' => 'That does not look like a purchase id.',
        ]);

        try {
            $result = $tally->settle($registration, $validated['purchase_id'] ?? null);
        } catch (PaymentGatewayException $e) {
            return back()->withInput()->withErrors([
                'tally' => 'The gateway could not be reached, so nothing was compared and nothing was changed. ' . $e->publicMessage(),
            ]);
        }

        AdminLogger::activity(
            'payments.tally',
            sprintf('Tallied %s against the gateway. %s', $registration->reference, $result['message']),
        );

        // A refusal is not an error: "the gateway says none of these were paid" is a
        // useful answer, and putting it in the error slot would make it look like the
        // press failed.
        return $result['changed']
            ? back()->with('status', $result['message'])
            : back()->with('warning', $result['message']);
    }

    /**
     * Reduce an uploaded filename to something safe to store and show.
     *
     * The name is display only and never reaches the filesystem: the stored path
     * carries a hashed name so uploads cannot collide. It strips directory parts
     * anyway in case a later change does build a path from it, drops control
     * characters, and trims to the column width so a long name cannot fail the
     * insert.
     */
    private function displayFileName(?string $name): string
    {
        $name = basename(str_replace('\\', '/', (string) $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);

        return $name === '' ? 'proof' : str($name)->limit(250, '')->toString();
    }

    private function resolveTab(?string $tab): string
    {
        return array_key_exists((string) $tab, self::TABS) ? (string) $tab : 'individual';
    }

    private function scoped(string $tab): Builder
    {
        $query = EventRegistration::query();

        return match ($tab) {
            'team' => $query->where('mode', Event::MODE_MANAGER),
            'paid' => $query->where('payment_status', EventRegistration::PAYMENT_PAID),
            'unpaid' => $query->where('payment_status', '!=', EventRegistration::PAYMENT_PAID),
            default => $query->where('mode', Event::MODE_INDIVIDUAL),
        };
    }

    /**
     * Row count per tab, shown as a badge on the tab bar.
     *
     * @return array<string, int>
     */
    private function counts(): array
    {
        $counts = [];

        foreach (array_keys(self::TABS) as $tab) {
            $counts[$tab] = $this->scoped($tab)->count();
        }

        return $counts;
    }
}
