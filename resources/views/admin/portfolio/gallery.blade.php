@extends('layouts.admin')

@php
    $head = 'px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500';
    $label = 'block text-sm font-semibold text-gray-700 mb-1.5';
    $input = 'w-full rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';
    $isGrid = $activeView === 'grid';

    // Carried into the view links so switching grid and list keeps the filter.
    $keep = $projectId > 0 ? ['project' => $projectId] : [];
@endphp

@section('title', 'Portfolio Gallery')

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>Portfolio</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">Gallery</span>
@endsection

@section('content')

    {{-- ==================== Upload ==================== --}}
    @if ($canCreate)
        @if ($projects->isEmpty())
            <div class="flex flex-wrap items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 px-5 py-4 mb-5">
                <x-admin.icon name="warning" class="w-5 h-5 shrink-0 mt-0.5 text-amber-600" />

                <div class="flex-1 min-w-64">
                    <p class="text-sm font-semibold text-amber-900">There are no projects to attach photographs to</p>
                    <p class="text-sm text-amber-800 mt-0.5">
                        Every photograph belongs to a project, so add the project first and then
                        come back with its pictures.
                    </p>
                </div>

                <a href="{{ route('admin.portfolio.create') }}"
                   class="inline-flex items-center gap-2 rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700 transition">
                    <x-admin.icon name="plus" class="w-4 h-4" />
                    Add a project
                </a>
            </div>
        @else
            <x-admin.page-card
                title="Add Photographs"
                description="Pick the project, choose the files, upload. Up to {{ $maxFiles }} at a time.">

                <form action="{{ route('admin.portfolio.gallery.store') }}" method="POST" enctype="multipart/form-data">
                    @csrf

                    <x-admin.panel title="Upload" icon="photo">
                        <x-admin.field-row
                            label="Project"
                            help="Which project these belong to. The tag is set here, so a photograph can never end up unattached."
                            for="portfolio_project_id"
                            :required="true"
                            error="portfolio_project_id">

                            <select id="portfolio_project_id" name="portfolio_project_id" required class="{{ $input }} max-w-md bg-white">
                                <option value="">Choose a project</option>
                                @foreach ($projects as $project)
                                    <option value="{{ $project->id }}"
                                            @selected((int) old('portfolio_project_id', $preselectedProject) === $project->id)>
                                        {{ $project->title }}
                                        ({{ $project->images_count }} {{ Str::plural('photo', $project->images_count) }})
                                    </option>
                                @endforeach
                            </select>
                        </x-admin.field-row>

                        <x-admin.field-row
                            label="Images"
                            help="JPG, PNG or WebP up to 6 MB each. Choose several at once."
                            for="images"
                            :required="true"
                            error="images">

                            <input type="file" id="images" name="images[]" multiple required accept="image/jpeg,image/png,image/webp"
                                   class="block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-blue-600 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-blue-700 file:cursor-pointer">

                            <div id="upload-preview" class="hidden grid grid-cols-3 sm:grid-cols-6 gap-3 mt-3"></div>

                            @error('images.0')
                                <p class="text-xs text-red-600 mt-2">{{ $message }}</p>
                            @enderror
                        </x-admin.field-row>

                        <x-admin.field-row
                            label="Caption"
                            help="Optional, and applied to every file in this batch. Captions usually read better written one at a time afterwards, which the list view is for."
                            for="caption"
                            error="caption">

                            <input type="text" id="caption" name="caption" maxlength="255"
                                   value="{{ old('caption') }}"
                                   placeholder="e.g. Grand final, RH Hotel Sibu"
                                   class="{{ $input }}">
                        </x-admin.field-row>
                    </x-admin.panel>

                    <div class="flex justify-end pt-5">
                        <button type="submit"
                                class="inline-flex items-center gap-2 bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-sm">
                            <x-admin.icon name="plus" class="w-4 h-4" />
                            Upload
                        </button>
                    </div>
                </form>
            </x-admin.page-card>
        @endif
    @endif

    {{-- ==================== The gallery ==================== --}}
    <div class="mt-5">
        <x-admin.page-card
            title="Gallery"
            description="Every photograph, grouped by the project it belongs to."
            :flush="true">

            <x-slot:actions>
                <a href="{{ route('portfolio') }}" target="_blank" rel="noopener"
                   class="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                    <x-admin.icon name="eye" class="w-4 h-4" />
                    View public page
                </a>

                {{-- Grid or list. Real links carrying ?view=, so the choice is
                     shareable and survives a reload. --}}
                <div class="inline-flex rounded-lg border border-gray-300 overflow-hidden" role="group" aria-label="How to show the photographs">
                    @foreach ($views as $slug => $definition)
                        <a href="{{ route('admin.portfolio.gallery', array_merge($keep, ['view' => $slug])) }}"
                           @class([
                               'inline-flex items-center gap-1.5 px-3.5 py-2.5 text-sm font-semibold transition',
                               'bg-blue-600 text-white' => $activeView === $slug,
                               'bg-white text-gray-700 hover:bg-gray-50' => $activeView !== $slug,
                           ])
                           @if ($activeView === $slug) aria-current="true" @endif>
                            <x-admin.icon :name="$definition['icon']" class="w-4 h-4" />
                            {{ $definition['label'] }}
                        </a>
                    @endforeach
                </div>
            </x-slot:actions>

            <x-admin.filter-bar
                :action="route('admin.portfolio.gallery')"
                :reset="$isFiltered ? route('admin.portfolio.gallery', ['view' => $activeView]) : null">

                {{-- The view is a hidden field rather than a second control, so
                     applying a filter does not throw you back to the grid. --}}
                <input type="hidden" name="view" value="{{ $activeView }}">

                <label for="project" class="sr-only">Project</label>
                <select id="project" name="project"
                        class="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 bg-white focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
                    <option value="">All projects</option>
                    @foreach ($projects as $project)
                        <option value="{{ $project->id }}" @selected($projectId === $project->id)>
                            {{ $project->title }} ({{ $project->images_count }})
                        </option>
                    @endforeach
                </select>
            </x-admin.filter-bar>

            <div class="flex flex-wrap items-center justify-between gap-3 px-6 py-2.5 bg-gray-50 border-b border-gray-200">
                <p class="text-xs text-gray-500">
                    {{ $images->total() }} {{ Str::plural('photograph', $images->total()) }}
                    @if ($isFiltered) in this project @endif
                </p>
                <p class="text-xs text-gray-500">
                    Lower sort numbers appear first in the popup
                </p>
            </div>

            @if ($images->isEmpty())
                <div class="px-5 py-12 text-center">
                    <x-admin.icon name="photo" class="w-10 h-10 mx-auto text-gray-300" />

                    <p class="text-sm font-semibold text-gray-700 mt-3">
                        {{ $isFiltered ? 'No photographs in that project yet' : 'No photographs yet' }}
                    </p>

                    <p class="text-sm text-gray-500 mt-1 max-w-md mx-auto">
                        A project with no photographs still shows on the public page, using its
                        cover image. Its card just does not open a popup.
                    </p>
                </div>

            @elseif ($isGrid)
                {{-- ---------------- Grid ---------------- --}}
                <div class="p-5 space-y-8">
                    {{-- Grouped up front rather than by watching the project change
                         inside one loop, because Blade cannot open a wrapper in one
                         iteration and close it in another. --}}
                    @foreach ($images->getCollection()->groupBy('portfolio_project_id') as $groupProjectId => $group)
                        <section>
                            <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-sm font-bold text-gray-900">
                                        {{ $group->first()->project?->title ?? 'Project removed' }}
                                    </h3>

                                    <span class="text-xs text-gray-500">
                                        {{ $group->count() }} {{ Str::plural('photograph', $group->count()) }} on this page
                                    </span>

                                    @if ($group->first()->project && ! $group->first()->project->isPublished())
                                        <x-admin.badge tone="gray">Draft, not public</x-admin.badge>
                                    @endif
                                </div>

                                @unless ($isFiltered)
                                    <a href="{{ route('admin.portfolio.gallery', ['project' => $groupProjectId, 'view' => $activeView]) }}"
                                       class="text-xs font-semibold text-blue-600 hover:underline">
                                        Show only this project
                                    </a>
                                @endunless
                            </div>

                            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6 gap-4">
                                @foreach ($group as $image)
                                    @php $url = $image->url(); @endphp

                                    <figure class="group rounded-lg border border-gray-200 overflow-hidden bg-white">
                                        <div class="aspect-square bg-gray-50 flex items-center justify-center relative">
                                            @if ($url)
                                                <img src="{{ $url }}" alt="{{ $image->altText() }}" loading="lazy"
                                                     class="w-full h-full object-cover">
                                            @else
                                                <div class="text-center px-2">
                                                    <x-admin.icon name="warning" class="w-5 h-5 mx-auto text-amber-500" />
                                                    <p class="text-xs text-amber-700 mt-1">File missing</p>
                                                </div>
                                            @endif

                                            <span class="absolute top-1.5 left-1.5 rounded bg-gray-900/70 px-1.5 py-0.5 text-xs font-semibold text-white tabular-nums">
                                                {{ $image->sort_order }}
                                            </span>
                                        </div>

                                        <figcaption class="px-2.5 py-2 border-t border-gray-100">
                                            <p @class([
                                                'text-xs leading-snug truncate',
                                                'text-gray-700' => filled($image->caption),
                                                'text-gray-400 italic' => blank($image->caption),
                                            ])
                                               title="{{ $image->caption ?: 'No caption' }}">
                                                {{ $image->caption ?: 'No caption' }}
                                            </p>

                                            @if ($canUpdate || $canDelete)
                                                <div class="flex items-center gap-1 mt-1.5">
                                                    @if ($canUpdate)
                                                        <button type="button" data-open-dialog="image-edit-{{ $image->id }}"
                                                                class="p-1 rounded text-amber-600 hover:bg-amber-50 transition"
                                                                title="Edit this photograph" aria-label="Edit this photograph">
                                                            <x-admin.icon name="pencil" class="w-3.5 h-3.5" />
                                                        </button>
                                                    @endif

                                                    @if ($canDelete)
                                                        <form action="{{ route('admin.portfolio.gallery.destroy', $image) }}" method="POST"
                                                              onsubmit="return confirm('Delete this photograph?\n\nThe file is deleted with it. This cannot be undone.');">
                                                            @csrf
                                                            @method('DELETE')
                                                            <button type="submit"
                                                                    class="p-1 rounded text-red-600 hover:bg-red-50 transition"
                                                                    title="Delete this photograph" aria-label="Delete this photograph">
                                                                <x-admin.icon name="trash" class="w-3.5 h-3.5" />
                                                            </button>
                                                        </form>
                                                    @endif

                                                    @if ($url)
                                                        <a href="{{ $url }}" target="_blank" rel="noopener"
                                                           class="p-1 rounded text-gray-500 hover:bg-gray-100 transition ml-auto"
                                                           title="Open the full size image" aria-label="Open the full size image">
                                                            <x-admin.icon name="eye" class="w-3.5 h-3.5" />
                                                        </a>
                                                    @endif
                                                </div>
                                            @endif
                                        </figcaption>
                                    </figure>
                                @endforeach
                            </div>
                        </section>
                    @endforeach
                </div>

            @else
                {{-- ---------------- List ---------------- --}}
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-left">
                            <tr>
                                <th scope="col" class="{{ $head }} w-16">Image</th>
                                <th scope="col" class="{{ $head }}">Caption</th>
                                <th scope="col" class="{{ $head }}">Project</th>
                                <th scope="col" class="{{ $head }}">File</th>
                                <th scope="col" class="{{ $head }} text-right">Size</th>
                                <th scope="col" class="{{ $head }} text-center">Order</th>
                                <th scope="col" class="{{ $head }}">Uploaded</th>
                                @if ($canUpdate || $canDelete)
                                    <th scope="col" class="{{ $head }} text-center">Actions</th>
                                @endif
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-100">
                            @foreach ($images as $image)
                                @php
                                    $url = $image->url();
                                    $size = $image->sizeKb();
                                @endphp

                                <tr class="hover:bg-blue-50/40 align-top">
                                    <td class="px-5 py-3">
                                        @if ($url)
                                            <a href="{{ $url }}" target="_blank" rel="noopener">
                                                <img src="{{ $url }}" alt="{{ $image->altText() }}" loading="lazy"
                                                     class="w-12 h-12 object-cover rounded border border-gray-200">
                                            </a>
                                        @else
                                            <div class="w-12 h-12 rounded border border-amber-200 bg-amber-50 flex items-center justify-center">
                                                <x-admin.icon name="warning" class="w-4 h-4 text-amber-500" />
                                            </div>
                                        @endif
                                    </td>

                                    <td class="px-5 py-3">
                                        @if (filled($image->caption))
                                            <span class="text-gray-900">{{ $image->caption }}</span>
                                        @else
                                            <span class="text-xs text-gray-400 italic">No caption</span>
                                        @endif
                                    </td>

                                    <td class="px-5 py-3">
                                        <span class="text-gray-700">{{ $image->project?->title ?? 'Project removed' }}</span>

                                        @if ($image->project && ! $image->project->isPublished())
                                            <span class="block mt-0.5">
                                                <x-admin.badge tone="gray">Draft</x-admin.badge>
                                            </span>
                                        @endif
                                    </td>

                                    <td class="px-5 py-3">
                                        <code class="text-xs text-gray-500 break-all">{{ $image->fileName() }}</code>
                                    </td>

                                    <td class="px-5 py-3 text-right whitespace-nowrap tabular-nums text-gray-600">
                                        {{ $size === null ? '—' : $size . ' KB' }}
                                    </td>

                                    <td class="px-5 py-3 text-center tabular-nums text-gray-600">
                                        {{ $image->sort_order }}
                                    </td>

                                    <td class="px-5 py-3 whitespace-nowrap text-gray-600">
                                        {{ $image->created_at->format('d M Y') }}
                                    </td>

                                    @if ($canUpdate || $canDelete)
                                        <td class="px-5 py-3 whitespace-nowrap">
                                            <div class="flex items-center justify-center gap-1">
                                                @if ($canUpdate)
                                                    <button type="button" data-open-dialog="image-edit-{{ $image->id }}"
                                                            class="p-1.5 rounded-lg text-amber-600 hover:bg-amber-50 transition"
                                                            title="Edit this photograph" aria-label="Edit this photograph">
                                                        <x-admin.icon name="pencil" class="w-4 h-4" />
                                                    </button>
                                                @endif

                                                @if ($canDelete)
                                                    <form action="{{ route('admin.portfolio.gallery.destroy', $image) }}" method="POST"
                                                          onsubmit="return confirm('Delete this photograph?\n\nThe file is deleted with it. This cannot be undone.');">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit"
                                                                class="p-1.5 rounded-lg text-red-600 hover:bg-red-50 transition"
                                                                title="Delete this photograph" aria-label="Delete this photograph">
                                                            <x-admin.icon name="trash" class="w-4 h-4" />
                                                        </button>
                                                    </form>
                                                @endif
                                            </div>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <div class="px-5 py-3.5 border-t border-gray-200">
                @if ($images->hasPages())
                    {{ $images->links() }}
                @else
                    <p class="text-xs text-gray-500">
                        Showing {{ $images->count() }} {{ Str::plural('photograph', $images->count()) }}
                    </p>
                @endif
            </div>

        </x-admin.page-card>
    </div>

    {{-- ==================== Edit dialogs ==================== --}}
    @if ($canUpdate)
        @foreach ($images as $image)
            <div id="image-edit-{{ $image->id }}" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true" aria-labelledby="image-edit-title-{{ $image->id }}">
                <div class="fixed inset-0 bg-gray-900/50" data-close-dialog></div>

                <div class="relative min-h-full flex items-start justify-center p-4">
                    <div class="relative w-full max-w-lg bg-white rounded-xl shadow-xl my-8">
                        <div class="flex items-center justify-between gap-4 px-6 py-4 border-b border-gray-200">
                            <h2 id="image-edit-title-{{ $image->id }}" class="text-base font-bold text-gray-900">Edit Photograph</h2>
                            <button type="button" data-close-dialog class="p-1 rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition" aria-label="Close">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>

                        <form action="{{ route('admin.portfolio.gallery.update', $image) }}" method="POST" class="p-6 space-y-4">
                            @csrf
                            @method('PUT')

                            @if ($image->url())
                                <img src="{{ $image->url() }}" alt="{{ $image->altText() }}"
                                     class="w-full max-h-56 object-contain rounded-lg border border-gray-200 bg-gray-50">
                            @endif

                            <div>
                                <label for="edit-caption-{{ $image->id }}" class="{{ $label }}">Caption</label>
                                <input type="text" id="edit-caption-{{ $image->id }}" name="caption" maxlength="255"
                                       value="{{ $image->caption }}" class="{{ $input }}">
                                <p class="text-xs text-gray-500 mt-1">
                                    Shown under the picture in the popup, and read out by screen readers.
                                </p>
                            </div>

                            <div>
                                <label for="edit-project-{{ $image->id }}" class="{{ $label }}">Project</label>
                                <select id="edit-project-{{ $image->id }}" name="portfolio_project_id" required class="{{ $input }} bg-white">
                                    @foreach ($projects as $project)
                                        <option value="{{ $project->id }}" @selected($image->portfolio_project_id === $project->id)>
                                            {{ $project->title }}
                                        </option>
                                    @endforeach
                                </select>
                                <p class="text-xs text-gray-500 mt-1">
                                    Move it to another project if it was filed against the wrong one.
                                </p>
                            </div>

                            <div>
                                <label for="edit-order-{{ $image->id }}" class="{{ $label }}">Sort Order</label>
                                <input type="number" id="edit-order-{{ $image->id }}" name="sort_order" min="0" max="9999"
                                       value="{{ $image->sort_order }}"
                                       class="{{ $input }} max-w-32 text-right tabular-nums">
                                <p class="text-xs text-gray-500 mt-1">Lower numbers appear first in the popup.</p>
                            </div>

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
    /* ---------------------------------------------------------------------
     | Edit dialogs
     * ------------------------------------------------------------------ */
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
    })();

    /* ---------------------------------------------------------------------
     | Preview of the files chosen for upload
     |
     | Read in the browser, so nothing leaves the machine until the form is
     | submitted.
     * ------------------------------------------------------------------ */
    (function () {
        const field = document.getElementById('images');
        const preview = document.getElementById('upload-preview');

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
