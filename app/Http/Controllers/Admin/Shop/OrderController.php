<?php

namespace App\Http\Controllers\Admin\Shop;

use App\Http\Controllers\Controller;
use App\Models\ShopOrder;
use App\Services\AdminLogger;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\ShopOrderWriter;
use App\Support\PaymentFigures;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request)
    {
        $search = trim((string) $request->query('q'));
        $status = trim((string) $request->query('status'));
        $method = trim((string) $request->query('method'));
        $tab = $this->resolveTab($request->query('tab'));
        $isOffline = $tab === ShopOrder::FULFILMENT_OFFLINE;

        /*
         | The two kinds of order are kept on separate tabs rather than mixed with a
         | filter, because almost nothing about handling them is the same. A posted
         | order has a destination, a courier and a tracking number; a collected one has
         | a counter, a date and somebody's identity card. One table trying to show
         | both would have half its columns empty on every row.
         */
        $orders = $this->filtered($search, $status, $method)
            ->fulfilment($tab)
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

            'search' => $search,
            'status' => $status,
            'method' => $method,
            'isFiltered' => $search !== '' || $status !== '' || $method !== '',

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

            'canUpdate' => $request->user()->hasPermission('shop.orders.update'),
            'canConfirmPayment' => $request->user()->hasPermission('shop.orders.payment'),
        ]);
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
            ->withCount('items')
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
        $order->load(['items', 'events.user']);

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
