{{--
    Create and edit dialogs for shop categories, plus the script that drives them.

    Included after the settings shell closes, so the fixed overlays are not nested
    inside a card whose stacking context would trap them behind the page.

    @param \Illuminate\Support\Collection $categories
    @param array                          $icons
    @param bool                           $canCreateCategory
    @param bool                           $canUpdateCategory
--}}
@php
    $label = 'block text-sm font-semibold text-gray-700 mb-1.5';
    $dialogInput = 'w-full rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';
@endphp

{{-- ===================== Create ===================== --}}
@if ($canCreateCategory)
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
                               class="{{ $dialogInput }}">
                        @error('name')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="create-slug" class="{{ $label }}">URL Slug</label>
                        <input type="text" id="create-slug" name="slug" maxlength="120"
                               value="{{ old('slug') }}" placeholder="Leave blank to build one from the name"
                               class="{{ $dialogInput }}">
                        @error('slug')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="create-icon" class="{{ $label }}">Icon</label>
                        <select id="create-icon" name="icon" class="{{ $dialogInput }} bg-white">
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
                               value="{{ old('description') }}" placeholder="Optional, shown in this list only"
                               class="{{ $dialogInput }}">
                        @error('description')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="create-sort" class="{{ $label }}">Sort Order</label>
                        <input type="number" id="create-sort" name="sort_order" min="0" max="9999"
                               value="{{ old('sort_order', 0) }}"
                               class="{{ $dialogInput }} max-w-32 text-right tabular-nums">
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

{{-- ===================== Edit ===================== --}}
@if ($canUpdateCategory)
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
                                   value="{{ $category->name }}" class="{{ $dialogInput }}">
                        </div>

                        <div>
                            <label for="edit-slug-{{ $category->id }}" class="{{ $label }}">URL Slug</label>
                            <input type="text" id="edit-slug-{{ $category->id }}" name="slug" maxlength="120"
                                   value="{{ $category->slug }}" class="{{ $dialogInput }}">
                            <p class="text-xs text-gray-500 mt-1">
                                Changing this changes the shop link that filters by this category.
                            </p>
                        </div>

                        <div>
                            <label for="edit-icon-{{ $category->id }}" class="{{ $label }}">Icon</label>
                            <select id="edit-icon-{{ $category->id }}" name="icon" class="{{ $dialogInput }} bg-white">
                                <option value="">None</option>
                                @foreach ($icons as $value => $text)
                                    <option value="{{ $value }}" @selected($category->icon === $value)>{{ $text }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label for="edit-description-{{ $category->id }}" class="{{ $label }}">Description</label>
                            <input type="text" id="edit-description-{{ $category->id }}" name="description" maxlength="255"
                                   value="{{ $category->description }}" class="{{ $dialogInput }}">
                        </div>

                        <div>
                            <label for="edit-sort-{{ $category->id }}" class="{{ $label }}">Sort Order</label>
                            <input type="number" id="edit-sort-{{ $category->id }}" name="sort_order" min="0" max="9999"
                                   value="{{ $category->sort_order }}"
                                   class="{{ $dialogInput }} max-w-32 text-right tabular-nums">
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
