<?php

namespace App\Http\Controllers\Admin\Event;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TransferRegistrationRequest;
use App\Http\Requests\Admin\UpdateParticipantRequest;
use App\Http\Requests\Admin\UpdateRegistrationEntryRequest;
use App\Models\Event;
use App\Models\EventAddon;
use App\Models\EventAddonVariant;
use App\Models\EventParticipant;
use App\Models\EventParticipantAnswer;
use App\Models\EventParticipantChange;
use App\Models\EventRegistration;
use App\Models\EventRegistrationPayment;
use App\Services\AdminLogger;
use App\Services\Coupon\CouponReleaser;
use App\Services\EventNotifier;
use App\Services\Payment\GatewayReceiptAudit;
use App\Services\Payment\PaymentGatewayException;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\RegistrationPaymentUpdater;
use App\Services\Payment\RegistrationTally;
use App\Services\Registration\ParticipantSizeWriter;
use App\Services\Registration\RegistrationTotalsRecalculator;
use App\Services\Registration\TotalCorrection;
use App\Services\SizeConfirmationSender;
use App\Support\EventTemplates;
use App\Support\GatewayPaymentRecord;
use App\Support\LocalTime;
use App\Support\ParticipantOptions;
use App\Support\ParticipantSizes;
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
        'group' => ['label' => 'Grouping', 'icon' => 'users'],
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

        $registrations = $this->filtered($request, $tab)
            // checkouts is loaded for the tally dialog, which lists the purchases on
            // record. Without it the list would query once per row.
            //
            // paymentReminders is loaded for the Reminder column, which answers "has
            // anybody actually told this registrant". Same reasoning: one query for
            // the page instead of one per row, and it is read from the message log
            // rather than from a column on the registration.
            //
            // event.addons.variants and sizeConfirmations are loaded for the Size
            // column, which answers "who still has no shirt size" and "has anybody
            // been asked for it". The catalogue is needed because which items collect
            // a size is a property of the event, not of the entry, and the second is
            // read from the message log for the same reason the Reminder column is.
            ->with(['event.addons.variants', 'participants', 'addonLines', 'checkouts', 'paymentReminders', 'sizeConfirmations'])
            ->latest()
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $counts = $this->counts($request);

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

            // Correcting what an entry was charged is a correction to the record, so
            // it sits behind the same permission as correcting anything else on it.
            'canRecalculate' => $request->user()->hasPermission('participants.update'),

            /*
             | Checking the receipt ledger against what the gateway reports. Behind
             | payments.record, the permission that already governs writing rows into
             | that ledger, because this is the only other thing that touches them.
             */
            'canAuditReceipts' => $request->user()->hasPermission('payments.record'),

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
             | How many entries on this list still owe a shirt size, and how many
             | people that is.
             |
             | Over filtered() — the same method the rows and the money figures are
             | built from — and over the active tab as well, so the figure on screen is
             | exactly the set the "ask everybody" button beside it would write to. A
             | count that covered more than the button sends is how an operator ends up
             | believing a job is finished.
             */
            'sizesMissing' => $this->sizesOutstanding($request, $tab),
            /*
             | Read through PaymentFigures, over the list's own filtered query.
             |
             | These two were counted inline once, which meant they answered slightly
             | different questions from the same figures on the Payments screens. They
             | then answered the right question over the wrong rows: every event's,
             | while the screen was filtered to one. The owner chose HARI SUKAN NEGARA
             | and was shown RM 2,840.00 collected for an event that had taken
             | RM 1,640.00; the other RM 1,200.00 was a different event's money.
             |
             | So the scope comes from filtered() — the same method the rows above are
             | built from — and the arithmetic stays in PaymentFigures. One query,
             | whatever the scope holds.
             |
             | Deliberately not narrowed to the active tab. The pair reports the money
             | the filters describe, and switching between Paid and Unpaid must not
             | look like the takings changed. The caption under them names the scope.
             */
            'totals' => PaymentFigures::totalsFor($this->filtered($request, null)),
        ]);
    }

    /**
     * One registration in full, including whatever the gateway holds about its
     * payment.
     */
    public function show(Request $request, EventRegistration $registration)
    {
        // event.addons.variants is loaded for the correction dialog, which offers the
        // choices this event collects one per person. Which fields those are is a
        // property of the event rather than of the entry, so the catalogue has to be
        // here for the dialog to be able to draw itself.
        $registration->load(['event.addons.variants', 'participants.answers', 'addonLines', 'notifications.triggeredBy', 'payments.recordedBy']);

        $reachedGateway = $this->refreshPayment($registration);

        return view('admin.event.participant-show', [
            'registration' => $registration,
            'event' => $registration->event,

            /*
             | Everybody on the entry against every choice this event collects one per
             | person, with whatever is already recorded against each.
             |
             | Read from ParticipantSizes — the same one definition the public
             | confirmation page, the writer and the list column read — and grouped by
             | person so each correction dialog can draw its own rows. The dialog
             | deliberately has no field list of its own: an event that starts
             | collecting a second choice must appear in both places or neither.
             */
            'choiceRows' => collect(ParticipantSizes::sheetFor($registration))
                ->groupBy(fn (array $row) => $row['participant']->id)
                ->all(),

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
     * What re-pricing one event's entries would do, before anything is written.
     *
     * A read-only screen. Entries stored before the event charged add-ons per head
     * still name the old figure — a group of six choosing a RM40 shirt each was
     * charged RM40 — and this is where the operator sees every row that would move,
     * with its head count, what it says now, what it should say and the difference,
     * alongside the rows that need nothing. Applying it is a separate press.
     *
     * Scoped to one chosen event on purpose. A sweep across every event would be a
     * single irreversible press against a live table holding other organisers' money.
     */
    public function recalculateForm(Request $request, Event $event, RegistrationTotalsRecalculator $recalculator)
    {
        $corrections = $recalculator->preview($event);

        return view('admin.event.participants-recalculate', [
            'event' => $event,
            'corrections' => $corrections,

            /*
             | Split here rather than in the markup, so the screen and the counts in
             | its headings cannot disagree about which rows are which.
             |
             | Three buckets, not two, because there are two different kinds of wrong
             | and reading them in one table would hide the one that matters most: a
             | row whose total moves is money the organiser is about to start chasing,
             | and a row whose total stays put is a charge being re-described. Mixing
             | them puts a column of +RM 0.00 beside real shortfalls and invites the
             | operator to skim past both.
             */
            'changing' => array_values(array_filter(
                $corrections,
                fn (TotalCorrection $correction) => $correction->movesMoney(),
            )),
            'reitemised' => array_values(array_filter(
                $corrections,
                fn (TotalCorrection $correction) => $correction->reshapes(),
            )),
            'unchanged' => array_values(array_filter(
                $corrections,
                fn (TotalCorrection $correction) => ! $correction->changes(),
            )),
        ]);
    }

    /**
     * Write the corrected totals for one event.
     *
     * POST only, and refused unless the operator has ticked the confirmation on the
     * preview: the figures on that screen are what they are agreeing to. Every amount
     * is recomputed from the database here rather than carried in the request, so a
     * replayed or edited post cannot name its own total.
     */
    public function recalculate(Request $request, Event $event, RegistrationTotalsRecalculator $recalculator)
    {
        $request->validate([
            'confirm' => ['accepted'],
        ], [
            'confirm.accepted' => 'Tick the confirmation to apply these corrections.',
        ]);

        $applied = $recalculator->apply($event);

        if ($applied === []) {
            // Back to the preview by name rather than back(): this is also what a
            // second press lands on, and it must say so on a page rather than
            // depending on a referer being there.
            return redirect()
                ->route('admin.event.participants.recalculate', $event)
                ->with('warning', sprintf(
                    'Nothing to correct on %s. Every entry is already charging the right amount.',
                    $event->title,
                ));
        }

        $moved = round(array_sum(array_map(
            fn (TotalCorrection $correction) => $correction->difference(),
            $applied,
        )), 2);

        /*
         | Counted apart in the message for the same reason the preview tables are.
         | "22 entries corrected, RM 0.00 added to what is owed" reads like nothing
         | happened; it is the sentence an owner would dismiss.
         */
        $reitemised = count(array_filter(
            $applied,
            fn (TotalCorrection $correction) => $correction->reshapes(),
        ));

        $repriced = count($applied) - $reitemised;

        $summary = trim(sprintf(
            '%s%s',
            $repriced > 0
                ? sprintf(
                    '%d %s re-priced, %s added to what is owed. ',
                    $repriced,
                    $repriced === 1 ? 'entry' : 'entries',
                    PaymentFigures::money($moved),
                )
                : '',
            $reitemised > 0
                ? sprintf(
                    '%d %s re-itemised at the same amount, so the items now describe the charge.',
                    $reitemised,
                    $reitemised === 1 ? 'entry' : 'entries',
                )
                : '',
        ));

        AdminLogger::activity(
            'participants.recalculate',
            sprintf(
                'Rechecked add-on totals for %s: %s',
                $event->title,
                $summary,
            ),
        );

        return redirect()
            ->route('admin.event.participants.recalculate', $event)
            ->with('status', sprintf(
                '%s on %s. %s Each change is in the activity log.',
                sprintf('%d %s corrected', count($applied), count($applied) === 1 ? 'entry' : 'entries'),
                $event->title,
                $summary,
            ));
    }

    /**
     * Receipt rows the gateway's own record contradicts, before anything is removed.
     *
     * A read-only screen, and the reason it exists rather than a one-off script:
     * settleLedger() used to insert whatever was left of a charge when a gateway event
     * said paid, which invented money the moment a charge was corrected upwards. Rows
     * like that may have been written since any particular database was taken, so this
     * is a diagnostic that can be run again rather than a fix that was applied once.
     *
     * Every row listed carries its evidence: the purchase it names, what the stored
     * gateway payload reports that purchase took, and what the ledger claims.
     */
    public function receiptsForm(Request $request, Event $event, GatewayReceiptAudit $audit)
    {
        return view('admin.event.participants-receipts', [
            'event' => $event,
            'findings' => $audit->preview($event),
        ]);
    }

    /**
     * Remove the rows the diagnostic identified.
     *
     * POST only, and refused unless the operator has ticked the confirmation on the
     * diagnostic: deleting a payment record cannot be undone. Every figure and every
     * row id is worked out again from the database inside the service, so a replayed or
     * edited post cannot name its own rows.
     */
    public function receipts(Request $request, Event $event, GatewayReceiptAudit $audit)
    {
        $request->validate([
            'confirm' => ['accepted'],
        ], [
            'confirm.accepted' => 'Tick the confirmation to remove these rows.',
        ]);

        $corrected = $audit->correct($event);

        if ($corrected === []) {
            // Back to the diagnostic by name rather than back(): this is also what a
            // second press lands on, and it must say so on a page.
            return redirect()
                ->route('admin.event.participants.receipts', $event)
                ->with('warning', sprintf(
                    'Nothing to remove on %s. Every gateway receipt matches what the gateway reports for its purchase.',
                    $event->title,
                ));
        }

        $removed = GatewayReceiptAudit::total($corrected);

        AdminLogger::activity(
            'payments.phantom',
            sprintf(
                'Removed gateway receipts the payload contradicts on %s: %d entries, %s taken back out of the takings.',
                $event->title,
                count($corrected),
                PaymentFigures::money($removed),
            ),
        );

        return redirect()
            ->route('admin.event.participants.receipts', $event)
            ->with('status', sprintf(
                '%d %s corrected on %s. %s removed from the takings because the gateway never took it. Every removed row is in the audit trail.',
                count($corrected),
                count($corrected) === 1 ? 'entry' : 'entries',
                $event->title,
                PaymentFigures::money($removed),
            ));
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

        /*
         | A second press inside the window sends nothing.
         |
         | The same guard the shop keeps on an order, and for the same reason: this is
         | a button on a table row, a double click is one slip of the hand, and the
         | person on the other end gets both of them. Read from the message log, so
         | the cooldown and the state drawn on the list are the same fact.
         |
         | Only a link the queue accepted holds anybody back. An attempt that failed,
         | or was skipped for want of an address, told nobody anything and must stay
         | pressable the moment the address is corrected.
         |
         | Loaded once, because the three questions below are asked of the same rows.
         */
        $registration->loadMissing('paymentReminders');

        if ($registration->paymentLinkRemindedRecently()) {
            return back()->with('warning', sprintf(
                'A payment link already went out for %s %s, to %s. Another can be sent after %s, so nothing was sent now.',
                $registration->reference,
                $registration->paymentLinkSentAt()->diffForHumans(),
                $registration->lastPaymentReminder()?->recipient ?: 'the registrant',
                LocalTime::format($registration->paymentLinkCooldownEndsAt()),
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

        /*
         | The address is named in the message rather than left to be guessed. "Queued"
         | is the honest word for it: the cron worker is what sends it, and the Reminder
         | column on the list is where that turns into Sent.
         */
        $address = $registration->load('paymentReminders')->lastPaymentReminder()?->recipient;

        return back()->with('status', sprintf(
            'Payment link queued for %s (%s outstanding)%s. The Reminder column says when it actually leaves.',
            $this->registrant($registration)?->full_name ?? $registration->reference,
            $registration->outstandingAmountLabel(),
            filled($address) ? ', to ' . $address : '',
        ));
    }

    /**
     * Ask one registrant to confirm the shirt size of everybody on the entry.
     *
     * The case this exists for: an event began collecting a size part way through its
     * entries. Everybody who registered before that was charged for a shirt and never
     * asked which size, so the organiser cannot order or hand them out. A size cannot
     * be guessed, and backfilling one would misstate what to print, so it is collected
     * from the person who registered.
     *
     * Behind participants.notify, the permission that already governs reaching a
     * participant's inbox. Nothing about money is touched by this press or by the page
     * it sends them to.
     *
     * Every rule about who may be asked lives in SizeConfirmationSender, shared with
     * sendAllSizeLinks() below.
     */
    public function sendSizeLink(Request $request, EventRegistration $registration, SizeConfirmationSender $sender)
    {
        if ($reason = $sender->skipReason($registration)) {
            return back()->with('warning', sprintf(
                'No size link went out for %s: %s.',
                $registration->reference,
                $this->whyNoSizeLink($registration, $reason),
            ));
        }

        if (! $sender->send($registration, $request->user()?->id)) {
            return back()->with('warning', sprintf(
                'No size link went out for %s. Check the Confirm Shirt Size template is switched on under Event > Settings, and that the registrant has an email address.',
                $registration->reference,
            ));
        }

        $missing = ParticipantSizes::missingCount($registration);

        AdminLogger::activity('participants.size-link', sprintf(
            'Queued a size confirmation for %s (%d %s outstanding).',
            $registration->reference,
            $missing,
            $missing === 1 ? 'size' : 'sizes',
        ));

        $address = $registration->load('sizeConfirmations')->lastSizeConfirmation()?->recipient;

        return back()->with('status', sprintf(
            'Size link queued for %s (%d %s outstanding)%s. It carries no payment, and the Size column says when it actually leaves.',
            $this->registrant($registration)?->full_name ?? $registration->reference,
            $missing,
            $missing === 1 ? 'size' : 'sizes',
            filled($address) ? ', to ' . $address : '',
        ));
    }

    /**
     * Ask everybody on the list, as it is currently filtered.
     *
     * Thirty-two entries needing a size is thirty-two presses of the row action, which
     * is what this replaces. Deliberately not a schedule: somebody with the notify
     * permission decides, and it is recorded against their name.
     *
     * "As currently filtered" is the whole contract. The tab, the event and the search
     * box arrive in the query string of this URL and go into the same filtered() the
     * table itself paginates, so an operator who has narrowed the list to one event and
     * presses a button labelled "all" means those, not the database. Nothing in the
     * request decides who is eligible or what is written: eligibility is the sender's,
     * and the link is signed and rebuilt server-side per registration.
     */
    public function sendAllSizeLinks(Request $request, SizeConfirmationSender $sender)
    {
        /*
         | The filters are the only input, and they may only be values that exist. They
         | can narrow the set or match nothing; a crafted value cannot widen it past the
         | tab, and it cannot reach an entry the sender would refuse.
         */
        $request->validate([
            'tab' => ['nullable', Rule::in(array_keys(self::TABS))],
            'q' => ['nullable', 'string', 'max:190'],
            'event' => ['nullable', 'integer', 'exists:events,id'],
        ]);

        $tab = $this->resolveTab($request->query('tab'));

        $queued = 0;
        /** @var array<string, int> $skipped  reason => how many */
        $skipped = [];

        /*
         | Walked in id order in chunks rather than loaded at once: this is a live list
         | that keeps growing. chunkById pages on the primary key, and nothing in the
         | loop writes to event_registrations at all, so the pages cannot shuffle
         | underneath it.
         */
        $this->filtered($request, $tab)
            ->with(['event.addons.variants', 'participants', 'addonLines', 'sizeConfirmations'])
            ->chunkById(100, function ($registrations) use ($sender, $request, &$queued, &$skipped) {
                foreach ($registrations as $registration) {
                    // Asked once and counted, never asked twice: the reason is what the
                    // operator is told afterwards.
                    $reason = $sender->skipReason($registration);

                    if ($reason === null && $sender->send($registration, $request->user()?->id)) {
                        $queued++;

                        continue;
                    }

                    // Nothing to skip it for, but the queue would not take it. Rare,
                    // logged by the notifier, and worth a line of its own so the counts
                    // still add up to what was on the list.
                    $reason ??= SizeConfirmationSender::SKIP_QUEUE_FAILED;

                    $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;
                }
            });

        $passedOver = array_sum($skipped);
        $breakdown = SizeConfirmationSender::breakdown($skipped);

        // One entry for the whole press: who, what was filtered, and the counts. A bulk
        // outbound action touching real participants has to be answerable for later.
        AdminLogger::activity('participants.size-link-all', sprintf(
            'Queued %d size %s from the %s participants list (%s). Skipped %d%s.',
            $queued,
            $queued === 1 ? 'confirmation' : 'confirmations',
            strtolower(self::TABS[$tab]['label'] ?? $tab),
            $this->sizeFilterLabel($request),
            $passedOver,
            $breakdown === '' ? '' : ': ' . $breakdown,
        ));

        if ($queued === 0) {
            return back()->with('warning', $passedOver === 0
                ? 'Nothing on this list is waiting for a size, so no links went out.'
                : sprintf(
                    'No size links went out. All %d %s on this list were passed over: %s.',
                    $passedOver,
                    $passedOver === 1 ? 'entry' : 'entries',
                    $breakdown,
                ));
        }

        return back()->with('status', sprintf(
            '%d size %s queued, and %s out as soon as the queue worker runs. No payment is asked for on any of them.%s',
            $queued,
            $queued === 1 ? 'link' : 'links',
            $queued === 1 ? 'goes' : 'go',
            $passedOver === 0
                ? ' Nothing was passed over.'
                : sprintf(
                    ' %d %s passed over: %s.',
                    $passedOver,
                    $passedOver === 1 ? 'entry' : 'entries',
                    $breakdown,
                ),
        ));
    }

    /**
     * Why there is nothing to ask for, in words the operator can act on.
     */
    private function whyNoSizeLink(EventRegistration $registration, string $reason): string
    {
        if ($reason === SizeConfirmationSender::SKIP_COOLDOWN) {
            return sprintf(
                'one was already queued %s, to %s, so the registrant is being left alone until %s',
                $registration->sizeLinkSentAt()->diffForHumans(),
                $registration->lastSizeConfirmation()?->recipient ?: 'the registrant',
                LocalTime::format($registration->sizeLinkCooldownEndsAt()),
            );
        }

        return SizeConfirmationSender::reasons()[$reason] ?? 'there is nothing to confirm';
    }

    /**
     * The filters in force, in words, for the activity entry.
     */
    private function sizeFilterLabel(Request $request): string
    {
        $search = trim((string) $request->query('q'));
        $eventId = trim((string) $request->query('event'));

        $parts = [];

        if ($eventId !== '') {
            $parts[] = 'event ' . (Event::query()->whereKey($eventId)->value('title') ?? $eventId);
        }

        if ($search !== '') {
            $parts[] = 'search "' . $search . '"';
        }

        return $parts === [] ? 'no filters' : implode(', ', $parts);
    }

    /**
     * How many entries on this list still owe a size, and how many people that is.
     *
     * Built over filtered(), the list's own query, so it cannot describe a different
     * set from the rows. Which items collect a size and who has not answered is
     * ParticipantSizes' single definition, applied in PHP rather than rewritten as a
     * second SQL predicate: the per-head rule already lives on the add-on, and a
     * duplicate of it in a WHERE clause is exactly how two parts of this screen
     * started answering the same question differently.
     *
     * Chunked with the catalogue and the lines eager loaded, so the cost is a handful
     * of queries whatever the scope holds, not one per row.
     *
     * @return array{registrations: int, participants: int}
     */
    private function sizesOutstanding(Request $request, string $tab): array
    {
        $registrations = 0;
        $people = 0;

        $this->filtered($request, $tab)
            /*
             | Rows that could not possibly be missing a size are never walked.
             |
             | Deliberately a broader test than ParticipantSizes applies — it leaves out
             | the per-head condition, which belongs to the event's mode — so anything
             | the real rule would accept is still here. It decides only whether a row is
             | worth looking at, and most of the table is not: one event collects sizes
             | and the rest never will, so this is what keeps a screen that lists every
             | event from hydrating all of them.
             */
            ->whereHas('event.addons', fn (Builder $addons) => $addons
                ->where('is_active', true)
                ->where('selection_type', EventAddon::SELECTION_RADIO)
                ->whereHas('variants'))
            ->with(['event.addons.variants', 'participants', 'addonLines'])
            ->chunkById(200, function ($rows) use (&$registrations, &$people) {
                foreach ($rows as $registration) {
                    if ($registration->status === EventRegistration::STATUS_CANCELLED) {
                        continue;
                    }

                    $missing = ParticipantSizes::missingCount($registration);

                    if ($missing > 0) {
                        $registrations++;
                        $people += $missing;
                    }
                }
            });

        return ['registrations' => $registrations, 'participants' => $people];
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
     * Delete one registration, giving back everything it was holding.
     *
     * Child rows go with it through cascading foreign keys. The THREE things the
     * database cannot work out on its own are the counters: seats on the event,
     * stock on each add-on size, and the uses of any coupon the entry spent.
     * Nothing else in the system gives any of them back, so if this did not do it
     * the event would quietly lose capacity every time an entry was removed, an
     * add-on size could never be edited again because stock_taken would stay above
     * the real figure, and a coupon would read as spent on an entry that no longer
     * exists.
     *
     * WHY THE COUPON RELEASE IS HERE RATHER THAN ON A MODEL EVENT
     *
     * It was tempting to hang it off EventRegistration's `deleting` event, which
     * would catch every deletion including ones not yet written. It is in the delete
     * path instead, for two reasons and the first is the one that decides it:
     *
     *   THE RELEASE IS ONLY SAFE BESIDE THE MONEY GUARD. Handing a use back is
     *   correct precisely because hasMoneyReceived() below refuses to delete an
     *   entry that took money — so nobody ever benefited from the discount. A model
     *   event fires for ANY delete() anywhere, including some future path that has
     *   not checked that guard, and would then release a use that really did buy
     *   somebody a cheaper place. Keeping the two in one method keeps the reasoning
     *   true by construction rather than by remembering.
     *
     *   IT IS NOT THE GUARANTEE IT LOOKS LIKE. Eloquent model events do not fire for
     *   a mass delete or for a database-level cascade, so a hook would catch exactly
     *   the $model->delete() calls an explicit call already covers — and this is the
     *   only place in the application that deletes a registration at all.
     *
     * A shop order is the other thing a coupon can be spent on, and it has NO delete
     * path: there is no route, no controller action and no admin screen that removes
     * one, because an order is a financial record. If one is ever added it has to
     * call the releaser the same way, which is why CouponReleaser is a service rather
     * than a private method here.
     */
    public function destroy(EventRegistration $registration, CouponReleaser $releaser)
    {
        // A settled payment is a financial record, and the money still sits with
        // the gateway. Refunding and cancelling is the honest path; deleting
        // would leave the books disagreeing with the gateway's dashboard.
        /*
         | Judged on whether money actually arrived, not on what was invoiced and
         | not on the payment_status flag.
         |
         | An entry can name RM 40.00 and hold none of it: the sum is what is owed,
         | and a purchase id is only a checkout somebody opened. Reading either as a
         | financial record left an unpaid entry undeletable and told the office that
         | money had been taken for it. What blocks a delete is a sum received, a
         | receipt in the ledger, or an amount already refunded.
         */
        if ($registration->hasMoneyReceived()) {
            return back()->withInput()->withErrors([
                'registration' => sprintf(
                    '%s cannot be deleted because %s has been taken for it. Refund it at the gateway first, or leave it for the record.',
                    $registration->reference,
                    $registration->amountPaidLabel(),
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

        $released = DB::transaction(function () use ($registration, $seatsHeld, $releaser) {
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

            /*
             | The coupon uses, given back before the row goes.
             |
             | Before, because the ledger rows are found by registration id and the
             | foreign key is nullOnDelete — after the delete there would be nothing
             | left to say which rows belonged to this entry. Inside this
             | transaction, so the release and the deletion commit or roll back
             | together: a rolled-back delete must not leave a batch holding uses
             | back for an entry that is still standing.
             |
             | Safe to do unconditionally because of the guard at the top of this
             | method. See the class note above.
             */
            $given = $releaser->releaseForRegistration($registration);

            $registration->delete();

            return $given;
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

        // Its own line per batch, after the commit. A use reappearing in a cap is as
        // confusing as one disappearing, so the trail says which coupon got what back.
        $releaser->log($released, $reference);

        return redirect()
            ->route('admin.event.participants')
            ->with('status', sprintf(
                'Registration %s deleted. %d %s released back to the event.%s',
                $reference,
                $seatsHeld,
                $seatsHeld === 1
                    ? $registration->event?->seatUnit() ?? 'place'
                    : $registration->event?->seatUnitPlural() ?? 'places',
                $this->couponReleaseNote($released),
            ));
    }

    /**
     * What to add to the delete message about coupon uses handed back, if any.
     *
     * Said on screen rather than only in the log because the operator is looking at
     * the batch's remaining count on the next screen along: a number that moved with
     * no explanation is what sent the owner looking for a bug in the first place.
     *
     * @param  array<int, array{coupon: string, uses: int, codes: int, discount: float}>  $released
     */
    private function couponReleaseNote(array $released): string
    {
        if ($released === []) {
            return '';
        }

        return ' '.collect($released)
            ->map(fn (array $entry) => sprintf(
                '%d %s of coupon %s %s given back.',
                $entry['uses'],
                $entry['uses'] === 1 ? 'use' : 'uses',
                $entry['coupon'],
                $entry['uses'] === 1 ? 'was' : 'were',
            ))
            ->implode(' ');
    }

    /**
     * The move page for one entry.
     *
     * A page rather than a dialog. Two events rarely want the same shape of entry,
     * so moving one can mean choosing who is left behind, entering somebody new to
     * make up a shortfall, and answering the target's own questions for everybody
     * who ends up on it. None of that fits in a box over a table.
     *
     * The arithmetic is worked out here rather than in the markup, so there is one
     * place that decides how many have to go and how many may come in.
     */
    public function transferForm(Request $request, EventRegistration $registration)
    {
        $registration->load(['event', 'participants', 'addonLines']);

        /*
         | Only events of the same shape are offered. A squad entry has a manager and
         | players and an individual entry has neither, so listing the other kind
         | would be offering a choice that is refused the moment it is taken.
         */
        $targets = Event::query()
            ->whereIn('status', Event::REGISTERABLE)
            ->where('registration_mode', $registration->mode)
            ->whereKeyNot($registration->event_id)
            ->orderBy('title')
            ->get();

        $chosen = $request->query('event');

        /** @var Event|null $target */
        $target = is_numeric($chosen) ? $targets->firstWhere('id', (int) $chosen) : null;

        $target?->load('questions');

        // Manager entries count playing places; grouping entries count every person.
        $playing = $registration->participants
            ->filter(fn (EventParticipant $person) => $person->isPlaying())
            ->count();
        $boundedCount = $target?->isGroupingMode()
            ? $registration->participants->count()
            : $playing;

        [$min, $max] = $target?->playerBounds() ?? [0, null];

        return view('admin.event.participant-transfer', [
            'registration' => $registration,
            'from' => $registration->event,
            'targets' => $targets,
            'target' => $target,

            'playing' => $playing,
            'boundedCount' => $boundedCount,
            'minPlayers' => $min,
            'maxPlayers' => $max,

            /*
             | How many have to go, and how many may be brought in. Never both above
             | zero: a squad over the target's ceiling has to shed people, and one
             | under its floor has to gain them.
             */
            'mustDrop' => $target === null || $max === null ? 0 : max(0, $boundedCount - $max),
            'mustAdd' => $target === null ? 0 : max(0, $min - $boundedCount),
            'addSlots' => $this->addSlots($target, $boundedCount, $min, $max),

            // Settled before anything is drawn, because the rest of the page would
            // be a form that cannot be submitted.
            'blocked' => $registration->hasMoneyOnRecord()
                ? sprintf(
                    '%s cannot be moved because %s has been taken for it. Refund it at the gateway, then enter it on the other event.',
                    $registration->reference,
                    $registration->amountLabel(),
                )
                : null,
        ]);
    }

    /**
     * How many "add a person" forms to draw.
     *
     * Every place up to the target's ceiling, so the compulsory ones and the
     * optional ones are on screen together and the operator can see which is
     * which. An event with no ceiling has no arithmetic answer, so it gets enough
     * to cover the shortfall and a few spare.
     */
    private function addSlots(?Event $target, int $playing, int $min, ?int $max): int
    {
        if ($target === null || ! $target->allowsMultipleParticipants()) {
            return 0;
        }

        if ($max === null) {
            return max(0, $min - $playing) + 3;
        }

        return max(0, $max - $playing);
    }

    /**
     * Move a whole entry to a different event.
     *
     * For the entry filed against the wrong event, which happens when two of them
     * are open at once and look alike. The alternative is deleting it and asking
     * seven people to type their details again.
     *
     * What is refused, and why, lives in TransferRegistrationRequest. What is left
     * here is the writing, in one transaction, in an order that matters: the old
     * event's add-on lines and answers go first because they point at its catalogue
     * and its questions; then the people being left behind, so no answer is
     * recorded against somebody who is not coming; then the arrivals; then the
     * target's questions for everybody now on the entry.
     *
     * Capacity is the one test that has to happen here rather than in the request,
     * because it is only meaningful under a lock: two administrators moving entries
     * at once must not both be told yes.
     */
    public function transfer(TransferRegistrationRequest $request, EventRegistration $registration, EventNotifier $notifier)
    {
        $registration->loadMissing(['event', 'participants']);

        $from = $registration->event;

        /** @var Event $target */
        $target = $request->target();

        $dropped = $request->dropped();
        $staying = $request->staying();
        $added = $request->added();

        $outcome = DB::transaction(function () use ($registration, $request, $target, $from, $dropped, $staying, $added) {
            /** @var Event $locked */
            $locked = Event::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();

            $headCount = $staying->count() + count($added);
            $wanted = $locked->seatsForEntry($headCount);

            if ($locked->seats_total > 0 && $wanted > $locked->seatsLeft()) {
                return ['error' => sprintf('%s is fully booked.', $locked->title)];
            }

            $droppedAddons = $registration->addonLines()->count();
            $droppedAnswers = EventParticipantAnswer::query()
                ->whereIn('event_participant_id', $registration->participants->pluck('id'))
                ->count();

            $registration->addonLines()->delete();

            EventParticipantAnswer::query()
                ->whereIn('event_participant_id', $registration->participants->pluck('id'))
                ->delete();

            foreach ($dropped as $person) {
                /*
                 | Written before the delete. Once the row is gone its id cannot be
                 | recorded, and this row is the only surviving trace that the person
                 | was ever named on the entry.
                 */
                EventParticipantChange::create([
                    'event_id' => $registration->event_id,
                    'event_registration_id' => $registration->id,
                    'event_participant_id' => $person->id,
                    'type' => EventParticipantChange::TYPE_REMOVED,
                    'previous_name' => $person->full_name,
                    'previous_ic' => $person->ic_number,
                    'new_name' => null,
                    'new_ic' => null,
                    'details_before' => $person->only([
                        'role', 'also_plays', 'full_name', 'ic_number',
                        'ign_player_id', 'ign_server_id', 'ign_name',
                        'phone', 'email', 'gender', 'race', 'date_of_birth',
                    ]),
                    'details_after' => null,
                    'reason' => sprintf('Left behind when the entry moved to %s.', $locked->title),
                    'changed_by' => $request->user()->id,
                ]);

                $person->delete();
            }

            $arrived = collect();

            foreach ($added as $person) {
                // Whitelisted rather than passed through, so the answers nested in
                // each block cannot reach the model as a column.
                $attributes = array_intersect_key($person, array_flip([
                    'full_name', 'ic_number', 'phone', 'email', 'date_of_birth',
                    'gender', 'race', 'address_line_1', 'city', 'state', 'country',
                    'ign_player_id', 'ign_server_id', 'ign_name',
                ]));

                $arrived->push(EventParticipant::create($attributes + [
                    'event_registration_id' => $registration->id,
                    // Always a player. A squad has at most one manager and it
                    // already has theirs, so there is no second one to bring in.
                    'role' => $request->addedRole(),
                    'also_plays' => false,
                ]));
            }

            $recorded = $this->recordTargetAnswers($locked, $staying, $arrived, $request);

            // The place goes back before the new one is taken, so no event can
            // appear to hold the same entry twice while this runs.
            if ($from !== null) {
                $released = $from->seatsForEntry($registration->participants->count());

                Event::query()->whereKey($from->id)->lockForUpdate()->first()?->forceFill([
                    'seats_taken' => max(0, $from->seats_taken - $released),
                ])->save();
            }

            if ($wanted > 0) {
                $locked->increment('seats_taken', $wanted);
            }

            /*
             | The amount is rewritten from the target's fee. Extras are gone, and
             | the entry fee is the target's now, not the one it arrived with. Only
             | reachable when no money has moved, so nothing is being written over a
             | figure somebody actually paid.
             */
            $fee = $locked->registrationAmount();

            $registration->forceFill([
                'event_id' => $locked->id,
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
                'target' => $locked,
                'dropped_addons' => $droppedAddons,
                'dropped_answers' => $droppedAnswers,
                'left_behind' => $dropped->count(),
                'brought_in' => $arrived->count(),
                'answers_recorded' => $recorded,
                'fee' => $fee,
            ];
        });

        if (isset($outcome['error'])) {
            return back()->withInput()->withErrors(['event_id' => $outcome['error']]);
        }

        /** @var Event $moved */
        $moved = $outcome['target'];

        AdminLogger::activity('participants.transfer', sprintf(
            'Moved %s from %s to %s. %d left behind, %d brought in.',
            $registration->reference,
            $from?->title ?? 'an unknown event',
            $moved->title,
            $outcome['left_behind'],
            $outcome['brought_in'],
        ));

        AdminLogger::audit($registration, 'transferred', [
            'event' => $from?->title,
            'amount' => $from?->registrationAmount(),
        ], [
            'event' => $moved->title,
            'amount' => $outcome['fee'],
            'left_behind' => $outcome['left_behind'],
            'brought_in' => $outcome['brought_in'],
            'addon_lines_dropped' => $outcome['dropped_addons'],
            'answers_dropped' => $outcome['dropped_answers'],
            'answers_recorded' => $outcome['answers_recorded'],
        ]);

        /*
         | Money. The fee is the target's now, so an entry that arrived free of
         | charge can land on an event that costs something. The person who
         | registered is the only one holding the means to pay, and they have no
         | reason to look, so they are told rather than left to find out.
         |
         | Reloaded first: the email renders the event and the figure from the
         | record, and both have just changed.
         */
        $registration->refresh()->load(['event', 'participants']);

        $chased = $registration->owesBalance()
            && $notifier->paymentReminder($registration, $request->user()?->id) > 0;

        $notes = [];

        if ($outcome['left_behind'] > 0) {
            $notes[] = sprintf(
                '%d %s left behind',
                $outcome['left_behind'],
                $outcome['left_behind'] === 1 ? 'person was' : 'people were',
            );
        }

        if ($outcome['brought_in'] > 0) {
            $notes[] = sprintf(
                '%d %s added',
                $outcome['brought_in'],
                $outcome['brought_in'] === 1 ? 'person was' : 'people were',
            );
        }

        if ($outcome['dropped_addons'] > 0) {
            $notes[] = sprintf(
                '%d extra %s removed, because they belonged to the old event',
                $outcome['dropped_addons'],
                $outcome['dropped_addons'] === 1 ? 'line was' : 'lines were',
            );
        }

        if ($outcome['dropped_answers'] > 0) {
            $notes[] = sprintf(
                '%d old %s cleared',
                $outcome['dropped_answers'],
                $outcome['dropped_answers'] === 1 ? 'answer was' : 'answers were',
            );
        }

        if ($registration->owesBalance()) {
            $notes[] = $chased
                ? sprintf('%s is now owed, and a request to pay has been sent', $registration->outstandingAmountLabel())
                : sprintf(
                    '%s is now owed, but no request went out. Check the Payment Reminder template is switched on and that the registrant has an email address',
                    $registration->outstandingAmountLabel(),
                );
        }

        return redirect()
            ->route('admin.event.participants.show', $registration)
            ->with('status', sprintf(
                '%s moved to %s.%s',
                $registration->reference,
                $moved->title,
                $notes === [] ? '' : ' ' . ucfirst(implode('. ', $notes)) . '.',
            ));
    }

    /**
     * Record the target event's questions for everybody now on the entry.
     *
     * The wording is copied onto each answer rather than read back through the
     * relation, so editing the question afterwards cannot rewrite what was agreed.
     * A row is written for every question, ticked or not, because "not agreed" is
     * an answer and its absence would be indistinguishable from never having been
     * asked.
     *
     * @param  \Illuminate\Support\Collection<int, EventParticipant>  $staying
     * @param  \Illuminate\Support\Collection<int, EventParticipant>  $arrived
     * @return int how many answers were written
     */
    private function recordTargetAnswers(Event $target, $staying, $arrived, TransferRegistrationRequest $request): int
    {
        $questions = $target->loadMissing('questions')->questions;

        if ($questions->isEmpty()) {
            return 0;
        }

        $given = (array) $request->input('answers', []);
        $addedInput = $request->added();
        $now = now();
        $rows = [];

        $write = function (EventParticipant $person, array $answers) use ($questions, $now, &$rows): void {
            foreach ($questions as $question) {
                $ticked = filter_var($answers[$question->id] ?? false, FILTER_VALIDATE_BOOLEAN);

                $rows[] = $question->snapshot() + [
                    'event_participant_id' => $person->id,
                    'answered' => $ticked,
                    // Stamped with now, not with the original registration's time:
                    // this was recorded by an administrator during the move, and
                    // saying otherwise would misdate the agreement.
                    'answered_at' => $ticked ? $now : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        };

        foreach ($staying as $person) {
            $write($person, (array) ($given[$person->id] ?? []));
        }

        // Index order, because the arrivals were pushed in the order they were
        // posted and their answers are still keyed by that position.
        foreach ($arrived as $index => $person) {
            $write($person, (array) ($addedInput[$index]['answers'] ?? []));
        }

        if ($rows !== []) {
            EventParticipantAnswer::query()->insert($rows);
        }

        return count($rows);
    }

    /** Where logos live on the public disk. The same folder the public form uses. */
    private const LOGO_DIRECTORY = 'registration-logos';

    /**
     * Correct the entry itself: the team's name, its logo, and the note on it.
     *
     * The panel these sit in was read-only, which left a misspelt team name with
     * nowhere to be fixed and a missing logo with nowhere to be added. Everything
     * else on that panel stays read-only on purpose, and the dialog says why:
     * the reference is quoted in every message already sent, the event has its
     * own screen because moving one moves seats and money, and the rest is either
     * the event's setting or a record of what happened.
     *
     * The old image is deleted only once the new row is saved. Deleting first
     * would leave the entry pointing at nothing if the write then failed.
     */
    public function updateEntry(UpdateRegistrationEntryRequest $request, EventRegistration $registration)
    {
        $registration->loadMissing('event');

        $before = $registration->only(['team_name', 'logo_path', 'notes']);

        $registration->fill([
            'team_name' => $request->input('team_name'),
            'notes' => $request->input('notes'),
        ]);

        $replaced = null;

        if ($request->hasFile('logo')) {
            // Stored before the save so a rejected upload cannot leave the row
            // pointing at a file that was never written.
            $registration->logo_path = $request->file('logo')->store(self::LOGO_DIRECTORY, 'public');
            $replaced = $before['logo_path'];
        } elseif ($request->boolean('remove_logo')) {
            $registration->logo_path = null;
            $replaced = $before['logo_path'];
        }

        if (! $registration->isDirty()) {
            return redirect()
                ->route('admin.event.participants.show', $registration)
                ->with('status', 'Nothing was changed on this entry.');
        }

        $changed = array_keys($registration->getDirty());

        $registration->save();

        // Now that nothing points at it. An upload that replaced nothing leaves
        // this null and there is nothing to tidy.
        if (filled($replaced)) {
            Storage::disk('public')->delete($replaced);
        }

        AdminLogger::activity('participants.entry-updated', sprintf(
            'Updated %s: %s.',
            $registration->reference,
            implode(', ', $changed),
        ));

        AdminLogger::audit(
            $registration,
            'updated',
            array_intersect_key($before, array_flip($changed)),
            array_intersect_key($registration->only(['team_name', 'logo_path', 'notes']), array_flip($changed)),
        );

        return redirect()
            ->route('admin.event.participants.show', $registration)
            ->with('status', sprintf(
                '%s updated. %d %s changed.',
                $registration->reference,
                count($changed),
                count($changed) === 1 ? 'field' : 'fields',
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
     *
     * It also carries whatever this event collects one choice of per person, such as
     * a shirt size. That lives on the entry's own item lines rather than on the
     * person, so it is written by the service the registrant's own confirmation link
     * writes through — one implementation, one promise that no money moves — and it
     * is written FIRST: a refusal from it has to leave the whole dialog unsaved
     * rather than half applied.
     */
    public function updateParticipant(
        UpdateParticipantRequest $request,
        EventRegistration $registration,
        EventParticipant $participant,
        ParticipantSizeWriter $choices,
    ) {
        $participant->loadMissing(['registration.event']);

        /*
         | Narrowed to the person whose dialog this is, in the shape the writer reads:
         | [participantId][addonId] => optionId. The writer resolves every id against
         | this entry's own people and its event's own catalogue, so the payload can
         | only ever say which option is wanted and never its price, but there is no
         | reason for one person's dialog to be able to answer for the rest of the
         | squad either.
         */
        $given = $request->input('sizes.' . $participant->id);

        $outcome = $choices->apply(
            $registration,
            is_array($given) ? [$participant->id => $given] : [],
            $request->user(),
        );

        /*
         | Nothing was written. The dialog is sent back with its messages and the
         | typed values still in it, the same way a rejected card number arrives, so
         | the refusal is read beside the field that caused it.
         */
        if ($outcome['refused'] !== []) {
            return redirect()
                ->to(route('admin.event.participants.show', $registration) . '#person-' . $participant->id)
                ->withErrors($outcome['refused'])
                ->withInput();
        }

        if ($outcome['recorded'] > 0) {
            AdminLogger::activity('participants.person-option', sprintf(
                'Recorded %d %s for %s on %s.',
                $outcome['recorded'],
                $outcome['recorded'] === 1 ? 'item choice' : 'item choices',
                $participant->full_name,
                $registration->reference,
            ));
        }

        $fields = array_keys($request->validated());

        $before = $participant->only($fields);

        $participant->fill($request->validated());

        /*
         | What the choice above did, said separately from the field count. A size
         | taken at the counter is not one of this person's own columns, and reporting
         | it as "1 field changed" would be the screen misdescribing what it wrote.
         */
        $choiceNote = $outcome['recorded'] > 0
            ? sprintf(
                ' %d %s recorded, and nothing was charged for it.',
                $outcome['recorded'],
                $outcome['recorded'] === 1 ? 'item choice' : 'item choices',
            )
            : '';

        // Nothing was typed differently, so there is nothing to write and nothing
        // worth putting in the log either.
        if (! $participant->isDirty()) {
            return redirect()
                ->route('admin.event.participants.show', $registration)
                ->with('status', $choiceNote !== ''
                    ? trim(sprintf('%s updated.%s', $participant->full_name, $choiceNote))
                    : sprintf('Nothing was changed for %s.', $participant->full_name));
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
                '%s updated. %d %s changed.%s',
                $participant->full_name,
                count($changed),
                count($changed) === 1 ? 'field' : 'fields',
                $choiceNote,
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
        $event = $registration->event;
        $minimum = $event?->min_players;

        if ($minimum === null || $minimum < 1) {
            return '';
        }

        $remaining = $event?->isGroupingMode()
            ? $registration->participants()->count()
            : $registration->participants()->playing()->count();
        $noun = $event?->isGroupingMode()
            ? ($remaining === 1 ? 'participant' : 'participants')
            : ($remaining === 1 ? 'player' : 'players');

        if ($remaining >= $minimum) {
            return '';
        }

        return sprintf(
            ' Note: this entry now has %d %s, below this event\'s minimum of %d.',
            $remaining,
            $noun,
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
            'group' => $query->where('mode', Event::MODE_GROUPING),
            'team' => $query->where('mode', Event::MODE_MANAGER),
            'paid' => $query->where('payment_status', EventRegistration::PAYMENT_PAID),
            'unpaid' => $query->where('payment_status', '!=', EventRegistration::PAYMENT_PAID),
            default => $query->where('mode', Event::MODE_INDIVIDUAL),
        };
    }

    /**
     * The list's query: one tab, narrowed by whatever filters are in force.
     *
     * THE one place the filters on this screen are expressed. The rows, the five tab
     * counts and the two money badges are all built from this, because they were not:
     * the rows honoured the event filter and the counts and badges swept every event,
     * so a screen filtered to one event reported another event's takings beside it.
     * Two queries describing the same intent is how they drifted, and this project
     * already has that lesson from a badge and a ledger disagreeing on screen.
     *
     * A null tab means every tab, which is what the money figures are built over.
     */
    private function filtered(Request $request, ?string $tab): Builder
    {
        $search = trim((string) $request->query('q'));
        $eventId = trim((string) $request->query('event'));

        return ($tab === null ? EventRegistration::query() : $this->scoped($tab))
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
            ->when($eventId !== '', fn (Builder $query) => $query->where('event_id', $eventId));
    }

    /**
     * Row count per tab, shown as a badge on the tab bar.
     *
     * Under the filters in force, so the badge promises what the tab will actually
     * show: Paid read 74 while the screen was filtered to an event holding 30 of
     * them, and Team read 44 on an event with none. Five aggregate counts, not five
     * lists — nothing is hydrated to be counted.
     *
     * @return array<string, int>
     */
    private function counts(Request $request): array
    {
        $counts = [];

        foreach (array_keys(self::TABS) as $tab) {
            $counts[$tab] = $this->filtered($request, $tab)->count();
        }

        return $counts;
    }
}
