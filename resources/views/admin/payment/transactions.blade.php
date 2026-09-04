@extends('layouts.admin')

@section('title', 'All Transactions')

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <a href="{{ route('admin.payments.overview') }}" class="hover:text-gray-700 transition">Payments</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">All Transactions</span>
@endsection

@section('content')
    @php
        use App\Models\EventRegistration;
        use App\Support\PaymentFigures;

        $payTones = [
            EventRegistration::PAYMENT_UNPAID => 'gray',
            EventRegistration::PAYMENT_PENDING => 'amber',
            // Blue: some of the money is in, so it reads as neither settled nor untouched.
            EventRegistration::PAYMENT_PARTIAL => 'blue',
            EventRegistration::PAYMENT_PAID => 'green',
            EventRegistration::PAYMENT_FAILED => 'red',
            EventRegistration::PAYMENT_REFUNDED => 'purple',
        ];

        $head = 'px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500';
        $filterInput = 'rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 bg-white focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';
    @endphp

    <x-admin.page-card
        title="All Transactions"
        description="Every registration that carries an amount. Free entries are left out, having nothing to pay."
        :flush="true">

        <x-slot:actions>
            {{-- Carries names, identity card numbers and amounts, so it sits
                 behind its own permission rather than behind payments.view. --}}
            @if ($canExport)
                <a href="{{ route('admin.payments.export', request()->only(['status', 'event', 'from', 'to'])) }}"
                   class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                    <x-admin.icon name="archive" class="w-4 h-4" />
                    Export CSV
                </a>
            @endif
        </x-slot:actions>

        <x-admin.filter-bar
            :action="route('admin.payments.transactions')"
            :reset="$isFiltered ? route('admin.payments.transactions') : null">

            <div class="relative flex-1 min-w-56">
                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" aria-hidden="true">
                    <x-admin.icon name="search" class="w-4 h-4" />
                </span>
                <label for="q" class="sr-only">Search transactions</label>
                <input type="search" id="q" name="q" value="{{ $filters['search'] }}"
                       placeholder="Reference, team, name, IC or gateway reference..."
                       class="w-full rounded-lg border border-gray-300 pl-9 pr-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
            </div>

            <label for="status" class="sr-only">Payment status</label>
            <select id="status" name="status" class="{{ $filterInput }}">
                <option value="">All Statuses</option>
                @foreach ($statuses as $value => $text)
                    <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $text }}</option>
                @endforeach
            </select>

            <label for="event" class="sr-only">Event</label>
            <select id="event" name="event" class="{{ $filterInput }}">
                <option value="">All Events</option>
                @foreach ($events as $id => $title)
                    <option value="{{ $id }}" @selected((string) $filters['eventId'] === (string) $id)>{{ $title }}</option>
                @endforeach
            </select>

            <x-admin.date-range :from="$filters['from']" :to="$filters['to']" />
        </x-admin.filter-bar>

        {{-- The total of what is on screen, not of the whole table. A filtered
             list showing an unfiltered total reads as the filter's answer. --}}
        <div class="flex flex-wrap items-center justify-between gap-3 px-6 py-3 border-b border-gray-200 bg-gray-50">
            <p class="text-sm text-gray-600">
                <span class="font-bold text-gray-900">{{ number_format($filteredCount) }}</span>
                {{ Str::plural('transaction', $filteredCount) }}
                @if ($isFiltered) matching these filters @endif
            </p>
            <p class="text-sm text-gray-600">
                Total value
                <span class="font-bold text-gray-900 tabular-nums">{{ PaymentFigures::money($filteredTotal) }}</span>
            </p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left">
                    <tr>
                        <th scope="col" class="{{ $head }}">Reference</th>
                        <th scope="col" class="{{ $head }}">Entry</th>
                        <th scope="col" class="{{ $head }}">Event</th>
                        <th scope="col" class="{{ $head }} text-right">Amount</th>
                        <th scope="col" class="{{ $head }}">Payment</th>
                        <th scope="col" class="{{ $head }}">Gateway Ref</th>
                        <th scope="col" class="{{ $head }}">Registered</th>
                        @if ($canRefund)
                            <th scope="col" class="{{ $head }} text-center">Refund</th>
                        @endif
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-100">
                    @forelse ($registrations as $registration)
                        <tr class="hover:bg-blue-50/40 align-top">
                            <td class="px-5 py-3 whitespace-nowrap">
                                <a href="{{ route('admin.event.participants.show', $registration) }}"
                                   class="font-semibold text-blue-600 hover:underline">{{ $registration->reference }}</a>
                            </td>

                            <td class="px-5 py-3">
                                <span class="block font-semibold text-gray-900">{{ $registration->displayName() }}</span>
                                <span class="block text-xs text-gray-500">
                                    {{ $registration->participants->count() }} {{ Str::plural('person', $registration->participants->count()) }}
                                    &middot; {{ ucfirst($registration->mode) }}
                                </span>
                            </td>

                            <td class="px-5 py-3 text-gray-700">{{ $registration->event?->title ?? '—' }}</td>

                            <td class="px-5 py-3 text-right whitespace-nowrap tabular-nums text-gray-900">
                                {{ $registration->amountLabel() }}
                                @if ($registration->addonLines->isNotEmpty())
                                    <span class="block text-xs text-gray-400">incl. {{ $registration->addonsTotalLabel() }} extras</span>
                                @endif

                                {{-- A partial refund keeps the entry paid, so the amount
                                     column has to say what is actually left. Without this
                                     the row would read as though nothing had been sent
                                     back. --}}
                                @if ($registration->isRefunded())
                                    <span class="block text-xs text-purple-700">
                                        &minus;{{ $registration->refundedAmountLabel() }} refunded
                                    </span>
                                    @if ($registration->isPartiallyRefunded())
                                        <span class="block text-xs font-semibold text-gray-600">
                                            {{ App\Support\PaymentFigures::money($registration->netAmount()) }} kept
                                        </span>
                                    @endif
                                @endif
                            </td>

                            <td class="px-5 py-3 whitespace-nowrap">
                                <x-admin.badge :tone="$payTones[$registration->payment_status] ?? 'gray'">
                                    {{ $registration->paymentStatusLabel() }}
                                </x-admin.badge>
                            </td>

                            <td class="px-5 py-3">
                                @if (filled($registration->payment_reference))
                                    <code class="text-xs text-gray-500 break-all">{{ $registration->payment_reference }}</code>
                                @else
                                    <span class="text-xs text-gray-300">Never started</span>
                                @endif
                            </td>

                            <td class="px-5 py-3 whitespace-nowrap text-xs text-gray-500">
                                {{ $registration->created_at?->format('d M Y, g:i a') }}
                            </td>

                            @if ($canRefund)
                                <td class="px-5 py-3 whitespace-nowrap text-center">
                                    @php $refundable = $registration->refundableAmount(); @endphp

                                    @if ($refundable > 0 && filled($registration->payment_reference))
                                        {{-- <details> rather than a modal: the form is a real
                                             form, it works without JavaScript, and the amount
                                             cannot be submitted without being seen. --}}
                                        <details class="inline-block text-left group">
                                            <summary class="inline-flex items-center gap-1.5 cursor-pointer rounded-lg border border-purple-300 bg-white px-2.5 py-1.5 text-xs font-semibold text-purple-700 hover:bg-purple-50 transition list-none [&::-webkit-details-marker]:hidden">
                                                <x-admin.icon name="cash" class="w-3.5 h-3.5" />
                                                Refund
                                            </summary>

                                            <form action="{{ route('admin.payments.refund', $registration) }}"
                                                  method="POST"
                                                  onsubmit="return confirm('Send this refund through {{ addslashes($gatewayLabel) }}?\n\nThis moves real money out of the account and cannot be undone from here.');"
                                                  class="mt-2 w-64 rounded-lg border border-gray-200 bg-white p-3 shadow-lg space-y-2">
                                                @csrf

                                                <p class="text-xs text-gray-500">
                                                    Up to <span class="font-semibold text-gray-900">{{ App\Support\PaymentFigures::money($refundable) }}</span>
                                                    can still be sent back.
                                                </p>

                                                <div>
                                                    <label for="amount-{{ $registration->id }}" class="block text-xs font-semibold text-gray-700 mb-1">
                                                        Amount (RM)
                                                    </label>
                                                    <input type="number" id="amount-{{ $registration->id }}" name="amount"
                                                           step="0.01" min="0.01" max="{{ $refundable }}"
                                                           value="{{ number_format($refundable, 2, '.', '') }}"
                                                           required
                                                           class="w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm tabular-nums focus:outline-none focus:border-purple-500 focus:ring-2 focus:ring-purple-500/40">
                                                </div>

                                                <div>
                                                    <label for="reason-{{ $registration->id }}" class="block text-xs font-semibold text-gray-700 mb-1">
                                                        Reason <span class="text-red-500" aria-hidden="true">*</span>
                                                    </label>
                                                    <input type="text" id="reason-{{ $registration->id }}" name="reason"
                                                           required maxlength="255"
                                                           placeholder="e.g. withdrew before the closing date"
                                                           class="w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm focus:outline-none focus:border-purple-500 focus:ring-2 focus:ring-purple-500/40">
                                                </div>

                                                <button type="submit"
                                                        class="w-full rounded-lg border border-purple-600 bg-purple-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-purple-700 transition">
                                                    Send Refund
                                                </button>

                                                <p class="text-xs text-gray-400">
                                                    Nothing changes here until {{ $gatewayLabel }} confirms it.
                                                </p>
                                            </form>
                                        </details>
                                    @elseif ($registration->isFullyRefunded())
                                        <span class="text-xs text-gray-400">Fully refunded</span>
                                    @elseif (blank($registration->payment_reference))
                                        <span class="text-xs text-gray-300" title="Settled outside the gateway, so there is nothing for it to refund">No gateway ref</span>
                                    @else
                                        <span class="text-xs text-gray-300">Not paid</span>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canRefund ? 8 : 7 }}" class="px-5 py-12 text-center text-sm text-gray-500">
                                @if ($isFiltered)
                                    Nothing matches these filters.
                                @else
                                    No registration carries an amount yet.
                                @endif
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
                <p class="text-xs text-gray-500">
                    Showing {{ $registrations->count() }} of {{ number_format($filteredCount) }}
                </p>
            @endif
        </div>
    </x-admin.page-card>
@endsection
