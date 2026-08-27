@extends('layouts.admin')

@section('title', 'Payments')

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>Payments</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">Overview</span>
@endsection

@section('content')
    @php
        use App\Models\EventRegistration;
        use App\Support\PaymentFigures;

        $payTones = [
            EventRegistration::PAYMENT_UNPAID => 'gray',
            EventRegistration::PAYMENT_PENDING => 'amber',
            EventRegistration::PAYMENT_PAID => 'green',
            EventRegistration::PAYMENT_FAILED => 'red',
            EventRegistration::PAYMENT_REFUNDED => 'purple',
        ];

        $label = 'px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500';
    @endphp

    <x-admin.page-card
        title="Payments"
        description="What has come in, what has not, and where it came from.">

        <x-slot:actions>
            <x-admin.badge :tone="$gateway['ready'] ? 'green' : 'amber'" dot>
                {{ $gateway['label'] }}
            </x-admin.badge>
            <a href="{{ route('admin.payments.transactions') }}"
               class="rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                All Transactions
            </a>
        </x-slot:actions>

        {{-- The range applies to every figure below, so it sits above them
             rather than beside any one of them. --}}
        <form action="{{ route('admin.payments.overview') }}" method="GET"
              class="flex flex-wrap items-center gap-2 mb-5">
            <x-admin.date-range :from="$from" :to="$to" />
            <button type="submit" class="rounded-lg bg-gray-200 px-3.5 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-300 transition">
                Apply
            </button>
            @if ($from || $to)
                <a href="{{ route('admin.payments.overview') }}" class="px-3 py-2 text-sm font-semibold text-gray-500 hover:text-gray-800 transition">Reset</a>
            @else
                <span class="text-xs text-gray-400">Showing everything to date.</span>
            @endif
        </form>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-5">
            <x-admin.money-card
                label="Collected"
                :value="PaymentFigures::money($collected)"
                note="Marked paid. Refunds are not counted here."
                tone="green"
                icon="credit-card" />

            <x-admin.money-card
                label="Outstanding"
                :value="PaymentFigures::money($outstanding)"
                note="Cancelled entries excluded."
                tone="amber"
                icon="lock"
                :href="route('admin.payments.transactions', ['status' => 'unpaid'])" />

            <x-admin.money-card
                label="Refunded"
                :value="PaymentFigures::money($refunded)"
                note="Given back."
                tone="red"
                icon="activity"
                :href="route('admin.payments.refunds')" />

            <x-admin.money-card
                label="Needs chasing"
                :value="(string) ($failedCount + $abandonedCount)"
                :note="$failedCount . ' failed, ' . $abandonedCount . ' abandoned'"
                tone="gray"
                icon="power"
                :href="route('admin.payments.unpaid')" />
        </div>

        @unless ($gateway['ready'])
            <div role="alert" class="flex items-start gap-3 bg-amber-50 border border-amber-200 rounded-lg p-4 mb-5">
                <x-admin.icon name="lock" class="w-5 h-5 mt-0.5 shrink-0 text-amber-600" />
                <div class="text-sm text-amber-800">
                    <p class="font-semibold mb-0.5">No gateway is taking payments</p>
                    <p>{{ $gateway['summary'] }}</p>
                    <p class="text-xs mt-1.5">
                        Figures below still show what has been recorded.
                        <a href="{{ route('admin.settings.integration', ['tab' => 'payments']) }}" class="underline font-semibold">Open the gateway settings</a>.
                    </p>
                </div>
            </div>
        @endunless

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
            {{-- Where the money is, by state --}}
            <x-admin.panel title="By Payment Status" icon="clipboard" :flush="true">
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($counts as $status => $count)
                            <tr class="hover:bg-gray-50">
                                <td class="px-5 py-3">
                                    <x-admin.badge :tone="$payTones[$status] ?? 'gray'">
                                        {{ EventRegistration::PAYMENT_STATUSES[$status] ?? $status }}
                                    </x-admin.badge>
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums text-gray-900 font-semibold">{{ $count }}</td>
                                <td class="px-5 py-3 text-right w-24">
                                    @if ($count > 0)
                                        <a href="{{ route('admin.payments.transactions', ['status' => $status]) }}"
                                           class="text-xs font-semibold text-blue-600 hover:underline">View</a>
                                    @else
                                        <span class="text-xs text-gray-300">None</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-admin.panel>

            {{-- Which event earned what --}}
            <x-admin.panel title="By Event" icon="grid" :flush="true">
                @if ($byEvent === [])
                    <p class="px-5 py-8 text-sm text-gray-500 text-center">No paid or payable entries in this range.</p>
                @else
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-left">
                            <tr>
                                <th scope="col" class="{{ $label }}">Event</th>
                                <th scope="col" class="{{ $label }} text-right">Collected</th>
                                <th scope="col" class="{{ $label }} text-right">Outstanding</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($byEvent as $row)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-5 py-3">
                                        <span class="block text-gray-900">{{ $row['event'] }}</span>
                                        <span class="block text-xs text-gray-400">{{ $row['count'] }} {{ Str::plural('entry', $row['count']) }}</span>
                                    </td>
                                    <td class="px-5 py-3 text-right tabular-nums text-green-700 font-semibold">
                                        {{ PaymentFigures::money($row['collected']) }}
                                    </td>
                                    <td class="px-5 py-3 text-right tabular-nums {{ $row['outstanding'] > 0 ? 'text-amber-700' : 'text-gray-400' }}">
                                        {{ PaymentFigures::money($row['outstanding']) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </x-admin.panel>
        </div>

        {{-- Latest money in --}}
        <div class="mt-5">
            <x-admin.panel title="Most Recent Payments" icon="activity" :flush="true">
                @if ($recent->isEmpty())
                    <p class="px-5 py-8 text-sm text-gray-500 text-center">Nothing has been paid yet.</p>
                @else
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-left">
                            <tr>
                                <th scope="col" class="{{ $label }}">Reference</th>
                                <th scope="col" class="{{ $label }}">Entry</th>
                                <th scope="col" class="{{ $label }}">Event</th>
                                <th scope="col" class="{{ $label }} text-right">Amount</th>
                                <th scope="col" class="{{ $label }}">Confirmed</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($recent as $registration)
                                <tr class="hover:bg-blue-50/40">
                                    <td class="px-5 py-3 whitespace-nowrap">
                                        <a href="{{ route('admin.event.participants.show', $registration) }}"
                                           class="font-semibold text-blue-600 hover:underline">{{ $registration->reference }}</a>
                                    </td>
                                    <td class="px-5 py-3 text-gray-900">{{ $registration->displayName() }}</td>
                                    <td class="px-5 py-3 text-gray-600">{{ $registration->event?->title ?? '—' }}</td>
                                    <td class="px-5 py-3 text-right tabular-nums text-gray-900">{{ $registration->amountLabel() }}</td>
                                    <td class="px-5 py-3 whitespace-nowrap text-xs text-gray-500">
                                        {{ $registration->payment_synced_at?->format('d M Y, g:i a') ?? 'Recorded by hand' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </x-admin.panel>
        </div>
    </x-admin.page-card>
@endsection
