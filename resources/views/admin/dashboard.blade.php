@extends('layouts.admin')

@section('title', 'Dashboard')

{{-- A breadcrumb rather than a subheading, because the page card below carries the
     title. Both would print the same heading twice. --}}
@section('breadcrumb')
    <span class="font-semibold text-gray-700">Dashboard</span>
@endsection

@section('content')
<x-admin.page-card
    title="Dashboard"
    description="How the events, money and tournaments are doing.">

    <x-slot:actions>
        <span class="text-xs text-gray-500">
            Worked out {{ $generatedAt->diffForHumans() }}
        </span>
    </x-slot:actions>

    {{-- ==================== Headline figures ====================
         Two rows grouped by meaning: money first, then what is happening. A single
         grid holding all five sat as three plus two, and the empty cell read as
         something that had failed to load.

         Only the cards this role may see are present, so a narrower role gets fewer
         cards rather than gaps. --}}
    @foreach (['money' => 'sm:grid-cols-2', 'activity' => 'sm:grid-cols-2 lg:grid-cols-3'] as $group => $columns)
        @if (($cards[$group] ?? collect())->isNotEmpty())
            <div class="grid grid-cols-1 {{ $columns }} gap-4 mb-4">
                @foreach ($cards[$group] as $card)
                    <x-admin.stat-card
                        :label="$card['label']"
                        :value="$card['value']"
                        :note="$card['note']"
                        :accent="$card['accent']"
                        :icon="$card['icon']"
                        :href="$card['href']"
                        :change="$card['change']"
                        :change-note="$card['changeNote']" />
                @endforeach
            </div>
        @endif
    @endforeach

    {{-- ==================== Charts ==================== --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mb-5">

        @if ($can['money'])
            <x-admin.panel title="Money Collected" icon="cash">
                <div class="px-5 py-5">
                    <p class="text-sm text-gray-600 mb-4">
                        Paid entries per day over the last {{ $trendDays }} days. Quiet days are
                        drawn as zero rather than skipped, so a flat week looks flat.
                    </p>

                    <x-admin.chart-area
                        :points="$revenueSeries"
                        tone="green"
                        empty="No payments have been recorded in the last {{ $trendDays }} days." />
                </div>
            </x-admin.panel>
        @endif

        @if ($can['events'])
            <x-admin.panel title="Registrations Taken" icon="clipboard">
                <div class="px-5 py-5">
                    <p class="text-sm text-gray-600 mb-4">
                        Entries received per day over the last {{ $barDays }} days, whether they
                        have been paid for or not.
                    </p>

                    <x-admin.chart-bars
                        :points="$registrationSeries"
                        tone="blue"
                        empty="No registrations have come in over the last {{ $barDays }} days." />
                </div>
            </x-admin.panel>
        @endif
    </div>

    {{-- ==================== Breakdowns ==================== --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mb-5">

        @if ($can['money'])
            <x-admin.panel title="Where Entries Stand" icon="credit-card" :flush="true">
                <div class="px-5 py-4 border-b border-gray-100">
                    <p class="text-sm text-gray-600">
                        Every entry that carries a fee, by payment status. Free entries are left
                        out because they have no payment to be in a state about.
                    </p>
                </div>

                @php $breakdownTotal = collect($paymentBreakdown)->sum('count'); @endphp

                @if ($breakdownTotal === 0)
                    <p class="px-5 py-10 text-sm text-gray-500 text-center">
                        No paid-for entries yet. This fills in as registrations with a fee arrive.
                    </p>
                @else
                    <ul class="divide-y divide-gray-100">
                        @foreach ($paymentBreakdown as $row)
                            @php
                                // Written out in full so Tailwind finds them when scanning.
                                $bar = match ($row['tone']) {
                                    'green' => 'bg-green-500',
                                    'amber' => 'bg-amber-500',
                                    'red' => 'bg-red-500',
                                    'purple' => 'bg-purple-500',
                                    default => 'bg-gray-400',
                                };
                            @endphp

                            <li class="px-5 py-3">
                                <div class="flex items-baseline justify-between gap-3 mb-1.5">
                                    <span class="text-sm text-gray-900">{{ $row['label'] }}</span>
                                    <span class="text-sm text-gray-600 tabular-nums shrink-0">
                                        <span class="font-bold text-gray-900">{{ number_format($row['count']) }}</span>
                                        <span class="text-xs text-gray-400">{{ $row['share'] }}%</span>
                                    </span>
                                </div>

                                <div class="h-1.5 rounded-full bg-gray-100 overflow-hidden">
                                    <div class="h-full rounded-full {{ $bar }}"
                                         style="width: {{ $row['share'] }}%"
                                         role="img"
                                         aria-label="{{ $row['label'] }}: {{ $row['count'] }} entries, {{ $row['share'] }} per cent"></div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-admin.panel>
        @endif

        @if ($can['money'])
            <x-admin.panel title="Best Earning Events" icon="trophy" :flush="true">
                <div class="px-5 py-4 border-b border-gray-100">
                    <p class="text-sm text-gray-600">
                        Bars are drawn against the biggest earner, not against everything, so the
                        smaller events stay readable.
                    </p>
                </div>

                @if ($topEvents === [])
                    <p class="px-5 py-10 text-sm text-gray-500 text-center">
                        Nothing collected yet. An event appears here once one of its entries is paid.
                    </p>
                @else
                    <ul class="divide-y divide-gray-100">
                        @foreach ($topEvents as $row)
                            <li class="px-5 py-3">
                                <div class="flex items-baseline justify-between gap-3 mb-1.5">
                                    <span class="text-sm text-gray-900 truncate">{{ $row['event'] }}</span>
                                    <span class="text-sm font-bold text-gray-900 tabular-nums shrink-0">
                                        {{ App\Support\PaymentFigures::money($row['collected']) }}
                                    </span>
                                </div>

                                <div class="h-1.5 rounded-full bg-gray-100 overflow-hidden mb-1">
                                    <div class="h-full rounded-full bg-green-500" style="width: {{ $row['share'] }}%"></div>
                                </div>

                                <p class="text-xs text-gray-500">
                                    {{ number_format($row['count']) }} {{ Str::plural('entry', $row['count']) }}
                                    @if ($row['outstanding'] > 0)
                                        &middot; <span class="text-amber-700">{{ App\Support\PaymentFigures::money($row['outstanding']) }} still owed</span>
                                    @endif
                                </p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-admin.panel>
        @endif
    </div>

    {{-- ==================== Coming up ==================== --}}
    @if ($can['events'])
        <x-admin.panel title="Coming Up" icon="activity" :flush="true">
            <div class="px-5 py-4 border-b border-gray-100">
                <p class="text-sm text-gray-600">
                    The next events to start, and how full each one is.
                </p>
            </div>

            @if ($upcomingEvents === [])
                <div class="px-5 py-10 text-center">
                    <x-admin.icon name="clipboard" class="w-8 h-8 mx-auto text-gray-300" />
                    <p class="text-sm font-semibold text-gray-700 mt-2">No events scheduled</p>
                    <p class="text-sm text-gray-500 mt-1">
                        <a href="{{ route('admin.event.registration') }}" class="underline font-semibold text-blue-600">
                            Create an event
                        </a>
                        and it will appear here.
                    </p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-left">
                            <tr>
                                <th scope="col" class="px-5 py-2.5 text-xs font-bold uppercase tracking-wide text-gray-500">Event</th>
                                <th scope="col" class="px-5 py-2.5 text-xs font-bold uppercase tracking-wide text-gray-500">Starts</th>
                                <th scope="col" class="px-5 py-2.5 text-xs font-bold uppercase tracking-wide text-gray-500">Status</th>
                                <th scope="col" class="px-5 py-2.5 text-xs font-bold uppercase tracking-wide text-gray-500">Filled</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-100">
                            @foreach ($upcomingEvents as $event)
                                <tr class="hover:bg-blue-50/40">
                                    <td class="px-5 py-3">
                                        <span class="font-semibold text-gray-900">{{ $event['title'] }}</span>
                                        <span class="block text-xs text-gray-400">{{ $event['category'] }}</span>
                                    </td>

                                    <td class="px-5 py-3 whitespace-nowrap text-gray-600">
                                        {{ $event['starts_at']?->format('d M Y') ?? '—' }}
                                    </td>

                                    <td class="px-5 py-3 whitespace-nowrap">
                                        <x-admin.badge :tone="$event['status'] === 'open' ? 'green' : 'gray'">
                                            {{ $event['status_label'] }}
                                        </x-admin.badge>
                                    </td>

                                    <td class="px-5 py-3 whitespace-nowrap">
                                        <div class="flex items-center gap-2">
                                            <div class="w-24 h-1.5 rounded-full bg-gray-100 overflow-hidden shrink-0">
                                                <div class="h-full rounded-full bg-blue-500"
                                                     style="width: {{ min(100, $event['filled']) }}%"></div>
                                            </div>
                                            <span class="text-xs text-gray-600 tabular-nums">
                                                {{ number_format($event['registrations']) }}
                                                @if ($event['seats_total'] > 0)
                                                    / {{ number_format($event['seats_total']) }}
                                                @endif
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-admin.panel>
    @endif

    {{-- Says the figures are cached, because a number that looks live but is not is
         worse than a stale one that admits it. --}}
    <p class="text-xs text-gray-400 mt-4">
        Figures are worked out at most every two minutes, so a change you have just
        made can take a moment to appear here.
    </p>
</x-admin.page-card>
@endsection
