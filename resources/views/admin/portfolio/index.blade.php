@extends('layouts.admin')

@php
    $head = 'px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500';
    $select = 'rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';
@endphp

@section('title', 'Portfolio Projects')

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>Portfolio</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">Projects</span>
@endsection

@section('content')
    <x-admin.page-card
        title="Portfolio Projects"
        description="Work shown on the public Portfolio page. Only published entries are visible to visitors."
        :flush="true">

        <x-slot:actions>
            <a href="{{ route('portfolio') }}" target="_blank" rel="noopener"
               class="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                <x-admin.icon name="eye" class="w-4 h-4" />
                View public page
            </a>

            @if ($canCreate)
                <a href="{{ route('admin.portfolio.create') }}"
                   class="inline-flex items-center gap-2 bg-blue-600 text-white px-5 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-sm">
                    <x-admin.icon name="plus" class="w-4 h-4" />
                    Add Project
                </a>
            @endif
        </x-slot:actions>

        <x-admin.filter-bar
            :action="route('admin.portfolio.index')"
            :reset="$isFiltered ? route('admin.portfolio.index') : null">

            <div class="relative flex-1 min-w-56">
                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" aria-hidden="true">
                    <x-admin.icon name="search" class="w-4 h-4" />
                </span>
                <label for="q" class="sr-only">Search projects</label>
                <input type="search" id="q" name="q" value="{{ $search }}"
                       placeholder="Title, client or category..."
                       class="w-full rounded-lg border border-gray-300 pl-9 pr-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
            </div>

            <label for="service" class="sr-only">Service</label>
            <select id="service" name="service" class="{{ $select }}">
                <option value="">All services</option>
                @foreach ($services as $slug => $label)
                    <option value="{{ $slug }}" @selected($service === $slug)>{{ $label }}</option>
                @endforeach
            </select>

            <label for="status" class="sr-only">Status</label>
            <select id="status" name="status" class="{{ $select }}">
                <option value="">All statuses</option>
                @foreach ($statuses as $slug => $label)
                    <option value="{{ $slug }}" @selected($status === $slug)>{{ $label }}</option>
                @endforeach
            </select>
        </x-admin.filter-bar>

        {{-- A count of what the public can actually see. The table shows drafts too,
             so the total row count does not answer "is anything live yet". --}}
        <div class="flex flex-wrap items-center justify-between gap-3 px-6 py-2.5 bg-gray-50 border-b border-gray-200">
            <p class="text-xs text-gray-500">
                {{ $projects->total() }} {{ Str::plural('project', $projects->total()) }} in total
            </p>
            <p class="text-xs text-gray-500">
                <span class="font-semibold text-gray-700">{{ $publishedCount }}</span>
                live on the public page
            </p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left">
                    <tr>
                        <th scope="col" class="{{ $head }}">Project</th>
                        <th scope="col" class="{{ $head }}">Service</th>
                        <th scope="col" class="{{ $head }}">Delivered</th>
                        <th scope="col" class="{{ $head }} text-center">Image</th>
                        <th scope="col" class="{{ $head }} text-center">Status</th>
                        @if ($canUpdate || $canDelete)
                            <th scope="col" class="{{ $head }} text-center">Actions</th>
                        @endif
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-100">
                    @forelse ($projects as $project)
                        <tr class="hover:bg-blue-50/40 align-top">
                            <td class="px-5 py-3">
                                @if ($canUpdate)
                                    <a href="{{ route('admin.portfolio.edit', $project) }}"
                                       class="font-semibold text-blue-600 hover:underline">{{ $project->title }}</a>
                                @else
                                    <span class="font-semibold text-gray-900">{{ $project->title }}</span>
                                @endif

                                @if ($project->is_featured)
                                    <span class="ml-1.5 align-middle" title="Featured, shown first">
                                        <x-admin.badge tone="amber">Featured</x-admin.badge>
                                    </span>
                                @endif

                                <span class="block text-xs text-gray-500 mt-0.5">
                                    {{ $project->clientLabel() }} &middot; {{ $project->category }}
                                </span>
                            </td>

                            <td class="px-5 py-3 text-gray-600 whitespace-nowrap">
                                {{ $project->serviceLabel() }}
                            </td>

                            <td class="px-5 py-3 text-gray-600 whitespace-nowrap tabular-nums">
                                {{ $project->deliveredLabel() }}
                            </td>

                            <td class="px-5 py-3 text-center">
                                @if ($project->imageUrl())
                                    <img src="{{ $project->imageUrl() }}" alt=""
                                         class="w-14 h-10 object-cover rounded border border-gray-200 inline-block">
                                @else
                                    <span class="text-xs text-gray-400">None</span>
                                @endif
                            </td>

                            <td class="px-5 py-3 text-center">
                                <x-admin.badge :tone="$project->isPublished() ? 'green' : 'gray'" :dot="true">
                                    {{ $statuses[$project->status] ?? $project->status }}
                                </x-admin.badge>
                            </td>

                            @if ($canUpdate || $canDelete)
                                <td class="px-5 py-3 whitespace-nowrap">
                                    <div class="flex items-center justify-center gap-1">
                                        @if ($canUpdate)
                                            <a href="{{ route('admin.portfolio.edit', $project) }}"
                                               class="p-1.5 rounded-lg text-amber-600 hover:bg-amber-50 transition"
                                               title="Edit {{ $project->title }}" aria-label="Edit {{ $project->title }}">
                                                <x-admin.icon name="pencil" class="w-4 h-4" />
                                            </a>
                                        @endif

                                        @if ($canDelete)
                                            <form action="{{ route('admin.portfolio.destroy', $project) }}" method="POST"
                                                  onsubmit="return confirm('Delete {{ addslashes($project->title) }}?\n\nThe uploaded image is deleted with it. This cannot be undone.');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit"
                                                        class="p-1.5 rounded-lg text-red-600 hover:bg-red-50 transition"
                                                        title="Delete {{ $project->title }}" aria-label="Delete {{ $project->title }}">
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
                            <td colspan="6" class="px-5 py-12 text-center">
                                <x-admin.icon name="photo" class="w-10 h-10 mx-auto text-gray-300" />

                                <p class="text-sm font-semibold text-gray-700 mt-3">
                                    {{ $isFiltered ? 'Nothing matches those filters' : 'No projects yet' }}
                                </p>

                                <p class="text-sm text-gray-500 mt-1 max-w-md mx-auto">
                                    @if ($isFiltered)
                                        Clear the filters to see everything.
                                    @else
                                        The public Portfolio page stays empty until a project is added and
                                        published. Add the work you would want a new client to see first.
                                    @endif
                                </p>

                                @if ($canCreate && ! $isFiltered)
                                    <a href="{{ route('admin.portfolio.create') }}"
                                       class="inline-flex items-center gap-2 mt-5 bg-blue-600 text-white px-5 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition">
                                        <x-admin.icon name="plus" class="w-4 h-4" />
                                        Add the first project
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="px-5 py-3.5 border-t border-gray-200">
            @if ($projects->hasPages())
                {{ $projects->links() }}
            @else
                <p class="text-xs text-gray-500">
                    Showing {{ $projects->count() }} {{ Str::plural('project', $projects->count()) }}
                </p>
            @endif
        </div>

    </x-admin.page-card>
@endsection
