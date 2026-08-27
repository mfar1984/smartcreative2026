<?php

namespace App\Http\Controllers\Admin\Event;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventAddonVariant;
use App\Models\EventParticipant;
use App\Models\EventRegistration;
use App\Services\AdminLogger;
use App\Services\EventNotifier;
use App\Services\Payment\PaymentGatewayException;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\RegistrationPaymentUpdater;
use App\Support\EventTemplates;
use App\Support\GatewayPaymentRecord;
use App\Support\ParticipantOptions;
use App\Support\PaymentSettings;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
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
            ->with(['event', 'participants', 'addonLines'])
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
            'totals' => [
                'collected' => EventRegistration::query()->where('payment_status', EventRegistration::PAYMENT_PAID)->sum('amount'),
                'outstanding' => EventRegistration::query()
                    ->whereIn('payment_status', [
                        EventRegistration::PAYMENT_UNPAID,
                        EventRegistration::PAYMENT_PENDING,
                        EventRegistration::PAYMENT_FAILED,
                    ])
                    ->where('status', '!=', EventRegistration::STATUS_CANCELLED)
                    ->sum('amount'),
            ],
        ]);
    }

    /**
     * One registration in full, including whatever the gateway holds about its
     * payment.
     */
    public function show(Request $request, EventRegistration $registration)
    {
        $registration->load(['event', 'participants', 'addonLines', 'notifications.triggeredBy']);

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
        if (! $registration->awaitingPayment()) {
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
            'Payment reminder queued for %s (%s).',
            $this->registrant($registration)?->full_name ?? $registration->reference,
            $registration->amountLabel(),
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
            return back()->withErrors([
                'registration' => sprintf(
                    '%s cannot be deleted because it is marked %s. Refund it at the gateway first, or leave it for the record.',
                    $registration->reference,
                    strtolower($registration->paymentStatusLabel()),
                ),
            ]);
        }

        $registration->load(['event', 'participants', 'addonLines']);

        $reference = $registration->reference;
        $name = $registration->displayName();
        $logoPath = $registration->logo_path;
        $headCount = $registration->participants->count();

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
