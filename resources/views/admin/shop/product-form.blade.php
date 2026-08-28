@extends('layouts.admin')

@php
    use App\Models\ShopProduct;
    use App\Support\PaymentSettings;

    $isCreate = $mode === 'create';
    $heading = $isCreate ? 'Add Product' : 'Edit Product';
    $action = $isCreate
        ? route('admin.shop.products.store')
        : route('admin.shop.products.update', $product);

    $input = 'w-full rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';
    $currency = PaymentSettings::currency();
@endphp

@section('title', $heading)

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>Shop</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <a href="{{ route('admin.shop.products') }}" class="hover:text-gray-700 transition">Products</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">{{ $isCreate ? 'Add' : 'Edit' }}</span>
@endsection

@section('content')
    <form action="{{ $action }}" method="POST" enctype="multipart/form-data" id="product-form">
        @csrf
        @unless ($isCreate)
            @method('PUT')
        @endunless

        <x-admin.page-card
            :title="$heading"
            description="One item the shop sells. It reaches the public storefront once its status is Active and the shop is open."
            :back="route('admin.shop.products')">

            <x-slot:actions>
                <a href="{{ route('admin.shop.products') }}"
                   class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                    Cancel
                </a>
                <button type="submit"
                        class="inline-flex items-center gap-2 bg-blue-600 text-white px-5 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1-4l-4 4m0 0L8 3m4 4V3"/>
                    </svg>
                    {{ $isCreate ? 'Save Product' : 'Save Changes' }}
                </button>
            </x-slot:actions>

            <x-admin.section-intro
                title="Product Details"
                description="What it is, what it costs, and how many there are."
                icon="bag" />

            {{-- ==================== Basic ==================== --}}
            <x-admin.panel title="Basic Information" icon="identification">
                <x-admin.field-row label="Product Name" help="Shown as the card title in the shop." for="name" :required="true" error="name">
                    <input type="text" id="name" name="name" required maxlength="180"
                           value="{{ old('name', $product->name) }}"
                           placeholder="e.g. Champion Medal, Gold Finish"
                           class="{{ $input }}">
                </x-admin.field-row>

                <x-admin.field-row label="URL Slug" help="Leave blank to build one from the name." for="slug" error="slug">
                    <input type="text" id="slug" name="slug" maxlength="180"
                           value="{{ old('slug', $product->slug) }}"
                           placeholder="champion-medal-gold-finish"
                           class="{{ $input }}">
                </x-admin.field-row>

                <x-admin.field-row
                    label="Short Description"
                    help="One or two lines on the listing card. Keep it to what somebody needs to decide whether to look closer."
                    for="short_description"
                    error="short_description">
                    <textarea id="short_description" name="short_description" rows="2" maxlength="400"
                              class="{{ $input }} resize-y">{{ old('short_description', $product->short_description) }}</textarea>
                </x-admin.field-row>

                <x-admin.field-row
                    label="Full Description"
                    help="The detail on the product page. Plain text: line breaks are kept, anything that looks like HTML is shown as typed rather than rendered."
                    for="description"
                    error="description">
                    <textarea id="description" name="description" rows="8" maxlength="20000"
                              class="{{ $input }} resize-y">{{ old('description', $product->description) }}</textarea>
                </x-admin.field-row>
            </x-admin.panel>

            {{-- ==================== Pricing ==================== --}}
            <x-admin.panel title="Pricing" icon="cash">
                <x-admin.field-row label="Price ({{ $currency }})" help="What a buyer pays. Enter 0 if it is given away." for="price" :required="true" error="price">
                    <input type="number" id="price" name="price" required step="0.01" min="0" max="999999.99"
                           value="{{ old('price', $product->price) }}"
                           class="{{ $input }} max-w-40 text-right tabular-nums">
                </x-admin.field-row>

                <x-admin.field-row
                    label="Compare At Price ({{ $currency }})"
                    help="The old price, shown struck through beside the current one. Leave blank when the product is not on offer. It has to be higher than the price, otherwise there is no saving to show."
                    for="compare_at_price"
                    error="compare_at_price">
                    <input type="number" id="compare_at_price" name="compare_at_price" step="0.01" min="0" max="999999.99"
                           value="{{ old('compare_at_price', $product->compare_at_price) }}"
                           placeholder="Not on offer"
                           class="{{ $input }} max-w-40 text-right tabular-nums">
                </x-admin.field-row>

                <x-admin.field-row
                    label="Cost Per Item ({{ $currency }})"
                    help="What it costs you, for working out margin. Admin only: this never reaches a visitor."
                    for="cost_price"
                    error="cost_price">
                    <input type="number" id="cost_price" name="cost_price" step="0.01" min="0" max="999999.99"
                           value="{{ old('cost_price', $product->cost_price) }}"
                           placeholder="Optional"
                           class="{{ $input }} max-w-40 text-right tabular-nums">
                </x-admin.field-row>
            </x-admin.panel>

            {{-- ==================== Inventory ==================== --}}
            <x-admin.panel title="Inventory" icon="archive">
                <x-admin.field-row label="SKU" help="Your own code for this item. Must be unique across products." for="sku" error="sku">
                    <input type="text" id="sku" name="sku" maxlength="80"
                           value="{{ old('sku', $product->sku) }}"
                           placeholder="e.g. MED-GOLD-50"
                           class="{{ $input }} max-w-xs">
                </x-admin.field-row>

                <x-admin.field-row label="Barcode" help="EAN, UPC or similar, if you use one." for="barcode" error="barcode">
                    <input type="text" id="barcode" name="barcode" maxlength="80"
                           value="{{ old('barcode', $product->barcode) }}"
                           placeholder="Optional"
                           class="{{ $input }} max-w-xs">
                </x-admin.field-row>

                <x-admin.field-row
                    label="Track Stock"
                    help="Off means this product never sells out, which suits anything made to order."
                    error="track_inventory">
                    <x-admin.toggle
                        name="track_inventory"
                        :checked="old('track_inventory', $product->track_inventory)"
                        label="Count stock for this product" />
                </x-admin.field-row>

                <x-admin.field-row
                    label="Stock Quantity"
                    help="How many you hold. Ignored once the product has options, because each option carries its own stock."
                    for="stock_quantity"
                    :required="true"
                    error="stock_quantity">
                    <input type="number" id="stock_quantity" name="stock_quantity" required step="1" min="0" max="1000000"
                           value="{{ old('stock_quantity', $product->stock_quantity ?? 0) }}"
                           class="{{ $input }} max-w-40 text-right tabular-nums">
                </x-admin.field-row>

                <x-admin.field-row
                    label="Low Stock Warning"
                    help="The point at which the shop says how few are left, and the admin list flags it."
                    for="low_stock_threshold"
                    :required="true"
                    error="low_stock_threshold">
                    <input type="number" id="low_stock_threshold" name="low_stock_threshold" required step="1" min="0" max="9999"
                           value="{{ old('low_stock_threshold', $product->low_stock_threshold ?? 5) }}"
                           class="{{ $input }} max-w-40 text-right tabular-nums">
                </x-admin.field-row>
            </x-admin.panel>

            {{-- ==================== Options ==================== --}}
            @include('admin.shop.partials.variants', ['product' => $product, 'input' => $input])

            {{-- ==================== Pictures ==================== --}}
            <x-admin.panel title="Pictures" icon="photo">
                @if ($product->exists && $product->images->isNotEmpty())
                    <div class="px-5 py-4 border-b border-gray-100">
                        <p class="text-sm text-gray-600 mb-4">
                            Pick the one shown on the listing card, and tick anything you want removed.
                            Removing deletes the file, so it cannot be undone by cancelling.
                        </p>

                        @php
                            $currentFeatured = old('featured_image', $product->images->firstWhere('is_featured', true)?->id);
                            $markedForRemoval = (array) old('remove_images', []);
                        @endphp

                        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                            @foreach ($product->images as $image)
                                @php $url = $image->url(); @endphp

                                <div class="rounded-lg border border-gray-200 overflow-hidden">
                                    <div class="aspect-square bg-gray-50 flex items-center justify-center">
                                        @if ($url)
                                            <img src="{{ $url }}" alt="{{ $image->altText() }}" class="w-full h-full object-cover">
                                        @else
                                            {{-- The row survived but the file did not. Said plainly so
                                                 the operator can remove the orphan. --}}
                                            <div class="text-center px-2">
                                                <x-admin.icon name="warning" class="w-5 h-5 mx-auto text-amber-500" />
                                                <p class="text-xs text-amber-700 mt-1">File missing</p>
                                            </div>
                                        @endif
                                    </div>

                                    <div class="px-3 py-2.5 space-y-2 bg-white">
                                        <label class="flex items-center gap-2 text-xs text-gray-700 cursor-pointer">
                                            <input type="radio" name="featured_image" value="{{ $image->id }}"
                                                   @checked((int) $currentFeatured === $image->id)
                                                   class="text-blue-600 focus:ring-blue-500/40">
                                            On the card
                                        </label>

                                        <label class="flex items-center gap-2 text-xs text-red-700 cursor-pointer">
                                            <input type="checkbox" name="remove_images[]" value="{{ $image->id }}"
                                                   @checked(in_array((string) $image->id, array_map('strval', $markedForRemoval), true))
                                                   class="rounded text-red-600 focus:ring-red-500/40">
                                            Remove
                                        </label>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <x-admin.field-row
                    label="Add Pictures"
                    help="JPG, PNG or WebP up to 4 MB each, and up to 8 per product. Square or landscape works best; the card crops to a square."
                    for="images"
                    error="images.0">

                    <input type="file" id="images" name="images[]" multiple accept="image/jpeg,image/png,image/webp"
                           class="block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-blue-600 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-blue-700 file:cursor-pointer">

                    <div id="image-preview" class="hidden grid grid-cols-3 sm:grid-cols-5 gap-3 mt-3"></div>

                    @error('images')
                        <p class="text-xs text-red-600 mt-2">{{ $message }}</p>
                    @enderror

                    <p class="text-xs text-gray-500 mt-2">
                        The first picture a product gets becomes the one on the card, so it is never
                        left without one.
                    </p>
                </x-admin.field-row>
            </x-admin.panel>

            {{-- ==================== Copy ==================== --}}
            <x-admin.panel title="What To Say About It" icon="clipboard">
                <x-admin.field-row
                    label="Key Highlights"
                    help="One per line. Each becomes a bullet on the product page. Good for the two or three things that sell it."
                    for="highlights"
                    error="highlights">
                    <textarea id="highlights" name="highlights" rows="5" maxlength="2000"
                              placeholder="One per line, for example:&#10;Solid zinc alloy, 50 mm across&#10;Choice of gold, silver or bronze finish&#10;Ribbon included"
                              class="{{ $input }} resize-y">{{ old('highlights', $product->highlights) }}</textarea>
                </x-admin.field-row>

                <x-admin.field-row
                    label="Specifications"
                    help="One per line as &quot;Label: Value&quot;. Rendered as a table. A line without a colon is shown as a heading on its own."
                    for="specifications"
                    error="specifications">
                    <textarea id="specifications" name="specifications" rows="6" maxlength="4000"
                              placeholder="One per line, for example:&#10;Material: Zinc alloy&#10;Diameter: 50 mm&#10;Weight: 45 g&#10;Ribbon: 22 mm, choice of colour"
                              class="{{ $input }} resize-y">{{ old('specifications', $product->specifications) }}</textarea>
                </x-admin.field-row>

                <x-admin.field-row
                    label="Included With Purchase"
                    help="One per line. Things that come with it, for example a presentation box or a ribbon."
                    for="included_items"
                    error="included_items">
                    <textarea id="included_items" name="included_items" rows="4" maxlength="2000"
                              placeholder="One per line, for example:&#10;Presentation box&#10;Neck ribbon&#10;Free engraving on orders over 20"
                              class="{{ $input }} resize-y">{{ old('included_items', $product->included_items) }}</textarea>
                </x-admin.field-row>
            </x-admin.panel>

            {{-- ==================== Shipping ==================== --}}
            <x-admin.panel title="Shipping" icon="archive">
                <x-admin.field-row
                    label="Weight (kg)"
                    help="Used to work out postage. Stored to the gram, so 0.045 is fine."
                    for="weight_kg"
                    error="weight_kg">
                    <input type="number" id="weight_kg" name="weight_kg" step="0.001" min="0" max="1000"
                           value="{{ old('weight_kg', $product->weightKg()) }}"
                           placeholder="Optional"
                           class="{{ $input }} max-w-40 text-right tabular-nums">
                </x-admin.field-row>

                <x-admin.field-row
                    label="Dimensions (cm)"
                    help="Length, width and height. All three or none: two out of three describes nothing."
                    error="length_cm">
                    <div class="flex flex-wrap items-center gap-2">
                        @foreach ([
                            ['length_cm', 'Length', $product->length_mm],
                            ['width_cm', 'Width', $product->width_mm],
                            ['height_cm', 'Height', $product->height_mm],
                        ] as [$field, $label, $mm])
                            <div>
                                <label for="{{ $field }}" class="sr-only">{{ $label }} in centimetres</label>
                                <input type="number" id="{{ $field }}" name="{{ $field }}" step="0.1" min="0" max="500"
                                       value="{{ old($field, $mm === null ? null : round($mm / 10, 1)) }}"
                                       placeholder="{{ $label }}"
                                       class="{{ $input }} w-24 text-right tabular-nums">
                            </div>

                            @if (! $loop->last)
                                <span class="text-gray-400" aria-hidden="true">&times;</span>
                            @endif
                        @endforeach
                    </div>

                    @foreach (['length_cm', 'width_cm', 'height_cm'] as $field)
                        @error($field)
                            <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                        @enderror
                    @endforeach
                </x-admin.field-row>
            </x-admin.panel>

            {{-- ==================== Organisation ==================== --}}
            <x-admin.panel title="Organisation" icon="grid">
                <x-admin.field-row
                    label="Categories"
                    help="Groups the product on the shop filter. A product can sit in more than one."
                    error="categories">

                    @if ($categories->isEmpty())
                        <div class="rounded-lg border border-dashed border-gray-300 bg-gray-50 px-4 py-5 text-center">
                            <p class="text-sm text-gray-600">No categories yet</p>
                            <a href="{{ route('admin.shop.settings', ['tab' => App\Http\Controllers\Admin\Shop\SettingsController::TAB_CATEGORIES]) }}"
                               class="inline-block text-sm font-semibold text-blue-600 hover:underline mt-1">
                                Set some up
                            </a>
                        </div>
                    @else
                        @php $checked = (array) old('categories', $selectedCategories); @endphp

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                            @foreach ($categories as $category)
                                <label class="flex items-start gap-2.5 rounded-lg border border-gray-200 px-3 py-2.5 cursor-pointer hover:bg-gray-50 transition">
                                    <input type="checkbox" name="categories[]" value="{{ $category->id }}"
                                           @checked(in_array((string) $category->id, array_map('strval', $checked), true))
                                           class="mt-0.5 rounded text-blue-600 focus:ring-blue-500/40">
                                    <span class="min-w-0">
                                        <span class="block text-sm font-semibold text-gray-900">{{ $category->name }}</span>
                                        @unless ($category->is_active)
                                            <span class="block text-xs text-amber-700">Not shown in the shop</span>
                                        @endunless
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    @endif
                </x-admin.field-row>

                <x-admin.field-row label="Vendor" help="Who supplies it. Admin only." for="vendor" error="vendor">
                    <input type="text" id="vendor" name="vendor" maxlength="180"
                           value="{{ old('vendor', $product->vendor) }}"
                           placeholder="Optional"
                           class="{{ $input }} max-w-xs">
                </x-admin.field-row>

                <x-admin.field-row label="Brand" help="Shown on the product page when set." for="brand" error="brand">
                    <input type="text" id="brand" name="brand" maxlength="180"
                           value="{{ old('brand', $product->brand) }}"
                           placeholder="Optional"
                           class="{{ $input }} max-w-xs">
                </x-admin.field-row>
            </x-admin.panel>

            {{-- ==================== Publishing ==================== --}}
            <x-admin.panel title="Publishing" icon="globe">
                <x-admin.field-row
                    label="Status"
                    help="Draft keeps it out of the shop. Active puts it in. Archived takes it out again while keeping its history."
                    for="status"
                    :required="true"
                    error="status">
                    <select id="status" name="status" required class="{{ $input }} max-w-xs bg-white">
                        @foreach ($statuses as $slug => $label)
                            <option value="{{ $slug }}" @selected(old('status', $product->status) === $slug)>{{ $label }}</option>
                        @endforeach
                    </select>
                </x-admin.field-row>

                <x-admin.field-row
                    label="Featured"
                    help="Featured products lead the shop, ahead of everything else."
                    error="is_featured">
                    <x-admin.toggle
                        name="is_featured"
                        :checked="old('is_featured', $product->is_featured)"
                        label="Show this product first" />
                </x-admin.field-row>

                <x-admin.field-row
                    label="Sort Order"
                    help="Lower numbers come first, within the featured and unfeatured groups."
                    for="sort_order"
                    error="sort_order">
                    <input type="number" id="sort_order" name="sort_order" min="0" max="9999"
                           value="{{ old('sort_order', $product->sort_order ?? 0) }}"
                           class="{{ $input }} max-w-32 text-right tabular-nums">
                </x-admin.field-row>
            </x-admin.panel>

            {{-- ==================== SEO ==================== --}}
            <x-admin.panel title="Search Engines" icon="search">
                <x-admin.field-row
                    label="SEO Title"
                    help="What a search result shows. Up to 70 characters, because Google cuts it there. Falls back to the product name."
                    for="seo_title"
                    error="seo_title">
                    <input type="text" id="seo_title" name="seo_title" maxlength="70"
                           value="{{ old('seo_title', $product->seo_title) }}"
                           placeholder="e.g. Champion Medals in Gold, Silver and Bronze"
                           class="{{ $input }}">
                </x-admin.field-row>

                <x-admin.field-row
                    label="SEO Description"
                    help="The grey text under a search result. Up to 180 characters. Falls back to the short description."
                    for="seo_description"
                    error="seo_description">
                    <textarea id="seo_description" name="seo_description" rows="2" maxlength="180"
                              class="{{ $input }} resize-y">{{ old('seo_description', $product->seo_description) }}</textarea>
                </x-admin.field-row>

                <x-admin.field-row
                    label="SEO Keywords"
                    help="Comma separated. Search engines largely ignore these now, so it is fine to leave blank."
                    for="seo_keywords"
                    error="seo_keywords">
                    <input type="text" id="seo_keywords" name="seo_keywords" maxlength="255"
                           value="{{ old('seo_keywords', $product->seo_keywords) }}"
                           placeholder="medals, trophies, awards"
                           class="{{ $input }}">
                </x-admin.field-row>
            </x-admin.panel>

        </x-admin.page-card>
    </form>
@endsection

@push('scripts')
<script>
    /* ---------------------------------------------------------------------
     | Options builder
     |
     | Rows are cloned from the <template> with __INDEX__ swapped for a counter.
     | The counter only ever goes up, so removing row 1 and adding another cannot
     | collide with a name still on the page.
     * ------------------------------------------------------------------ */
    (function () {
        const list = document.getElementById('variant-list');
        const emptyState = document.getElementById('variant-empty');
        const head = document.querySelector('[data-variant-head]');
        const addButton = document.getElementById('variant-add');
        const template = document.getElementById('variant-template');

        if (!list || !addButton || !template) {
            return;
        }

        // Read the highest index in use rather than counting rows, so a removal in
        // the middle cannot cause two options to share a name.
        function nextIndex() {
            let highest = -1;

            list.querySelectorAll('[data-variant-row] input[name]').forEach(function (field) {
                const match = field.name.match(/^variants\[(\d+)\]/);

                if (match) {
                    highest = Math.max(highest, parseInt(match[1], 10));
                }
            });

            return highest + 1;
        }

        function syncChrome() {
            const count = list.querySelectorAll('[data-variant-row]').length;

            emptyState?.classList.toggle('hidden', count > 0);

            // The heading carries sm:grid, so toggling 'hidden' alone would lose to
            // it. Both classes are managed together.
            if (head) {
                head.classList.toggle('hidden', count === 0);
                head.classList.toggle('sm:grid', count > 0);
            }
        }

        addButton.addEventListener('click', function () {
            const html = template.innerHTML.split('__INDEX__').join(nextIndex());
            const holder = document.createElement('div');
            holder.innerHTML = html.trim();

            const row = holder.firstElementChild;
            list.appendChild(row);
            syncChrome();

            // Straight into the label, since that is the first thing to type.
            row.querySelector('input[type="text"]')?.focus();
        });

        list.addEventListener('click', function (event) {
            const remove = event.target.closest('[data-variant-remove]');

            if (!remove) {
                return;
            }

            /*
             | Removing an option people have already ordered would orphan their
             | order lines, so it is refused here as well as on save.
             */
            if (remove.hasAttribute('data-variant-locked')) {
                window.alert(
                    'This option has ' + remove.getAttribute('data-variant-locked') +
                    ' order(s), so it cannot be removed.\n\n' +
                    'Set its stock to the number already ordered to stop selling it.'
                );

                return;
            }

            remove.closest('[data-variant-row]').remove();
            syncChrome();
        });

        syncChrome();
    })();

    /* ---------------------------------------------------------------------
     | Preview of newly chosen pictures
     |
     | Read in the browser, so nothing is uploaded until the form is submitted.
     * ------------------------------------------------------------------ */
    (function () {
        const field = document.getElementById('images');
        const preview = document.getElementById('image-preview');

        if (!field || !preview) {
            return;
        }

        field.addEventListener('change', function () {
            preview.innerHTML = '';

            const files = Array.from(this.files || []);

            preview.classList.toggle('hidden', files.length === 0);

            files.forEach(function (file) {
                const reader = new FileReader();

                reader.addEventListener('load', function () {
                    const wrap = document.createElement('div');
                    wrap.className = 'aspect-square rounded-lg border border-gray-200 overflow-hidden bg-gray-50';

                    const img = document.createElement('img');
                    img.src = reader.result;
                    img.alt = '';
                    img.className = 'w-full h-full object-cover';

                    wrap.appendChild(img);
                    preview.appendChild(wrap);
                });

                reader.readAsDataURL(file);
            });
        });
    })();
</script>
@endpush
