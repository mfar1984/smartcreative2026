<?php

namespace App\Http\Controllers;

use App\Support\Cart;
use App\Support\ShippingSettings;
use App\Support\ShopSettings;
use Illuminate\Http\Request;

/**
 * The basket.
 *
 * Only quantities are accepted from the request. Prices come from the database on
 * every read, so nothing here trusts what the browser sends about money.
 */
class CartController extends Controller
{
    public function index()
    {
        if (! ShopSettings::isOpen()) {
            return response()->view('pages.shop-closed', ['pageTitle' => ShopSettings::heading()]);
        }

        $lines = Cart::lines();
        $itemsTotal = round((float) $lines->sum('line_total'), 2);

        return view('pages.cart', [
            'pageTitle' => 'Your Basket',
            'lines' => $lines,
            'itemsTotal' => $itemsTotal,
            'capped' => $lines->filter(fn (array $line) => $line['capped_to'] !== null)->values(),

            /*
             | Shown as an estimate only. The real figure needs a delivery state, which
             | is asked for at checkout, so quoting a total here would be a guess
             | presented as a price.
             */
            'freeShippingThreshold' => ShippingSettings::freeShippingThreshold(),
            'shippingNote' => ShippingSettings::note(),

            /*
             | A collected basket is not posted, so none of the postage copy applies to
             | it. Stated here rather than left for checkout, because a buyer looking at
             | "delivery worked out at checkout" would reasonably expect a parcel.
             */
            'isOffline' => Cart::isOffline(),
            'collectionPoint' => Cart::collectionPoint(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer'],
            'variant_id' => ['nullable', 'integer'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:' . Cart::MAX_PER_LINE],
        ]);

        /*
         | Cart::add returns the reason it refused, or null when it worked. A reason
         | rather than a bare false because the refusals are no longer alike: "sold
         | out" and "that cannot be in the same order as what you already have" need
         | different things from the buyer.
         */
        $refusal = Cart::add(
            (int) $validated['product_id'],
            isset($validated['variant_id']) ? (int) $validated['variant_id'] : null,
            (int) ($validated['quantity'] ?? 1),
        );

        if ($refusal !== null) {
            return back()->withErrors(['cart' => $refusal]);
        }

        return redirect()
            ->route('cart')
            ->with('status', 'Added to your basket.');
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'quantities' => ['required', 'array'],
            'quantities.*' => ['nullable', 'integer', 'min:0', 'max:' . Cart::MAX_PER_LINE],
        ]);

        foreach ($validated['quantities'] as $key => $quantity) {
            // Zero removes the line, which is what an emptied box means.
            Cart::setQuantity((string) $key, (int) $quantity);
        }

        return redirect()
            ->route('cart')
            ->with('status', 'Basket updated.');
    }

    public function destroy()
    {
        Cart::clear();

        return redirect()
            ->route('shop')
            ->with('status', 'Basket emptied.');
    }
}
