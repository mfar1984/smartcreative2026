<?php

namespace App\Http\Controllers\Admin\Event;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventAddonVariant;
use App\Models\EventParticipant;
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
        $registration->load(['event', 'participants', 'addonLines', 'notifications.triggeredBy', 'payments.recordedBy']);

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
        if (in_array($registration->payment_status, [
            EventRegistration::PAYMENT_PAID,
            EventRegistration::PAYMENT_REFUNDED,
        ], true)) {
            return back()->withInput()->withErrors([
                'registration' => sprintf(
                    '%s cannot be deleted because it is marked %s. Refund it at the gateway first, or leave it for the record.',
                    $registration->reference,
                    strtolower($registration->paymentStatusLabel()),
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

        DB::transaction(function () use ($registration, $headCount) {
            // Locked and clamped the same way the public form takes them, so two
            // administrators deleting at once cannot push the count negative.
            if ($registration->event_id !== null) {
                $event = Event::query()->whereKey($registration->event_id)->lockForUpdate()->first();

                if ($event !== null && $headCount > 0) {
                    $event->seats_taken = max(0, $event->seats_taken - $headCount);
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
            sprintf('Deleted registration %s (%s), releasing %d seat(s).', $reference, $name, $headCount),
        );

        return redirect()
            ->route('admin.event.participants')
            ->with('status', sprintf(
                'Registration %s deleted. %d seat(s) released back to the event.',
                $reference,
                $headCount,
            ));
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
