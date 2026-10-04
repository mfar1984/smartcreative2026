<?php

namespace App\Services\Payment;

use App\Models\EventRegistration;
use App\Models\ShopOrder;
use App\Services\AdminLogger;
use App\Services\ShopOrderWriter;
use App\Support\PaymentFigures;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The single place a shop order's payment state is moved.
 *
 * The shop equivalent of RegistrationPaymentUpdater: the signed webhook and the
 * return-from-gateway page both write through here, so the ordering guards and the
 * audit trail cannot drift apart between them.
 *
 * It depends on ShopOrderWriter rather than on the model, because moveTo() is already
 * the only place that sets paid_at, takes stock exactly once and fires the collection
 * email. Bypassing it is how a gateway payment ends up not decrementing stock.
 *
 * Note on the status vocabulary: PaymentGateway::statusFromPayment() returns
 * EventRegistration::PAYMENT_* constants. Those are the published contract of the
 * interface, so they are read here rather than re-invented; changing them would mean
 * editing the registration money path.
 */
class ShopOrderPaymentUpdater
{
    public function __construct(private readonly ShopOrderWriter $writer)
    {
    }

    /**
     * Record that a checkout has been opened, without claiming it succeeded.
     */
    public function markPending(
        ShopOrder $order,
        string $purchaseId,
        string $source,
        ?string $checkoutUrl = null,
    ): void {
        /*
         | Written before payment_reference is touched, and that ordering is the whole
         | point of the table. A buyer who presses Pay twice creates a second
         | purchase; if the first is the one that settles, its id would be gone the
         | moment this method runs.
         */
        $order->checkouts()->updateOrCreate(
            ['purchase_id' => $purchaseId],
            [
                'checkout_url' => $checkoutUrl,
                'gateway' => 'chip',
                'opened_at' => now(),
            ],
        );

        /*
         | A settled order keeps its reference. Overwriting it would point the record
         | away from the purchase that actually took the money, which is what a refund
         | has to target.
         */
        if ($order->isPaid()) {
            AdminLogger::activity('shop.orders.checkout', sprintf(
                'Opened a %s checkout for %s, which is already paid. The existing reference was kept.',
                $source,
                $order->reference,
            ));

            return;
        }

        /*
         | An operator may re-send the payment link after a short collection was
         | refused. That opens a new checkout, which clears payment_details below —
         | and with it the red shortfall line on the order, the one surface somebody
         | sees without going looking. The record of the previous attempt is therefore
         | written into the history before it is dropped, so re-sending can never be
         | the step that makes money held at CHIP invisible.
         */
        if (filled($order->payment_details)) {
            $this->writer->note($order, $this->capped(sprintf(
                'Opening a new checkout. The gateway record for purchase %s is being cleared from this order; check CHIP before assuming nothing was collected.',
                $order->payment_reference ?: 'unknown',
            )));
        }

        $order->payment_reference = $purchaseId;

        // A new checkout means a new purchase at the gateway, so anything held about
        // the previous attempt no longer describes this one.
        $order->payment_details = null;
        $order->payment_synced_at = null;

        /*
         | status is deliberately not touched. It stays pending_payment, which is
         | true — no money has arrived — and inventing a status would mean a new value
         | in an enum every screen reads. paid_purchase_id is not touched either: this
         | branch only runs for an unpaid order, where it is already NULL.
         */
        $order->save();

        $this->writer->note($order, $this->capped(sprintf(
            'Opened a %s checkout for %s.',
            $source,
            $order->grandTotalLabel(),
        )));

        AdminLogger::activity('shop.orders.checkout', sprintf(
            'Opened a %s checkout for %s.',
            $source,
            $order->reference,
        ));
    }

    /**
     * Point the order at the purchase that actually settled.
     *
     * Needed because the stored reference is only the most recent attempt. When an
     * earlier purchase is the one that got paid, leaving the column alone would mean
     * every later read-back asks the gateway about the wrong purchase, and a refund
     * would target a purchase that never took money.
     *
     * No paid guard here on purpose: this method is handed an id and a model, and
     * "is this the purchase that settled the order" is a question about the arriving
     * event. The caller answers it, while the event is still in hand.
     */
    public function adoptPurchase(ShopOrder $order, string $purchaseId): void
    {
        if ($order->payment_reference === $purchaseId) {
            return;
        }

        $previous = $order->payment_reference;

        $order->payment_reference = $purchaseId;
        $order->save();

        $this->writer->note($order, $this->capped(sprintf(
            'Pointed this order at purchase %s, which is the one that settled. It was pointing at %s.',
            $purchaseId,
            $previous ?: 'nothing',
        )));

        AdminLogger::activity('shop.orders.payment-reference', sprintf(
            'Pointed %s at purchase %s, which is the one that settled. It was pointing at %s.',
            $order->reference,
            $purchaseId,
            $previous ?: 'nothing',
        ));

        AdminLogger::audit($order, 'payment-reference-adopted', [
            'payment_reference' => $previous,
        ], [
            'payment_reference' => $purchaseId,
        ]);
    }

    /**
     * Keep the gateway's own record, without touching the order's status.
     *
     * Two columns and nothing else, so it is safe to call on any order in any state —
     * including one the lifecycle will refuse to move.
     *
     * @param  array<string, mixed>  $payment
     */
    public function rememberPayment(ShopOrder $order, array $payment): void
    {
        $order->payment_details = $payment;
        $order->payment_synced_at = now();
        $order->save();
    }

    /**
     * The only path to paid from a gateway.
     *
     * @param  array<string, mixed>  $payment
     * @param  string  $source  the gateway event name, or a short description
     * @param  string|null  $purchaseId  the purchase this payload describes; null means
     *                                   the caller cannot say, which is treated as a
     *                                   known purchase so no caller has to guess
     * @return bool  whether the order moved
     */
    public function applyPaid(ShopOrder $order, array $payment, string $source, ?string $purchaseId = null): bool
    {
        /*
         | Which purchase settled this order, read from the column that records exactly
         | that. Not from payment_reference: that holds the latest attempt, an
         | administrator can type a bank reference into it, and adoptPurchase() moves
         | it. Not from shop_order_checkouts either: a purchase this application opened
         | is in that table from the moment it was opened, which is before any money
         | moved.
         |
         | So: known means "this is the purchase that is already on record as having
         | paid for this order", i.e. a replay. Unknown on a paid order means a
         | different purchase has collected money for an order that is already settled,
         | and a person has to be told.
         */
        $knownPurchase = $purchaseId === null || $order->wasSettledBy($purchaseId);

        // Stored first, so the admin can see what CHIP said even if the move below is
        // refused.
        $this->rememberPayment($order, $payment);

        $expected = (int) round((float) $order->grand_total * 100);
        $collected = ShopOrder::collectedCents($payment, $expected);

        /*
         | A purchase this order was not settled by has collected money for it. That is
         | the fact a person needs first, short or not, so it is routed to the move
         | below — where moveTo() refuses it anyway and the ! $moved branch writes it as
         | a double collection naming both purchase ids. Classifying it as a shortfall
         | instead would be a true sentence about the event and a false one about the
         | order.
         */
        $secondCollection = $order->isPaid() && ! $knownPurchase;

        if ($collected !== null && $collected < $expected && ! $secondCollection) {
            $this->writer->note($order, $this->capped(sprintf(
                'The gateway reported %s against the %s charged, so this order was not marked paid.',
                PaymentFigures::money($collected / 100),
                $order->grandTotalLabel(),
            )));

            Log::warning('A gateway payment fell short of the amount charged.', [
                'reference' => $order->reference,
                'purchase' => $purchaseId,
                'collected_cents' => $collected,
                'expected_cents' => $expected,
            ]);

            AdminLogger::activity('shop.orders.payment-short', sprintf(
                'A %s payment of %s arrived for %s against the %s charged. It was not applied.',
                $source,
                PaymentFigures::money($collected / 100),
                $order->reference,
                $order->grandTotalLabel(),
            ), level: AdminLogger::LEVEL_ERROR);

            return false;
        }

        if ($collected === null) {
            /*
             | The fail-safe direction, deliberately. The purchase lines were built
             | server-side, chargePayload() already refuses a cent-level mismatch, and
             | the RSA signature proves the body came from CHIP. Withholding goods
             | because we misread a field would re-create the exact fault this change
             | exists to fix, with the buyer's money already taken.
             */
            Log::info('A gateway payment arrived with an amount we could not corroborate.', [
                'reference' => $order->reference,
                'purchase' => $purchaseId,
                'source' => $source,
            ]);
        }

        // Read, not assumed. Hardcoding pending_payment here would file a false record
        // for any order that reached paid from somewhere else.
        $before = $order->status;

        $caveat = $collected === null
            ? ' The gateway did not report an amount we could corroborate, so the figure was not checked.'
            : '';

        $moved = $this->writer->moveTo($order, ShopOrder::STATUS_PAID, $this->capped(sprintf(
            'Paid through %s (%s).%s',
            $source,
            $order->methodLabel(),
            $caveat,
        )));

        if (! $moved) {
            /*
             | moveTo() refused. There are exactly three ways to be here, and only one
             | of them is harmless.
             |
             | 1. A replay of the purchase that already paid for this order. Not an
             |    incident: the payment was applied the first time, CHIP is just
             |    retrying. This must stay quiet, because an error-level row for every
             |    replay buries the two below it.
             |
             | 2. Money taken for an order the lifecycle will not move: cancelled and
             |    refunded are both end states. Nothing else on any screen would show
             |    this.
             |
             | 3. A DIFFERENT purchase has collected money for an order that is already
             |    paid. The buyer has been charged twice. isPaid() is true and
             |    isClosed() is false, so case 1's test alone would file this as a
             |    replay and the only trace would be one info line.
             |
             | $knownPurchase separates 1 from 3, isClosed() catches 2. Both are
             | needed; neither is sufficient alone.
             */
            if (! $order->isClosed() && $knownPurchase) {
                Log::info('A repeat gateway payment event arrived for an order already settled.', [
                    'reference' => $order->reference,
                    'status' => $order->status,
                    'purchase' => $order->paid_purchase_id,
                ]);

                return false;
            }

            /*
             | Either the order is closed, or a purchase this order was not settled by
             | has collected money for it. Both need a person, and both are shouted in
             | the same three places: the order's own history, the log, and the admin
             | activity log at error level.
             |
             | The wording splits on isClosed(), not on $knownPurchase. A cancelled
             | order that was never paid has no settling purchase, so $knownPurchase is
             | false there too, and telling its owner about "a second payment" would be
             | a false record.
             */
            $this->writer->note($order, $this->capped($order->isClosed()
                ? sprintf(
                    'The gateway reported %s collected, but this order is %s, so it was not marked paid. Decide whether to refund it at CHIP.',
                    $order->grandTotalLabel(),
                    strtolower($order->statusLabel()),
                )
                : sprintf(
                    'A second gateway payment of %s arrived on purchase %s and was not applied; this order was settled by %s. Check CHIP for a double charge.',
                    $collected !== null ? PaymentFigures::money($collected / 100) : $order->grandTotalLabel(),
                    $purchaseId ?: 'unknown',
                    $order->paid_purchase_id ?: 'something other than a gateway purchase',
                )));

            Log::warning('A gateway payment arrived for an order that cannot be marked paid.', [
                'reference' => $order->reference,
                'status' => $order->status,
                'purchase' => $purchaseId,
                'settled_by' => $order->paid_purchase_id,
                'known_purchase' => $knownPurchase,
                // null means the figure could not be corroborated. Carried because a
                // short second collection is routed here rather than to the shortfall
                // branch, so this is the only place the number appears in the log.
                'collected_cents' => $collected,
            ]);

            AdminLogger::activity('shop.orders.payment-unapplied', $order->isClosed()
                ? sprintf(
                    'A %s payment of %s arrived for %s, which is %s. It was not applied.',
                    $source,
                    $order->grandTotalLabel(),
                    $order->reference,
                    strtolower($order->statusLabel()),
                )
                : sprintf(
                    'A second %s payment arrived for %s on purchase %s, which this order was not settled by (%s against the %s charged). It was not applied. Check CHIP for a double charge.',
                    $source,
                    $order->reference,
                    $purchaseId ?: 'unknown',
                    $collected !== null
                        ? PaymentFigures::money($collected / 100)
                        : 'an amount we could not corroborate',
                    $order->grandTotalLabel(),
                ),
                level: AdminLogger::LEVEL_ERROR);

            return false;
        }

        /*
         | The move succeeded, so this purchase is the one that settled this order.
         | Recorded now, as its own small write, because it is the fact every later
         | event is judged against: without it, the branch above cannot tell a replay
         | from a second collection. Guarded so a repeat cannot rewrite it, and skipped
         | when the caller could not name a purchase.
         */
        if ($purchaseId !== null && $order->paid_purchase_id !== $purchaseId) {
            $order->paid_purchase_id = $purchaseId;
            $order->save();
        }

        AdminLogger::activity('shop.orders.payment', sprintf(
            'Settled %s (%s) through %s.',
            $order->reference,
            $order->grandTotalLabel(),
            $source,
        ));

        AdminLogger::audit($order, 'payment-settled', ['status' => $before], [
            'status' => $order->status,
            'payment_reference' => $order->payment_reference,
            'paid_purchase_id' => $order->paid_purchase_id,
            'grand_total' => $order->grand_total,
        ]);

        return true;
    }

    /**
     * Records a failed, cancelled or expired purchase. Never moves the order.
     *
     * The order stays in pending_payment, which is where it belongs: the buyer can
     * retry from the same signed link, and moving it to cancelled would need an
     * administrator to undo before they could.
     */
    public function applyFailure(ShopOrder $order, string $source): void
    {
        $note = $this->capped(sprintf('The gateway reported a failed or cancelled payment (%s).', $source));

        /*
         | Written only when it would say something new. CHIP controls how many times a
         | failure event arrives, and a buyer who retried four times should read as four
         | attempts rather than forty.
         |
         | reorder() first, and this matters: ShopOrder::events() is ordered ascending,
         | so chaining latest('id') onto it appends a SECOND ordering and the relation's
         | ascending one still wins — the query would answer with the FIRST note this
         | order ever had and dedupe nothing. reorder() drops the relation's clause.
         */
        if ($order->events()->reorder()->latest('id')->value('note') !== $note) {
            $this->writer->note($order, $note);
        }

        // Not deduplicated: a log line per event is how anybody reconstructs how many
        // times CHIP actually called, and the log is rotated while the trail is not.
        Log::info('CHIP reported a failed shop payment.', [
            'reference' => $order->reference,
            'purchase' => $order->payment_reference,
            'source' => $source,
        ]);
    }

    /**
     * Records a refund the gateway reported.
     *
     * Does not write refunded_amount. That column is written only by
     * Admin\Shop\OrderController::refund(), after the gateway has confirmed; letting a
     * webhook also write it would double-count our own refunds.
     *
     * @param  array<string, mixed>  $payment
     */
    public function noteRefund(ShopOrder $order, array $payment, string $source): void
    {
        $this->writer->note($order, $this->capped(sprintf(
            'The gateway reported a refund on this order (%s). Nothing here was changed; check CHIP for the figure.',
            $source,
        )));

        Log::info('CHIP reported a refund on a shop order.', [
            'reference' => $order->reference,
            'purchase' => $order->payment_reference,
            'source' => $source,
        ]);
    }

    /**
     * Read the purchase back from the gateway and apply whatever it reports.
     *
     * This is what keeps a page honest where webhooks cannot arrive, such as a machine
     * the gateway cannot reach.
     *
     * @return array<string, mixed>|null  the record, or null when unreadable
     */
    public function syncFromGateway(ShopOrder $order, PaymentGateway $gateway): ?array
    {
        $purchaseId = $order->payment_reference;

        if (blank($purchaseId)) {
            return null;
        }

        $payment = $gateway->fetchPayment($purchaseId);

        if ($payment === null) {
            return null;
        }

        /*
         | One rule, stated once: PAID stores the payload inside applyPaid(), as its
         | first step, precisely so a refused move still keeps the record. Every other
         | branch stores it here. Doing both would be two writes of payment_details and
         | two payment_synced_at stamps for one event.
         */
        $status = $gateway->statusFromPayment($payment);

        if ($status === EventRegistration::PAYMENT_PAID) {
            $this->applyPaid($order, $payment, 'gateway lookup', $purchaseId);

            return $payment;
        }

        $this->rememberPayment($order, $payment);

        if ($status === EventRegistration::PAYMENT_FAILED) {
            $this->applyFailure($order, 'gateway lookup');
        } elseif ($status === EventRegistration::PAYMENT_REFUNDED) {
            $this->noteRefund($order, $payment, 'gateway lookup');
        }

        return $payment;
    }

    /**
     * shop_order_events.note is varchar(255) and ShopOrderWriter does not truncate, so
     * every note composed here goes through this first. On MySQL an over-long value is
     * an exception, not a trim — which would turn a payment that has already been taken
     * into a 500 on the request that was recording it.
     *
     * 252 plus the three characters Str::limit appends is 255, the same arithmetic
     * AdminLogger uses.
     */
    private function capped(string $note): string
    {
        return Str::limit($note, 252, '...');
    }
}
