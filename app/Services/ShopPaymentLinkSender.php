<?php

namespace App\Services;

use App\Models\ShopOrder;
use Illuminate\Support\Str;

/**
 * Asking one buyer for money, and the rules about when that is allowed.
 *
 * Extracted from the admin controller when the bulk control arrived, so the single
 * envelope on a row and the "email them all" button cannot disagree about which
 * orders may be chased, what the trail says afterwards, or when a buyer is left
 * alone. Two copies of those rules is two chances for the bulk one to mail somebody
 * the per-order one would have refused.
 *
 * It sits here rather than on ShopOrderNotifier because the trail entry needs
 * ShopOrderWriter, and the writer already depends on the notifier — injecting the
 * writer into the notifier would be a constructor cycle the container cannot
 * resolve. This class depends on both and neither depends on it.
 */
class ShopPaymentLinkSender
{
    /* Why an order was passed over. Counted by the bulk action, so each one is a
       bucket a buyer falls in exactly once. */
    public const SKIP_CLOSED = 'closed';
    public const SKIP_PAID = 'paid';
    public const SKIP_MANUAL = 'manual';
    public const SKIP_NO_EMAIL = 'no_email';
    public const SKIP_NOTHING_TO_PAY = 'nothing_to_pay';
    public const SKIP_NOT_AWAITING = 'not_awaiting';
    public const SKIP_COOLDOWN = 'cooldown';
    public const SKIP_QUEUE_FAILED = 'queue_failed';

    /**
     * Reason => the words the operator reads.
     *
     * Ordered the way the breakdown reads best, not the way the checks run.
     *
     * @return array<string, string>
     */
    public static function reasons(): array
    {
        return [
            self::SKIP_PAID => 'already paid',
            self::SKIP_CLOSED => 'cancelled or refunded',
            self::SKIP_MANUAL => 'settled by hand, so there is no online link',
            self::SKIP_NO_EMAIL => 'no email address on the order',
            self::SKIP_NOTHING_TO_PAY => 'nothing left to pay',
            self::SKIP_NOT_AWAITING => 'not waiting for payment',
            self::SKIP_COOLDOWN => sprintf('reminded in the last %d hours', ShopOrder::PAYMENT_LINK_COOLDOWN_HOURS),
            self::SKIP_QUEUE_FAILED => 'the email could not be queued',
        ];
    }

    public function __construct(
        private readonly ShopOrderNotifier $notifier,
        private readonly ShopOrderWriter $writer,
    ) {
    }

    /**
     * Why this order must not be sent a payment link, or null when it may be.
     *
     * The order of the checks decides which bucket an order that fails several of
     * them lands in, and it runs from the most informative answer down: a refunded
     * order is also a paid one, and "cancelled or refunded" tells the operator more
     * than "already paid" does.
     */
    public function skipReason(ShopOrder $order): ?string
    {
        if (
            in_array($order->status, [ShopOrder::STATUS_CANCELLED, ShopOrder::STATUS_REFUNDED], true)
            || $order->isRefunded()
        ) {
            return self::SKIP_CLOSED;
        }

        if ($order->isPaid() || $order->status === ShopOrder::STATUS_PAID) {
            return self::SKIP_PAID;
        }

        // Cash on delivery and a bank transfer are settled outside this system, so
        // there is no gateway link to send and the page a link would land on refuses
        // itself.
        if ($order->payment_method !== ShopOrder::METHOD_GATEWAY) {
            return self::SKIP_MANUAL;
        }

        if (blank($order->customer_email)) {
            return self::SKIP_NO_EMAIL;
        }

        if ((float) $order->grand_total <= 0) {
            return self::SKIP_NOTHING_TO_PAY;
        }

        // Anything left that the gateway cannot be asked for: packing, shipped,
        // delivered. Reached only by a row the three checks above let through.
        if (! $order->awaitsGatewayPayment()) {
            return self::SKIP_NOT_AWAITING;
        }

        if ($order->paymentLinkRemindedRecently()) {
            return self::SKIP_COOLDOWN;
        }

        return null;
    }

    /**
     * Queue the link, stamp the cooldown, write the trail entry.
     *
     * Returns false when the mail could not be handed to the queue. Nothing is
     * stamped in that case, so the next press tries again rather than sitting out a
     * cooldown for an email that never left.
     *
     * The caller is expected to have asked skipReason() first. It is not re-asked
     * here: the bulk action needs the reason to count it, and asking twice would
     * invite the two answers to drift.
     */
    public function send(ShopOrder $order): bool
    {
        if ($this->notifier->paymentLink($order) === 0) {
            return false;
        }

        $order->payment_link_sent_at = now();
        $order->save();

        /*
         | On the trail that already renders on the order page. "queued" and not
         | "sent": the cron worker is what sends it.
         |
         | Str::limit because shop_order_events.note is varchar(255) and the writer
         | does not truncate, while customer_email is itself a varchar(255) — so a
         | long address plus this sentence overflows, and MySQL answers that with an
         | exception rather than a trim.
         */
        $this->writer->note($order, Str::limit(
            sprintf('Payment link queued to %s.', $order->customer_email),
            252,
            '...',
        ));

        return true;
    }

    /**
     * The skip counts as one readable clause: "already paid (4), no email (1)".
     *
     * Empty string when nothing was skipped, so the caller can append it to a
     * sentence without checking.
     *
     * @param  array<string, int>  $skipped  reason => how many
     */
    public static function breakdown(array $skipped): string
    {
        $reasons = self::reasons();

        return collect($reasons)
            ->map(fn (string $label, string $reason) => ($skipped[$reason] ?? 0) > 0
                ? sprintf('%s (%d)', $label, $skipped[$reason])
                : null)
            ->filter()
            ->join(', ');
    }
}
