@extends('layouts.admin')

@php
    $head = 'px-6 py-3 text-xs font-bold uppercase tracking-wide text-gray-500';
    $label = 'block text-sm font-semibold text-gray-700 mb-1.5';
    $input = 'w-full rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';
@endphp

@section('title', 'Shop Categories')

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>Shop</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">Categories</span>
@endsection

@section('content')
    <x-admin.page-card
        title="Categories"
        description="How the shop is grouped. Visitors use these as filters, so keep them few and obvious."
        :flush="true">

        @if ($canCreate)
            <x-slot:actions>
                <button type="button" data-open-dialog="category-create"
                        class="inline-flex items-center gap-2 bg-blue-600 text-white px-4 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-sm">
                    <x-admin.icon name="plus" class="w-4 h-4" />
                    Add Category
                </button>
            </x-slot:actions>
        @endif

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left">
                    <tr>
                        <th scope="col" class="{{ $head }} w-16">Icon</th>
                        <th scope="col" class="{{ $head }}">Name</th>
                        <th scope="col" class="{{ $head }}">Slug</th>
                        <th scope="col" class="{{ $head }} text-center">Products</th>
                        <th scope="col" class="{{ $head }} text-center">Order</th>
                        <th scope="col" class="{{ $head }} text-center">Status</th>
                        @if ($canUpdate || $canDelete)
                            <th scope="col" class="{{ $head }} text-center">Actions</th>
                        @endif
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-100">
                    @forelse ($categories as $category)
                        <tr class="hover:bg-blue-50/40">
                            <td class="px-6 py-3">
                                <span class="inline-flex w-9 h-9 items-center justify-center rounded-lg bg-blue-50 text-blue-600">
                                    <x-admin.icon :name="$category->iconName()" class="w-4 h-4" />
                                </span>
                            </td>

                            <td class="px-6 py-3">
                                <span class="font-semibold text-gray-900">{{ $category->name }}</span>

                                @if (filled($category->description))
                                    <span class="block text-xs text-gray-500 mt-0.5">{{ $category->description }}</span>
                                @endif
                            </td>

                            <td class="px-6 py-3">
                                <code class="text-xs text-gray-600">{{ $category->slug }}</code>
                            </td>

                            <td class="px-6 py-3 text-center">
                                @if ($category->products_count > 0)
                                    <a href="{{ route('admin.shop.products', ['category' => $category->id]) }}"
                                       class="inline-block rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-semibold text-blue-800 hover:bg-blue-200 transition tabular-nums"
                                       title="Show these products">
                                        {{ $category->products_count }}
                                    </a>
                                @else
                                    <span class="text-xs text-gray-400 tabular-nums">0</span>
                                @endif
                            </td>

                            <td class="px-6 py-3 text-center text-gray-600 tabular-nums">
                                {{ $category->sort_order }}
                            </td>

                            <td class="px-6 py-3 text-center">
                                <x-admin.badge :tone="$category->is_active ? 'green' : 'gray'" :dot="true">
                                    {{ $category->is_active ? 'Active' : 'Hidden' }}
                                </x-admin.badge>
                            </td>

                            @if ($canUpdate || $canDelete)
                                <td class="px-6 py-3 whitespace-nowrap">
                                    <div class="flex items-center justify-center gap-1">
                                        @if ($canUpdate)
                                            <button type="button" data-open-dialog="category-edit-{{ $category->id }}"
                                                    class="p-1.5 rounded-lg text-amber-600 hover:bg-amber-50 transition"
                                                    title="Edit {{ $category->name }}" aria-label="Edit {{ $category->name }}">
                                                <x-admin.icon name="pencil" class="w-4 h-4" />
                                            </button>
                                        @endif

                                        @if ($canDelete)
                                            <form action="{{ route('admin.shop.categories.destroy', $category) }}" method="POST"
                                                  onsubmit="return confirm('Delete {{ addslashes($category->name) }}?\n\n{{ $category->products_count }} product(s) will stop being grouped under it. No product is removed from the shop.');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit"
                                                        class="p-1.5 rounded-lg text-red-600 hover:bg-red-50 transition"
                                                        title="Delete {{ $category->name }}" aria-label="Delete {{ $category->name }}">
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
                            <td colspan="7" class="px-6 py-12 text-center">
                                <x-admin.icon name="tag" class="w-10 h-10 mx-auto text-gray-300" />

                                <p class="text-sm font-semibold text-gray-700 mt-3">No categories yet</p>

                                <p class="text-sm text-gray-500 mt-1 max-w-md mx-auto">
                                    Categories are optional. Without them the shop lists everything on one
                                    page, which is fine until there is a lot to look through.
                                </p>

                                @if ($canCreate)
                                    <button type="button" data-open-dialog="category-create"
                                            class="inline-flex items-center gap-2 mt-5 bg-blue-600 text-white px-5 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition">
                                        <x-admin.icon name="plus" class="w-4 h-4" />
                                        Add the first category
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="px-6 py-3.5 border-t border-gray-200">
            <p class="text-xs text-gray-500">
                Showing {{ $categories->count() }} {{ Str::plural('category', $categories->count()) }}
            </p>
        </div>

    </x-admin.page-card>

    {{-- ===================== Create dialog ===================== --}}
    @if ($canCreate)
        <div id="category-create" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true" aria-labelledby="category-create-title">
            <div class="fixed inset-0 bg-gray-900/50" data-close-dialog></div>

            <div class="relative min-h-full flex items-start justify-center p-4">
                <div class="relative w-full max-w-lg bg-white rounded-xl shadow-xl my-8">
                    <div class="flex items-center justify-between gap-4 px-6 py-4 border-b border-gray-200">
                        <h2 id="category-create-title" class="text-base font-bold text-gray-900">Add Category</h2>
                        <button type="button" data-close-dialog class="p-1 rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition" aria-label="Close">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    <form action="{{ route('admin.shop.categories.store') }}" method="POST" class="p-6 space-y-4">
                        @csrf

                        <div>
                            <label for="create-name" class="{{ $label }}">Name <span class="text-red-600" aria-hidden="true">*</span></label>
                            <input type="text" id="create-name" name="name" required maxlength="120"
                                   value="{{ old('name') }}" placeholder="e.g. Medals"
                                   class="{{ $input }}">
                            @error('name')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label for="create-slug" class="{{ $label }}">URL Slug</label>
                            <input type="text" id="create-slug" name="slug" maxlength="120"
                                   value="{{ old('slug') }}" placeholder="Leave blank to build one from the name"
                                   class="{{ $input }}">
                            @error('slug')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label for="create-icon" class="{{ $label }}">Icon</label>
                            <select id="create-icon" name="icon" class="{{ $input }} bg-white">
                                <option value="">None</option>
                                @foreach ($icons as $value => $text)
                                    <option value="{{ $value }}" @selected(old('icon') === $value)>{{ $text }}</option>
                                @endforeach
                            </select>
                            @error('icon')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label for="create-description" class="{{ $label }}">Description</label>
                            <input type="text" id="create-description" name="description" maxlength="255"
                                   value="{{ old('description') }}" placeholder="Optional, shown in the admin list only"
                                   class="{{ $input }}">
                            @error('description')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label for="create-sort" class="{{ $label }}">Sort Order</label>
                            <input type="number" id="create-sort" name="sort_order" min="0" max="9999"
                                   value="{{ old('sort_order', 0) }}"
                                   class="{{ $input }} max-w-32 text-right tabular-nums">
                            <p class="text-xs text-gray-500 mt-1">Lower numbers come first. Ties fall back to alphabetical.</p>
                            @error('sort_order')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        </div>

                        <x-admin.toggle name="is_active" id="create-active" :checked="old('is_active', true)" label="Show this category in the shop" />

                        <div class="flex justify-end gap-3 pt-2 border-t border-gray-100">
                            <button type="button" data-close-dialog class="px-4 py-2.5 rounded-lg border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                                Cancel
                            </button>
                            <button type="submit" class="bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-sm">
                                Add Category
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    {{-- ===================== Edit dialogs ===================== --}}
    @if ($canUpdate)
        @foreach ($categories as $category)
            <div id="category-edit-{{ $category->id }}" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true" aria-labelledby="category-edit-title-{{ $category->id }}">
                <div class="fixed inset-0 bg-gray-900/50" data-close-dialog></div>

                <div class="relative min-h-full flex items-start justify-center p-4">
                    <div class="relative w-full max-w-lg bg-white rounded-xl shadow-xl my-8">
                        <div class="flex items-center justify-between gap-4 px-6 py-4 border-b border-gray-200">
                            <h2 id="category-edit-title-{{ $category->id }}" class="text-base font-bold text-gray-900">Edit {{ $category->name }}</h2>
                            <button type="button" data-close-dialog class="p-1 rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition" aria-label="Close">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>

                        <form action="{{ route('admin.shop.categories.update', $category) }}" method="POST" class="p-6 space-y-4">
                            @csrf
                            @method('PUT')

                            <div>
                                <label for="edit-name-{{ $category->id }}" class="{{ $label }}">Name <span class="text-red-600" aria-hidden="true">*</span></label>
                                <input type="text" id="edit-name-{{ $category->id }}" name="name" required maxlength="120"
                                       value="{{ $category->name }}" class="{{ $input }}">
                            </div>

                            <div>
                                <label for="edit-slug-{{ $category->id }}" class="{{ $label }}">URL Slug</label>
                                <input type="text" id="edit-slug-{{ $category->id }}" name="slug" maxlength="120"
                                       value="{{ $category->slug }}" class="{{ $input }}">
                                <p class="text-xs text-gray-500 mt-1">
                                    Changing this changes the shop link that filters by this category.
                                </p>
                            </div>

                            <div>
                                <label for="edit-icon-{{ $category->id }}" class="{{ $label }}">Icon</label>
                                <select id="edit-icon-{{ $category->id }}" name="icon" class="{{ $input }} bg-white">
                                    <option value="">None</option>
                                    @foreach ($icons as $value => $text)
                                        <option value="{{ $value }}" @selected($category->icon === $value)>{{ $text }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label for="edit-description-{{ $category->id }}" class="{{ $label }}">Description</label>
                                <input type="text" id="edit-description-{{ $category->id }}" name="description" maxlength="255"
                                       value="{{ $category->description }}" class="{{ $input }}">
                            </div>

                            <div>
                                <label for="edit-sort-{{ $category->id }}" class="{{ $label }}">Sort Order</label>
                                <input type="number" id="edit-sort-{{ $category->id }}" name="sort_order" min="0" max="9999"
                                       value="{{ $category->sort_order }}"
                                       class="{{ $input }} max-w-32 text-right tabular-nums">
                            </div>

                            <x-admin.toggle name="is_active" id="edit-active-{{ $category->id }}" :checked="$category->is_active" label="Show this category in the shop" />

                            <div class="flex justify-end gap-3 pt-2 border-t border-gray-100">
                                <button type="button" data-close-dialog class="px-4 py-2.5 rounded-lg border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                                    Cancel
                                </button>
                                <button type="submit" class="bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-sm">
                                    Save Changes
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endforeach
    @endif
@endsection

@push('scripts')
<script>
    (function () {
        function closeAll() {
            document.querySelectorAll('[role="dialog"]').forEach(function (dialog) {
                dialog.classList.add('hidden');
            });
            document.body.classList.remove('overflow-hidden');
        }

        document.querySelectorAll('[data-open-dialog]').forEach(function (trigger) {
            trigger.addEventListener('click', function () {
                const dialog = document.getElementById(trigger.dataset.openDialog);

                if (!dialog) {
                    return;
                }

                closeAll();
                dialog.classList.remove('hidden');
                document.body.classList.add('overflow-hidden');
                dialog.querySelector('input:not([type="hidden"]):not(.sr-only), select')?.focus();
            });
        });

        document.querySelectorAll('[data-close-dialog]').forEach(function (trigger) {
            trigger.addEventListener('click', closeAll);
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeAll();
            }
        });

        // Reopen the create dialog when validation sent the operator back with
        // errors, so the typing is not lost behind a closed panel.
        @if ($errors->any() && old('name'))
            document.getElementById('category-create')?.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
        @endif
    })();
</script>
@endpush
