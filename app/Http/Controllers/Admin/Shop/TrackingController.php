<?php

namespace App\Http\Controllers\Admin\Shop;

use App\Http\Controllers\Controller;
use App\Models\ShopOrder;
use App\Services\AdminLogger;
use App\Services\ShopOrderWriter;
use App\Support\ShippingSettings;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * The same orders as the Orders screen, read from the delivery end.
 *
 * Its own screen because the question it answers is different: Orders asks what has
 * been bought, this asks what still has to go out and where the parcels are. It
 * shares the orders permissions, since there is nothing here somebody with the order
 * list cannot already see.
 *
 * Live courier sync is not wired yet. Tracking numbers are typed in, and the
 * EasyParcel credentials on the Shipping tab are what that will use when it is
 * built, which is why the screen says so rather than implying otherwise.
 */
class TrackingController extends Controller
{
    private const PER_PAGE = 20;

    /**
     * Tab slug => label, icon and the statuses it covers.
     */
    public const TABS = [
        'to-send' => [
            'label' => 'To Send',
            'icon' => 'inbox',
            'statuses' => [ShopOrder::STATUS_PAID, ShopOrder::STATUS_PACKING],
        ],
        'in-transit' => [
            'label' => 'In Transit',
            'icon' => 'globe',
            'statuses' => [ShopOrder::STATUS_SHIPPED],
        ],
        'delivered' => [
            'label' => 'Delivered',
            'icon' => 'shield',
            'statuses' => [ShopOrder::STATUS_DELIVERED],
        ],
    ];

    public function index(Request $request)
    {
        $tab = $this->resolveTab($request->query('tab'));
        $search = trim((string) $request->query('q'));

        $orders = ShopOrder::query()
            ->with('items')
            ->whereIn('status', self::TABS[$tab]['statuses'])
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $inner) use ($search) {
                $inner->where('reference', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('tracking_number', 'like', "%{$search}%")
                    ->orWhere('postcode', 'like', "%{$search}%");
            }))
            /*
             | Oldest first on the sending tabs: a parcel that has been waiting three
             | days matters more than one paid for ten minutes ago. Newest first once
             | it is delivered, where recency is what somebody is looking for.
             */
            ->orderBy('paid_at', $tab === 'delivered' ? 'desc' : 'asc')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.shop.tracking', [
            'orders' => $orders,
            'tabs' => collect(self::TABS)
                ->map(fn (array $definition, string $slug) => $definition + [
                    'count' => ShopOrder::query()->whereIn('status', $definition['statuses'])->count(),
                ])
                ->all(),
            'activeTab' => $tab,
            'search' => $search,
            'isFiltered' => $search !== '',
            'easyParcelReady' => ShippingSettings::easyParcelEnabled(),
            'shippingSummary' => ShippingSettings::summary(),
            'canUpdate' => $request->user()->hasPermission('shop.orders.update'),
        ]);
    }

    /**
     * Save a tracking number, and mark the parcel shipped if it is not already.
     *
     * The two happen together because they are one act at the counter: nobody enters
     * a tracking number for a parcel they have not just handed over.
     */
    public function update(Request $request, ShopOrder $order, ShopOrderWriter $writer)
    {
        $validated = $request->validate([
            'courier_name' => ['nullable', 'string', 'max:190'],
            'tracking_number' => ['required', 'string', 'max:190'],
            'tracking_url' => ['nullable', 'url', 'max:500'],
        ], [
            'tracking_number.required' => 'A tracking number is what this screen is for. Use the order screen to change a status without one.',
        ]);

        $order->fill($validated)->save();

        /*
         | The To Send tab also holds orders still sitting in paid, and the transition
         | map deliberately routes paid -> packing -> shipped rather than allowing the
         | jump, because packing is a real state the order screen relies on.
         |
         | So walk the legs instead of attempting one hop. Entering a tracking number
         | means the parcel was both packed and handed over, and recording both keeps
         | the history honest. Checking canMoveTo on each leg is what stops a
         | delivered parcel getting a corrected tracking number from going backwards.
         */
        $moved = false;

        foreach ([ShopOrder::STATUS_PACKING, ShopOrder::STATUS_SHIPPED] as $step) {
            if ($order->status === $step || ! $order->canMoveTo($step)) {
                continue;
            }

            $writer->moveTo($order, $step, $step === ShopOrder::STATUS_SHIPPED
                ? sprintf(
                    'Shipped with %s, tracking %s.',
                    $validated['courier_name'] ?: 'a courier',
                    $validated['tracking_number'],
                )
                : 'Packed and ready to hand over.');

            $moved = true;
        }

        if (! $moved) {
            $writer->note($order, sprintf('Tracking updated to %s.', $validated['tracking_number']));
        }

        AdminLogger::activity(
            'shop.orders.update',
            sprintf('Set tracking on order %s.', $order->reference),
        );

        return redirect()
            ->route('admin.shop.tracking', ['tab' => $request->input('return_tab', 'in-transit')])
            // Worded from what actually happened: a corrected tracking number on a
            // delivered parcel must not claim the thing is on its way.
            ->with('status', $moved
                ? sprintf('Order %s is on its way.', $order->reference)
                : sprintf('Tracking on order %s updated.', $order->reference));
    }

    /**
     * The signed link a buyer presses to say the parcel arrived.
     *
     * Built here so the operator can copy it into a message. It is the same link the
     * confirmation email will carry once that is wired.
     */
    public static function receiptLink(ShopOrder $order): string
    {
        return URL::signedRoute('shop.order.received', ['reference' => $order->reference]);
    }

    private function resolveTab(?string $tab): string
    {
        return array_key_exists((string) $tab, self::TABS) ? (string) $tab : 'to-send';
    }
}
