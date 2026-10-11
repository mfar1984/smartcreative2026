<?php

namespace App\Support;

use App\Models\ShopProduct;
use App\Models\ShopProductAddon;
use App\Models\ShopProductAddonVariant;
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
     *
     * @param  array<int|string, mixed>  $addons  add-on id => chosen option id, as posted
     */
    public static function add(int $productId, ?int $variantId, int $quantity = 1, array $addons = []): ?string
    {
        $product = ShopProduct::query()
            ->active()
            ->with(['variants', 'collectionEvent', 'addons.variants'])
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

        /*
         | The extras, settled against the catalogue rather than taken as sent. A
         | string back is the reason it was refused, exactly as above.
         */
        $resolved = self::resolveAddons($product, $addons);

        if (is_string($resolved)) {
            return $resolved;
        }

        $lines = self::raw();
        $key = self::key($productId, $variantId, $resolved);

        $existing = (int) ($lines[$key]['quantity'] ?? 0);
        $wanted = $existing + max(1, $quantity);

        $line = [
            'product_id' => $productId,
            'variant_id' => $variantId,
            'quantity' => self::clamp($wanted, $product, $variantId, $resolved),
        ];

        /*
         | Only when something was actually chosen. A line with no extras keeps the
         | exact three keys it has always had, so a basket from before this feature
         | existed, and every total built from one, is untouched.
         */
        if ($resolved !== []) {
            $line['addons'] = $resolved;
        }

        $lines[$key] = $line;

        self::store($lines);

        return null;
    }

    /**
     * The extras on one line, decided here and nowhere else.
     *
     * The request contributes which add-on and which option, and nothing more: every
     * price comes off the catalogue row, the same rule the rest of this class follows.
     * Keys that are not purchasable add-ons of this product are ignored rather than
     * refused, because an add-on switched off while somebody had the page open is not
     * the buyer's mistake.
     *
     * Three things it will not let through:
     *  - an option id that does not belong to the add-on, or has sold out;
     *  - a compulsory add-on with options and no valid choice, which is refused in
     *    words so the buyer knows what to do;
     *  - a compulsory add-on with no options being left off, which cannot happen at
     *    all: it is added whatever was posted, so there is nothing to tamper with.
     *
     * @param  array<int|string, mixed>  $posted
     * @return array<int, int|null>|string  add-on id => option id or null, or the refusal
     */
    private static function resolveAddons(ShopProduct $product, array $posted): array|string
    {
        $resolved = [];

        foreach ($product->purchasableAddons() as $addon) {
            /** @var ShopProductAddon $addon */
            $choice = $posted[$addon->id] ?? null;

            if ($addon->hasVariants()) {
                if (blank($choice)) {
                    if ($addon->is_required) {
                        return sprintf('Choose an option for "%s" before adding this to your basket.', $addon->name);
                    }

                    continue;
                }

                /** @var ShopProductAddonVariant|null $option */
                $option = $addon->variants->firstWhere('id', (int) $choice);

                // Not one of this add-on's options, or gone since the page loaded.
                // Either way there is nothing here to sell.
                if ($option === null || $option->isSoldOut()) {
                    return self::UNAVAILABLE;
                }

                $resolved[$addon->id] = $option->id;

                continue;
            }

            if ($addon->is_required) {
                $resolved[$addon->id] = null;

                continue;
            }

            /*
             | No options, so the add-on id being there IS the choice.
             |
             | Two shapes have to mean the same thing: the tick box posts "1" when
             | it is ticked and nothing at all when it is not, and a stored line
             | holds the add-on id against null, because null is where an option id
             | would have gone. Reading the value alone would make the stored shape
             | look like a declined add-on and the extras would quietly vanish the
             | first time the basket was read back.
             */
            if (! array_key_exists($addon->id, $posted)) {
                continue;
            }

            if ($choice === null || ! in_array((string) $choice, ['', '0'], true)) {
                $resolved[$addon->id] = null;
            }
        }

        return $resolved;
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

        $product = ShopProduct::query()->active()->with(['variants', 'addons.variants'])->find($lines[$key]['product_id']);

        if ($product === null) {
            unset($lines[$key]);
            self::store($lines);

            return;
        }

        // Only the quantity is written, so the line keeps the extras it was added
        // with. Re-resolving them here would be a second authority on a selection
        // the key itself was built from.
        $lines[$key]['quantity'] = self::clamp(
            $quantity,
            $product,
            $lines[$key]['variant_id'],
            self::storedAddons($lines[$key]),
        );

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
     * @return Collection<int, array{key: string, product: ShopProduct, variant: ShopProductVariant|null, quantity: int, unit_price: float, addons: array<int, array{addon: ShopProductAddon, variant: ShopProductAddonVariant|null, unit_price: float}>, addons_unit_price: float, line_total: float, weight_grams: int, capped_to: int|null}>  weight_grams and addons_unit_price are per unit
     */
    public static function lines(): Collection
    {
        $raw = self::raw();

        if ($raw === []) {
            return collect();
        }

        $products = ShopProduct::query()
            ->active()
            ->with(['variants', 'images', 'collectionEvent', 'addons.variants'])
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

            /*
             | The extras, resolved again from the catalogue on every read, which is
             | how a price change or a sold out option is picked up. A refusal drops
             | the line, exactly as a deleted option does above: it is no longer the
             | thing the buyer put in the basket.
             */
            $selection = self::resolveAddons($product, self::storedAddons($line));

            if (is_string($selection)) {
                continue;
            }

            $addons = self::describeAddons($product, $selection);
            $addonsUnit = round((float) array_sum(array_column($addons, 'unit_price')), 2);

            $available = self::available($product, $variant, $selection);
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

                /*
                 | The extras, and what they add to one unit. unit_price stays the
                 | product or option price alone, which is what the order line
                 | snapshots and what ShopOrderItem::unit_price has always meant;
                 | the extras ride on the line total instead.
                 */
                'addons' => $addons,
                'addons_unit_price' => $addonsUnit,
                'line_total' => round(($unit + $addonsUnit) * $quantity, 2),
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

            /*
             | The stored line kept verbatim apart from the quantity, so the write
             | back below only fires when something really changed. Rebuilding it
             | from three named keys would drop the selection on every read, and the
             | basket would lose its extras the first time anybody looked at it.
             */
            $kept[$key] = $line;
            $kept[$key]['quantity'] = $quantity;
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

    /**
     * The identity of a line.
     *
     * The same product in the same size with different extras is two lines, so the
     * selection is folded in as a short fingerprint. With no extras the string is
     * exactly what it has always been, which is what keeps a basket somebody is
     * part way through, and every caller passing two arguments, working.
     *
     * @param  array<int, int|null>  $addons  add-on id => option id or null
     */
    public static function key(int $productId, ?int $variantId, array $addons = []): string
    {
        $key = $productId . ':' . ($variantId ?? 'base');

        if ($addons === []) {
            return $key;
        }

        // Sorted, so the same two extras chosen in either order are one line rather
        // than two priced the same.
        ksort($addons);

        return $key . ':a' . substr(sha1((string) json_encode($addons)), 0, 12);
    }

    /**
     * @return array<string, array{product_id: int, variant_id: int|null, quantity: int, addons?: array<int, int|null>}>
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
     * The selection stored on a session line, as add-on id => option id or null.
     *
     * @param  array<string, mixed>  $line
     * @return array<int, int|null>
     */
    private static function storedAddons(array $line): array
    {
        return is_array($line['addons'] ?? null) ? $line['addons'] : [];
    }

    /**
     * A resolved selection turned into rows a view and the order writer can read.
     *
     * Priced from the catalogue, never from the session: the stored selection says
     * which extras, and the rows say what they cost right now.
     *
     * @param  array<int, int|null>  $selection
     * @return array<int, array{addon: ShopProductAddon, variant: ShopProductAddonVariant|null, unit_price: float}>
     */
    private static function describeAddons(ShopProduct $product, array $selection): array
    {
        $rows = [];

        foreach ($selection as $addonId => $optionId) {
            /** @var ShopProductAddon|null $addon */
            $addon = $product->addons->firstWhere('id', $addonId);

            if ($addon === null) {
                continue;
            }

            $option = $optionId === null ? null : $addon->variants->firstWhere('id', $optionId);

            $rows[] = [
                'addon' => $addon,
                'variant' => $option,
                'unit_price' => $option !== null ? $option->unitPrice() : $addon->unitPrice(),
            ];
        }

        return $rows;
    }

    /**
     * How many are left to sell, or null when the answer is unlimited.
     *
     * The lowest of what the product or its option has left, what every chosen
     * extra has left, and the per order cap any of them sets. One engraving per
     * medal (D4), so ten medals need ten engravings and the smallest figure wins.
     *
     * @param  array<int, int|null>  $selection
     */
    private static function available(ShopProduct $product, ?ShopProductVariant $variant, array $selection = []): ?int
    {
        $figures = [];

        if ($product->track_inventory) {
            $figures[] = $variant !== null ? $variant->stockLeft() : $product->stockLeft();
        }

        foreach (self::describeAddons($product, $selection) as $row) {
            $figures[] = $row['variant'] !== null
                ? $row['variant']->stockLeft()
                : $row['addon']->stockLeft();

            $figures[] = $row['addon']->perOrderCap();
        }

        $figures = array_filter($figures, fn (?int $figure) => $figure !== null);

        return $figures === [] ? null : (int) min($figures);
    }

    /**
     * Hold a quantity to what is sellable and to the per line cap.
     *
     * @param  array<int, int|null>  $selection
     */
    private static function clamp(int $quantity, ShopProduct $product, ?int $variantId, array $selection = []): int
    {
        $variant = $variantId === null ? null : $product->variants->firstWhere('id', $variantId);
        $available = self::available($product, $variant, $selection);

        $ceiling = $available === null
            ? self::MAX_PER_LINE
            : min(self::MAX_PER_LINE, $available);

        return max(1, min($quantity, max(1, $ceiling)));
    }
}
