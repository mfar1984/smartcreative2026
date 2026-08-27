@extends('layouts.admin')

@section('title', 'Tournaments')

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>Tournament</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">{{ $intro['label'] }}</span>
@endsection

@section('content')
    @php
        use App\Models\Tournament;

        $head = 'px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500';
        $filterInput = 'rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 bg-white focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';

        $statusTones = [
            Tournament::STATUS_SETUP => 'amber',
            Tournament::STATUS_ONGOING => 'blue',
            Tournament::STATUS_COMPLETED => 'green',
            Tournament::STATUS_PUBLISHED => 'purple',
        ];
    @endphp

    <x-admin.settings-shell
        title="Tournaments"
        description="Every tournament, the format it runs, and the stage it has reached."
        :tabs="$tabs"
        :active-tab="$activeTab"
        :route="$route">

        @if ($errors->any())
            <div role="alert" class="bg-red-50 border border-red-200 rounded-lg p-4 mb-5">
                <ul class="text-sm text-red-800 space-y-0.5">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="flex flex-wrap items-start justify-between gap-4 mb-5">
            <x-admin.section-intro
                :title="$intro['title']"
                :description="$intro['description']"
                :icon="$intro['icon']"
                :accent="$intro['accent']"
                class="mb-0" />

            {{-- Said across every status, because running more than one at a time is
                 the point of the module and the operator should see it stated. --}}
            <div class="flex flex-wrap items-center gap-3 shrink-0">
                <div class="inline-flex items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-3.5 py-2.5">
                    <span class="text-xs font-semibold uppercase tracking-wide text-blue-700">Running Now</span>
                    <span class="text-sm font-bold text-blue-900 tabular-nums">{{ $ongoingTotal }}</span>
                </div>

                @if ($canCreate)
                    <a href="{{ route('admin.tournaments.create') }}"
                       class="inline-flex items-center gap-2 rounded-lg border border-blue-600 bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition shadow-sm">
                        <x-admin.icon name="plus" class="w-4 h-4" />
                        New Tournament
                    </a>
                @endif
            </div>
        </div>

        <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
            <x-admin.filter-bar
                :action="route('admin.tournaments.index')"
                :reset="$isFiltered ? route('admin.tournaments.index', ['tab' => $activeTab]) : null">

                <input type="hidden" name="tab" value="{{ $activeTab }}">

                <div class="relative flex-1 min-w-56">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" aria-hidden="true">
                        <x-admin.icon name="search" class="w-4 h-4" />
                    </span>
                    <label for="q" class="sr-only">Search tournaments</label>
                    <input type="search" id="q" name="q" value="{{ $filters['search'] }}"
                           placeholder="Search by name..."
                           class="w-full rounded-lg border border-gray-300 pl-9 pr-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
                </div>

                <label for="event" class="sr-only">Event</label>
                <select id="event" name="event" class="{{ $filterInput }}">
                    <option value="">All Events</option>
                    @foreach ($events as $id => $title)
                        <option value="{{ $id }}" @selected((string) $filters['eventId'] === (string) $id)>{{ $title }}</option>
                    @endforeach
                </select>
            </x-admin.filter-bar>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-left">
                        <tr>
                            <th scope="col" class="{{ $head }}">Tournament</th>
                            <th scope="col" class="{{ $head }}">Event</th>
                            <th scope="col" class="{{ $head }}">Format</th>
                            <th scope="col" class="{{ $head }}">Scoring</th>
                            <th scope="col" class="{{ $head }} text-right">Entrants</th>
                            <th scope="col" class="{{ $head }}">Status</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-gray-100">
                        @forelse ($tournaments as $tournament)
                            <tr class="hover:bg-blue-50/40 align-top">
                                <td class="px-5 py-3">
                                    <a href="{{ route('admin.tournaments.show', $tournament) }}"
                                       class="font-semibold text-blue-600 hover:underline">{{ $tournament->name }}</a>
                                    <span class="block text-xs text-gray-400 mt-0.5">
                                        by {{ $tournament->creator?->name ?? 'a removed account' }}
                                    </span>
                                </td>

                                <td class="px-5 py-3 text-gray-700">{{ $tournament->event?->title ?? '—' }}</td>

                                <td class="px-5 py-3 text-gray-700">{{ $tournament->formatLabel() }}</td>

                                <td class="px-5 py-3 text-xs text-gray-600">{{ $tournament->pointRule?->name ?? '—' }}</td>

                                <td class="px-5 py-3 text-right tabular-nums text-gray-900">
                                    {{ $tournament->entrants_count }}
                                </td>

                                <td class="px-5 py-3 whitespace-nowrap">
                                    <x-admin.badge :tone="$statusTones[$tournament->status] ?? 'gray'" dot>
                                        {{ $tournament->statusLabel() }}
                                    </x-admin.badge>

                                    @unless ($tournament->hasDraw())
                                        <span class="block text-xs text-amber-700 mt-0.5">No draw yet</span>
                                    @endunless
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-12 text-center">
                                    <x-admin.icon name="trophy" class="w-10 h-10 mx-auto text-gray-300" />
                                    <p class="text-sm font-semibold text-gray-700 mt-3">
                                        {{ $isFiltered ? 'Nothing matches these filters' : 'No ' . strtolower($intro['label']) . ' tournaments' }}
                                    </p>
                                    <p class="text-sm text-gray-500 mt-1 max-w-lg mx-auto">
                                        @if ($isFiltered)
                                            Try a different search, or clear the filters.
                                        @else
                                            A tournament is built on top of an event's paid entries. More
                                            than one can run at the same time, on the same event or
                                            different ones.
                                        @endif
                                    </p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="px-5 py-3.5 border-t border-gray-200">
                @if ($tournaments->hasPages())
                    {{ $tournaments->links() }}
                @else
                    <p class="text-xs text-gray-500">
                        {{ $tournaments->total() }} {{ Str::plural('tournament', $tournaments->total()) }}
                    </p>
                @endif
            </div>
        </div>
    </x-admin.settings-shell>
@endsection
