<?php

namespace App\Http\Controllers;

use App\Models\ShopOrder;
use App\Services\ShopOrderWriter;
use App\Support\Cart;
use App\Support\PaymentFigures;
use App\Support\PaymentSettings;
use App\Support\ShippingSettings;
use App\Support\ShopSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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

        $methods = $this->availableMethods($lines);

        if ($methods === []) {
            /*
             | Nothing left that could take the money. Said plainly, and with the
             | reason, rather than showing a form whose submit button could not do
             | anything.
             */
            return redirect()
                ->route('cart')
                ->withErrors(['cart' => $this->whyNoMethod($lines)]);
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
        $lines = Cart::lines();

        if (! ShopSettings::isOpen() || $lines->isEmpty()) {
            return redirect()->route('shop');
        }

        $methods = $this->availableMethods($lines);

        /*
         | The basket can change between opening the form and posting it: a setting
         | switched off, or a product edited. Caught here rather than left to the
         | payment_method rule, which would report "choose one of the methods
         | offered" beside a form that is offering none.
         */
        if ($methods === []) {
            return redirect()
                ->route('cart')
                ->withErrors(['cart' => $this->whyNoMethod($lines)]);
        }

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
        $order = $this->findOrder($reference);

        return view('pages.order-confirmation', [
            'pageTitle' => 'Order ' . $order->reference,
            'order' => $order,
            'bankAccount' => PaymentSettings::bankAccount(),
            'bankNote' => PaymentSettings::bankTransferNote(),
            'codNote' => PaymentSettings::codNote(),
        ]);
    }

    /**
     * The page the buyer lands on from the link we send when a parcel goes out.
     *
     * A GET so mail clients prefetching the link cannot confirm anything: previews
     * and threat scanners follow links, and a GET that wrote would report parcels
     * received that nobody had touched.
     */
    public function confirmReceiptForm(string $reference)
    {
        return view('pages.order-received', [
            'pageTitle' => 'Confirm delivery',
            'order' => $this->findOrder($reference),
        ]);
    }

    public function confirmReceipt(Request $request, string $reference, ShopOrderWriter $writer)
    {
        $order = $this->findOrder($reference);

        if ($order->isReceiptConfirmed()) {
            // Pressing it twice is not an error; it just does nothing the second time.
            return view('pages.order-received', [
                'pageTitle' => 'Confirm delivery',
                'order' => $order,
            ]);
        }

        $order->received_confirmed_at = now();
        $order->received_confirmed_ip = $request->ip();
        $order->save();

        /*
         | A cash on delivery parcel that has been received has also been paid for, at
         | the door. Both moves are recorded so the trail shows what the buyer said and
         | what it meant, rather than one silently implying the other.
         */
        if ($order->awaitsManualPayment() && $order->payment_method === ShopOrder::METHOD_COD) {
            $writer->moveTo($order, ShopOrder::STATUS_PAID, 'Buyer confirmed the parcel arrived and was paid for on delivery.');
        }

        if ($order->canMoveTo(ShopOrder::STATUS_DELIVERED)) {
            $writer->moveTo($order, ShopOrder::STATUS_DELIVERED, 'Buyer confirmed the parcel arrived.');
        } else {
            $writer->note($order, 'Buyer confirmed the parcel arrived.');
        }

        return view('pages.order-received', [
            'pageTitle' => 'Thank you',
            'order' => $order->fresh(),
        ]);
    }

    /**
     * The order behind a signed reference.
     *
     * A 404 rather than a 403 for one that does not exist: there is nothing to tell a
     * stranger about whether a reference is real.
     */
    private function findOrder(string $reference): ShopOrder
    {
        return ShopOrder::query()
            ->with('items')
            ->where('reference', $reference)
            ->firstOrFail();
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    /**
     * The payment methods a buyer may actually choose for this basket.
     *
     * Two things narrow it, and both have to agree. PaymentSettings::enabledMethods()
     * says what the shop can take at all, judging the online gateway by whether its
     * credentials are complete, because sending somebody to a gateway that will
     * refuse the request wastes the sale. Then every product in the basket has to
     * accept the method as well.
     *
     * An intersection rather than a union. A method only one product accepts cannot
     * pay for the whole order, and offering it would charge for an item its seller
     * had refused that method for. The cost is that a mixed basket can end up with
     * nothing in common, which whyNoMethod() explains instead of leaving the buyer
     * at a dead end.
     *
     * @param  Collection<int, array<string, mixed>>  $lines
     * @return array<string, string>
     */
    private function availableMethods(Collection $lines): array
    {
        $methods = PaymentSettings::enabledMethods();

        foreach ($lines as $line) {
            $methods = array_intersect_key(
                $methods,
                array_flip($line['product']->allowedPaymentMethods()),
            );

            if ($methods === []) {
                break;
            }
        }

        return $methods;
    }

    /**
     * Why this basket cannot be paid for, in words a buyer can act on.
     *
     * Three different situations end up with no method, and telling them apart is
     * the whole point: "contact us" is right when the shop takes nothing, and wrong
     * when the fix is to split the basket in two.
     *
     * @param  Collection<int, array<string, mixed>>  $lines
     */
    private function whyNoMethod(Collection $lines): string
    {
        if (PaymentSettings::enabledMethods() === []) {
            return 'We cannot take payment online at the moment. Please contact us to place this order.';
        }

        // Items that cannot be paid for by any method the shop currently takes.
        $unpayable = $lines
            ->filter(fn (array $line) => $line['product']->payablePaymentMethods() === [])
            ->map(fn (array $line) => $line['product']->name)
            ->unique()
            ->values();

        if ($unpayable->isNotEmpty()) {
            return sprintf(
                'We cannot take payment for %s at the moment. Please remove %s from your basket, or contact us to order %s.',
                $unpayable->join(', ', ' and '),
                $unpayable->count() === 1 ? 'it' : 'them',
                $unpayable->count() === 1 ? 'it' : 'them',
            );
        }

        /*
         | Every item can be paid for on its own, but not by the same method, so the
         | basket has to be split. Each item is listed with what it does take, which
         | is what tells the buyer where to cut it.
         */
        $described = $lines
            ->unique(fn (array $line) => $line['product']->id)
            ->map(fn (array $line) => sprintf(
                '%s (%s)',
                $line['product']->name,
                collect($line['product']->payablePaymentMethods())->join(', ', ' or '),
            ))
            ->values();

        return sprintf(
            'These items do not share a payment method, so they cannot be bought together: %s. Please order them separately.',
            $described->join('; '),
        );
    }
}
