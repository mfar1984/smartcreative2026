<?php

namespace App\Services;

use App\Mail\ShopOrderBankTransferInstructions;
use App\Mail\ShopOrderCollectionReady;
use App\Models\ShopOrder;
use App\Support\PaymentSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * The emails a shop order sends, and the rules about when.
 *
 * One place for both, so "which orders get told what" is a single readable list
 * rather than a condition repeated in a controller and a service that are free to
 * drift apart.
 *
 * Nothing here throws. An order is a record of money and goods; a mail server being
 * unreachable must not undo a payment or block a status change. Failures are logged
 * with the reference so they can be resent by hand, and the caller carries on.
 */
class ShopOrderNotifier
{
    /**
     * Tell the buyer what to transfer and how to send the receipt.
     *
     * Only for a manual bank transfer. A gateway payment needs no instructions and
     * cash on delivery is settled at the door, so neither has anything to send.
     */
    public function bankTransferInstructions(ShopOrder $order): void
    {
        if ($order->payment_method !== ShopOrder::METHOD_BANK_TRANSFER) {
            return;
        }

        $this->send($order, 'bank transfer instructions', fn () => new ShopOrderBankTransferInstructions(
            order: $order,
            bankAccount: PaymentSettings::bankAccount(),
            bankNote: PaymentSettings::bankTransferNote(),
            receiptUrl: self::receiptUrl($order),
        ));
    }

    /**
     * Tell the buyer where and when to collect, and to bring their identity card.
     *
     * Only for a collected order, and only once it is paid. A posted order has a
     * courier instead, and an unpaid order is not a collection anybody should be
     * invited to.
     */
    public function collectionReady(ShopOrder $order): void
    {
        if (! $order->isOffline() || ! $order->isPaid()) {
            return;
        }

        $this->send($order, 'collection details', fn () => new ShopOrderCollectionReady($order));
    }

    /**
     * The buyer's link for uploading proof of a transfer.
     *
     * Signed, for the same reason the confirmation page is: references run in
     * sequence, so an unsigned link would let anybody count upwards through other
     * people's orders. No expiry, because the page refuses itself once the order is
     * paid, which is a better end than a deadline that could pass before somebody
     * gets to their online banking.
     */
    public static function receiptUrl(ShopOrder $order): string
    {
        return URL::signedRoute('shop.order.receipt', ['reference' => $order->reference]);
    }

    /**
     * @param  callable(): \Illuminate\Mail\Mailable  $build
     */
    private function send(ShopOrder $order, string $what, callable $build): void
    {
        try {
            Mail::to($order->customer_email, $order->customer_name)->send($build());
        } catch (Throwable $exception) {
            Log::error('Shop order email could not be sent.', [
                'reference' => $order->reference,
                'email' => $what,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
