@extends('layouts.admin')

@section('title', 'Refunds')

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <a href="{{ route('admin.payments.overview') }}" class="hover:text-gray-700 transition">Payments</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">Refunds</span>
@endsection

@section('content')
    @php
        use App\Support\PaymentFigures;

        $head = 'px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500';
    @endphp

    <x-admin.page-card
        title="Refunds"
        description="Entries marked as refunded, and what was given back."
        :flush="true">

        <x-slot:actions>
            <x-admin.money-card
                label="Refunded"
                :value="PaymentFigures::money($total)"
                :note="$count . ' ' . Str::plural('entry', $count)"
                tone="red" />
        </x-slot:actions>

        {{-- Said plainly rather than implied by the absence of a button. Somebody
             looking for a refund button needs to know where the refund actually
             happens, not just that it is not here. --}}
        <div role="note" class="flex items-start gap-3 px-6 py-4 border-b border-gray-200 bg-blue-50">
            <x-admin.icon name="lock" class="w-5 h-5 mt-0.5 shrink-0 text-blue-600" />
            <div class="text-sm text-blue-800">
                <p class="font-semibold mb-0.5">Refunds are issued in {{ $gatewayLabel }}, not here</p>
                <p>
                    This application has no refund call wired to the gateway, so it cannot move
                    the money. Refund in the {{ $gatewayLabel }} portal; the status here follows
                    on the next webhook or the next time the record is opened.
                </p>
                <p class="text-xs mt-1.5">
                    This page is the record of what has been refunded, which is what a set of
                    books needs.
                </p>
            </div>
        </div>

        <x-admin.filter-bar
            :action="route('admin.payments.refunds')"
            :reset="($filters['from'] || $filters['to']) ? route('admin.payments.refunds') : null">
            <x-admin.date-range :from="$filters['from']" :to="$filters['to']" />
        </x-admin.filter-bar>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left">
                    <tr>
                        <th scope="col" class="{{ $head }}">Reference</th>
                        <th scope="col" class="{{ $head }}">Entry</th>
                        <th scope="col" class="{{ $head }}">Event</th>
                        <th scope="col" class="{{ $head }} text-right">Amount</th>
                        <th scope="col" class="{{ $head }}">Gateway Ref</th>
                        <th scope="col" class="{{ $head }}">Marked Refunded</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-100">
                    @forelse ($registrations as $registration)
                        <tr class="hover:bg-purple-50/40 align-top">
                            <td class="px-5 py-3 whitespace-nowrap">
                                <a href="{{ route('admin.event.participants.show', $registration) }}"
                                   class="font-semibold text-blue-600 hover:underline">{{ $registration->reference }}</a>
                            </td>

                            <td class="px-5 py-3">
                                <span class="block font-semibold text-gray-900">{{ $registration->displayName() }}</span>
                                <span class="block text-xs text-gray-500">
                                    {{ $registration->participants->count() }} {{ Str::plural('person', $registration->participants->count()) }}
                                </span>
                            </td>

                            <td class="px-5 py-3 text-gray-700">{{ $registration->event?->title ?? '—' }}</td>

                            <td class="px-5 py-3 text-right whitespace-nowrap tabular-nums font-semibold text-gray-900">
                                {{ $registration->amountLabel() }}
                            </td>

                            <td class="px-5 py-3">
                                @if (filled($registration->payment_reference))
                                    <code class="text-xs text-gray-500 break-all">{{ $registration->payment_reference }}</code>
                                @else
                                    <span class="text-xs text-gray-300">—</span>
                                @endif
                            </td>

                            <td class="px-5 py-3 whitespace-nowrap text-xs text-gray-500">
                                {{ $registration->updated_at?->format('d M Y, g:i a') }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-12 text-center">
                                <x-admin.icon name="shield" class="w-10 h-10 mx-auto text-gray-300" />
                                <p class="text-sm font-semibold text-gray-700 mt-3">Nothing has been refunded</p>
                                <p class="text-sm text-gray-500 mt-1">
                                    Refunds raised in the {{ $gatewayLabel }} portal appear here once
                                    their status reaches us.
                                </p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="px-6 py-3.5 border-t border-gray-200">
            @if ($registrations->hasPages())
                {{ $registrations->links() }}
            @else
                <p class="text-xs text-gray-500">{{ $registrations->count() }} shown</p>
            @endif
        </div>
    </x-admin.page-card>
@endsection
