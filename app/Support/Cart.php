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

    /* ---------------------------------------------------------------------
     | Writing
     * ------------------------------------------------------------------ */

    /**
     * Add to the basket, or raise the quantity of a line already in it.
     *
     * Returns false when there is nothing to add: no such product, not on sale, or
     * an option that does not belong to it.
     */
    public static function add(int $productId, ?int $variantId, int $quantity = 1): bool
    {
        $product = ShopProduct::query()->active()->with('variants')->find($productId);

        if ($product === null) {
            return false;
        }

        /*
         | A product with options may only be bought as one of them, and a product
         | without options may not be given one. Otherwise a crafted post could buy
         | a shirt with no size, or attach another product's option to it.
         */
        if ($product->hasVariants()) {
            if ($variantId === null || $product->variants->firstWhere('id', $variantId) === null) {
                return false;
            }
        } else {
            $variantId = null;
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

        return true;
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
     * @return Collection<int, array{key: string, product: ShopProduct, variant: ShopProductVariant|null, quantity: int, unit_price: float, line_total: float, weight_grams: int, capped_to: int|null}>
     */
    public static function lines(): Collection
    {
        $raw = self::raw();

        if ($raw === []) {
            return collect();
        }

        $products = ShopProduct::query()
            ->active()
            ->with(['variants', 'images'])
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
                'weight_grams' => (int) $product->weight_grams,
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
