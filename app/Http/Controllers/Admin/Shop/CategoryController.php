<?php

namespace App\Http\Controllers\Admin\Shop;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ShopCategoryRequest;
use App\Models\ShopCategory;
use App\Services\AdminLogger;
use Illuminate\Http\Request;

/**
 * Categories are short records with six fields, so they are created and edited in
 * a dialog on the list rather than on their own page. That follows User Management,
 * which is the other screen in the project where a round trip to a separate form
 * would cost more than the record is worth.
 */
class CategoryController extends Controller
{
    public function index(Request $request)
    {
        return view('admin.shop.categories', [
            'categories' => ShopCategory::query()
                ->withCount('products')
                ->inDisplayOrder()
                ->get(),
            'icons' => ShopCategoryRequest::ICONS,
            'canCreate' => $request->user()->hasPermission('shop.categories.create'),
            'canUpdate' => $request->user()->hasPermission('shop.categories.update'),
            'canDelete' => $request->user()->hasPermission('shop.categories.delete'),
        ]);
    }

    public function store(ShopCategoryRequest $request)
    {
        $category = new ShopCategory($request->categoryAttributes());
        $category->slug = $this->resolveSlug($request->input('slug'), $request->input('name'));
        $category->save();

        AdminLogger::activity('shop.categories.create', sprintf('Added shop category %s.', $category->name));
        AdminLogger::audit($category, 'created', null, [
            'name' => $category->name,
            'slug' => $category->slug,
        ]);

        return redirect()
            ->route('admin.shop.categories')
            ->with('status', sprintf('Category %s added.', $category->name));
    }

    public function update(ShopCategoryRequest $request, ShopCategory $category)
    {
        $before = [
            'name' => $category->name,
            'is_active' => $category->is_active,
            'sort_order' => $category->sort_order,
        ];

        $category->fill($request->categoryAttributes());

        if ($request->filled('slug')) {
            $category->slug = $this->resolveSlug($request->input('slug'), $request->input('name'), $category->id);
        }

        $category->save();

        AdminLogger::activity('shop.categories.update', sprintf('Updated shop category %s.', $category->name));
        AdminLogger::audit($category, 'updated', $before, [
            'name' => $category->name,
            'is_active' => $category->is_active,
            'sort_order' => $category->sort_order,
        ]);

        return redirect()
            ->route('admin.shop.categories')
            ->with('status', sprintf('Category %s saved.', $category->name));
    }

    public function destroy(ShopCategory $category)
    {
        /*
         | Deleting is allowed even when products use it. The pivot rows go, the
         | products stay, so nothing is lost that cannot be put back by ticking the
         | box again. The count is reported so the operator is not surprised by
         | products dropping off a storefront filter.
         */
        $attached = $category->products()->count();

        AdminLogger::audit($category, 'deleted', [
            'name' => $category->name,
            'slug' => $category->slug,
            'products' => $attached,
        ], null);

        $name = $category->name;
        $category->delete();

        AdminLogger::activity('shop.categories.delete', sprintf('Deleted shop category %s.', $name));

        $message = $attached === 0
            ? sprintf('Category %s deleted.', $name)
            : sprintf(
                'Category %s deleted. %d %s no longer grouped under it, but nothing was removed from the shop.',
                $name,
                $attached,
                $attached === 1 ? 'product is' : 'products are',
            );

        return redirect()
            ->route('admin.shop.categories')
            ->with('status', $message);
    }

    private function resolveSlug(?string $slug, string $name, ?int $ignoreId = null): string
    {
        $base = filled($slug) ? str($slug)->slug()->toString() : str($name)->slug()->toString();
        $base = $base !== '' ? $base : 'category';

        $candidate = $base;
        $suffix = 1;

        while (ShopCategory::query()
            ->where('slug', $candidate)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists()
        ) {
            $candidate = $base . '-' . (++$suffix);
        }

        return $candidate;
    }
}
