<?php

namespace App\Http\Controllers;

use App\Models\ShopCategory;
use App\Models\ShopProduct;
use App\Support\ShopSettings;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The public shop.
 *
 * Reads active products only, and only when the shop has been opened in settings.
 * A draft or archived product is not reachable by guessing its address either: the
 * detail route filters on status before it looks the slug up, so an unpriced draft
 * cannot be found by someone who saw the URL earlier.
 *
 * There is no cart and no checkout. Products carry an enquiry route instead, which
 * is honest about what the shop can do today rather than offering a button that
 * leads nowhere.
 */
class ShopController extends Controller
{
    public function index(Request $request)
    {
        if (! ShopSettings::isOpen()) {
            return response()->view('pages.shop-closed', [
                'pageTitle' => ShopSettings::heading(),
            ]);
        }

        $categorySlug = trim((string) $request->query('category'));

        $category = $categorySlug === ''
            ? null
            : ShopCategory::query()->active()->where('slug', $categorySlug)->first();

        /*
         | An unknown or hidden category falls back to everything rather than
         | producing an empty grid, which would read as a fault. The chosen filter is
         | echoed from $category, so the chip highlighted on screen always matches
         | what was actually applied.
         */
        $products = ShopProduct::query()
            ->with(['images', 'variants', 'categories'])
            ->active()
            ->when($category !== null, fn (Builder $query) => $query->whereHas(
                'categories',
                fn (Builder $inner) => $inner->whereKey($category->id),
            ))
            ->inDisplayOrder()
            ->paginate(ShopSettings::perPage())
            ->withQueryString();

        /*
         | Sold out products are dropped in PHP because selling out depends on
         | variant stock and on whether the product tracks stock at all. Expressing
         | that in SQL would mean restating the model's rules where the two could
         | then disagree.
         */
        if (ShopSettings::hidesSoldOut()) {
            $products->setCollection(
                $products->getCollection()->reject(fn (ShopProduct $p) => $p->isSoldOut())->values()
            );
        }

        return view('pages.shop', [
            'pageTitle' => ShopSettings::heading(),
            'pageSubtitle' => ShopSettings::intro(),
            'products' => $products,
            'categories' => $this->categoriesWithStock(),
            'activeCategory' => $category,
            'totalActive' => ShopProduct::query()->active()->count(),
            'showsStockCount' => ShopSettings::showsStockCount(),
        ]);
    }

    public function show(string $slug)
    {
        if (! ShopSettings::isOpen()) {
            return response()->view('pages.shop-closed', [
                'pageTitle' => ShopSettings::heading(),
            ]);
        }

        // active() before the slug lookup, so a draft 404s rather than 403s. There
        // is nothing to tell a stranger about a product that is not for sale.
        $product = ShopProduct::query()
            ->with(['images', 'variants', 'categories'])
            ->active()
            ->where('slug', $slug)
            ->firstOrFail();

        return view('pages.shop-product', [
            'pageTitle' => $product->name,
            'product' => $product,
            'related' => $this->related($product),
            'showsStockCount' => ShopSettings::showsStockCount(),
            'enquiryNote' => ShopSettings::enquiryNote(),
        ]);
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    /**
     * Active categories that actually hold something active, with their counts.
     *
     * A filter that leads to an empty grid is worse than no filter, so an empty
     * category is not offered at all.
     *
     * @return \Illuminate\Support\Collection<int, ShopCategory>
     */
    private function categoriesWithStock()
    {
        return ShopCategory::query()
            ->active()
            ->withCount(['products' => fn (Builder $query) => $query->where('status', ShopProduct::STATUS_ACTIVE)])
            ->inDisplayOrder()
            ->get()
            ->filter(fn (ShopCategory $category) => $category->products_count > 0)
            ->values();
    }

    /**
     * A few other products from the same categories.
     *
     * Falls back to anything active when the product is uncategorised, so the
     * section is not empty on a shop that has never used categories.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, ShopProduct>
     */
    private function related(ShopProduct $product)
    {
        $categoryIds = $product->categories->pluck('id');

        return ShopProduct::query()
            ->with(['images', 'variants'])
            ->active()
            ->whereKeyNot($product->id)
            ->when($categoryIds->isNotEmpty(), fn (Builder $query) => $query->whereHas(
                'categories',
                fn (Builder $inner) => $inner->whereIn('shop_categories.id', $categoryIds),
            ))
            ->inDisplayOrder()
            ->limit(4)
            ->get();
    }
}
