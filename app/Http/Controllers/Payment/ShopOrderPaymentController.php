<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\ShopOrder;
use App\Services\Coupon\CouponAvailability;
use App\Services\Coupon\ShopOrderCouponWriter;
use App\Services\Payment\CheckoutUrls;
use App\Services\Payment\PaymentGatewayException;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\ShopCheckoutStarter;
use App\Services\Payment\ShopOrderPaymentUpdater;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

/**
 * Paying a shop order on the gateway.
 *
 * Every URL here is signed. A reference like SO-2026-0007 is trivial to guess and the
 * page it reaches shows what was ordered and what is owed, so a plain path would let
 * anybody walk the sequence and read other people's orders.
 *
 * Two URL shapes, and the difference matters:
 *
 *   urlFor()  -> shop.order, GET.  Goes in emails, in copyable fields, in redirects.
 *   payUrl()  -> shop.order.pay, POST ONLY. Only ever a <form action>.
 *
 * Nothing that is emailed, logged for copying or redirected to is ever payUrl(): mail
 * clients and scanners follow links, and a POST route followed by hand is a 405.
 */
class ShopOrderPaymentController extends Controller
{
    /** How long an emailed payment link stays valid. Matches the registration side. */
    private const LINK_DAYS = 30;

    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly ShopOrderPaymentUpdater $updater,
        private readonly ShopCheckoutStarter $starter,
        private readonly CouponAvailability $coupons,
        private readonly ShopOrderCouponWriter $couponWriter,
    ) {
    }

    /**
     * The link that goes in the email: the order's own confirmation page, which
     * carries the Pay Now button.
     */
    public static function urlFor(ShopOrder $order): string
    {
        return URL::temporarySignedRoute(
            'shop.order',
            now()->addDays(self::LINK_DAYS),
            ['reference' => $order->reference],
        );
    }

    /** POST only. The action of the Pay Now form, and nowhere else. */
    public static function payUrl(ShopOrder $order): string
    {
        return URL::temporarySignedRoute(
            'shop.order.pay',
            now()->addDays(self::LINK_DAYS),
            ['reference' => $order->reference],
        );
    }

    /** POST only. The action of the Voucher Code form on the confirmation page. */
    public static function couponUrl(ShopOrder $order): string
    {
        return URL::temporarySignedRoute(
            'shop.order.coupon',
            now()->addDays(self::LINK_DAYS),
            ['reference' => $order->reference],
        );
    }

    /**
     * Whether a code may still be applied to this order.
     *
     * Static, because the confirmation page is drawn by CheckoutController and the
     * rule has to be the same one this controller enforces. Two places asking the
     * same question is how a field ends up on screen beside an endpoint that refuses
     * it.
     *
     * ONLY WHILE NOTHING HAS BEEN PAID. A discount on a settled order would reduce
     * the charge below money already received, which creates a credit nobody has
     * decided how to refund. An order has no part-payment ledger — it is pending or
     * it is paid — so those two checks are the whole of it.
     */
    public static function canApplyCoupon(ShopOrder $order, CouponAvailability $coupons): bool
    {
        if ($order->isPaid() || ! $order->isPendingPayment() || $order->hasDiscount()) {
            return false;
        }

        if ((float) $order->items_total <= 0) {
            return false;
        }

        return $coupons->forOrder($order)->isNotEmpty();
    }

    /**
     * Where the gateway sends the buyer, and us, once it has an outcome.
     */
    public static function returnUrls(ShopOrder $order): CheckoutUrls
    {
        return new CheckoutUrls(
            success: self::returnUrl($order, 'success'),
            failure: self::returnUrl($order, 'failure'),
            cancel: self::returnUrl($order, 'cancel'),
            callback: route('payments.chip.webhook'),
        );
    }

    /**
     * Open a checkout and send the buyer to it.
     */
    public function pay(string $reference)
    {
        $order = $this->find($reference);

        /*
         | Closed before paid, and the order of these two checks is the point.
         | isPaid() is paid_at !== null, which stays true after a full refund — so
         | testing it first would tell the owner of a refunded order that it is
         | "already paid, nothing further is owed", which is both wrong and the
         | opposite of useful.
         */
        if ($order->isClosed()) {
            return redirect()
                ->to(self::urlFor($order))
                ->withErrors(['payment' => $this->whyNotPayable($order)]);
        }

        if ($order->isPaid()) {
            return redirect()
                ->to(self::urlFor($order))
                ->with('status', sprintf('Order %s is already paid. Nothing further is owed.', $order->reference));
        }

        if (! $order->awaitsGatewayPayment()) {
            return redirect()
                ->to(self::urlFor($order))
                ->withErrors(['payment' => $this->whyNotPayable($order)]);
        }

        if (! $this->gateways->isUsable()) {
            return redirect()
                ->to(self::urlFor($order))
                ->withErrors(['payment' => 'Online payment is not available at the moment. Please contact us.']);
        }

        try {
            $away = $this->starter->start($order, self::returnUrls($order));
        } catch (PaymentGatewayException $e) {
            Log::warning('Could not open a shop checkout.', [
                'reference' => $order->reference,
                'error' => $e->getMessage(),
            ]);

            return redirect()
                ->to(self::urlFor($order))
                ->withErrors(['payment' => $e->publicMessage()]);
        }

        return redirect()->away($away);
    }

    /**
     * Apply a voucher code to an order that has already been placed.
     *
     * Refused once anything has been paid, on the same terms canApplyCoupon() draws
     * the field on. Checked here and not only in the view, because a signed link
     * lives for thirty days and the page it was drawn from can be long out of date by
     * the time it is posted.
     *
     * A refusal leaves every figure exactly as it was and says why, beside a Pay
     * button that still works.
     */
    public function applyCoupon(Request $request, string $reference)
    {
        $order = $this->find($reference);

        $validated = $request->validate([
            'voucher_code' => ['required', 'string', 'max:64'],
        ]);

        if (! self::canApplyCoupon($order, $this->coupons)) {
            return redirect()
                ->to(self::urlFor($order))
                ->withErrors(['voucher_code' => $this->whyCouponRefused($order)]);
        }

        $offered = $this->coupons->forOrder($order);
        $lookup = $this->coupons->lookup($validated['voucher_code'], $offered, Coupon::KIND_SHOP);

        if (! $lookup->succeeded()) {
            return redirect()
                ->to(self::urlFor($order))
                ->withErrors(['voucher_code' => $lookup->message()]);
        }

        // The claim re-reads the expiry and the remaining count under a lock, so this
        // is where the last code is actually decided.
        $outcome = $this->couponWriter->applyCode($order, $validated['voucher_code']);

        if (! $outcome->succeeded()) {
            return redirect()
                ->to(self::urlFor($order))
                ->withErrors(['voucher_code' => $outcome->message()]);
        }

        /*
         | Covered in full, so the order settles itself and stops offering payment.
         | Through ShopOrderWriter::moveTo(), the one place an order becomes paid.
         */
        $settled = $this->couponWriter->settleIfCovered($order->refresh());

        return redirect()
            ->to(self::urlFor($order))
            ->with('coupon_status', sprintf(
                'Coupon applied: %s %s',
                $outcome->message(),
                $settled ? 'There is nothing left to pay.' : 'The total has been updated.',
            ));
    }

    /**
     * Where the gateway sends the buyer back to.
     *
     * The outcome in the URL is treated as a hint only. What marks an order paid is
     * the signed webhook, or a direct read of the purchase below; never a query
     * string, which the buyer controls.
     */
    public function handleReturn(string $reference, string $outcome)
    {
        $order = $this->find($reference);

        // Before the redirect, so the confirmation page reads fresh database state and
        // there is only one render path for that view.
        $this->reconcile($order);

        return redirect()
            ->to(self::urlFor($order))
            ->with('payment_outcome', in_array($outcome, ['success', 'failure', 'cancel'], true) ? $outcome : null);
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    private static function returnUrl(ShopOrder $order, string $outcome): string
    {
        return URL::temporarySignedRoute(
            'shop.order.payment.return',
            now()->addDays(self::LINK_DAYS),
            ['reference' => $order->reference, 'outcome' => $outcome],
        );
    }

    /**
     * A 404 rather than a 403 for a reference that does not exist: there is nothing to
     * tell a stranger about whether one is real. The signature check runs first anyway.
     */
    private function find(string $reference): ShopOrder
    {
        return ShopOrder::query()
            ->with('items')
            ->where('reference', $reference)
            ->firstOrFail();
    }

    /**
     * Ask the gateway what happened, when there is something to ask about.
     *
     * This is the fallback that keeps the page honest where a webhook cannot arrive —
     * a host the gateway cannot reach, where ChipGateway drops the callback entirely.
     */
    private function reconcile(ShopOrder $order): void
    {
        if (blank($order->payment_reference)) {
            return;
        }

        // Nothing to learn about a payment that has already settled or come back.
        if ($order->isPaid() || $order->status === ShopOrder::STATUS_REFUNDED) {
            return;
        }

        try {
            $this->updater->syncFromGateway($order, $this->gateways->active());
        } catch (PaymentGatewayException) {
            // Nothing to learn right now. The page draws from what is stored.
        }
    }

    /** Why a code cannot be applied, in words rather than a silent no-op. */
    private function whyCouponRefused(ShopOrder $order): string
    {
        if ($order->isPaid()) {
            return sprintf(
                'Order %s has already been paid, so a coupon cannot be applied to it now. Contact us quoting that reference.',
                $order->reference,
            );
        }

        if ($order->hasDiscount()) {
            return 'A coupon has already been applied to this order.';
        }

        if (! $order->isPendingPayment()) {
            return sprintf(
                'Order %s is %s, so a coupon cannot be applied to it.',
                $order->reference,
                strtolower($order->statusLabel()),
            );
        }

        return sprintf(
            'A coupon cannot be applied to order %s. Contact us quoting that reference if you think that is wrong.',
            $order->reference,
        );
    }

    /**
     * Why this order cannot be paid online, in words rather than a dead end.
     */
    private function whyNotPayable(ShopOrder $order): string
    {
        if ($order->status === ShopOrder::STATUS_REFUNDED) {
            return sprintf(
                'Order %s has been refunded, so there is nothing to pay. Contact us if you think that is wrong.',
                $order->reference,
            );
        }

        if ($order->status === ShopOrder::STATUS_CANCELLED) {
            return sprintf(
                'Order %s was cancelled, so it cannot be paid. Contact us quoting that reference and we will set it up again.',
                $order->reference,
            );
        }

        if ($order->payment_method !== ShopOrder::METHOD_GATEWAY) {
            return sprintf(
                'Order %s is being paid by %s, which is settled with us directly rather than online.',
                $order->reference,
                $order->methodLabel(),
            );
        }

        if ((float) $order->grand_total <= 0) {
            return sprintf('There is nothing to pay on order %s.', $order->reference);
        }

        return sprintf(
            'Order %s cannot be paid online at the moment. Please contact us quoting that reference.',
            $order->reference,
        );
    }
}
