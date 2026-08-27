@extends('layouts.admin')

@section('title', 'Payment Reports')

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <a href="{{ route('admin.payments.overview') }}" class="hover:text-gray-700 transition">Payments</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">Reports</span>
@endsection

@section('content')
    @php
        use App\Models\EventRegistration;
        use App\Support\PaymentFigures;

        $head = 'px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500';

        // Ranges somebody actually asks for, so the common case is one press
        // rather than two date pickers.
        $presets = [
            'This month' => [now()->startOfMonth()->toDateString(), now()->toDateString()],
            'Last month' => [now()->subMonthNoOverflow()->startOfMonth()->toDateString(), now()->subMonthNoOverflow()->endOfMonth()->toDateString()],
            'This year' => [now()->startOfYear()->toDateString(), now()->toDateString()],
        ];
    @endphp

    <x-admin.page-card
        title="Payment Reports"
        description="Summaries for a period, and the export for anything this page does not answer.">

        <x-slot:actions>
            @if ($canExport)
                <a href="{{ route('admin.payments.export', ['from' => $from, 'to' => $to]) }}"
                   class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition shadow-sm">
                    <x-admin.icon name="archive" class="w-4 h-4" />
                    Export This Range
                </a>
            @endif
        </x-slot:actions>

        <form action="{{ route('admin.payments.reports') }}" method="GET" class="flex flex-wrap items-center gap-2 mb-5">
            <x-admin.date-range :from="$from" :to="$to" />
            <button type="submit" class="rounded-lg bg-gray-200 px-3.5 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-300 transition">
                Apply
            </button>

            <span class="mx-1 text-gray-300">|</span>

            @foreach ($presets as $name => [$presetFrom, $presetTo])
                <a href="{{ route('admin.payments.reports', ['from' => $presetFrom, 'to' => $presetTo]) }}"
                   @class([
                       'rounded-lg px-3 py-2 text-sm font-semibold transition',
                       'bg-blue-100 text-blue-800' => $from === $presetFrom && $to === $presetTo,
                       'text-gray-600 hover:bg-gray-100' => ! ($from === $presetFrom && $to === $presetTo),
                   ])>{{ $name }}</a>
            @endforeach

            @if ($from || $to)
                <a href="{{ route('admin.payments.reports') }}" class="px-3 py-2 text-sm font-semibold text-gray-500 hover:text-gray-800 transition">All time</a>
            @endif
        </form>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-5">
            <x-admin.money-card label="Collected" :value="PaymentFigures::money($collected)" tone="green" icon="credit-card" />
            <x-admin.money-card label="Outstanding" :value="PaymentFigures::money($outstanding)" tone="amber" icon="lock" />
            <x-admin.money-card label="Refunded" :value="PaymentFigures::money($refunded)" tone="red" icon="activity" />
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
            <x-admin.panel title="By Event" icon="grid" :flush="true">
                @if ($byEvent === [])
                    <p class="px-5 py-8 text-sm text-gray-500 text-center">Nothing in this range.</p>
                @else
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-left">
                            <tr>
                                <th scope="col" class="{{ $head }}">Event</th>
                                <th scope="col" class="{{ $head }} text-right">Entries</th>
                                <th scope="col" class="{{ $head }} text-right">Collected</th>
                                <th scope="col" class="{{ $head }} text-right">Outstanding</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($byEvent as $row)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-5 py-3 text-gray-900">{{ $row['event'] }}</td>
                                    <td class="px-5 py-3 text-right tabular-nums text-gray-600">{{ $row['count'] }}</td>
                                    <td class="px-5 py-3 text-right tabular-nums font-semibold text-green-700">{{ PaymentFigures::money($row['collected']) }}</td>
                                    <td class="px-5 py-3 text-right tabular-nums {{ $row['outstanding'] > 0 ? 'text-amber-700' : 'text-gray-400' }}">{{ PaymentFigures::money($row['outstanding']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </x-admin.panel>

            <x-admin.panel title="By Payment Status" icon="clipboard" :flush="true">
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($counts as $status => $count)
                            <tr class="hover:bg-gray-50">
                                <td class="px-5 py-3 text-gray-700">{{ EventRegistration::PAYMENT_STATUSES[$status] ?? $status }}</td>
                                <td class="px-5 py-3 text-right tabular-nums font-semibold text-gray-900">{{ $count }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-admin.panel>
        </div>

        <div class="mt-5">
            <x-admin.panel title="Day By Day" icon="activity" :flush="true">
                @if ($days === [])
                    <p class="px-5 py-8 text-sm text-gray-500 text-center">Nothing was confirmed paid in this range.</p>
                @else
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-left">
                            <tr>
                                <th scope="col" class="{{ $head }}">Date</th>
                                <th scope="col" class="{{ $head }} text-right">Payments</th>
                                <th scope="col" class="{{ $head }} text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($days as $day)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-5 py-3 whitespace-nowrap text-gray-900">
                                        {{ \Illuminate\Support\Carbon::parse($day['date'])->format('d M Y') }}
                                    </td>
                                    <td class="px-5 py-3 text-right tabular-nums text-gray-600">{{ $day['count'] }}</td>
                                    <td class="px-5 py-3 text-right tabular-nums font-semibold text-green-700">{{ PaymentFigures::money($day['total']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </x-admin.panel>
        </div>

        @unless ($canExport)
            <p class="mt-4 text-xs text-gray-500">
                Your role can read these figures but not export them. An export carries names
                and identity card numbers, so it sits behind a separate permission.
            </p>
        @endunless
    </x-admin.page-card>
@endsection
