<?php

namespace App\Services\Coupon;

use App\Models\Coupon;
use App\Models\ShopOrder;
use App\Services\ShopOrderWriter;
use App\Support\CouponDiscount;
use Illuminate\Support\Facades\DB;

/**
 * Putting a claimed coupon onto an order that already exists.
 *
 * The checkout does not need this: there the discount is known before the order row
 * is written, and ShopOrderWriter::place() stores it with everything else. This is
 * for the buyer who has already placed an order and then types a code on the
 * confirmation page, where the figures have to be moved rather than composed.
 *
 * WHAT IT WRITES
 *
 *   discount_total  what came off, capped at the goods.
 *   grand_total     goods less the discount, plus the postage. Re-derived, never
 *                   adjusted by subtraction, so applying this twice could not
 *                   compound even if the guard above it failed.
 *
 * items_total and shipping_total are not touched. The courier charges what it
 * charges, and what was bought has not changed.
 */
class ShopOrderCouponWriter
{
    public function __construct(
        private readonly CouponRedeemer $redeemer,
        private readonly ShopOrderWriter $orders,
    ) {
    }

    /**
     * Claim one use by the code somebody typed and reduce this order by it.
     *
     * Returns the outcome untouched so the caller can fall back to the normal price
     * without catching anything. Nothing is written unless the claim succeeded.
     */
    public function applyCode(ShopOrder $order, string $typed): CouponOutcome
    {
        // Already discounted, so a second coupon would stack. One per order.
        if ($order->hasDiscount()) {
            return CouponOutcome::failed(CouponOutcome::ALREADY_USED);
        }

        /*
         | The GOODS, never the grand total.
         |
         | Handing over the grand total would let a coupon worth more than the goods
         | swallow the postage, which is paying to post somebody a free parcel. The
         | cap below and the one in ShopOrderWriter both hold the same line.
         */
        $outcome = $this->redeemer->claimByCode(
            typed: $typed,
            kind: Coupon::KIND_SHOP,
            charge: (float) $order->items_total,
            order: $order,
        );

        if ($outcome->succeeded()) {
            $this->write($order, $outcome->discount, $outcome->code?->id);
        }

        return $outcome;
    }

    /**
     * Settle an order a coupon has covered in full.
     *
     * Through ShopOrderWriter::moveTo(), which is the one place an order becomes
     * paid: it stamps paid_at, takes the stock, writes the trail entry and sends the
     * collection email. Reusing it is the point — a second way of becoming paid is
     * how a shop ends up with orders that are settled but never dispatched.
     *
     * @return bool whether it was settled here
     */
    public function settleIfCovered(ShopOrder $order): bool
    {
        if ((float) $order->grand_total > 0.005) {
            return false;
        }

        return $this->orders->moveTo(
            $order,
            ShopOrder::STATUS_PAID,
            'A coupon covered this order in full, so there was nothing to pay.',
        );
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    private function write(ShopOrder $order, float $discount, ?int $codeId): void
    {
        DB::transaction(function () use ($order, $discount, $codeId) {
            $items = round((float) $order->items_total, 2);

            $order->discount_total = min(round(max(0.0, $discount), 2), $items);
            $order->coupon_code_id = $codeId;
            $order->grand_total = round(
                CouponDiscount::applyTo($items, (float) $order->discount_total) + (float) $order->shipping_total,
                2,
            );

            $order->save();
        });
    }
}
