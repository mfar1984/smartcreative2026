@extends('layouts.admin')

@section('title', 'Logging')

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>Settings</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">Logging</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>{{ $tabs[$activeTab]['label'] }}</span>
@endsection

@section('content')
    @php
        $filterInput = 'rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 bg-white focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';

        // Level and event chips use a fixed tone map, written out so Tailwind
        // finds the classes when scanning.
        $levelTones = ['info' => 'blue', 'warn' => 'amber', 'error' => 'red', 'debug' => 'gray'];

        $eventTone = function (string $event): string {
            return match (true) {
                str_contains($event, 'created') => 'green',
                str_contains($event, 'deleted') => 'red',
                str_contains($event, 'updated') => 'blue',
                default => 'gray',
            };
        };

        // Preserve the current tab and unrelated filters when a chip is clicked.
        $chipUrl = function (array $overrides) use ($activeTab, $filters) {
            return route('admin.settings.logging', array_merge(
                ['tab' => $activeTab],
                array_filter($filters, fn ($value) => $value !== null && $value !== ''),
                $overrides,
            ));
        };
    @endphp

    <x-admin.settings-shell
        title="Logging"
        description="Monitor system activity, user actions and the security audit trail."
        :tabs="$tabs"
        :active-tab="$activeTab"
        route="admin.settings.logging">

        {{-- ==================== Activity Logging ==================== --}}
        @if ($activeTab === 'activity')
            <x-admin.section-intro
                title="Activity Logging"
                description="Every action taken by a person in the admin area, newest first."
                icon="activity" />

            {{-- Level chips --}}
            <div class="flex flex-wrap items-center gap-2 mb-4">
                <a href="{{ $chipUrl(['level' => null]) }}"
                   @class([
                       'inline-flex items-center gap-2 rounded-full border px-3.5 py-1.5 text-xs font-semibold transition',
                       'border-blue-600 bg-blue-50 text-blue-700' => $filters['level'] === null,
                       'border-gray-300 text-gray-600 hover:bg-gray-50' => $filters['level'] !== null,
                   ])>
                    All Levels
                    <span class="text-gray-400">{{ array_sum($levelCounts) }}</span>
                </a>

                @foreach ($levels as $levelKey => $levelLabel)
                    <a href="{{ $chipUrl(['level' => $levelKey]) }}"
                       @class([
                           'inline-flex items-center gap-2 rounded-full border px-3.5 py-1.5 text-xs font-semibold uppercase tracking-wide transition',
                           'border-blue-600 bg-blue-50 text-blue-700' => $filters['level'] === $levelKey,
                           'border-gray-300 text-gray-600 hover:bg-gray-50' => $filters['level'] !== $levelKey,
                       ])>
                        <x-admin.badge :tone="$levelTones[$levelKey] ?? 'gray'" :dot="true">{{ $levelLabel }}</x-admin.badge>
                        <span class="text-gray-400">{{ $levelCounts[$levelKey] ?? 0 }}</span>
                    </a>
                @endforeach
            </div>

            <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                <x-admin.filter-bar
                    :action="route('admin.settings.logging')"
                    :reset="$isFiltered ? route('admin.settings.logging', ['tab' => 'activity']) : null">

                    <input type="hidden" name="tab" value="activity">
                    @if ($filters['level'])
                        <input type="hidden" name="level" value="{{ $filters['level'] }}">
                    @endif

                    <div class="relative flex-1 min-w-56">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" aria-hidden="true">
                            <x-admin.icon name="search" class="w-4 h-4" />
                        </span>
                        <label for="q" class="sr-only">Search activity</label>
                        <input type="search" id="q" name="q" value="{{ $filters['q'] }}"
                               placeholder="Search message, action, user or IP..."
                               class="w-full rounded-lg border border-gray-300 pl-9 pr-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
                    </div>

                    <label for="category" class="sr-only">Category</label>
                    <select id="category" name="category" class="{{ $filterInput }}">
                        <option value="">All Categories</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category }}" @selected($filters['category'] === $category)>{{ $category }}</option>
                        @endforeach
                    </select>

                    <label for="actor" class="sr-only">User</label>
                    <select id="actor" name="actor" class="{{ $filterInput }}">
                        <option value="">All Users</option>
                        @foreach ($actors as $actor)
                            <option value="{{ $actor }}" @selected($filters['actor'] === $actor)>{{ $actor }}</option>
                        @endforeach
                    </select>

                    <label for="from" class="sr-only">From date</label>
                    <input type="date" id="from" name="from" value="{{ $filters['from'] }}" class="{{ $filterInput }}">

                    <label for="to" class="sr-only">To date</label>
                    <input type="date" id="to" name="to" value="{{ $filters['to'] }}" class="{{ $filterInput }}">
                </x-admin.filter-bar>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-left">
                            <tr>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Timestamp</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Level</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Category</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Message</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">User</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">IP</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-100">
                            @forelse ($activityEntries as $entry)
                                <tr class="hover:bg-blue-50/40">
                                    <td class="px-5 py-3 text-xs text-gray-600 whitespace-nowrap">
                                        {{ $entry->created_at?->format('d M Y') }}
                                        <span class="block text-gray-400">{{ $entry->created_at?->format('g:i:s a') }}</span>
                                    </td>
                                    <td class="px-5 py-3 whitespace-nowrap">
                                        <x-admin.badge :tone="$levelTones[$entry->level] ?? 'gray'">{{ strtoupper($entry->level) }}</x-admin.badge>
                                    </td>
                                    <td class="px-5 py-3 whitespace-nowrap text-gray-700">{{ $entry->category }}</td>
                                    <td class="px-5 py-3 text-gray-900">
                                        {{ $entry->description }}
                                        <code class="block text-xs text-gray-400">{{ $entry->action }}</code>
                                    </td>
                                    <td class="px-5 py-3 text-xs text-gray-600">{{ $entry->actor_label ?? 'System' }}</td>
                                    <td class="px-5 py-3 text-xs text-gray-500 whitespace-nowrap">{{ $entry->ip_address ?: '—' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-5 py-12 text-center text-sm text-gray-500">
                                        @if ($isFiltered)
                                            No activity matches the current filters.
                                        @else
                                            No activity recorded yet.
                                        @endif
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="px-5 py-3.5 border-t border-gray-200">
                    @if ($activityEntries->hasPages())
                        {{ $activityEntries->links() }}
                    @else
                        <p class="text-xs text-gray-500">
                            Showing {{ $activityEntries->total() }} {{ Str::plural('entry', $activityEntries->total()) }}
                        </p>
                    @endif
                </div>
            </div>
        @endif

        {{-- ==================== Audit Log ==================== --}}
        @if ($activeTab === 'audit')
            <x-admin.section-intro
                title="Audit Log"
                description="Record level changes, with the values before and after."
                icon="clipboard"
                accent="purple" />

            <div role="note" class="flex items-start gap-3 bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4">
                <svg class="w-5 h-5 shrink-0 text-blue-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                </svg>
                <p class="text-sm text-blue-800">
                    The audit log records every change made through the admin area. Entries are
                    append only and cannot be edited or removed from this screen.
                    Credentials and secrets are stored as <code class="font-mono text-xs">[redacted]</code>.
                </p>
            </div>

            {{-- Event chips --}}
            <div class="flex flex-wrap items-center gap-2 mb-4">
                <a href="{{ $chipUrl(['event' => null]) }}"
                   @class([
                       'inline-flex items-center gap-2 rounded-full border px-3.5 py-1.5 text-xs font-semibold transition',
                       'border-blue-600 bg-blue-50 text-blue-700' => $filters['event'] === null,
                       'border-gray-300 text-gray-600 hover:bg-gray-50' => $filters['event'] !== null,
                   ])>
                    All Actions
                    <span class="text-gray-400">{{ array_sum($eventCounts) }}</span>
                </a>

                @foreach ($eventCounts as $event => $count)
                    <a href="{{ $chipUrl(['event' => $event]) }}"
                       @class([
                           'inline-flex items-center gap-2 rounded-full border px-3.5 py-1.5 text-xs font-semibold transition',
                           'border-blue-600 bg-blue-50 text-blue-700' => $filters['event'] === $event,
                           'border-gray-300 text-gray-600 hover:bg-gray-50' => $filters['event'] !== $event,
                       ])>
                        <x-admin.badge :tone="$eventTone($event)" :dot="true">{{ $event }}</x-admin.badge>
                        <span class="text-gray-400">{{ $count }}</span>
                    </a>
                @endforeach
            </div>

            <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                <x-admin.filter-bar
                    :action="route('admin.settings.logging')"
                    :reset="$isFiltered ? route('admin.settings.logging', ['tab' => 'audit']) : null">

                    <input type="hidden" name="tab" value="audit">
                    @if ($filters['event'])
                        <input type="hidden" name="event" value="{{ $filters['event'] }}">
                    @endif

                    <div class="relative flex-1 min-w-56">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" aria-hidden="true">
                            <x-admin.icon name="search" class="w-4 h-4" />
                        </span>
                        <label for="q" class="sr-only">Search audit log</label>
                        <input type="search" id="q" name="q" value="{{ $filters['q'] }}"
                               placeholder="Search event, record, user or IP..."
                               class="w-full rounded-lg border border-gray-300 pl-9 pr-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
                    </div>

                    <label for="actor" class="sr-only">User</label>
                    <select id="actor" name="actor" class="{{ $filterInput }}">
                        <option value="">All Users</option>
                        @foreach ($actors as $actor)
                            <option value="{{ $actor }}" @selected($filters['actor'] === $actor)>{{ $actor }}</option>
                        @endforeach
                    </select>

                    <label for="from" class="sr-only">From date</label>
                    <input type="date" id="from" name="from" value="{{ $filters['from'] }}" class="{{ $filterInput }}">

                    <label for="to" class="sr-only">To date</label>
                    <input type="date" id="to" name="to" value="{{ $filters['to'] }}" class="{{ $filterInput }}">
                </x-admin.filter-bar>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-left">
                            <tr>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Timestamp</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Action</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Module</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Target</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">User</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Role</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Before</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">After</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">IP</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-100">
                            @forelse ($auditEntries as $entry)
                                <tr class="hover:bg-blue-50/40 align-top">
                                    <td class="px-5 py-3 text-xs text-gray-600 whitespace-nowrap">
                                        {{ $entry->created_at?->format('d M Y') }}
                                        <span class="block text-gray-400">{{ $entry->created_at?->format('g:i:s a') }}</span>
                                    </td>
                                    <td class="px-5 py-3 whitespace-nowrap">
                                        <x-admin.badge :tone="$eventTone($entry->event)">{{ $entry->event }}</x-admin.badge>
                                    </td>
                                    <td class="px-5 py-3 whitespace-nowrap text-gray-700">{{ $entry->auditableLabel() }}</td>
                                    <td class="px-5 py-3 whitespace-nowrap text-xs text-gray-500">
                                        {{ $entry->auditable_id ? '#' . $entry->auditable_id : '—' }}
                                    </td>
                                    <td class="px-5 py-3 text-xs text-gray-600">{{ $entry->actor_label ?? 'System' }}</td>
                                    <td class="px-5 py-3 whitespace-nowrap text-xs text-gray-500">{{ $entry->actor_role ?: '—' }}</td>

                                    @foreach (['old_values', 'new_values'] as $column)
                                        <td class="px-5 py-3">
                                            @php $values = array_filter($entry->{$column} ?? [], fn ($value) => $value !== null); @endphp

                                            @if ($values === [])
                                                <span class="text-xs text-gray-400">—</span>
                                            @else
                                                <dl class="space-y-0.5">
                                                    @foreach ($values as $key => $value)
                                                        <div class="flex gap-1.5 text-xs">
                                                            <dt class="text-gray-500 shrink-0">{{ $key }}:</dt>
                                                            <dd class="text-gray-800 break-all">
                                                                {{ is_scalar($value) ? (string) $value : json_encode($value) }}
                                                            </dd>
                                                        </div>
                                                    @endforeach
                                                </dl>
                                            @endif
                                        </td>
                                    @endforeach

                                    <td class="px-5 py-3 text-xs text-gray-500 whitespace-nowrap">{{ $entry->ip_address ?: '—' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="px-5 py-12 text-center text-sm text-gray-500">
                                        @if ($isFiltered)
                                            No audit entries match the current filters.
                                        @else
                                            No record changes logged yet.
                                        @endif
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="px-5 py-3.5 border-t border-gray-200">
                    @if ($auditEntries->hasPages())
                        {{ $auditEntries->links() }}
                    @else
                        <p class="text-xs text-gray-500">
                            Showing {{ $auditEntries->total() }} {{ Str::plural('entry', $auditEntries->total()) }}
                        </p>
                    @endif
                </div>
            </div>
        @endif
    </x-admin.settings-shell>
@endsection
