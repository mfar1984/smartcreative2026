@extends('layouts.admin')

@section('title', 'Analytic Reporting')

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>Event</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">Analytic Reporting</span>
@endsection

@section('content')
    @php
        $accents = [
            'blue' => 'border-blue-500 bg-blue-500',
            'green' => 'border-green-500 bg-green-500',
            'purple' => 'border-purple-500 bg-purple-500',
            'amber' => 'border-amber-500 bg-amber-500',
        ];

        $lifecycleTones = ['upcoming' => 'blue', 'ongoing' => 'green', 'completed' => 'gray', 'cancelled' => 'red'];
    @endphp

    <x-admin.page-card
        title="Analytic Reporting"
        description="Figures drawn straight from the event and enquiry records.">

        <x-admin.section-intro
            title="Overview"
            description="Every number here is counted live from the database, not stored or cached."
            icon="activity" />

        {{-- Summary cards --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-5">
            @foreach ($summary as $card)
                @php $accent = $accents[$card['accent']] ?? $accents['blue']; @endphp

                <div class="bg-white rounded-lg border border-gray-200 border-t-4 {{ Str::before($accent, ' ') }} p-5">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <span class="block text-xs text-gray-500 uppercase tracking-wide mb-1">{{ $card['label'] }}</span>
                            <span class="block text-2xl font-bold text-gray-900">{{ number_format($card['value']) }}</span>
                            <span class="block text-xs text-gray-500 mt-1">{{ $card['note'] }}</span>
                        </div>
                        <span class="{{ Str::after($accent, ' ') }} p-2.5 rounded-lg shrink-0" aria-hidden="true">
                            <x-admin.icon :name="$card['icon']" class="w-5 h-5 text-white" />
                        </span>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
            {{-- By lifecycle --}}
            <x-admin.panel title="Events by Stage" icon="activity">
                @forelse ($byLifecycle as $stage => $total)
                    <x-admin.field-row :label="ucfirst($stage)">
                        <div class="md:pt-1.5 flex items-center gap-3">
                            <x-admin.badge :tone="$lifecycleTones[$stage] ?? 'gray'" :dot="true">{{ $total }}</x-admin.badge>
                            <span class="text-xs text-gray-500">
                                {{ $total }} {{ Str::plural('event', $total) }}
                            </span>
                        </div>
                    </x-admin.field-row>
                @empty
                    <p class="px-5 py-10 text-sm text-gray-500 text-center">No events recorded yet.</p>
                @endforelse
            </x-admin.panel>

            {{-- By category --}}
            <x-admin.panel title="Seats by Category" icon="users" :flush="true">
                @if ($byCategory->isEmpty())
                    <p class="px-5 py-10 text-sm text-gray-500 text-center">No events recorded yet.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50 text-left">
                                <tr>
                                    <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Category</th>
                                    <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Events</th>
                                    <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Seats</th>
                                    <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Entries</th>
                                    <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Fees</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($byCategory as $category => $row)
                                    <tr>
                                        <td class="px-5 py-3 text-gray-900">{{ $category }}</td>
                                        <td class="px-5 py-3 text-gray-600">{{ $row['events'] }}</td>
                                        <td class="px-5 py-3 text-gray-600 whitespace-nowrap">
                                            {{ $row['seats_taken'] }} / {{ $row['seats_total'] }}
                                        </td>
                                        <td class="px-5 py-3 text-gray-600 whitespace-nowrap">{{ $row['registrations'] }}</td>
                                        <td class="px-5 py-3 text-gray-600 whitespace-nowrap">
                                            RM {{ number_format($row['revenue'], 2) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-admin.panel>
        </div>

        {{-- Per event breakdown --}}
        <x-admin.panel title="Per Event" icon="clipboard" :flush="true" class="mt-5">
            @if ($events->isEmpty())
                <p class="px-5 py-10 text-sm text-gray-500 text-center">No events recorded yet.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-left">
                            <tr>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Event</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Starts</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Stage</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Seats</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Filled</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Fees</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($events as $event)
                                <tr class="hover:bg-blue-50/40">
                                    <td class="px-5 py-3">
                                        <span class="block text-gray-900">{{ $event->title }}</span>
                                        <span class="block text-xs text-gray-400">{{ $event->category }}</span>
                                    </td>
                                    <td class="px-5 py-3 text-xs text-gray-600 whitespace-nowrap">{{ $event->starts_at->format('d M Y') }}</td>
                                    <td class="px-5 py-3 whitespace-nowrap">
                                        <x-admin.badge :tone="$lifecycleTones[$event->lifecycle()] ?? 'gray'" :dot="true">
                                            {{ ucfirst($event->lifecycle()) }}
                                        </x-admin.badge>
                                    </td>
                                    <td class="px-5 py-3 text-gray-600 whitespace-nowrap">{{ $event->seats_taken }} / {{ $event->seats_total }}</td>
                                    <td class="px-5 py-3 whitespace-nowrap">
                                        <div class="flex items-center gap-2">
                                            <span class="w-24 h-1.5 bg-gray-200 rounded-full overflow-hidden" aria-hidden="true">
                                                <span class="block h-full bg-blue-600 rounded-full" style="width: {{ $event->filledPercent() }}%"></span>
                                            </span>
                                            <span class="text-xs text-gray-500">{{ $event->filledPercent() }}%</span>
                                        </div>
                                    </td>
                                    <td class="px-5 py-3 text-gray-600 whitespace-nowrap">
                                        RM {{ number_format($event->registrationAmount() * $event->registrations_count, 2) }}
                                        <span class="block text-xs text-gray-400">
                                            {{ $event->registrations_count }} &times; {{ $event->feeLabel() }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-admin.panel>

        <div role="note" class="flex items-start gap-3 bg-blue-50 border border-blue-200 rounded-lg p-4 mt-5">
            <svg class="w-5 h-5 shrink-0 text-blue-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <p class="text-sm text-blue-800">
                Fees are the event price multiplied by the number of registrations, because the
                price is charged once per registration rather than per head. They are an indication
                of expected income, not confirmed payments, because payment collection is not
                switched on yet. Attendance figures will appear here once attendance has a data model.
            </p>
        </div>
    </x-admin.page-card>
@endsection
