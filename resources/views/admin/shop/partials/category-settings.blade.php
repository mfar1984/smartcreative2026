{{--
    The Categories tab of Shop Settings: the list and the Add button.

    The dialogs live in category-dialogs.blade.php and are included outside the
    settings shell, because a fixed overlay inside a card inherits its stacking
    context and would be trapped behind the page.

    @param \Illuminate\Support\Collection $categories
    @param array                          $icons
    @param bool                           $canCreateCategory
    @param bool                           $canUpdateCategory
    @param bool                           $canDeleteCategory
--}}
@php $head = 'px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500'; @endphp

<div class="rounded-lg border border-gray-200 bg-white overflow-hidden">

    <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3.5 border-b border-gray-200">
        <p class="text-sm text-gray-600">
            {{ $categories->count() }} {{ Str::plural('category', $categories->count()) }},
            {{ $categories->where('is_active', true)->count() }} shown in the shop
        </p>

        @if ($canCreateCategory)
            <button type="button" data-open-dialog="category-create"
                    class="inline-flex items-center gap-2 bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-sm">
                <x-admin.icon name="plus" class="w-4 h-4" />
                Add Category
            </button>
        @endif
    </div>

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
                    @if ($canUpdateCategory || $canDeleteCategory)
                        <th scope="col" class="{{ $head }} text-center">Actions</th>
                    @endif
                </tr>
            </thead>

            <tbody class="divide-y divide-gray-100">
                @forelse ($categories as $category)
                    <tr class="hover:bg-blue-50/40">
                        <td class="px-5 py-3">
                            <span class="inline-flex w-9 h-9 items-center justify-center rounded-lg bg-blue-50 text-blue-600">
                                <x-admin.icon :name="$category->iconName()" class="w-4 h-4" />
                            </span>
                        </td>

                        <td class="px-5 py-3">
                            <span class="font-semibold text-gray-900">{{ $category->name }}</span>

                            @if (filled($category->description))
                                <span class="block text-xs text-gray-500 mt-0.5">{{ $category->description }}</span>
                            @endif
                        </td>

                        <td class="px-5 py-3">
                            <code class="text-xs text-gray-600">{{ $category->slug }}</code>
                        </td>

                        <td class="px-5 py-3 text-center">
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

                        <td class="px-5 py-3 text-center text-gray-600 tabular-nums">
                            {{ $category->sort_order }}
                        </td>

                        <td class="px-5 py-3 text-center">
                            <x-admin.badge :tone="$category->is_active ? 'green' : 'gray'" :dot="true">
                                {{ $category->is_active ? 'Active' : 'Hidden' }}
                            </x-admin.badge>
                        </td>

                        @if ($canUpdateCategory || $canDeleteCategory)
                            <td class="px-5 py-3 whitespace-nowrap">
                                <div class="flex items-center justify-center gap-1">
                                    @if ($canUpdateCategory)
                                        <button type="button" data-open-dialog="category-edit-{{ $category->id }}"
                                                class="p-1.5 rounded-lg text-amber-600 hover:bg-amber-50 transition"
                                                title="Edit {{ $category->name }}" aria-label="Edit {{ $category->name }}">
                                            <x-admin.icon name="pencil" class="w-4 h-4" />
                                        </button>
                                    @endif

                                    @if ($canDeleteCategory)
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
                        <td colspan="7" class="px-5 py-12 text-center">
                            <x-admin.icon name="tag" class="w-10 h-10 mx-auto text-gray-300" />

                            <p class="text-sm font-semibold text-gray-700 mt-3">No categories yet</p>

                            <p class="text-sm text-gray-500 mt-1 max-w-md mx-auto">
                                Categories are optional. Without them the shop lists everything on one
                                page, which is fine until there is a lot to look through.
                            </p>

                            @if ($canCreateCategory)
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

</div>
