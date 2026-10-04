<?php

namespace App\Http\Controllers\Admin\Shop;

use App\Http\Controllers\Controller;
use App\Models\CollectionHandover;
use App\Models\ShopOrder;
use App\Services\AdminLogger;
use App\Services\Collection\CollectionCodeException;
use App\Services\Collection\CollectionVerifier;
use App\Services\Messaging\MessagingException;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\ShopOrderWriter;
use App\Services\ShopPaymentLinkSender;
use App\Support\LocalTime;
use App\Support\PaymentFigures;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $tab = $filters['tab'];
        $isOffline = $tab === ShopOrder::FULFILMENT_OFFLINE;
        $canNotify = $request->user()->hasPermission('shop.orders.notify');

        /*
         | The two kinds of order are kept on separate tabs rather than mixed with a
         | filter, because almost nothing about handling them is the same. A posted
         | order has a destination, a courier and a tracking number; a collected one has
         | a counter, a date and somebody's identity card. One table trying to show
         | both would have half its columns empty on every row.
         */
        $orders = $this->matching($filters)
            ->withCount('items')
            // Eager loaded so the Hand Over cell can say a handover went out without
            // a verified code. One extra query for the page rather than one per row.
            ->with('handover')
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.shop.orders', [
            'orders' => $orders,

            'activeTab' => $tab,
            'isOffline' => $isOffline,
            'tabs' => [
                ShopOrder::FULFILMENT_ONLINE => [
                    'label' => 'Online',
                    'count' => ShopOrder::query()->fulfilment(ShopOrder::FULFILMENT_ONLINE)->count(),
                ],
                ShopOrder::FULFILMENT_OFFLINE => [
                    'label' => 'Offline',
                    'count' => ShopOrder::query()->fulfilment(ShopOrder::FULFILMENT_OFFLINE)->count(),
                ],
            ],

            // Packing and shipped cannot happen to a collected order, so offering them
            // as filters would offer a search that can never match.
            'statuses' => $isOffline ? $this->offlineStatuses() : ShopOrder::STATUSES,
            'methods' => ShopOrder::METHODS,

            'search' => $filters['q'],
            'status' => $filters['status'],
            'method' => $filters['method'],
            'isFiltered' => $filters['q'] !== '' || $filters['status'] !== '' || $filters['method'] !== '',

            /*
             | The figures somebody opening this screen is actually looking for. Counted
             | within the tab, because "3 awaiting payment" is useless if two of them are
             | on the other list.
             */
            'awaitingPayment' => ShopOrder::query()->fulfilment($tab)->awaitingPayment()->count(),
            'openCount' => ShopOrder::query()->fulfilment($tab)->open()->count(),

            // Bank transfers with a receipt sitting there waiting to be checked. Only
            // worth showing when there are some.
            'awaitingReceiptCheck' => ShopOrder::query()->fulfilment($tab)->awaitingReceiptCheck()->count(),

            /*
             | How many of the orders on screen right now a payment link would actually
             | go out to. Counted through the same filters, so the figure on the button
             | is the figure the button will send, and it is 0 — hiding the button —
             | when everything matching is paid, settled by hand or inside its cooldown.
             */
            'remindableCount' => $canNotify
                ? $this->matching($filters)->remindableForPayment()->count()
                : 0,

            'canUpdate' => $request->user()->hasPermission('shop.orders.update'),
            'canConfirmPayment' => $request->user()->hasPermission('shop.orders.payment'),
            'canNotify' => $canNotify,
        ]);
    }

    /**
     * The filters in force, read from the request.
     *
     * One reader for both the list and the bulk payment-link action, so the set the
     * button emails is the set the operator is looking at. input() rather than
     * query(), because the bulk action posts the same four names in its body.
     *
     * @return array{tab: string, q: string, status: string, method: string}
     */
    private function filters(Request $request): array
    {
        return [
            'tab' => $this->resolveTab($request->input('tab')),
            'q' => trim((string) $request->input('q')),

            /*
             | A status or method that does not exist is dropped rather than passed to
             | the where clause. It matters because the bulk form posts these values
             | back: a junk status that silently filtered the table to nothing while
             | the button sent to everything would be the exact disaster this screen
             | must not have. Dropped on both sides, the two always agree.
             */
            'status' => $this->resolveKey($request->input('status'), ShopOrder::STATUSES),
            'method' => $this->resolveKey($request->input('method'), ShopOrder::METHODS),
        ];
    }

    /**
     * The value when it is one of the allowed keys, otherwise an empty string.
     *
     * @param  array<string, string>  $allowed
     */
    private function resolveKey(mixed $value, array $allowed): string
    {
        $value = is_string($value) ? trim($value) : '';

        return array_key_exists($value, $allowed) ? $value : '';
    }

    /**
     * The orders those filters select, in no particular order.
     *
     * The single definition of "what is on this screen". The list paginates it, the
     * bulk action walks it; neither rebuilds the clauses, so they cannot drift into
     * emailing a different set than the one being shown.
     *
     * @param  array{tab: string, q: string, status: string, method: string}  $filters
     */
    private function matching(array $filters): Builder
    {
        return $this->filtered($filters['q'], $filters['status'], $filters['method'])
            ->fulfilment($filters['tab']);
    }

    /**
     * Online unless offline was asked for, so a mistyped tab lands somewhere real
     * rather than on an empty page.
     */
    private function resolveTab(mixed $tab): string
    {
        return $tab === ShopOrder::FULFILMENT_OFFLINE
            ? ShopOrder::FULFILMENT_OFFLINE
            : ShopOrder::FULFILMENT_ONLINE;
    }

    /**
     * @return array<string, string>
     */
    private function offlineStatuses(): array
    {
        return collect(ShopOrder::STATUSES)
            ->except([ShopOrder::STATUS_PACKING, ShopOrder::STATUS_SHIPPED])
            ->all();
    }

    /**
     * The search and filter clauses, shared by both tabs.
     */
    private function filtered(string $search, string $status, string $method): Builder
    {
        return ShopOrder::query()
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $inner) use ($search) {
                $inner->where('reference', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_email', 'like', "%{$search}%")
                    ->orWhere('customer_phone', 'like', "%{$search}%")
                    ->orWhere('identity_card', 'like', "%{$search}%")
                    ->orWhere('tracking_number', 'like', "%{$search}%");
            }))
            ->when($status !== '', fn (Builder $query) => $query->where('status', $status))
            ->when($method !== '', fn (Builder $query) => $query->where('payment_method', $method));
    }

    public function show(Request $request, ShopOrder $order)
    {
        $order->load(['items', 'events.user', 'handover']);

        return view('admin.shop.order-show', [
            'order' => $order,
            'statuses' => ShopOrder::STATUSES,

            // Worked out from the model's own transition map, so a button is never
            // offered for a move the server would refuse.
            'transitions' => collect($order->allowedTransitions())
                ->mapWithKeys(fn (string $slug) => [$slug => ShopOrder::STATUSES[$slug] ?? $slug])
                ->all(),

            'canUpdate' => $request->user()->hasPermission('shop.orders.update'),
            'canConfirmPayment' => $request->user()->hasPermission('shop.orders.payment'),
            'canRefund' => $request->user()->hasPermission('shop.orders.refund'),
            'canNotify' => $request->user()->hasPermission('shop.orders.notify'),
        ]);
    }

    public function updateStatus(Request $request, ShopOrder $order, ShopOrderWriter $writer)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(array_keys(ShopOrder::STATUSES))],
            'note' => ['nullable', 'string', 'max:255'],
            'courier_name' => ['nullable', 'string', 'max:190'],
            'tracking_number' => ['nullable', 'string', 'max:190'],
            'tracking_url' => ['nullable', 'url', 'max:500'],

            // Where to send the operator afterwards. Only ever "list" or absent, so it
            // cannot be turned into an open redirect.
            'from' => ['nullable', 'in:list'],
        ]);

        /*
         | Moving to paid is deliberately refused here. That is the payment permission's
         | job, and letting it through this route would hand anybody who can tick an
         | order as packed the ability to write off an unpaid one.
         */
        if ($validated['status'] === ShopOrder::STATUS_PAID) {
            return back()->withErrors([
                'status' => 'Use Confirm Payment to mark an order paid. It is a separate permission because it asserts money was received.',
            ]);
        }

        if (! $order->canMoveTo($validated['status'])) {
            return back()->withErrors([
                'status' => sprintf(
                    'An order that is %s cannot move to %s.',
                    $order->statusLabel(),
                    ShopOrder::STATUSES[$validated['status']] ?? $validated['status'],
                ),
            ]);
        }

        /*
         | Tracking details are saved before the move, so the shipped notification and
         | the trail entry both see them.
         |
         | Read with ?? rather than by index: a nullable field that was not submitted at
         | all is absent from the validated set, not null in it. The status form on the
         | order page always posts these boxes, so the difference never showed until the
         | hand-over button on the orders list started posting a status on its own.
         */
        $courier = $validated['courier_name'] ?? null;
        $tracking = $validated['tracking_number'] ?? null;

        if (filled($courier) || filled($tracking)) {
            $order->fill([
                'courier_name' => $courier ?? $order->courier_name,
                'tracking_number' => $tracking ?? $order->tracking_number,
                'tracking_url' => $validated['tracking_url'] ?? $order->tracking_url,
            ])->save();
        }

        $writer->moveTo($order, $validated['status'], $validated['note'] ?? null);

        AdminLogger::activity(
            'shop.orders.update',
            sprintf('Moved order %s to %s.', $order->reference, $order->statusLabel()),
        );
        AdminLogger::audit($order, 'updated', ['status' => $order->getOriginal('status')], [
            'status' => $order->status,
            'tracking_number' => $order->tracking_number,
        ]);

        $message = $order->isOffline() && $order->isCollected()
            ? sprintf('Order %s handed over at the counter.', $order->reference)
            : sprintf('Order %s is now %s.', $order->reference, $order->statusLabel());

        /*
         | Back to the list when the press came from the list. Handing orders over at a
         | counter is a run of quick actions on one screen, and bouncing to a detail
         | page after each one would mean navigating back before the next person.
         */
        if ($request->input('from') === 'list') {
            return redirect()
                ->route('admin.shop.orders', ['tab' => $order->fulfilment])
                ->with('status', $message);
        }

        return redirect()
            ->route('admin.shop.orders.show', $order)
            ->with('status', $message);
    }

    /**
     * Text a one-time code to somebody collecting an order on the buyer's behalf.
     *
     * Answers JSON because the confirm dialog stays open: the operator types the
     * collector's details, presses this, and reads the gateway's own answer in place
     * without losing what they have already filled in. A redirect would clear the
     * form and send them round the houses with a queue waiting.
     *
     * Synchronous, straight through the gateway. The queue runs from cron once a
     * minute, which is useless when somebody is standing there.
     *
     * No code is in the response. CollectionVerifier never hands the digits back,
     * and the only thing returned here is where the message went and what the
     * gateway said about it.
     *
     * Gated on shop.orders.update, the same permission as the handover itself.
     */
    public function sendCollectionCode(Request $request, ShopOrder $order, CollectionVerifier $verifier)
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

        /*
         | Nothing to verify. Checked before a code is issued so a double press on an
         | order that has already gone out cannot text anybody.
         */
        if ($order->isCollected() || ! $order->isOffline() || ! $order->awaitsCollection()) {
            return response()->json([
                'ok' => false,
                'message' => sprintf(
                    'Order %s is not waiting to be collected, so no code was sent.',
                    $order->reference,
                ),
            ], 422);
        }

        try {
            $issued = $verifier->issue($order, $data['collector_phone'], 'order ' . $order->reference);
        } catch (CollectionCodeException $e) {
            // Our own rules: the cooldown, or too many codes to one handset. Not a
            // fault, so it reads as a wait rather than a failure.
            return response()->json(['ok' => false, 'message' => $e->publicMessage], 422);
        } catch (MessagingException $e) {
            /*
             | The gateway refused, could not be reached, or is not configured. The
             | public message only: the real one can quote the account base URL. The
             | gateway has already logged the detail.
             */
            return response()->json([
                'ok' => false,
                'message' => $e->publicMessage . ' If it will not go through, open "The code will not go through" below and say why.',
            ], 422);
        }

        AdminLogger::activity('shop.orders.collection-code', sprintf(
            'Texted a collection code for order %s to %s for %s.',
            $order->reference,
            $issued->sms->destination,
            $data['collector_name'],
        ));

        return response()->json([
            'ok' => true,
            'message' => $issued->summary(),
        ]);
    }

    /**
     * Record that a counter-collected order was actually handed over, and to whom.
     *
     * Its own route rather than a status posted through updateStatus(), and that is
     * the point of it: nothing in the request says which order moves or where it moves
     * to. The order comes from the route binding and the destination is a constant in
     * this method, so a stale page or a crafted body cannot aim this at an unpaid
     * order or at a posted one.
     *
     * Paying is not collecting. For a counter order the two are separated by however
     * long it is until the event, which is why payment leaves the order at paid —
     * awaiting collection — and this is the only thing that moves it to delivered.
     *
     * Two cases, treated differently on purpose:
     *
     *   The BUYER collects. Identity is established by the identity card check this
     *   counter has always run, against the number carried on the order. An SMS code
     *   here would cost counter time and prove nothing extra, so none is asked for.
     *   This is the default, and the flow is exactly what it was.
     *
     *   SOMEBODY ELSE collects. The risky case, and the reason any of this exists.
     *   Their name, identity card and telephone number are required, and a code
     *   texted to that number has to be read back before the handover is recorded.
     *
     * And an override, which is not optional kindness. Stadium signal is poor,
     * numbers get mistyped and gateways go down; a counter with no way through will
     * record the collector as the buyer and the record becomes a lie. So there is an
     * explicit way to complete without a code, it demands a reason, and it marks the
     * record as unverified wherever it is shown. An audited override beats a forged
     * record.
     *
     * Gated on shop.orders.update, the permission that already covers moving an order
     * along, so nothing needs re-seeding to grant it.
     */
    public function confirmCollection(
        Request $request,
        ShopOrder $order,
        ShopOrderWriter $writer,
        CollectionVerifier $verifier,
    ) {
        $validated = $request->validate([
            /*
             | Absent means the buyer, which is what every existing form and every
             | existing habit means. Nothing about today's press changes.
             */
            'collector' => ['nullable', Rule::in(array_keys(CollectionHandover::KINDS))],

            'collector_name' => ['required_if:collector,other', 'string', 'max:190'],
            'collector_ic' => ['required_if:collector,other', 'string', 'max:30'],
            'collector_phone' => ['required_if:collector,other', 'string', 'max:30'],

            // Never logged, never echoed, never put in an error message.
            'code' => ['nullable', 'string', 'max:12'],

            'override_reason' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'collector_name.required_if' => 'Name the person collecting. A record that does not say who took the goods answers nothing later.',
            'collector_ic.required_if' => 'Their identity card number is required.',
            'collector_phone.required_if' => 'A telephone number is required: it is where the code goes.',
        ]);

        /*
         | Already handed over. Answered before the eligibility check and answered as
         | a warning rather than an error, because a double press, a reload or two
         | people working the same counter queue is the ordinary way to arrive here —
         | and it must not write a second trail entry or a second activity line.
         | moveTo() would refuse the move anyway; this is what keeps the refusal from
         | reading as a fault.
         */
        if ($order->isCollected()) {
            return back()->with('warning', sprintf(
                'Order %s was already recorded as collected on %s. Nothing was changed.',
                $order->reference,
                LocalTime::format($order->delivered_at),
            ));
        }

        if (! $order->isOffline()) {
            return back()->with('warning', sprintf(
                'Order %s is posted to the buyer, so there is no counter handover to confirm. Use Move This Order Along for a parcel.',
                $order->reference,
            ));
        }

        if (! $order->awaitsCollection()) {
            return back()->with('warning', sprintf(
                'Order %s is %s, so it cannot be handed over. Only a paid order can be collected.',
                $order->reference,
                strtolower($order->statusLabel()),
            ));
        }

        $kind = ($validated['collector'] ?? CollectionHandover::KIND_BUYER) === CollectionHandover::KIND_OTHER
            ? CollectionHandover::KIND_OTHER
            : CollectionHandover::KIND_BUYER;

        $override = trim((string) ($validated['override_reason'] ?? ''));
        $note = trim((string) ($validated['note'] ?? ''));

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

            $pending = $verifier->pendingFor($order);

            /*
             | The number on the record has to be the number the code reached.
             | Without this a code could be sent to one handset and the handover
             | written against somebody else's details, which is the exact kind of
             | plausible-looking record this change exists to prevent. Checked before
             | verify() so a mismatch does not cost an attempt.
             */
            if ($pending !== null
                && $pending->phone !== PhoneNumber::toInternational((string) $validated['collector_phone'])) {
                return back()
                    ->withInput()
                    ->withErrors([
                        'collector_phone' => 'The code for this order went to a different number. Correct the number, or send a new code to this one.',
                    ]);
            }

            $check = $verifier->verify($order, (string) ($validated['code'] ?? ''));

            if (! $check->isVerified()) {
                // The order is untouched. Nothing is handed over on a failed code.
                return back()->withInput()->withErrors(['code' => $check->reason()]);
            }

            $verification = $check->verification;
        }

        $before = $order->status;
        $collector = $this->collectorDetails($order, $kind, $validated);
        $user = $request->user();

        /*
         | The move and the record of who took it, together or not at all. moveTo()
         | is what stamps delivered_at and writes the trail entry; the handover row is
         | the structured answer to "who physically walked away with this". One
         | without the other is the state this change set out to fix.
         */
        DB::transaction(function () use ($order, $writer, $kind, $collector, $verification, $override, $note, $user) {
            $writer->moveTo(
                $order,
                ShopOrder::STATUS_DELIVERED,
                $this->collectionTrailNote($kind, $collector, $verification !== null, $override, $note),
            );

            CollectionHandover::create([
                'collectable_type' => $order->getMorphClass(),
                'collectable_id' => $order->getKey(),
                'collector_kind' => $kind,
                'collector_name' => $collector['name'],
                'collector_ic' => $collector['ic'],
                'collector_phone' => $collector['phone'],
                'collection_verification_id' => $verification?->id,
                'verified_at' => $verification?->verified_at,
                'override_reason' => $override === '' ? null : $override,
                'confirmed_by' => $user?->id,
                'confirmed_by_label' => $user?->logLabel(),
                'collected_at' => $order->delivered_at ?? now(),
            ]);
        });

        AdminLogger::activity('shop.orders.collected', sprintf(
            'Confirmed collection of order %s by %s at %s.%s',
            $order->reference,
            $kind === CollectionHandover::KIND_OTHER
                ? sprintf('%s on behalf of %s', $collector['name'], $order->customer_name)
                : $order->customer_name,
            $order->collection_location ?: ($order->collection_label ?: 'the counter'),
            $override === '' ? '' : ' No SMS verification: ' . $override,
        ));

        AdminLogger::audit($order, 'collected', ['status' => $before], [
            'status' => $order->status,
            'delivered_at' => $order->delivered_at?->toDateTimeString(),

            // Who took it and how sure we are. No code and no hash: this trail is
            // read by people, and neither would tell them anything.
            'collector_kind' => $kind,
            'collector_name' => $collector['name'],
            'sms_verified' => $verification !== null,
            'override_reason' => $override === '' ? null : $override,
        ]);

        return back()->with($override === '' ? 'status' : 'warning', sprintf(
            'Order %s handed over at the counter to %s. Recorded against your name at %s.%s',
            $order->reference,
            $kind === CollectionHandover::KIND_OTHER ? $collector['name'] : $order->customer_name,
            LocalTime::format($order->delivered_at),
            $override === '' ? '' : ' Marked as handed over without SMS verification.',
        ));
    }

    /**
     * Who the record says collected it.
     *
     * For the buyer that is the order's own snapshot, so the record stands on its
     * own even if the customer row is later edited. For anybody else it is what the
     * operator was told and checked at the counter.
     *
     * @param  array<string, mixed>  $validated
     * @return array{name: string|null, ic: string|null, phone: string|null}
     */
    private function collectorDetails(ShopOrder $order, string $kind, array $validated): array
    {
        if ($kind === CollectionHandover::KIND_BUYER) {
            return [
                'name' => $order->customer_name,
                'ic' => $order->identity_card,
                'phone' => $order->customer_phone,
            ];
        }

        return [
            'name' => trim((string) ($validated['collector_name'] ?? '')),
            'ic' => trim((string) ($validated['collector_ic'] ?? '')),
            'phone' => trim((string) ($validated['collector_phone'] ?? '')),
        ];
    }

    /**
     * The sentence that goes on the order history.
     *
     * The buyer case is word for word what it has always been, including using the
     * operator's note verbatim when they typed one, because that is a flow in daily
     * use and there is no reason for it to read differently today.
     *
     * A third-party handover says who took it and whether a code backed it up. An
     * override says so in capitals: somebody reading this history months later
     * should not have to hunt for that fact.
     *
     * @param  array{name: string|null, ic: string|null, phone: string|null}  $collector
     */
    private function collectionTrailNote(
        string $kind,
        array $collector,
        bool $verified,
        string $override,
        string $note,
    ): string {
        if ($kind === CollectionHandover::KIND_BUYER) {
            return $note !== ''
                ? $note
                : 'Handed over at the counter after checking the identity card.';
        }

        $sentence = sprintf(
            'Handed over to %s. %s%s',
            collect([
                $collector['name'],
                filled($collector['ic']) ? 'IC ' . $collector['ic'] : null,
                $collector['phone'],
            ])->filter()->join(', '),
            $verified
                ? 'SMS code verified.'
                : 'HANDED OVER WITHOUT SMS VERIFICATION: ' . $override,
            $note === '' ? '' : ' Note: ' . $note,
        );

        // shop_order_events.note is a varchar(255), and MySQL answers an overlong
        // insert with an exception rather than a truncation. Recording the handover
        // matters more than recording every word about it.
        return Str::limit($sentence, 252, '...');
    }

    /**
     * Say the money arrived.
     *
     * Its own route and permission because cash on delivery and bank transfers settle
     * outside this system: nothing here can observe them, so a person has to assert
     * it. That assertion also takes the stock off, which is why it is not a tick box
     * on the status form.
     */
    public function confirmPayment(Request $request, ShopOrder $order, ShopOrderWriter $writer)
    {
        $validated = $request->validate([
            'payment_reference' => ['nullable', 'string', 'max:190'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        if (! $order->canMoveTo(ShopOrder::STATUS_PAID)) {
            return back()->withErrors([
                'payment' => sprintf('Order %s is %s, so it cannot be marked paid.', $order->reference, $order->statusLabel()),
            ]);
        }

        if (filled($validated['payment_reference'])) {
            $order->payment_reference = $validated['payment_reference'];
            $order->save();
        }

        $writer->moveTo(
            $order,
            ShopOrder::STATUS_PAID,
            $validated['note'] ?? sprintf('Payment confirmed by hand (%s).', $order->methodLabel()),
        );

        AdminLogger::activity(
            'shop.orders.payment',
            sprintf('Confirmed payment on order %s (%s).', $order->reference, $order->methodLabel()),
        );
        AdminLogger::audit($order, 'payment-confirmed', null, [
            'reference' => $order->reference,
            'method' => $order->payment_method,
            'grand_total' => $order->grand_total,
        ]);

        return redirect()
            ->route('admin.shop.orders.show', $order)
            ->with('status', sprintf('Order %s marked paid. Stock has been taken off.', $order->reference));
    }

    /**
     * Email the buyer a link to pay online.
     *
     * Separate from confirmPayment() in every sense: that one asserts money arrived,
     * this one asks for it.
     *
     * No Request parameter: nothing is read from the request, and the route's
     * permission middleware is the enforcement. refund() re-checks in its body because
     * it takes money out of the account; queueing an email to a buyer is not in that
     * class.
     *
     * The rules about which orders may be chased, the cooldown and the trail entry all
     * live in ShopPaymentLinkSender, shared with sendAllPaymentLinks() below. Two
     * copies would be two chances for the bulk button to mail somebody this press
     * would have refused.
     */
    public function sendPaymentLink(
        ShopOrder $order,
        ShopPaymentLinkSender $sender,
        PaymentGatewayManager $gateways,
    ) {
        if ($reason = $sender->skipReason($order)) {
            return back()->with('warning', sprintf(
                'No payment link went out for %s: %s.',
                $order->reference,
                $this->whyNoLink($order, $reason),
            ));
        }

        // Offering a link to a gateway that cannot answer wastes the buyer's time and
        // teaches them to ignore our emails.
        if (! $gateways->isUsable()) {
            return back()->with('warning', sprintf(
                'No payment link went out for %s: the payment gateway is not configured, so the link would not work.',
                $order->reference,
            ));
        }

        if (! $sender->send($order)) {
            return back()->with('warning', sprintf(
                'No payment link went out for %s. Check the buyer has an email address on the order.',
                $order->reference,
            ));
        }

        AdminLogger::activity('shop.orders.payment-link', sprintf(
            'Queued a payment link for %s (%s to %s).',
            $order->reference,
            $order->grandTotalLabel(),
            $order->customer_email,
        ));

        return back()->with('status', sprintf(
            'Payment link queued for %s: %s to %s. It leaves as soon as the queue worker runs.',
            $order->reference,
            $order->grandTotalLabel(),
            $order->customer_email,
        ));
    }

    /**
     * Email a payment link to everybody on the list as it is currently filtered.
     *
     * Twenty-two orders all reading Pending Payment and all paid by card is twenty-two
     * presses of the envelope, which is what this replaces. It is deliberately not a
     * schedule and not a cron: somebody with the notify permission decides, and it is
     * recorded against their name.
     *
     * "As currently filtered" is the whole contract. The tab, the status, the method
     * and the search box come through the form and go into the same matching() the
     * table itself paginates, because an operator who has narrowed the list to one
     * event and presses a button labelled "all" means those, not the database.
     *
     * Nothing from the request decides what is charged or which orders are eligible.
     * The amount is the order's own grand_total, the eligibility is the sender's, and
     * the link is signed and rebuilt server-side per order.
     */
    public function sendAllPaymentLinks(
        Request $request,
        ShopPaymentLinkSender $sender,
        PaymentGatewayManager $gateways,
    ) {
        /*
         | The filters are the only input, and they may only be values that exist.
         | They can narrow the set or match nothing; a crafted value cannot widen it
         | past the tab, and it cannot reach an order the sender would refuse.
         */
        $request->validate([
            'tab' => ['nullable', Rule::in(array_keys(ShopOrder::FULFILMENTS))],
            'status' => ['nullable', Rule::in(array_keys(ShopOrder::STATUSES))],
            'method' => ['nullable', Rule::in(array_keys(ShopOrder::METHODS))],
            'q' => ['nullable', 'string', 'max:190'],
        ]);

        $filters = $this->filters($request);

        if (! $gateways->isUsable()) {
            return back()->with('warning', 'No payment links went out: the payment gateway is not configured, so the links would not work.');
        }

        $queued = 0;
        /** @var array<string, int> $skipped  reason => how many */
        $skipped = [];

        /*
         | Walked in id order in chunks rather than loaded at once: this is a live list
         | that will keep growing, and the memory cost of a 500-order tab is not worth
         | the convenience. chunkById pages on the primary key, so stamping
         | payment_link_sent_at inside the loop cannot shuffle the pages underneath it.
         */
        $this->matching($filters)
            ->orderBy('id')
            ->chunkById(100, function ($orders) use ($sender, &$queued, &$skipped) {
                foreach ($orders as $order) {
                    // Asked once and counted, never asked twice: the reason is what
                    // the operator is told afterwards.
                    $reason = $sender->skipReason($order);

                    if ($reason === null && $sender->send($order)) {
                        $queued++;

                        continue;
                    }

                    // Nothing to skip it for, but the queue would not take it. Rare,
                    // logged by the notifier, and worth a line of its own so the
                    // counts still add up to what was on the list.
                    $reason ??= ShopPaymentLinkSender::SKIP_QUEUE_FAILED;

                    $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;
                }
            });

        $passedOver = array_sum($skipped);
        $breakdown = ShopPaymentLinkSender::breakdown($skipped);

        // One entry for the whole press: who, what was filtered, and the counts. A
        // bulk outbound action touching real customers has to be answerable for later.
        AdminLogger::activity('shop.orders.payment-link-all', sprintf(
            'Queued %d payment %s from the %s orders list (%s). Skipped %d%s.',
            $queued,
            Str::plural('link', $queued),
            strtolower(ShopOrder::FULFILMENTS[$filters['tab']] ?? $filters['tab']),
            $this->filterLabel($filters),
            $passedOver,
            $breakdown === '' ? '' : ': ' . $breakdown,
        ));

        if ($queued === 0) {
            return back()->with('warning', $passedOver === 0
                ? 'Nothing on this list is waiting for an online payment, so no links went out.'
                : sprintf(
                    'No payment links went out. All %d %s on this list were passed over: %s.',
                    $passedOver,
                    Str::plural('order', $passedOver),
                    $breakdown,
                ));
        }

        return back()->with('status', sprintf(
            '%d payment %s queued, and %s out as soon as the queue worker runs.%s',
            $queued,
            Str::plural('link', $queued),
            $queued === 1 ? 'goes' : 'go',
            $passedOver === 0
                ? ' Nothing was passed over.'
                : sprintf(
                    ' %d %s passed over: %s.',
                    $passedOver,
                    Str::plural('order', $passedOver),
                    $breakdown,
                ),
        ));
    }

    /**
     * Why there is nothing to send, in words the operator can act on.
     */
    private function whyNoLink(ShopOrder $order, string $reason): string
    {
        if ($reason === ShopPaymentLinkSender::SKIP_COOLDOWN) {
            return sprintf(
                'one was already queued %s, so the buyer is being left alone until %s',
                $order->payment_link_sent_at->diffForHumans(),
                $order->paymentLinkCooldownEndsAt()?->format('g:i a, d M') ?? 'later',
            );
        }

        if ($reason === ShopPaymentLinkSender::SKIP_MANUAL) {
            return sprintf('it is being paid by %s, which is settled by hand', $order->methodLabel());
        }

        if ($reason === ShopPaymentLinkSender::SKIP_NOT_AWAITING) {
            return sprintf('it is %s', strtolower($order->statusLabel()));
        }

        return ShopPaymentLinkSender::reasons()[$reason] ?? 'it is not waiting for payment';
    }

    /**
     * The filters in force, in words, for the activity entry.
     *
     * @param  array{tab: string, q: string, status: string, method: string}  $filters
     */
    private function filterLabel(array $filters): string
    {
        $parts = [];

        if ($filters['status'] !== '') {
            $parts[] = 'status ' . (ShopOrder::STATUSES[$filters['status']] ?? $filters['status']);
        }

        if ($filters['method'] !== '') {
            $parts[] = 'method ' . (ShopOrder::METHODS[$filters['method']] ?? $filters['method']);
        }

        if ($filters['q'] !== '') {
            $parts[] = sprintf('search "%s"', $filters['q']);
        }

        return $parts === [] ? 'no filters' : implode(', ', $parts);
    }

    /**
     * Send money back.
     *
     * The order of operations is the point, and it matches the registration refund:
     * the gateway is asked first and our records are written only once it confirms.
     * Marking a refund locally and then calling out would leave the books claiming
     * money moved whenever that call failed.
     *
     * An order settled by cash or bank transfer never went through a gateway, so
     * there is nothing to call: it is recorded as returned by hand, and whoever
     * presses it is asserting they sent the money.
     */
    public function refund(Request $request, ShopOrder $order, PaymentGatewayManager $gateways)
    {
        // Checked again here, not only on the route. This is the button that takes
        // money out of the account.
        if (! $request->user()->hasPermission('shop.orders.refund')) {
            abort(403);
        }

        $refundable = $order->isPaid() ? $order->netAmount() : 0.0;

        if ($refundable <= 0) {
            return back()->withErrors([
                'refund' => $order->isFullyRefunded()
                    ? 'This order has already been refunded in full.'
                    : 'Only a paid order can be refunded.',
            ]);
        }

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:' . $refundable],
            'reason' => ['required', 'string', 'max:255'],
        ], [
            'amount.max' => sprintf(
                'The most that can still be refunded on this order is %s.',
                PaymentFigures::money($refundable),
            ),
            'reason.required' => 'A reason is required. A refund nobody can explain later is worse than no refund.',
        ]);

        $amount = round((float) $data['amount'], 2);
        $throughGateway = $order->payment_method === ShopOrder::METHOD_GATEWAY
            && filled($order->payment_reference);

        if ($throughGateway) {
            try {
                /*
                 | Resolving the gateway is inside the try on purpose: active() throws
                 | when no provider is selected or its credentials are incomplete, and
                 | leaving it outside turned an unconfigured gateway into a 500 rather
                 | than telling the operator nothing was sent.
                 */
                $result = $gateways->active()->refund(
                    $order->payment_reference,
                    (int) round($amount * 100),
                );
            } catch (\Throwable $e) {
                AdminLogger::activity(
                    'shop.orders.refund-failed',
                    sprintf('Refund of %s on order %s was refused: %s', PaymentFigures::money($amount), $order->reference, $e->getMessage()),
                    level: AdminLogger::LEVEL_ERROR,
                );

                return back()->withErrors([
                    'refund' => 'The gateway refused the refund, so nothing was sent and nothing was recorded here. ' . $e->getMessage(),
                ]);
            }

            /*
             | The amount the gateway says it returned, not the amount asked for. They
             | can differ, and trusting our own figure would record a refund that never
             | happened at that size.
             */
            $confirmed = isset($result['payment']['amount'])
                ? round(((int) $result['payment']['amount']) / 100, 2)
                : $amount;
        } else {
            $confirmed = $amount;
        }

        $order->refunded_amount = round((float) $order->refunded_amount + $confirmed, 2);
        $order->refunded_at = now();
        $order->refund_reason = $data['reason'];
        $order->save();

        /*
         | Only a refund of the whole order closes it. A partial refund leaves the
         | order where it was, because the buyer is still owed the rest of the parcel.
         */
        if ($order->isFullyRefunded() && $order->canMoveTo(ShopOrder::STATUS_REFUNDED)) {
            $writer = app(ShopOrderWriter::class);
            $writer->moveTo($order, ShopOrder::STATUS_REFUNDED, sprintf(
                'Refunded in full: %s. %s',
                PaymentFigures::money($confirmed),
                $data['reason'],
            ));
        } else {
            app(ShopOrderWriter::class)->note($order, sprintf(
                'Refunded %s%s. %s',
                PaymentFigures::money($confirmed),
                $throughGateway ? '' : ' by hand',
                $data['reason'],
            ));
        }

        AdminLogger::activity(
            'shop.orders.refund',
            sprintf('Refunded %s on order %s. %s', PaymentFigures::money($confirmed), $order->reference, $data['reason']),
        );
        AdminLogger::audit($order, 'refunded', null, [
            'reference' => $order->reference,
            'amount' => $confirmed,
            'through_gateway' => $throughGateway,
            'reason' => $data['reason'],
        ]);

        return redirect()
            ->route('admin.shop.orders.show', $order)
            ->with('status', sprintf(
                '%s refunded on order %s.%s',
                PaymentFigures::money($confirmed),
                $order->reference,
                $throughGateway ? '' : ' Recorded as returned by hand, since this order never went through the gateway.',
            ));
    }
}
