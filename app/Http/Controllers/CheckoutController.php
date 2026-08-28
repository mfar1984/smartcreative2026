<?php

namespace App\Http\Controllers;

use App\Models\ShopOrder;
use App\Services\ShopOrderWriter;
use App\Support\Cart;
use App\Support\PaymentSettings;
use App\Support\ShippingSettings;
use App\Support\ShopSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;

/**
 * Checkout.
 *
 * No account: the buyer gives a name, an address, a phone number and an email, and
 * that is stored on the order as a snapshot.
 *
 * Only payment methods that are actually switched on are offered, and the choice is
 * validated against the same list, so a crafted post cannot select cash on delivery
 * on a shop that does not accept it.
 */
class CheckoutController extends Controller
{
    public function show()
    {
        if (! ShopSettings::isOpen()) {
            return response()->view('pages.shop-closed', ['pageTitle' => ShopSettings::heading()]);
        }

        $lines = Cart::lines();

        if ($lines->isEmpty()) {
            return redirect()
                ->route('shop')
                ->withErrors(['cart' => 'Your basket is empty, so there is nothing to check out.']);
        }

        $methods = $this->availableMethods();

        if ($methods === []) {
            /*
             | Nothing is configured to take money. Said plainly rather than showing a
             | form whose submit button could not do anything.
             */
            return redirect()
                ->route('cart')
                ->withErrors(['cart' => 'We cannot take payment online at the moment. Please contact us to place this order.']);
        }

        return view('pages.checkout', [
            'pageTitle' => 'Checkout',
            'lines' => $lines,
            'itemsTotal' => round((float) $lines->sum('line_total'), 2),
            'methods' => $methods,
            'states' => ShippingSettings::STATES,
            'shippingNote' => ShippingSettings::note(),
            'freeShippingThreshold' => ShippingSettings::freeShippingThreshold(),
            'flatRateWest' => ShippingSettings::flatRateWest(),
            'flatRateEast' => ShippingSettings::flatRateEast(),
            'bankAccount' => PaymentSettings::bankAccount(),
            'bankNote' => PaymentSettings::bankTransferNote(),
            'codNote' => PaymentSettings::codNote(),
        ]);
    }

    public function place(Request $request, ShopOrderWriter $writer)
    {
        if (! ShopSettings::isOpen() || Cart::lines()->isEmpty()) {
            return redirect()->route('shop');
        }

        $methods = $this->availableMethods();

        $validated = $request->validate([
            'customer_name' => ['required', 'string', 'max:190'],
            'customer_email' => ['required', 'email:rfc', 'max:190'],
            'customer_phone' => ['required', 'string', 'max:40'],

            'address_line_1' => ['required', 'string', 'max:190'],
            'address_line_2' => ['nullable', 'string', 'max:190'],
            'postcode' => ['required', 'string', 'max:10'],
            'city' => ['required', 'string', 'max:120'],
            'state' => ['required', Rule::in(array_keys(ShippingSettings::STATES))],

            // Validated against what is switched on, not against every method that
            // exists in the code.
            'payment_method' => ['required', Rule::in(array_keys($methods))],
        ], [
            'state.in' => 'Choose the state the parcel is going to.',
            'payment_method.in' => 'Choose one of the payment methods offered.',
        ]);

        $order = $writer->place($validated, $validated['payment_method'], $request->ip());

        Cart::clear();

        /*
         | Signed, because references run in sequence and an unsigned link would let
         | anybody count upwards through other people's names and addresses.
         */
        return redirect()->to(URL::signedRoute('shop.order', ['reference' => $order->reference]));
    }

    public function confirmation(string $reference)
    {
        $order = ShopOrder::query()
            ->with('items')
            ->where('reference', $reference)
            ->firstOrFail();

        return view('pages.order-confirmation', [
            'pageTitle' => 'Order ' . $order->reference,
            'order' => $order,
            'bankAccount' => PaymentSettings::bankAccount(),
            'bankNote' => PaymentSettings::bankTransferNote(),
            'codNote' => PaymentSettings::codNote(),
        ]);
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    /**
     * The payment methods a buyer may actually choose right now.
     *
     * The online gateway is offered only when it is fully configured, because sending
     * somebody to a gateway that will refuse the request wastes the sale.
     *
     * @return array<string, string>
     */
    private function availableMethods(): array
    {
        $methods = [];

        if (PaymentSettings::isReady()) {
            $methods[ShopOrder::METHOD_GATEWAY] = ShopOrder::METHODS[ShopOrder::METHOD_GATEWAY];
        }

        if (PaymentSettings::bankTransferEnabled()) {
            $methods[ShopOrder::METHOD_BANK_TRANSFER] = ShopOrder::METHODS[ShopOrder::METHOD_BANK_TRANSFER];
        }

        if (PaymentSettings::codEnabled()) {
            $methods[ShopOrder::METHOD_COD] = ShopOrder::METHODS[ShopOrder::METHOD_COD];
        }

        return $methods;
    }
}
