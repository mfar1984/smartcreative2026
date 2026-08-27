<?php

namespace App\Http\Controllers\Admin\Payment;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Services\AdminLogger;
use App\Services\EventNotifier;
use App\Services\Payment\ChipGateway;
use App\Services\Payment\PaymentGatewayException;
use App\Services\Payment\PaymentGatewayManager;
use App\Support\ChipBalance;
use App\Support\PaymentFigures;
use App\Support\PaymentSettings;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The money, read across every event at once.
 *
 * The Participants screen answers "who registered"; this one answers "what came
 * in". They read the same table from opposite ends, which is why they are
 * separate screens rather than another tab.
 *
 * Nothing here creates a payment. Registrations and their amounts are made on the
 * public side, and the gateway decides when one is paid. This module reports and,
 * in one place, chases.
 */
class PaymentController extends Controller
{
    private const PER_PAGE = 25;

    /**
     * How long after a checkout is opened before it counts as abandoned.
     *
     * Long enough that somebody slowly typing card details is not written off,
     * short enough that the list is useful on the day.
     */
    private const ABANDONED_GRACE_MINUTES = 30;

    /**
     * What came in, and what has not.
     */
    public function overview(Request $request)
    {
        [$from, $to] = $this->range($request);

        return view('admin.payment.overview', [
            'from' => $from,
            'to' => $to,
            'collected' => PaymentFigures::collected($from, $to),
            'outstanding' => PaymentFigures::outstanding($from, $to),
            'refunded' => PaymentFigures::refunded($from, $to),
            'counts' => PaymentFigures::countsByStatus($from, $to),
            'byEvent' => PaymentFigures::byEvent($from, $to),

            'abandonedCount' => PaymentFigures::abandoned(self::ABANDONED_GRACE_MINUTES)->count(),
            'failedCount' => PaymentFigures::failed()->count(),

            'recent' => PaymentFigures::base()
                ->with(['event', 'participants'])
                ->where('payment_status', EventRegistration::PAYMENT_PAID)
                ->latest('payment_synced_at')
                ->limit(8)
                ->get(),

            'gateway' => [
                'label' => PaymentSettings::providerLabel(),
                'summary' => PaymentSettings::summary(),
                'ready' => PaymentSettings::isReady(),
                'currency' => PaymentSettings::currency(),
            ],
        ]);
    }

    /**
     * Every registration that represents money.
     */
    public function transactions(Request $request)
    {
        [$from, $to] = $this->range($request);

        $status = (string) $request->query('status', '');
        $eventId = (string) $request->query('event', '');
        $search = trim((string) $request->query('q', ''));

        $query = PaymentFigures::window(PaymentFigures::base(), $from, $to)
            ->with(['event', 'participants'])
            ->when($status !== '', fn (Builder $q) => $q->where('payment_status', $status))
            ->when($eventId !== '', fn (Builder $q) => $q->where('event_id', $eventId))
            ->when($search !== '', fn (Builder $q) => $q->where(function (Builder $inner) use ($search) {
                $inner->where('reference', 'like', "%{$search}%")
                    ->orWhere('team_name', 'like', "%{$search}%")
                    ->orWhere('payment_reference', 'like', "%{$search}%")
                    ->orWhereHas('participants', fn (Builder $people) => $people
                        ->where('full_name', 'like', "%{$search}%")
                        ->orWhere('ic_number', 'like', "%{$search}%"));
            }));

        return view('admin.payment.transactions', [
            'registrations' => $query->latest()->paginate(self::PER_PAGE)->withQueryString(),

            // The totals of what is on screen, not of the whole table. A filtered
            // list showing unfiltered totals would be read as the filter's answer.
            'filteredTotal' => (float) $query->clone()->sum('amount'),
            'filteredCount' => $query->clone()->count(),

            'statuses' => EventRegistration::PAYMENT_STATUSES,
            'events' => $this->eventOptions(),
            'filters' => compact('status', 'eventId', 'search', 'from', 'to'),
            'isFiltered' => $status !== '' || $eventId !== '' || $search !== '' || filled($from) || filled($to),
            'canExport' => $request->user()->hasPermission('payments.export'),

            // The refund column only exists for a role that may send one, so nobody
            // is shown a control that would fail on press.
            'canRefund' => $request->user()->hasPermission('payments.refund'),
            'gatewayLabel' => PaymentSettings::providerLabel(),
        ]);
    }

    /**
     * What has been given back.
     *
     * Selected on the amount rather than on the status, because a partial refund
     * leaves the entry `paid`: some of the money is still ours, so calling the whole
     * entry refunded would be wrong. Filtering on the status alone used to miss
     * every partial refund entirely.
     */
    public function refunds(Request $request)
    {
        [$from, $to] = $this->range($request);

        $query = PaymentFigures::window(PaymentFigures::base(), $from, $to)
            ->with(['event', 'participants'])
            ->where('refunded_amount', '>', 0);

        return view('admin.payment.refunds', [
            'registrations' => $query->clone()->latest('refunded_at')->paginate(self::PER_PAGE)->withQueryString(),

            // The refunded amount, not the charge. Summing `amount` reported a
            // partial refund as though the whole entry had come back.
            'total' => (float) $query->clone()->sum('refunded_amount'),
            'count' => $query->clone()->count(),
            'filters' => compact('from', 'to'),

            'canIssue' => $request->user()->hasPermission('payments.refund'),
            'gatewayLabel' => PaymentSettings::providerLabel(),
        ]);
    }

    /**
     * Send money back through the gateway.
     *
     * The order matters and is the whole point of this method: CHIP is asked first,
     * and our records are only written once it has confirmed. Marking a refund
     * locally and then calling the gateway would leave the books claiming money
     * moved whenever that call failed.
     */
    public function refund(Request $request, EventRegistration $registration, PaymentGatewayManager $gateways)
    {
        // Checked again here, not only on the route. This is the button that takes
        // money out of the account.
        if (! $request->user()->hasPermission('payments.refund')) {
            abort(403);
        }

        if (blank($registration->payment_reference)) {
            return back()->withErrors([
                'refund' => 'This entry has no gateway reference, so there is nothing for CHIP to refund. It was most likely settled by hand.',
            ]);
        }

        $refundable = $registration->refundableAmount();

        if ($refundable <= 0) {
            return back()->withErrors([
                'refund' => $registration->isFullyRefunded()
                    ? 'This entry has already been refunded in full.'
                    : 'Only a paid entry can be refunded.',
            ]);
        }

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:' . $refundable],
            'reason' => ['required', 'string', 'max:255'],
        ], [
            'amount.max' => sprintf(
                'The most that can still be refunded on this entry is %s.',
                PaymentFigures::money($refundable),
            ),
            'reason.required' => 'A reason is required. A refund nobody can explain later is worse than no refund.',
        ]);

        /*
         | Resolving the gateway is inside the try on purpose. active() throws when
         | no provider is selected or its credentials are incomplete, and leaving
         | that outside meant an unconfigured gateway produced a 500 rather than
         | telling the operator that nothing was sent.
         */
        try {
            $gateway = $gateways->active();

            if (! $gateway instanceof ChipGateway) {
                return back()->withErrors([
                    'refund' => sprintf(
                        'Refunds through the gateway are only implemented for CHIP, and %s is selected.',
                        PaymentSettings::providerLabel(),
                    ),
                ]);
            }

            // Cents, because that is the unit CHIP works in.
            $result = $gateway->refund(
                $registration->payment_reference,
                (int) round(((float) $data['amount']) * 100),
            );
        } catch (PaymentGatewayException $e) {
            Log::warning('Refund refused by the gateway.', [
                'registration' => $registration->reference,
                'requested' => $data['amount'],
                'detail' => $e->getMessage(),
            ]);

            return back()->withInput()->withErrors(['refund' => $e->publicMessage()]);
        }

        $before = [
            'payment_status' => $registration->payment_status,
            'refunded_amount' => (float) $registration->refunded_amount,
        ];

        DB::transaction(function () use ($registration, $result, $data) {
            // The figure CHIP reported, not the figure that was asked for: a gateway
            // may return less, and recording the request would overstate it.
            $total = (float) $registration->refunded_amount + $result['amount'];

            $registration->forceFill([
                'refunded_amount' => $total,
                'refunded_at' => now(),
                'refund_reason' => $data['reason'],

                /*
                 | Only a full refund changes the status. A partial one stays paid,
                 | because part of the money is still ours and the entry still counts
                 | towards what the event took.
                 */
                'payment_status' => $total >= ((float) $registration->amount - 0.001)
                    ? EventRegistration::PAYMENT_REFUNDED
                    : $registration->payment_status,
            ])->save();
        });

        AdminLogger::activity('payments.refund', sprintf(
            'Refunded %s on %s.',
            PaymentFigures::money($result['amount']),
            $registration->reference,
        ));

        AdminLogger::audit($registration, 'payment.refunded', $before, [
            'refunded_amount' => (float) $registration->fresh()->refunded_amount,
            'payment_status' => $registration->fresh()->payment_status,
            'reason' => $data['reason'],
            'gateway_refund_id' => $result['id'],
            'is_test' => $result['is_test'],
        ]);

        // The account balance in the sidebar has just moved.
        ChipBalance::forget();

        return back()->with('status', sprintf(
            '%s refunded on %s.%s',
            PaymentFigures::money($result['amount']),
            $registration->reference,
            $result['is_test'] ? ' This was a test transaction, so no real money moved.' : '',
        ));
    }

    /**
     * Money that almost arrived.
     *
     * Two different failures on one screen because the office response to both is
     * the same: get in touch. A refusal by the gateway and a payer who wandered
     * off are equally worth a telephone call.
     */
    public function failed(Request $request)
    {
        $tab = $request->query('tab') === 'abandoned' ? 'abandoned' : 'failed';

        $query = $tab === 'abandoned'
            ? PaymentFigures::abandoned(self::ABANDONED_GRACE_MINUTES)
            : PaymentFigures::failed();

        return view('admin.payment.failed', [
            'activeTab' => $tab,
            'registrations' => $query->clone()->with(['event', 'participants'])
                ->latest('updated_at')
                ->paginate(self::PER_PAGE)
                ->withQueryString(),
            'total' => (float) $query->clone()->sum('amount'),
            'failedCount' => PaymentFigures::failed()->count(),
            'abandonedCount' => PaymentFigures::abandoned(self::ABANDONED_GRACE_MINUTES)->count(),
            'graceMinutes' => self::ABANDONED_GRACE_MINUTES,
            'canNotify' => $request->user()->hasPermission('participants.notify'),
        ]);
    }

    /**
     * What to reconcile a bank statement against.
     *
     * Grouped by the day the gateway confirmed each payment, which is the figure
     * a statement line can be matched to. These totals come from this
     * application's own records: the gateway is not asked, so they are what we
     * believe we were paid rather than what was actually transferred. The page
     * says as much, because presenting them as a payout report would invite
     * somebody to stop checking.
     */
    public function settlements(Request $request)
    {
        [$from, $to] = $this->range($request);

        $days = PaymentFigures::dailyCollected($from, $to);

        return view('admin.payment.settlements', [
            'days' => $days,
            'total' => array_sum(array_column($days, 'total')),
            'count' => array_sum(array_column($days, 'count')),
            'filters' => compact('from', 'to'),
            'gatewayLabel' => PaymentSettings::providerLabel(),
        ]);
    }

    /**
     * Period summaries, and the export.
     */
    public function reports(Request $request)
    {
        [$from, $to] = $this->range($request);

        return view('admin.payment.reports', [
            'from' => $from,
            'to' => $to,
            'collected' => PaymentFigures::collected($from, $to),
            'outstanding' => PaymentFigures::outstanding($from, $to),
            'refunded' => PaymentFigures::refunded($from, $to),
            'counts' => PaymentFigures::countsByStatus($from, $to),
            'byEvent' => PaymentFigures::byEvent($from, $to),
            'days' => PaymentFigures::dailyCollected($from, $to),
            'canExport' => $request->user()->hasPermission('payments.export'),
        ]);
    }

    /**
     * The filtered transactions as a CSV.
     *
     * Streamed rather than assembled in memory: an export grows with the event,
     * and the one time it matters is the one time it is large.
     */
    public function export(Request $request): StreamedResponse
    {
        [$from, $to] = $this->range($request);

        $status = (string) $request->query('status', '');
        $eventId = (string) $request->query('event', '');

        $query = PaymentFigures::window(PaymentFigures::base(), $from, $to)
            ->with(['event', 'participants'])
            ->when($status !== '', fn (Builder $q) => $q->where('payment_status', $status))
            ->when($eventId !== '', fn (Builder $q) => $q->where('event_id', $eventId))
            ->orderBy('event_registrations.id');

        AdminLogger::activity(
            'payments.export',
            sprintf(
                'Exported %d payment rows%s.',
                $query->clone()->count(),
                filled($from) || filled($to) ? sprintf(' for %s to %s', $from ?: 'the start', $to ?: 'today') : '',
            ),
        );

        $filename = sprintf('payments-%s.csv', now()->format('Y-m-d-His'));

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'wb');

            // Excel reads a CSV as the local encoding unless told otherwise, and
            // Malaysian names carry characters that come out wrong without this.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'Reference', 'Event', 'Entry', 'Mode', 'People',
                'Registrant', 'Telephone', 'Email',
                'Fee', 'Extras', 'Amount',
                'Payment Status', 'Entry Status',
                'Gateway Reference', 'Registered At', 'Last Gateway Sync',
            ]);

            $query->chunk(200, function ($rows) use ($handle) {
                foreach ($rows as $registration) {
                    $registrant = $registration->participants->firstWhere('role', 'manager')
                        ?? $registration->participants->sortBy('id')->first();

                    fputcsv($handle, [
                        $registration->reference,
                        $registration->event?->title ?? '',
                        $registration->displayName(),
                        $registration->mode,
                        $registration->participants->count(),
                        $registrant?->full_name ?? '',
                        $registrant?->phone ?? '',
                        $registrant?->email ?? '',
                        number_format((float) $registration->registration_fee, 2, '.', ''),
                        number_format((float) $registration->addons_total, 2, '.', ''),
                        number_format((float) $registration->amount, 2, '.', ''),
                        $registration->paymentStatusLabel(),
                        $registration->statusLabel(),
                        $registration->payment_reference ?? '',
                        $registration->created_at?->toDateTimeString() ?? '',
                        $registration->payment_synced_at?->toDateTimeString() ?? '',
                    ]);
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Chase one unpaid entry from here, rather than making the operator go and
     * find it on the Participants screen.
     */
    public function remind(Request $request, EventRegistration $registration, EventNotifier $notifier)
    {
        if (! $registration->awaitingPayment()) {
            return back()->with('warning', sprintf(
                'Nothing to chase on %s: it is %s.',
                $registration->reference,
                strtolower($registration->paymentStatusLabel()),
            ));
        }

        $queued = $notifier->paymentReminder($registration, $request->user()?->id);

        if ($queued === 0) {
            return back()->with('warning', sprintf(
                'No reminder went out for %s. Check the Payment Reminder template is switched on and that the registrant has an email address.',
                $registration->reference,
            ));
        }

        return back()->with('status', sprintf(
            'Payment reminder queued for %s (%s).',
            $registration->reference,
            $registration->amountLabel(),
        ));
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    /**
     * The date range from the query string, validated rather than trusted.
     *
     * A malformed date would otherwise reach whereDate() and produce a range
     * nobody asked for, silently changing every figure on the page.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function range(Request $request): array
    {
        $parse = function (?string $value): ?string {
            if (blank($value)) {
                return null;
            }

            try {
                return \Illuminate\Support\Carbon::parse($value)->toDateString();
            } catch (\Throwable) {
                return null;
            }
        };

        $from = $parse($request->query('from'));
        $to = $parse($request->query('to'));

        // Swapped rather than refused: somebody who picks them the wrong way
        // round means the range between them.
        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }

    /**
     * Only events that have money against them, so the filter never offers a
     * choice that returns nothing.
     *
     * @return array<int, string>
     */
    private function eventOptions(): array
    {
        return Event::query()
            ->whereHas('registrations', fn (Builder $q) => $q->where('amount', '>', 0))
            ->orderBy('title')
            ->pluck('title', 'id')
            ->all();
    }
}
