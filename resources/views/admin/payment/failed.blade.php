@extends('layouts.admin')

@section('title', 'Unpaid & Failed')

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <a href="{{ route('admin.payments.overview') }}" class="hover:text-gray-700 transition">Payments</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">Unpaid &amp; Failed</span>
@endsection

@section('content')
    @php
        use App\Support\PaymentFigures;

        $head = 'px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500';

        $tabs = [
            'failed' => ['label' => 'Refused by the gateway', 'count' => $failedCount],
            'abandoned' => ['label' => 'Started and left', 'count' => $abandonedCount],
        ];
    @endphp

    <x-admin.page-card
        title="Unpaid &amp; Failed"
        description="Money that almost arrived. Both lists want the same thing from the office: a telephone call."
        :flush="true">

        <x-slot:actions>
            <x-admin.money-card
                label="On this list"
                :value="PaymentFigures::money($total)"
                tone="amber" />
        </x-slot:actions>

        {{-- Two kinds of near miss. Kept apart because the cause differs and so
             does what to say on the telephone: one was refused, the other was
             never finished. --}}
        <div class="flex flex-wrap gap-1 px-6 pt-4 border-b border-gray-200 bg-white">
            @foreach ($tabs as $slug => $tab)
                <a href="{{ route('admin.payments.unpaid', ['tab' => $slug]) }}"
                   @class([
                       'inline-flex items-center gap-2 px-4 py-2.5 text-sm font-semibold border-b-2 -mb-px transition',
                       'border-blue-600 text-blue-700' => $activeTab === $slug,
                       'border-transparent text-gray-500 hover:text-gray-800' => $activeTab !== $slug,
                   ])>
                    {{ $tab['label'] }}
                    <span @class([
                        'rounded-full px-2 py-0.5 text-xs font-bold',
                        'bg-blue-100 text-blue-800' => $activeTab === $slug,
                        'bg-gray-100 text-gray-600' => $activeTab !== $slug,
                    ])>{{ $tab['count'] }}</span>
                </a>
            @endforeach
        </div>

        <div class="px-6 py-3 border-b border-gray-200 bg-gray-50">
            <p class="text-sm text-gray-600">
                @if ($activeTab === 'abandoned')
                    A checkout was opened and never finished. Anything left for more than
                    {{ $graceMinutes }} minutes is listed, so somebody still typing card
                    details is not written off.
                @else
                    The gateway actively refused these. A refusal is usually the card, not
                    the entry, so the place is still held and can still be paid for.
                @endif
            </p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left">
                    <tr>
                        <th scope="col" class="{{ $head }}">Reference</th>
                        <th scope="col" class="{{ $head }}">Entry</th>
                        <th scope="col" class="{{ $head }}">Contact</th>
                        <th scope="col" class="{{ $head }} text-right">Amount</th>
                        <th scope="col" class="{{ $head }}">Last Change</th>
                        <th scope="col" class="{{ $head }} text-center">Chase</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-100">
                    @forelse ($registrations as $registration)
                        @php
                            $registrant = $registration->participants->firstWhere('role', 'manager')
                                ?? $registration->participants->sortBy('id')->first();
                        @endphp

                        <tr class="hover:bg-amber-50/40 align-top">
                            <td class="px-5 py-3 whitespace-nowrap">
                                <a href="{{ route('admin.event.participants.show', $registration) }}"
                                   class="font-semibold text-blue-600 hover:underline">{{ $registration->reference }}</a>
                                <span class="block text-xs text-gray-400">{{ $registration->event?->title ?? '—' }}</span>
                            </td>

                            <td class="px-5 py-3">
                                <span class="block font-semibold text-gray-900">{{ $registration->displayName() }}</span>
                                <span class="block text-xs text-gray-500">
                                    {{ $registration->participants->count() }} {{ Str::plural('person', $registration->participants->count()) }}
                                </span>
                            </td>

                            <td class="px-5 py-3">
                                @if ($registrant)
                                    <span class="block text-gray-900">{{ $registrant->full_name }}</span>
                                    <span class="block text-xs text-gray-500">{{ $registrant->phone ?: 'No phone' }}</span>
                                    <span class="block text-xs text-gray-400">{{ $registrant->email ?: 'No email' }}</span>
                                @else
                                    <span class="text-xs text-gray-400">Nobody on the entry</span>
                                @endif
                            </td>

                            <td class="px-5 py-3 text-right whitespace-nowrap tabular-nums font-semibold text-gray-900">
                                {{ $registration->amountLabel() }}
                            </td>

                            <td class="px-5 py-3 whitespace-nowrap text-xs text-gray-500">
                                {{ $registration->updated_at?->diffForHumans() }}
                                <span class="block text-gray-400">{{ $registration->updated_at?->format('d M Y, g:i a') }}</span>
                            </td>

                            <td class="px-5 py-3 text-center">
                                @if ($canNotify && blank($registrant?->email))
                                    <span class="text-xs text-gray-400">No email on file</span>
                                @elseif ($canNotify)
                                    <form action="{{ route('admin.payments.remind', $registration) }}" method="POST"
                                          onsubmit="return confirm('Email a payment reminder to {{ addslashes($registrant->full_name) }} for {{ $registration->amountLabel() }}?');">
                                        @csrf
                                        <button type="submit"
                                                class="inline-flex items-center gap-1.5 rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-xs font-semibold text-amber-700 hover:bg-amber-50 transition">
                                            <x-admin.icon name="mail" class="w-3.5 h-3.5" />
                                            Remind
                                        </button>
                                    </form>
                                @else
                                    <span class="text-xs text-gray-300">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-12 text-center">
                                <x-admin.icon name="shield" class="w-10 h-10 mx-auto text-green-300" />
                                <p class="text-sm font-semibold text-gray-700 mt-3">Nothing to chase</p>
                                <p class="text-sm text-gray-500 mt-1">
                                    @if ($activeTab === 'abandoned')
                                        No checkout has been left unfinished.
                                    @else
                                        The gateway has not refused anything.
                                    @endif
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
