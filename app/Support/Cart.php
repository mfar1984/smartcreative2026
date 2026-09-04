<?php

namespace App\Support;

use App\Models\ShopProduct;
use App\Models\ShopProductVariant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Session;

/**
 * The shop basket, held in the session.
 *
 * Only quantities are stored. Every price, name and stock figure is resolved from
 * the database on each read, the same rule App\Support\AddonOrder follows for event
 * add-ons: a session is client controlled in everything but name, so a total built
 * from it could be edited by whoever owns the cookie.
 *
 * Lines pointing at a product that has since been deleted, unpublished or sold out
 * are dropped from what is returned rather than silently priced at zero, and the
 * caller is told how many went so the buyer can be shown why their basket changed.
 */
final class Cart
{
    private const KEY = 'shop.cart';

    /** Most anybody can buy of one line in one order. */
    public const MAX_PER_LINE = 99;

    /**
     * The one message for "there is nothing here to buy".
     *
     * Gone, unpublished, sold out, the wrong option, or an offline product whose
     * collection point has disappeared. Five causes, one thing from the buyer's side:
     * it is not available. The distinctions matter to an administrator and are
     * visible in the admin, not here.
     */
    public const UNAVAILABLE = 'That is not available. It may have sold out or been taken off the shop while you were looking.';

    /* ---------------------------------------------------------------------
     | Writing
     * ------------------------------------------------------------------ */

    /**
     * Add to the basket, or raise the quantity of a line already in it.
     *
     * Returns false when there is nothing to add: no such product, not on sale, or
     * an option that does not belong to it.
     */
    public static function add(int $productId, ?int $variantId, int $quantity = 1): ?string
    {
        $product = ShopProduct::query()
            ->active()
            ->with(['variants', 'collectionEvent'])
            ->find($productId);

        if ($product === null) {
            return self::UNAVAILABLE;
        }

        /*
         | An offline product with no collection point cannot be handed over, so it
         | cannot be sold. This happens when the event it pointed at is deleted.
         */
        if (! $product->hasCollectionPoint()) {
            return self::UNAVAILABLE;
        }

        /*
         | A product with options may only be bought as one of them, and a product
         | without options may not be given one. Otherwise a crafted post could buy
         | a shirt with no size, or attach another product's option to it.
         */
        if ($product->hasVariants()) {
            if ($variantId === null || $product->variants->firstWhere('id', $variantId) === null) {
                return self::UNAVAILABLE;
            }
        } else {
            $variantId = null;
        }

        if ($clash = self::fulfilmentClash($product)) {
            return $clash;
        }

        $lines = self::raw();
        $key = self::key($productId, $variantId);

        $existing = (int) ($lines[$key]['quantity'] ?? 0);
        $wanted = $existing + max(1, $quantity);

        $lines[$key] = [
            'product_id' => $productId,
            'variant_id' => $variantId,
            'quantity' => self::clamp($wanted, $product, $variantId),
        ];

        self::store($lines);

        return null;
    }

    /**
     * Why this product cannot join the basket, or null when it can.
     *
     * One order is fulfilled once. A posted parcel and a counter handover are two
     * different acts with different addresses, charges and paperwork, and two counter
     * handovers at different events happen in two different places on two different
     * days. None of those can be one order, so the basket refuses the combination at
     * the point somebody creates it rather than at the last step.
     */
    private static function fulfilmentClash(ShopProduct $product): ?string
    {
        $existing = self::lines();

        if ($existing->isEmpty()) {
            return null;
        }

        /** @var ShopProduct $first */
        $first = $existing->first()['product'];

        if ($first->isOffline() !== $product->isOffline()) {
            return $product->isOffline()
                ? 'That one is collected at a counter, and your basket already has something being posted out. Please order them separately.'
                : 'That one is posted out, and your basket already has something being collected at a counter. Please order them separately.';
        }

        if (! $product->isOffline()) {
            return null;
        }

        if ($first->collectionKey() !== $product->collectionKey()) {
            return sprintf(
                'That one is collected at %s, and your basket is being collected at %s. One order is handed over in one place, so please order them separately.',
                $product->collectionSummary() ?: 'another collection point',
                $first->collectionSummary() ?: 'another collection point',
            );
        }

        return null;
    }

    /**
     * Set a line to an exact quantity. Zero or less removes it.
     */
    public static function setQuantity(string $key, int $quantity): void
    {
        $lines = self::raw();

        if (! isset($lines[$key])) {
            return;
        }

        if ($quantity <= 0) {
            unset($lines[$key]);
            self::store($lines);

            return;
        }

        $product = ShopProduct::query()->active()->with('variants')->find($lines[$key]['product_id']);

        if ($product === null) {
            unset($lines[$key]);
            self::store($lines);

            return;
        }

        $lines[$key]['quantity'] = self::clamp($quantity, $product, $lines[$key]['variant_id']);

        self::store($lines);
    }

    public static function remove(string $key): void
    {
        $lines = self::raw();
        unset($lines[$key]);
        self::store($lines);
    }

    public static function clear(): void
    {
        Session::forget(self::KEY);
    }

    /* ---------------------------------------------------------------------
     | Reading
     * ------------------------------------------------------------------ */

    /**
     * The basket, priced from the database.
     *
     * @return Collection<int, array{key: string, product: ShopProduct, variant: ShopProductVariant|null, quantity: int, unit_price: float, line_total: float, weight_grams: int, capped_to: int|null}>  weight_grams is per unit
     */
    public static function lines(): Collection
    {
        $raw = self::raw();

        if ($raw === []) {
            return collect();
        }

        $products = ShopProduct::query()
            ->active()
            ->with(['variants', 'images', 'collectionEvent'])
            ->whereKey(collect($raw)->pluck('product_id')->unique()->all())
            ->get()
            ->keyBy('id');

        $kept = [];
        $lines = collect();

        foreach ($raw as $key => $line) {
            $product = $products->get($line['product_id']);

            if ($product === null) {
                // Gone or unpublished since it was added.
                continue;
            }

            $variant = $line['variant_id'] === null
                ? null
                : $product->variants->firstWhere('id', $line['variant_id']);

            // An option that has since been deleted cannot be bought, and falling
            // back to the product would sell the wrong thing at the wrong price.
            if ($line['variant_id'] !== null && $variant === null) {
                continue;
            }

            if ($variant !== null ? $variant->isSoldOut() : $product->isSoldOut()) {
                continue;
            }

            $available = self::available($product, $variant);
            $quantity = (int) $line['quantity'];
            $capped = null;

            if ($available !== null && $quantity > $available) {
                $capped = $available;
                $quantity = $available;
            }

            if ($quantity < 1) {
                continue;
            }

            $unit = $variant !== null ? $variant->unitPrice() : (float) $product->price;

            $lines->push([
                'key' => $key,
                'product' => $product,
                'variant' => $variant,
                'quantity' => $quantity,
                'unit_price' => $unit,
                'line_total' => round($unit * $quantity, 2),
                /*
                 | Per unit, not per line, which is the same thing unit_price means
                 | and what ShopOrder::weightGrams() multiplies by the quantity.
                 |
                 | Asked of the variant where there is one, so a shirt in 3XL can
                 | weigh more than the same shirt in S. Before this it was always
                 | the product's weight, which was harmless while postage was a flat
                 | rate and wrong the moment a courier is asked for a real price.
                 */
                'weight_grams' => $variant !== null
                    ? $variant->unitWeightGrams()
                    : (int) $product->weight_grams,
                'capped_to' => $capped,
            ]);

            $kept[$key] = [
                'product_id' => $line['product_id'],
                'variant_id' => $line['variant_id'],
                'quantity' => $quantity,
            ];
        }

        /*
         | Write the surviving lines back, so a basket does not keep re-reporting the
         | same dropped product on every page. Only when something actually changed,
         | to avoid touching the session on an ordinary read.
         */
        if ($kept !== $raw) {
            self::store($kept);
        }

        return $lines;
    }

    /** How many items are in the basket, counting quantities. */
    public static function count(): int
    {
        return (int) self::lines()->sum('quantity');
    }

    /**
     * Whether the cart should appear at all.
     *
     * Read from the raw session rather than through lines(), because the header runs
     * on every page and this avoids a product query on all of them. It can be
     * briefly optimistic if a line has since become unbuyable, which costs a visitor
     * one look at a cart that then reports the change.
     */
    public static function isEmpty(): bool
    {
        return self::raw() === [];
    }

    public static function itemsTotal(): float
    {
        return round((float) self::lines()->sum('line_total'), 2);
    }

    /**
     * What the whole basket weighs, in grams.
     *
     * The mirror of ShopOrder::weightGrams(), which cannot be used here because a
     * quotation is wanted at checkout, before any order row exists. Both multiply
     * the per unit weight by the quantity, so the figure quoted to the buyer and
     * the figure recorded against the order agree.
     *
     * Zero means nothing in the basket has a weight. That is not the same as a
     * light parcel and callers have to tell the difference: a courier cannot be
     * asked to price an unknown weight, so zero is a reason to fall back rather
     * than a number to send.
     */
    public static function parcelWeightGrams(): int
    {
        return (int) self::lines()->sum(
            fn (array $line) => (int) $line['weight_grams'] * (int) $line['quantity']
        );
    }

    /**
     * Whether every line knows what it weighs.
     *
     * A single unweighed line makes the whole parcel weight a guess, so this is
     * asked as a whole rather than per line. Postage would come back priced for
     * less than is actually being posted otherwise, and the difference is paid by
     * whoever runs the shop.
     */
    public static function hasCompleteWeights(): bool
    {
        $lines = self::lines();

        return $lines->isNotEmpty()
            && $lines->every(fn (array $line) => (int) $line['weight_grams'] > 0);
    }

    /* ---------------------------------------------------------------------
     | Posted out, or collected in person
     |
     | Read off the first line rather than combined across all of them, because add()
     | already refuses a basket that mixes the two. Asking every line and reducing
     | would be a second implementation of the same rule, free to disagree with the
     | first.
     * ------------------------------------------------------------------ */

    /**
     * Whether this basket is collected at a counter. False for an empty basket, which
     * is the harmless answer: an empty basket is not checked out.
     */
    public static function isOffline(): bool
    {
        $lines = self::lines();

        return $lines->isNotEmpty() && $lines->first()['product']->isOffline();
    }

    public static function fulfilment(): string
    {
        return self::isOffline()
            ? ShopProduct::FULFILMENT_OFFLINE
            : ShopProduct::FULFILMENT_ONLINE;
    }

    /**
     * Where and when this basket is collected, or null when it is posted.
     *
     * @return array{label: string, location: string|null, at: \Illuminate\Support\Carbon|null, event_id: int|null}|null
     */
    public static function collectionPoint(): ?array
    {
        $lines = self::lines();

        return $lines->isEmpty() ? null : $lines->first()['product']->collectionPoint();
    }

    /**
     * Lines that were trimmed because stock had fallen, for telling the buyer.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function cappedLines(): Collection
    {
        return self::lines()->filter(fn (array $line) => $line['capped_to'] !== null)->values();
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    public static function key(int $productId, ?int $variantId): string
    {
        return $productId . ':' . ($variantId ?? 'base');
    }

    /**
     * @return array<string, array{product_id: int, variant_id: int|null, quantity: int}>
     */
    private static function raw(): array
    {
        $lines = Session::get(self::KEY, []);

        return is_array($lines) ? $lines : [];
    }

    /**
     * @param  array<string, mixed>  $lines
     */
    private static function store(array $lines): void
    {
        if ($lines === []) {
            Session::forget(self::KEY);

            return;
        }

        Session::put(self::KEY, $lines);
    }

    /**
     * How many are left to sell, or null when the answer is unlimited.
     */
    private static function available(ShopProduct $product, ?ShopProductVariant $variant): ?int
    {
        if (! $product->track_inventory) {
            return null;
        }

        return $variant !== null ? $variant->stockLeft() : $product->stockLeft();
    }

    /**
     * Hold a quantity to what is sellable and to the per line cap.
     */
    private static function clamp(int $quantity, ShopProduct $product, ?int $variantId): int
    {
        $variant = $variantId === null ? null : $product->variants->firstWhere('id', $variantId);
        $available = self::available($product, $variant);

        $ceiling = $available === null
            ? self::MAX_PER_LINE
            : min(self::MAX_PER_LINE, $available);

        return max(1, min($quantity, max(1, $ceiling)));
    }
}
