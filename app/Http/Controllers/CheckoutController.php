<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Payment\ShopOrderPaymentController;
use App\Models\Coupon;
use App\Models\ShopOrder;
use App\Services\Coupon\CouponAvailability;
use App\Services\Coupon\CouponOutcome;
use App\Services\Coupon\CouponRedeemer;
use App\Services\Coupon\ShopOrderCouponWriter;
use App\Services\Payment\PaymentGatewayException;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\ShopCheckoutStarter;
use App\Services\ShopOrderNotifier;
use App\Services\ShopOrderWriter;
use App\Support\Cart;
use App\Support\PaymentFigures;
use App\Support\PaymentSettings;
use App\Support\ShippingSettings;
use App\Support\ShopSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
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
    /** What a buyer may upload as proof of a transfer, and how big. */
    private const RECEIPT_DIRECTORY = 'shop-receipts';

    private const RECEIPT_MIMES = 'jpg,jpeg,png,webp,pdf';

    private const RECEIPT_MAX_KB = 4096;

    public function __construct(
        private ShopOrderNotifier $notifier,
        private CouponAvailability $coupons,
        private ShopOrderCouponWriter $couponWriter,
    ) {
    }

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

        $isOffline = Cart::isOffline();

        return view('pages.checkout', [
            'pageTitle' => 'Checkout',
            'lines' => $lines,
            'itemsTotal' => round((float) $lines->sum('line_total'), 2),
            'methods' => $methods,
            'states' => ShippingSettings::STATES,

            /*
             | A collected order is not posted, so nothing about postage applies to it:
             | no rate, no threshold, no banding by state. The figures are still handed
             | over for the online case, and the view leans on isOffline rather than on
             | them being absent.
             */
            'isOffline' => $isOffline,
            'collectionPoint' => $isOffline ? Cart::collectionPoint() : null,

            'shippingNote' => $isOffline ? null : ShippingSettings::note(),
            'freeShippingThreshold' => ShippingSettings::freeShippingThreshold(),
            'flatRateWest' => ShippingSettings::flatRateWest(),
            'flatRateEast' => ShippingSettings::flatRateEast(),

            'bankAccount' => PaymentSettings::bankAccount(),
            'bankNote' => PaymentSettings::bankTransferNote(),
            'codNote' => PaymentSettings::codNote(),

            /*
             | The usable coupons ticked on anything in this basket, which is the only
             | thing that decides whether a Voucher Code box is drawn at all. No tick,
             | no box.
             */
            'coupons' => $this->coupons->forCart($lines),
        ]);
    }

    public function place(Request $request, ShopOrderWriter $writer, ShopCheckoutStarter $starter, CouponRedeemer $redeemer)
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

        $isOffline = Cart::isOffline();

        $validated = $request->validate([
            'customer_name' => ['required', 'string', 'max:190'],
            'customer_email' => ['required', 'email:rfc', 'max:190'],
            'customer_phone' => ['required', 'string', 'max:40'],

            /*
             | Only asked for when the goods are collected, because that is the only
             | time anybody checks it. Kept deliberately loose: a passport number is a
             | valid answer for somebody who does not hold a Malaysian identity card,
             | and a pattern tight enough to validate an IC would turn them away. The
             | counter compares the document to this, so a person is the check.
             */
            'identity_card' => $isOffline
                ? ['required', 'string', 'min:6', 'max:30']
                : ['nullable'],

            'address_line_1' => ['required', 'string', 'max:190'],
            'address_line_2' => ['nullable', 'string', 'max:190'],
            'postcode' => ['required', 'string', 'max:10'],
            'city' => ['required', 'string', 'max:120'],
            'state' => ['required', Rule::in(array_keys(ShippingSettings::STATES))],

            // Validated against what is switched on, not against every method that
            // exists in the code.
            'payment_method' => ['required', Rule::in(array_keys($methods))],

            /*
             | A coupon code, when the buyer had one. Only a shape check: whether it
             | exists, is for the shop, has expired or has gone is decided at claim
             | time under a lock, and a validation failure here would throw a whole
             | order out over a coupon.
             */
            'voucher_code' => ['nullable', 'string', 'max:64'],
        ], [
            'identity_card.required' => 'Enter your identity card or passport number. It is what the counter checks before handing the order over.',
            'identity_card.min' => 'That looks too short to be an identity card or passport number.',
            'state.in' => $isOffline ? 'Choose your state.' : 'Choose the state the parcel is going to.',
            'payment_method.in' => 'Choose one of the payment methods offered.',
        ]);

        /*
         | The coupon, claimed before the order is written.
         |
         | It has to be this way round: the discount is part of the total the order row
         | is created with, so the code is stamped first and ShopOrderWriter points it
         | at the order inside the same transaction. The claim is race-safe in
         | CouponRedeemer, and a refusal is NOT an error — the order is placed at the
         | normal price and the buyer is told what happened to their code.
         |
         | Priced against the GOODS, never the grand total: a coupon never reaches the
         | postage. ShopOrderWriter caps it at the goods as well.
         */
        $coupon = $this->claimCoupon(
            $redeemer,
            $lines,
            (string) ($validated['voucher_code'] ?? ''),
            round((float) $lines->sum('line_total'), 2),
        );

        $order = $writer->place(
            $validated,
            $validated['payment_method'],
            $request->ip(),
            $coupon?->succeeded() ? $coupon->discount : 0.0,
            $coupon?->succeeded() ? $coupon->code?->id : null,
        );

        Cart::clear();

        /*
         | Covered in full by a coupon, so there is no payment step at all.
         |
         | Settled through the same ShopOrderWriter::moveTo() every other payment goes
         | through, which stamps paid_at, takes the stock and writes the trail entry.
         | Nothing reaches the gateway: ShopOrderChargeBuilder throws on a zero total,
         | and that is a backstop rather than the path.
         |
         | Guarded on the coupon having actually been claimed, so an order that costs
         | nothing for some other reason keeps exactly the behaviour it had before
         | coupons existed, and the trail entry below cannot credit a coupon that was
         | never used.
         */
        if ($coupon?->succeeded() && $this->couponWriter->settleIfCovered($order)) {
            $order = $order->fresh();
        }

        $couponStatus = $this->couponMessage($coupon);

        /*
         | A bank transfer is the one method that needs the buyer to go and do something
         | afterwards, so the account details and the receipt link are emailed straight
         | away. The notifier never throws, so a mail failure cannot lose the order.
         */
        $this->notifier->bankTransferInstructions($order);

        /*
         | A gateway order is charged now. The buyer pressed Place Order expecting a
         | payment page, and the checkout screen promises one, so they are sent straight
         | there rather than waiting for an email.
         |
         | Cash on delivery and bank transfer fall past this and keep the manual flow
         | they have always had: nothing reaches the gateway, no checkout row is
         | written, and the redirect below is unchanged.
         */
        if ($order->payment_method === ShopOrder::METHOD_GATEWAY && $order->awaitsGatewayPayment()) {
            try {
                return redirect()->away(
                    $starter->start($order, ShopOrderPaymentController::returnUrls($order))
                );
            } catch (PaymentGatewayException $e) {
                /*
                 | The order is placed and correct; only the hand-off failed. The buyer
                 | goes to their confirmation page, which carries a Pay Now button they
                 | can press again, rather than losing the order.
                 */
                Log::warning('Shop checkout could not open a gateway payment.', [
                    'reference' => $order->reference,
                    'error' => $e->getMessage(),
                ]);

                return redirect()
                    ->to(URL::signedRoute('shop.order', ['reference' => $order->reference]))
                    ->withErrors(['payment' => $e->publicMessage()]);
            }
        }

        /*
         | Signed, because references run in sequence and an unsigned link would let
         | anybody count upwards through other people's names and addresses.
         |
         | Also where a gateway order with nothing left to pay lands: awaitsGatewayPayment()
         | is false once a coupon has covered it, so the hand-off above is skipped and
         | the buyer goes straight to a confirmation that reads as settled.
         */
        return redirect()
            ->to(URL::signedRoute('shop.order', ['reference' => $order->reference]))
            ->with('coupon_status', $couponStatus);
    }

    public function confirmation(string $reference, PaymentGatewayManager $gateways)
    {
        $order = $this->findOrder($reference);

        return view('pages.order-confirmation', [
            'pageTitle' => 'Order ' . $order->reference,
            'order' => $order,
            'bankAccount' => PaymentSettings::bankAccount(),
            'bankNote' => PaymentSettings::bankTransferNote(),
            'codNote' => PaymentSettings::codNote(),

            // POST only, and only ever the action of the Pay Now form on this page.
            'payUrl' => ShopOrderPaymentController::payUrl($order),

            // Decides whether a Pay Now button is shown at all. Offering one that
            // cannot work would be worse than saying so plainly.
            'gatewayReady' => $gateways->isUsable(),

            /*
             | Whether a Voucher Code box is drawn. Asked of the payment controller,
             | which is also what enforces it, so the field and the endpoint can never
             | disagree: only while nothing has been paid.
             */
            'canApplyCoupon' => ShopOrderPaymentController::canApplyCoupon($order, $this->coupons),
            'couponUrl' => ShopOrderPaymentController::couponUrl($order),

            'paymentOutcome' => session('payment_outcome'),
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

    /* ---------------------------------------------------------------------
     | Proof of a bank transfer
     |
     | Nothing observes money arriving in a bank account, so the buyer sends evidence
     | and a person decides. These two routes are only the evidence half: nothing here
     | marks an order paid.
     * ------------------------------------------------------------------ */

    public function receiptForm(string $reference)
    {
        $order = $this->findOrder($reference);

        return view('pages.order-receipt', $this->receiptViewData($order));
    }

    public function storeReceipt(Request $request, string $reference, ShopOrderWriter $writer)
    {
        $order = $this->findOrder($reference);

        /*
         | Refused once the order is settled or closed. A receipt against a paid order
         | would arrive after the decision it was meant to inform, and accepting uploads
         | on a cancelled one is an invitation to keep sending files nobody reads.
         */
        if (! $order->needsPaymentReceipt()) {
            return redirect()
                ->to(self::receiptUrl($order))
                ->withErrors(['receipt' => $this->whyReceiptClosed($order)]);
        }

        $request->validate([
            'receipt' => ['required', 'file', 'mimes:' . self::RECEIPT_MIMES, 'max:' . self::RECEIPT_MAX_KB],
        ], [
            'receipt.required' => 'Choose the receipt file to upload.',
            'receipt.mimes' => 'Upload a photo or a PDF of the receipt.',
            'receipt.max' => 'That file is too large. Please keep it under 4 MB.',
        ]);

        $writer->attachPaymentReceipt(
            $order,
            $request->file('receipt')->store(self::RECEIPT_DIRECTORY, 'public'),
        );

        return redirect()
            ->to(self::receiptUrl($order))
            ->with('status', 'Thank you. We will check it against our account and email you once your order is confirmed.');
    }

    /* ---------------------------------------------------------------------
     | Coupons
     * ------------------------------------------------------------------ */

    /**
     * Claim the typed code against this basket, or say why it could not be.
     *
     * Null when nothing was typed, so "no coupon" and "a coupon that was refused"
     * stay different facts: one has nothing to report, the other has to tell the
     * buyer what happened to the discount they expected.
     *
     * Two checks, and both are needed. The lookup decides whether the code belongs to
     * a batch actually ticked on something in this basket — without it a code for
     * another product, or for an event, would be spent here. The claim then re-reads
     * the expiry and the remaining count under a lock, which is the only thing that
     * can decide the last code safely.
     *
     * @param  Collection<int, array<string, mixed>>  $lines
     */
    private function claimCoupon(
        CouponRedeemer $redeemer,
        Collection $lines,
        string $typed,
        float $goods,
    ): ?CouponOutcome {
        $typed = trim($typed);

        if ($typed === '') {
            return null;
        }

        /*
         | The batches on offer, which may well be none: nothing ticked, or everything
         | ticked has expired or run out. An empty list is handed to the lookup rather
         | than short-circuited, because the lookup is what can tell an expired code
         | from a spent one from a code for another product.
         */
        $lookup = $this->coupons->lookup($typed, $this->coupons->forCart($lines), Coupon::KIND_SHOP);

        if (! $lookup->succeeded()) {
            return CouponOutcome::failed($lookup->status);
        }

        return $redeemer->claimByCode($typed, Coupon::KIND_SHOP, $goods);
    }

    /**
     * What to tell the buyer about their coupon, or null when there is nothing.
     *
     * A failure is phrased as the normal price applying rather than as an error,
     * because the order itself went through. The wording comes from CouponOutcome so
     * a refusal reads the same wherever it happens.
     */
    private function couponMessage(?CouponOutcome $coupon): ?string
    {
        if ($coupon === null) {
            return null;
        }

        return $coupon->succeeded()
            ? sprintf('Coupon applied: %s', $coupon->message())
            : $coupon->message();
    }

    /**
     * @return array<string, mixed>
     */
    private function receiptViewData(ShopOrder $order): array
    {
        return [
            'pageTitle' => 'Payment receipt for ' . $order->reference,
            'order' => $order,
            'bankAccount' => PaymentSettings::bankAccount(),
            'bankNote' => PaymentSettings::bankTransferNote(),

            // The form is only useful while there is still a decision to inform.
            'canUpload' => $order->needsPaymentReceipt(),
            'closedReason' => $order->needsPaymentReceipt() ? null : $this->whyReceiptClosed($order),
        ];
    }

    /**
     * Why this order is no longer taking receipts, in words rather than a blank page.
     */
    private function whyReceiptClosed(ShopOrder $order): string
    {
        if ($order->isPaid()) {
            return 'This order is already confirmed as paid, so there is nothing left to send us.';
        }

        if ($order->payment_method !== ShopOrder::METHOD_BANK_TRANSFER) {
            return sprintf('This order is being paid by %s, so no transfer receipt is needed.', $order->methodLabel());
        }

        return sprintf('This order is %s, so it is no longer waiting for a payment.', $order->statusLabel());
    }

    private static function receiptUrl(ShopOrder $order): string
    {
        return ShopOrderNotifier::receiptUrl($order);
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
