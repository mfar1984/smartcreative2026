@extends('layouts.admin')

@section('title', 'Settlements')

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <a href="{{ route('admin.payments.overview') }}" class="hover:text-gray-700 transition">Payments</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">Settlements</span>
@endsection

@section('content')
    @php
        use App\Support\PaymentFigures;

        $head = 'px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500';
    @endphp

    <x-admin.page-card
        title="Settlements"
        description="What was confirmed paid, day by day, for reconciling against the bank."
        :flush="true">

        <x-slot:actions>
            <x-admin.money-card
                label="In this range"
                :value="PaymentFigures::money($total)"
                :note="$count . ' ' . Str::plural('payment', $count)"
                tone="green" />
        </x-slot:actions>

        {{-- The honest caveat, at the top where it cannot be missed. Presenting
             these as payout figures would invite somebody to stop checking the
             statement, which is the one thing this page exists to encourage. --}}
        <div role="note" class="flex items-start gap-3 px-6 py-4 border-b border-gray-200 bg-amber-50">
            <x-admin.icon name="lock" class="w-5 h-5 mt-0.5 shrink-0 text-amber-600" />
            <div class="text-sm text-amber-800">
                <p class="font-semibold mb-0.5">These are our records, not {{ $gatewayLabel }}'s payout report</p>
                <p>
                    Grouped by the day {{ $gatewayLabel }} confirmed each payment, which is the date a
                    statement line should match. The gateway is not asked for its own payout
                    figures, so this is what we believe we were paid rather than what was
                    transferred.
                </p>
                <p class="text-xs mt-1.5">
                    Fees, chargebacks and the gateway's own settlement schedule are not reflected,
                    so the amount that lands in the bank will be lower and later. Check the
                    {{ $gatewayLabel }} portal for the actual payout.
                </p>
            </div>
        </div>

        <x-admin.filter-bar
            :action="route('admin.payments.settlements')"
            :reset="($filters['from'] || $filters['to']) ? route('admin.payments.settlements') : null">
            <x-admin.date-range :from="$filters['from']" :to="$filters['to']" />
        </x-admin.filter-bar>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left">
                    <tr>
                        <th scope="col" class="{{ $head }}">Date Confirmed</th>
                        <th scope="col" class="{{ $head }} text-right">Payments</th>
                        <th scope="col" class="{{ $head }} text-right">Amount</th>
                        <th scope="col" class="{{ $head }} text-right">Running Total</th>
                        <th scope="col" class="{{ $head }}"></th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-100">
                    @php $running = 0; @endphp

                    @forelse ($days as $day)
                        @php $running += $day['total']; @endphp

                        <tr class="hover:bg-green-50/40">
                            <td class="px-5 py-3 whitespace-nowrap">
                                <span class="font-semibold text-gray-900">
                                    {{ \Illuminate\Support\Carbon::parse($day['date'])->format('d M Y') }}
                                </span>
                                <span class="block text-xs text-gray-400">
                                    {{ \Illuminate\Support\Carbon::parse($day['date'])->format('l') }}
                                </span>
                            </td>

                            <td class="px-5 py-3 text-right tabular-nums text-gray-700">{{ $day['count'] }}</td>

                            <td class="px-5 py-3 text-right tabular-nums font-semibold text-green-700">
                                {{ PaymentFigures::money($day['total']) }}
                            </td>

                            <td class="px-5 py-3 text-right tabular-nums text-gray-500">
                                {{ PaymentFigures::money($running) }}
                            </td>

                            <td class="px-5 py-3 text-right whitespace-nowrap">
                                <a href="{{ route('admin.payments.transactions', ['status' => 'paid', 'from' => $day['date'], 'to' => $day['date']]) }}"
                                   class="text-xs font-semibold text-blue-600 hover:underline">See the {{ $day['count'] }}</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-12 text-center">
                                <x-admin.icon name="credit-card" class="w-10 h-10 mx-auto text-gray-300" />
                                <p class="text-sm font-semibold text-gray-700 mt-3">Nothing confirmed paid in this range</p>
                                <p class="text-sm text-gray-500 mt-1">Widen the dates, or check the Unpaid list.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>

                @if ($days !== [])
                    <tfoot class="bg-gray-50 border-t-2 border-gray-200">
                        <tr>
                            <th scope="row" class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-gray-600">Total</th>
                            <td class="px-5 py-3 text-right tabular-nums font-bold text-gray-900">{{ $count }}</td>
                            <td class="px-5 py-3 text-right tabular-nums font-bold text-green-700">{{ PaymentFigures::money($total) }}</td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </x-admin.page-card>
@endsection
