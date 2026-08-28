<?php

namespace App\Http\Controllers\Admin\Shop;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ShopProductRequest;
use App\Models\ShopCategory;
use App\Models\ShopProduct;
use App\Services\AdminLogger;
use App\Services\ShopVariantWriter;
use App\Support\ShopSettings;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    private const PER_PAGE = 15;

    /** Where product images live on the public disk. */
    private const IMAGE_DIRECTORY = 'shop-products';

    public function index(Request $request)
    {
        $search = trim((string) $request->query('q'));
        $categoryId = (int) $request->query('category', 0);
        $status = trim((string) $request->query('status'));
        $stock = trim((string) $request->query('stock'));

        $products = ShopProduct::query()
            ->with(['images', 'variants', 'categories'])
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $inner) use ($search) {
                $inner->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%");
            }))
            ->when($categoryId > 0, fn (Builder $query) => $query->whereHas(
                'categories',
                fn (Builder $inner) => $inner->whereKey($categoryId),
            ))
            ->when($status !== '', fn (Builder $query) => $query->where('status', $status))
            ->inDisplayOrder()
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        /*
         | The stock filter runs in PHP rather than SQL because "sold out" and "low"
         | depend on variant stock, on whether inventory is tracked at all, and on a
         | per product threshold. Expressing that as one query would mean duplicating
         | the model's rules in SQL, where the two could then disagree.
         |
         | Applied after pagination, so the filter narrows the page rather than the
         | whole set. Said plainly on screen rather than left to look like a bug.
         */
        $rows = $products->getCollection();

        if ($stock === 'sold-out') {
            $rows = $rows->filter(fn (ShopProduct $p) => $p->isSoldOut());
        } elseif ($stock === 'low') {
            $rows = $rows->filter(fn (ShopProduct $p) => $p->isLowStock());
        }

        $products->setCollection($rows->values());

        return view('admin.shop.products', [
            'products' => $products,
            'categories' => ShopCategory::query()->inDisplayOrder()->get(),
            'statuses' => ShopProduct::STATUSES,
            'search' => $search,
            'categoryId' => $categoryId,
            'status' => $status,
            'stock' => $stock,
            'isFiltered' => $search !== '' || $categoryId > 0 || $status !== '' || $stock !== '',
            'shopIsOpen' => ShopSettings::isOpen(),
            'activeCount' => ShopProduct::query()->active()->count(),
            'canCreate' => $request->user()->hasPermission('shop.products.create'),
            'canUpdate' => $request->user()->hasPermission('shop.products.update'),
            'canDelete' => $request->user()->hasPermission('shop.products.delete'),
        ]);
    }

    public function create()
    {
        return view('admin.shop.product-form', $this->formData(new ShopProduct([
            'status' => ShopProduct::STATUS_DRAFT,
            'track_inventory' => true,
            'stock_quantity' => 0,
            'low_stock_threshold' => ShopSettings::lowStockThreshold(),
            'sort_order' => 0,
        ]), 'create'));
    }

    public function store(ShopProductRequest $request, ShopVariantWriter $variants)
    {
        $product = new ShopProduct($request->productAttributes());
        $product->slug = $this->resolveSlug($request->input('slug'), $request->input('name'));
        $product->save();

        $product->categories()->sync($request->categoryIds());
        $variants->sync($product, $request->variantRows());

        $this->addImages($request, $product);

        AdminLogger::activity('shop.products.create', sprintf('Added product %s.', $product->name));
        AdminLogger::audit($product, 'created', null, [
            'name' => $product->name,
            'slug' => $product->slug,
            'price' => $product->price,
            'status' => $product->status,
        ]);

        return redirect()
            ->route('admin.shop.products.edit', $product)
            ->with('status', sprintf('Product %s added. Add its pictures and options here.', $product->name));
    }

    public function edit(ShopProduct $product)
    {
        return view('admin.shop.product-form', $this->formData($product, 'edit'));
    }

    public function update(ShopProductRequest $request, ShopProduct $product, ShopVariantWriter $variants)
    {
        $before = [
            'name' => $product->name,
            'price' => $product->price,
            'status' => $product->status,
            'stock_quantity' => $product->stock_quantity,
            'variants' => $product->variants()->count(),
        ];

        $product->fill($request->productAttributes());

        if ($request->filled('slug')) {
            $product->slug = $this->resolveSlug($request->input('slug'), $request->input('name'), $product->id);
        }

        $product->save();

        $product->categories()->sync($request->categoryIds());
        $variants->sync($product, $request->variantRows());

        $this->removeImages($request, $product);
        $this->addImages($request, $product);
        $this->applyFeaturedImage($request, $product);

        AdminLogger::activity('shop.products.update', sprintf('Updated product %s.', $product->name));
        AdminLogger::audit($product, 'updated', $before, [
            'name' => $product->name,
            'price' => $product->price,
            'status' => $product->status,
            'stock_quantity' => $product->stock_quantity,
            'variants' => $product->variants()->count(),
        ]);

        return redirect()
            ->route('admin.shop.products')
            ->with('status', sprintf('Product %s saved.', $product->name));
    }

    public function destroy(ShopProduct $product)
    {
        AdminLogger::audit($product, 'deleted', [
            'name' => $product->name,
            'slug' => $product->slug,
        ], null);

        $name = $product->name;

        /*
         | The rows go with the product through the cascade, but the files on disk
         | do not, so they are removed here first. Doing it before the delete means
         | a failure leaves the product intact rather than leaving files with no row
         | pointing at them.
         */
        foreach ($product->images as $image) {
            Storage::disk('public')->delete($image->path);
        }

        $product->delete();

        AdminLogger::activity('shop.products.delete', sprintf('Deleted product %s.', $name));

        return redirect()
            ->route('admin.shop.products')
            ->with('status', sprintf('Product %s deleted.', $name));
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private function formData(ShopProduct $product, string $mode): array
    {
        if ($product->exists) {
            $product->load(['images', 'variants', 'categories']);
        }

        return [
            'product' => $product,
            'mode' => $mode,
            'statuses' => ShopProduct::STATUSES,
            'categories' => ShopCategory::query()->inDisplayOrder()->get(),
            'selectedCategories' => $product->exists
                ? $product->categories->pluck('id')->all()
                : [],
        ];
    }

    private function resolveSlug(?string $slug, string $name, ?int $ignoreId = null): string
    {
        $base = filled($slug) ? str($slug)->slug()->toString() : str($name)->slug()->toString();
        $base = $base !== '' ? $base : 'product';

        $candidate = $base;
        $suffix = 1;

        while (ShopProduct::query()
            ->where('slug', $candidate)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists()
        ) {
            $candidate = $base . '-' . (++$suffix);
        }

        return $candidate;
    }

    /**
     * Store any newly uploaded pictures, appended after the ones already there.
     */
    private function addImages(ShopProductRequest $request, ShopProduct $product): void
    {
        if (! $request->hasFile('images')) {
            return;
        }

        $nextOrder = (int) $product->images()->max('sort_order') + 1;
        $hasFeatured = $product->images()->where('is_featured', true)->exists();

        foreach ($request->file('images') as $file) {
            $product->images()->create([
                'path' => $file->store(self::IMAGE_DIRECTORY, 'public'),

                /*
                 | The first picture a product ever gets becomes the one on the card,
                 | so a product is never left without a featured image just because
                 | nobody pressed the radio button.
                 */
                'is_featured' => ! $hasFeatured,
                'sort_order' => $nextOrder++,
            ]);

            $hasFeatured = true;
        }

        $product->unsetRelation('images');
    }

    /**
     * Delete the pictures that were ticked for removal, files included.
     */
    private function removeImages(ShopProductRequest $request, ShopProduct $product): void
    {
        $ids = array_map('intval', (array) $request->input('remove_images', []));

        if ($ids === []) {
            return;
        }

        // Scoped through the relation so an id belonging to another product cannot
        // be deleted even if the payload were tampered with.
        $images = $product->images()->whereKey($ids)->get();

        foreach ($images as $image) {
            Storage::disk('public')->delete($image->path);
            $image->delete();
        }

        $product->unsetRelation('images');
    }

    /**
     * Apply the chosen featured picture, and make sure exactly one is marked.
     *
     * Runs after the additions and removals so it sees the final list. Without the
     * promotion step, removing the featured picture would leave a product whose
     * card falls back to whatever happens to sort first.
     */
    private function applyFeaturedImage(ShopProductRequest $request, ShopProduct $product): void
    {
        $product->load('images');

        if ($product->images->isEmpty()) {
            return;
        }

        $chosen = (int) $request->input('featured_image', 0);

        $target = $product->images->firstWhere('id', $chosen)
            ?? $product->images->firstWhere('is_featured', true)
            ?? $product->images->first();

        foreach ($product->images as $image) {
            $shouldBeFeatured = $image->is($target);

            if ($image->is_featured !== $shouldBeFeatured) {
                $image->is_featured = $shouldBeFeatured;
                $image->save();
            }
        }

        $product->unsetRelation('images');
    }
}
