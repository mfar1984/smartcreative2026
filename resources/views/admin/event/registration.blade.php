@extends('layouts.admin')

@section('title', 'Registration')

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>Event</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">Registration</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>{{ $tabs[$activeTab]['label'] }}</span>
@endsection

@section('content')
    @php
        $filterInput = 'rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 bg-white focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';

        $statusTones = [
            \App\Models\Event::STATUS_DRAFT => 'gray',
            \App\Models\Event::STATUS_OPEN => 'green',
            \App\Models\Event::STATUS_CLOSING_SOON => 'amber',
            \App\Models\Event::STATUS_FULL => 'red',
            \App\Models\Event::STATUS_CLOSED => 'gray',
            \App\Models\Event::STATUS_CANCELLED => 'red',
        ];

        $lifecycleTones = ['upcoming' => 'blue', 'ongoing' => 'green', 'completed' => 'gray', 'cancelled' => 'red'];

        $intro = match ($activeTab) {
            'ongoing' => ['title' => 'Ongoing Events', 'description' => 'Events running today, worked out from their start and end dates.', 'icon' => 'activity', 'accent' => 'green'],
            'completed' => ['title' => 'Completed Events', 'description' => 'Events whose end date has passed.', 'icon' => 'shield', 'accent' => 'purple'],
            'cancel' => ['title' => 'Cancelled Events', 'description' => 'Events marked as cancelled. They are hidden from the public site.', 'icon' => 'power', 'accent' => 'amber'],
            default => ['title' => 'Register Event', 'description' => 'Events that are still upcoming and not cancelled. This is the working list.', 'icon' => 'clipboard', 'accent' => 'blue'],
        };
    @endphp

    <x-admin.settings-shell
        title="Registration"
        description="Set up event registration and follow each event through its lifecycle."
        :tabs="$tabs"
        :active-tab="$activeTab"
        route="admin.event.registration">

        <div class="flex flex-wrap items-start justify-between gap-4 mb-5">
            <x-admin.section-intro
                :title="$intro['title']"
                :description="$intro['description']"
                :icon="$intro['icon']"
                :accent="$intro['accent']"
                class="mb-0" />

            @if ($canCreate)
                <a href="{{ route('admin.event.registration.create') }}"
                   class="inline-flex items-center gap-2 bg-blue-600 text-white px-4 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-sm shrink-0">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    Add Registration Event
                </a>
            @endif
        </div>

        <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
            <x-admin.filter-bar
                :action="route('admin.event.registration')"
                :reset="$isFiltered ? route('admin.event.registration', ['tab' => $activeTab]) : null">

                <input type="hidden" name="tab" value="{{ $activeTab }}">

                <div class="relative flex-1 min-w-56">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" aria-hidden="true">
                        <x-admin.icon name="search" class="w-4 h-4" />
                    </span>
                    <label for="q" class="sr-only">Search events</label>
                    <input type="search" id="q" name="q" value="{{ $search }}"
                           placeholder="Search title, slug or location..."
                           class="w-full rounded-lg border border-gray-300 pl-9 pr-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
                </div>

                <label for="category" class="sr-only">Category</label>
                <select id="category" name="category" class="{{ $filterInput }}">
                    <option value="">All Categories</option>
                    @foreach ($categories as $option)
                        <option value="{{ $option }}" @selected($category === $option)>{{ $option }}</option>
                    @endforeach
                </select>
            </x-admin.filter-bar>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-left">
                        <tr>
                            <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500 w-12">#</th>
                            <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Event</th>
                            <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Category</th>
                            <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Dates</th>
                            <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Location</th>
                            <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Seats</th>
                            <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Fee</th>
                            <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Status</th>
                            <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Stage</th>
                            <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Entries</th>
                            <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500 text-center">Actions</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-gray-100">
                        @forelse ($events as $index => $event)
                            <tr class="hover:bg-blue-50/40">
                                <td class="px-5 py-3 text-gray-500">{{ $events->firstItem() + $index }}</td>

                                <td class="px-5 py-3">
                                    <span class="block font-semibold text-gray-900">{{ $event->title }}</span>
                                    <code class="block text-xs text-gray-400">{{ $event->slug }}</code>
                                </td>

                                <td class="px-5 py-3 whitespace-nowrap text-gray-700">{{ $event->category }}</td>

                                <td class="px-5 py-3 whitespace-nowrap text-xs text-gray-600">
                                    {{ $event->starts_at->format('d M Y') }}
                                    @unless ($event->starts_at->isSameDay($event->ends_at))
                                        <span class="block text-gray-400">to {{ $event->ends_at->format('d M Y') }}</span>
                                    @endunless
                                    @if ($event->time)
                                        <span class="block text-gray-400">{{ $event->time }}</span>
                                    @endif
                                </td>

                                <td class="px-5 py-3 text-gray-600">{{ $event->location ?: '—' }}</td>

                                <td class="px-5 py-3 whitespace-nowrap">
                                    <span class="text-gray-900">{{ $event->seats_taken }} / {{ $event->seats_total }}</span>
                                    <span class="block text-xs text-gray-400">
                                        {{ $event->filledPercent() }}% filled &middot; {{ $event->seatBasisLabel() }}
                                    </span>
                                </td>

                                <td class="px-5 py-3 whitespace-nowrap text-gray-700">
                                    {{ $event->fee === null ? 'Free' : 'RM ' . number_format((float) $event->fee, 2) }}
                                </td>

                                <td class="px-5 py-3 whitespace-nowrap">
                                    <x-admin.badge :tone="$statusTones[$event->status] ?? 'gray'">{{ $event->statusLabel() }}</x-admin.badge>
                                </td>

                                <td class="px-5 py-3 whitespace-nowrap">
                                    <x-admin.badge :tone="$lifecycleTones[$event->lifecycle()] ?? 'gray'" :dot="true">
                                        {{ ucfirst($event->lifecycle()) }}
                                    </x-admin.badge>
                                </td>

                                <td class="px-5 py-3 whitespace-nowrap text-gray-700">
                                    {{ $event->registrations_count }}
                                </td>

                                <td class="px-5 py-3 whitespace-nowrap">
                                    <div class="flex items-center justify-center gap-1">
                                        <a href="{{ route('admin.event.registration.show', $event) }}"
                                           class="p-1.5 rounded-lg text-blue-600 hover:bg-blue-50 transition"
                                           title="View {{ $event->title }}" aria-label="View {{ $event->title }}">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                            </svg>
                                        </a>

                                        @if ($canUpdate)
                                            <a href="{{ route('admin.event.registration.edit', $event) }}"
                                               class="p-1.5 rounded-lg text-amber-600 hover:bg-amber-50 transition"
                                               title="Edit {{ $event->title }}" aria-label="Edit {{ $event->title }}">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                                </svg>
                                            </a>
                                        @endif

                                        @if ($canDelete && $event->registrations_count === 0)
                                            <form action="{{ route('admin.event.registration.destroy', $event) }}" method="POST"
                                                  onsubmit="return confirm('Delete {{ addslashes($event->title) }}? This cannot be undone.');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit"
                                                        class="p-1.5 rounded-lg text-red-600 hover:bg-red-50 transition"
                                                        title="Delete {{ $event->title }}" aria-label="Delete {{ $event->title }}">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                                    </svg>
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="11" class="px-5 py-12 text-center text-sm text-gray-500">
                                    @if ($isFiltered)
                                        No events match the current filters.
                                    @else
                                        No events in this stage.
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="px-5 py-3.5 border-t border-gray-200">
                @if ($events->hasPages())
                    {{ $events->links() }}
                @else
                    <p class="text-xs text-gray-500">
                        Showing {{ $events->total() }} {{ Str::plural('event', $events->total()) }}
                    </p>
                @endif
            </div>
        </div>
    </x-admin.settings-shell>
@endsection
