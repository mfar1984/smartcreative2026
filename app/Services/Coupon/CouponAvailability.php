<?php

namespace App\Services\Coupon;

use App\Models\Coupon;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\ShopOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Which coupons are on offer where, and what a typed string is worth there.
 *
 * The owner's rule, in one place: a Voucher Code box appears on the public side only
 * once a coupon is ticked on the event or on something in the basket, and only while
 * one of those batches could still be used. No tick, no box — not a disabled box, not
 * a box that refuses everything.
 *
 * "Could still be used" is Coupon::isRedeemable(): not expired, and not used as many
 * times as it was allowed. An exhausted batch left ticked is deliberately NOT an
 * offer, which is the other half of the same rule: the price falls back to normal and
 * the Payment button comes back, rather than a visitor being invited to type a code
 * that cannot work.
 *
 * NOTHING HERE CLAIMS ANYTHING
 *
 * Every answer is advisory and can be stale by the time a form is posted. The claim
 * re-reads the expiry and the remaining count under a lock in CouponRedeemer, and
 * that is the only place a discount is actually decided. A check at render time is
 * not a claim, and this class exists on the understanding that it never pretends to
 * be one.
 */
class CouponAvailability
{
    /* ---------------------------------------------------------------------
     | What is on offer
     * ------------------------------------------------------------------ */

    /**
     * Usable batches ticked on this event.
     *
     * @return Collection<int, Coupon>
     */
    public function forEvent(Event $event): Collection
    {
        $event->loadMissing('coupons');

        return $event->coupons
            ->filter(fn (Coupon $coupon) => $coupon->isForEvents() && $coupon->isRedeemable())
            ->values();
    }

    public function eventOffersAny(Event $event): bool
    {
        return $this->forEvent($event)->isNotEmpty();
    }

    /**
     * Usable batches ticked on anything in this basket.
     *
     * One code pays for one order, so a batch ticked on any single line is offered
     * for the whole basket. The discount then comes off the goods as a whole, which
     * is what ShopOrderWriter stores and caps.
     *
     * @param  Collection<int, array<string, mixed>>  $lines  Cart::lines()
     * @return Collection<int, Coupon>
     */
    public function forCart(Collection $lines): Collection
    {
        $productIds = $lines
            ->map(fn (array $line) => $line['product']->id)
            ->unique()
            ->values();

        if ($productIds->isEmpty()) {
            return collect();
        }

        return Coupon::query()
            ->ofKind(Coupon::KIND_SHOP)
            ->whereHas('products', fn ($query) => $query->whereIn('shop_products.id', $productIds->all()))
            ->orderBy('name')
            ->get()
            ->filter(fn (Coupon $coupon) => $coupon->isRedeemable())
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $lines  Cart::lines()
     */
    public function cartOffersAny(Collection $lines): bool
    {
        return $this->forCart($lines)->isNotEmpty();
    }

    /** Usable batches ticked on the event this entry belongs to. */
    public function forRegistration(EventRegistration $registration): Collection
    {
        $registration->loadMissing('event');

        return $registration->event === null
            ? collect()
            : $this->forEvent($registration->event);
    }

    /**
     * Usable batches ticked on anything this order actually contains.
     *
     * Read off the order's own lines rather than the basket, which is long gone by
     * the time anybody is looking at a placed order.
     */
    public function forOrder(ShopOrder $order): Collection
    {
        $order->loadMissing('items');

        $productIds = $order->items
            ->pluck('shop_product_id')
            ->filter()
            ->unique()
            ->values();

        if ($productIds->isEmpty()) {
            return collect();
        }

        return Coupon::query()
            ->ofKind(Coupon::KIND_SHOP)
            ->whereHas('products', fn ($query) => $query->whereIn('shop_products.id', $productIds->all()))
            ->orderBy('name')
            ->get()
            ->filter(fn (Coupon $coupon) => $coupon->isRedeemable())
            ->values();
    }

    /* ---------------------------------------------------------------------
     | What a typed string is worth here
     * ------------------------------------------------------------------ */

    /**
     * Resolve a typed string against the batches on offer.
     *
     * One namespace, because there is one kind of code: the batch name. Matched
     * case-insensitively and with the whitespace trimmed, because the code is read off
     * a poster or a phone and typed back in.
     *
     * A real code whose batch is not ticked here answers WRONG_KIND — "that coupon
     * cannot be used here" — rather than "not recognised". It is the truthful answer
     * and it is the same answer an event code gets at a shop checkout, which stops
     * this box doubling as a way to discover which codes exist.
     *
     * @param  Collection<int, Coupon>  $offered  from forEvent(), forCart() and friends
     */
    public function lookup(string $typed, Collection $offered, string $kind): CouponLookup
    {
        $typed = Str::upper(trim($typed));

        if ($typed === '') {
            return CouponLookup::failed(CouponOutcome::NOT_FOUND);
        }

        $batch = Coupon::query()->where('name', $typed)->first();

        if ($batch === null) {
            return CouponLookup::failed(CouponOutcome::NOT_FOUND);
        }

        if ($batch->kind !== $kind) {
            return CouponLookup::failed(CouponOutcome::WRONG_KIND);
        }

        /*
         | Expiry and exhaustion are answered BEFORE the offered check, and the order
         | matters.
         |
         | A spent batch and an expired one have both dropped off the offer list, so
         | testing that first would answer "cannot be used here" to somebody holding a
         | code that ran out at midnight. Those are the two things they most need to be
         | told, and neither reveals anything: they are holding the code.
         */
        if ($batch->isExpired()) {
            return CouponLookup::failed(CouponOutcome::EXPIRED);
        }

        if ($batch->isExhausted()) {
            return CouponLookup::failed(CouponOutcome::RAN_OUT);
        }

        // A live code whose batch is not ticked here: another event's coupon.
        if (! in_array($batch->id, $offered->pluck('id')->all(), true)) {
            return CouponLookup::failed(CouponOutcome::WRONG_KIND);
        }

        return CouponLookup::found($batch);
    }
}
