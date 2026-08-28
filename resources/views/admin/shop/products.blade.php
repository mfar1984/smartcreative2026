@extends('layouts.admin')

@php
    $head = 'px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500';
    $select = 'rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 bg-white focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';
@endphp

@section('title', 'Products')

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>Shop</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">Products</span>
@endsection

@section('content')
    {{-- The shop defaults to closed, so a catalogue can be built before anything is
         public. Without this the operator would add products, visit the shop and
         find nothing, with no hint as to why. --}}
    @unless ($shopIsOpen)
        <div class="flex flex-wrap items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 px-5 py-4 mb-5">
            <x-admin.icon name="warning" class="w-5 h-5 shrink-0 mt-0.5 text-amber-600" />

            <div class="flex-1 min-w-64">
                <p class="text-sm font-semibold text-amber-900">The shop is closed to visitors</p>
                <p class="text-sm text-amber-800 mt-0.5">
                    Products saved here are not on the public site yet. Open the shop when you
                    are ready for people to see it.
                </p>
            </div>

            <a href="{{ route('admin.shop.settings') }}"
               class="inline-flex items-center gap-2 rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700 transition">
                <x-admin.icon name="cog" class="w-4 h-4" />
                Open the shop
            </a>
        </div>
    @endunless

    <x-admin.page-card
        title="Products"
        description="Everything the shop sells. Only Active products reach the public storefront."
        :flush="true">

        <x-slot:actions>
            <a href="{{ route('shop') }}" target="_blank" rel="noopener"
               class="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                <x-admin.icon name="eye" class="w-4 h-4" />
                View shop
            </a>

            @if ($canCreate)
                <a href="{{ route('admin.shop.products.create') }}"
                   class="inline-flex items-center gap-2 bg-blue-600 text-white px-5 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-sm">
                    <x-admin.icon name="plus" class="w-4 h-4" />
                    Add Product
                </a>
            @endif
        </x-slot:actions>

        <x-admin.filter-bar
            :action="route('admin.shop.products')"
            :reset="$isFiltered ? route('admin.shop.products') : null">

            <div class="relative flex-1 min-w-56">
                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" aria-hidden="true">
                    <x-admin.icon name="search" class="w-4 h-4" />
                </span>
                <label for="q" class="sr-only">Search products</label>
                <input type="search" id="q" name="q" value="{{ $search }}"
                       placeholder="Name, SKU or brand..."
                       class="w-full rounded-lg border border-gray-300 pl-9 pr-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
            </div>

            <label for="category" class="sr-only">Category</label>
            <select id="category" name="category" class="{{ $select }}">
                <option value="">All categories</option>
                @foreach ($categories as $option)
                    <option value="{{ $option->id }}" @selected($categoryId === $option->id)>{{ $option->name }}</option>
                @endforeach
            </select>

            <label for="status" class="sr-only">Status</label>
            <select id="status" name="status" class="{{ $select }}">
                <option value="">All statuses</option>
                @foreach ($statuses as $slug => $label)
                    <option value="{{ $slug }}" @selected($status === $slug)>{{ $label }}</option>
                @endforeach
            </select>

            <label for="stock" class="sr-only">Stock</label>
            <select id="stock" name="stock" class="{{ $select }}">
                <option value="">Any stock</option>
                <option value="low" @selected($stock === 'low')>Running low</option>
                <option value="sold-out" @selected($stock === 'sold-out')>Sold out</option>
            </select>
        </x-admin.filter-bar>

        <div class="flex flex-wrap items-center justify-between gap-3 px-6 py-2.5 bg-gray-50 border-b border-gray-200">
            <p class="text-xs text-gray-500">
                {{ $products->total() }} {{ Str::plural('product', $products->total()) }} in total
            </p>
            <p class="text-xs text-gray-500">
                <span class="font-semibold text-gray-700">{{ $activeCount }}</span>
                active
            </p>
        </div>

        {{-- Stock depends on variant counts and a per product threshold, so the
             filter runs in PHP on the current page rather than in SQL across the
             whole set. Said out loud, because a page that quietly holds fewer rows
             than its own count claims looks broken. --}}
        @if ($stock !== '')
            <div class="flex items-start gap-2 px-6 py-2.5 bg-blue-50 border-b border-blue-100">
                <x-admin.icon name="warning" class="w-4 h-4 shrink-0 mt-0.5 text-blue-600" />
                <p class="text-xs text-blue-800">
                    The stock filter narrows the page you are on, not the whole catalogue.
                    Page through to check the rest.
                </p>
            </div>
        @endif

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left">
                    <tr>
                        <th scope="col" class="{{ $head }} w-16">Image</th>
                        <th scope="col" class="{{ $head }}">Product</th>
                        <th scope="col" class="{{ $head }}">Categories</th>
                        <th scope="col" class="{{ $head }} text-right">Price</th>
                        <th scope="col" class="{{ $head }}">Stock</th>
                        <th scope="col" class="{{ $head }} text-center">Status</th>
                        @if ($canUpdate || $canDelete)
                            <th scope="col" class="{{ $head }} text-center">Actions</th>
                        @endif
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-100">
                    @forelse ($products as $product)
                        @php
                            $image = $product->featuredImageUrl();
                            $left = $product->stockLeft();
                        @endphp

                        <tr class="hover:bg-blue-50/40 align-top">
                            <td class="px-5 py-3">
                                @if ($image)
                                    <img src="{{ $image }}" alt=""
                                         class="w-12 h-12 object-cover rounded border border-gray-200">
                                @else
                                    <div class="w-12 h-12 rounded border border-gray-200 bg-gray-50 flex items-center justify-center">
                                        <x-admin.icon name="photo" class="w-4 h-4 text-gray-300" />
                                    </div>
                                @endif
                            </td>

                            <td class="px-5 py-3">
                                @if ($canUpdate)
                                    <a href="{{ route('admin.shop.products.edit', $product) }}"
                                       class="font-semibold text-blue-600 hover:underline">{{ $product->name }}</a>
                                @else
                                    <span class="font-semibold text-gray-900">{{ $product->name }}</span>
                                @endif

                                @if ($product->is_featured)
                                    <span class="ml-1.5 align-middle">
                                        <x-admin.badge tone="amber">Featured</x-admin.badge>
                                    </span>
                                @endif

                                <span class="block text-xs text-gray-500 mt-0.5">
                                    @if (filled($product->sku))
                                        <span class="tabular-nums">{{ $product->sku }}</span>
                                    @else
                                        <span class="text-gray-400">No SKU</span>
                                    @endif

                                    @if ($product->hasVariants())
                                        <span class="mx-1 text-gray-300" aria-hidden="true">&bull;</span>
                                        {{ $product->variants->count() }}
                                        {{ Str::plural(Str::lower($product->option_name ?: 'option'), $product->variants->count()) }}
                                    @endif
                                </span>
                            </td>

                            <td class="px-5 py-3">
                                @if ($product->categories->isEmpty())
                                    <span class="text-xs text-gray-400">Uncategorised</span>
                                @else
                                    <span class="flex flex-wrap gap-1">
                                        @foreach ($product->categories as $category)
                                            <span class="inline-block rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-700">
                                                {{ $category->name }}
                                            </span>
                                        @endforeach
                                    </span>
                                @endif
                            </td>

                            <td class="px-5 py-3 text-right whitespace-nowrap tabular-nums">
                                <span class="font-semibold text-gray-900">{{ $product->priceSummaryLabel() }}</span>

                                @if ($product->isOnOffer())
                                    <span class="block text-xs text-gray-400 line-through">
                                        {{ App\Support\PaymentFigures::money((float) $product->compare_at_price) }}
                                    </span>
                                @endif
                            </td>

                            <td class="px-5 py-3 whitespace-nowrap">
                                @if (! $product->track_inventory)
                                    <span class="text-xs text-gray-500">Not tracked</span>
                                @elseif ($product->isSoldOut())
                                    <x-admin.badge tone="red" :dot="true">Sold out</x-admin.badge>
                                @elseif ($product->isLowStock())
                                    <x-admin.badge tone="amber" :dot="true">{{ $left }} left</x-admin.badge>
                                @elseif ($left === null)
                                    <span class="text-xs text-gray-500">Unlimited</span>
                                @else
                                    <span class="text-sm text-gray-700 tabular-nums">{{ $left }}</span>
                                @endif
                            </td>

                            <td class="px-5 py-3 text-center">
                                <x-admin.badge
                                    :tone="match ($product->status) {
                                        App\Models\ShopProduct::STATUS_ACTIVE => 'green',
                                        App\Models\ShopProduct::STATUS_ARCHIVED => 'gray',
                                        default => 'amber',
                                    }"
                                    :dot="true">
                                    {{ $statuses[$product->status] ?? $product->status }}
                                </x-admin.badge>
                            </td>

                            @if ($canUpdate || $canDelete)
                                <td class="px-5 py-3 whitespace-nowrap">
                                    <div class="flex items-center justify-center gap-1">
                                        @if ($canUpdate)
                                            <a href="{{ route('admin.shop.products.edit', $product) }}"
                                               class="p-1.5 rounded-lg text-amber-600 hover:bg-amber-50 transition"
                                               title="Edit {{ $product->name }}" aria-label="Edit {{ $product->name }}">
                                                <x-admin.icon name="pencil" class="w-4 h-4" />
                                            </a>
                                        @endif

                                        @if ($canDelete)
                                            <form action="{{ route('admin.shop.products.destroy', $product) }}" method="POST"
                                                  onsubmit="return confirm('Delete {{ addslashes($product->name) }}?\n\nIts options and uploaded pictures are deleted with it. This cannot be undone.\n\nSet the status to Archived instead if you only want it off the shop.');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit"
                                                        class="p-1.5 rounded-lg text-red-600 hover:bg-red-50 transition"
                                                        title="Delete {{ $product->name }}" aria-label="Delete {{ $product->name }}">
                                                    <x-admin.icon name="trash" class="w-4 h-4" />
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-5 py-12 text-center">
                                <x-admin.icon name="bag" class="w-10 h-10 mx-auto text-gray-300" />

                                <p class="text-sm font-semibold text-gray-700 mt-3">
                                    {{ $isFiltered ? 'Nothing matches those filters' : 'No products yet' }}
                                </p>

                                <p class="text-sm text-gray-500 mt-1 max-w-md mx-auto">
                                    @if ($isFiltered)
                                        Clear the filters to see everything.
                                    @else
                                        Add a medal, a shirt or anything else you sell. Set up categories
                                        first if you want the shop filtered by type.
                                    @endif
                                </p>

                                @if ($canCreate && ! $isFiltered)
                                    <a href="{{ route('admin.shop.products.create') }}"
                                       class="inline-flex items-center gap-2 mt-5 bg-blue-600 text-white px-5 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition">
                                        <x-admin.icon name="plus" class="w-4 h-4" />
                                        Add the first product
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="px-5 py-3.5 border-t border-gray-200">
            @if ($products->hasPages())
                {{ $products->links() }}
            @else
                <p class="text-xs text-gray-500">
                    Showing {{ $products->count() }} {{ Str::plural('product', $products->count()) }}
                </p>
            @endif
        </div>

    </x-admin.page-card>
@endsection
