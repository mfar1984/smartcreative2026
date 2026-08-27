@extends('layouts.admin')

@section('title', 'Participants')

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>Event</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">Participants</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>{{ $tabs[$activeTab]['label'] }}</span>
@endsection

@section('content')
    @php
        use App\Models\EventRegistration;

        $filterInput = 'rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 bg-white focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';

        $regTones = [
            EventRegistration::STATUS_PENDING => 'amber',
            EventRegistration::STATUS_CONFIRMED => 'green',
            EventRegistration::STATUS_WAITLISTED => 'blue',
            EventRegistration::STATUS_CANCELLED => 'red',
        ];

        $payTones = [
            EventRegistration::PAYMENT_UNPAID => 'gray',
            EventRegistration::PAYMENT_PENDING => 'amber',
            EventRegistration::PAYMENT_PAID => 'green',
            EventRegistration::PAYMENT_FAILED => 'red',
            EventRegistration::PAYMENT_REFUNDED => 'purple',
        ];

        $intro = match ($activeTab) {
            'team' => ['title' => 'Team Entries', 'description' => 'Registrations where a manager entered a squad. One entry, one payment, however many players.', 'icon' => 'identification', 'accent' => 'purple'],
            'paid' => ['title' => 'Paid', 'description' => 'Registrations the gateway has confirmed as settled.', 'icon' => 'credit-card', 'accent' => 'green'],
            'unpaid' => ['title' => 'Unpaid', 'description' => 'Everything not yet settled: awaiting payment, failed, or never started.', 'icon' => 'lock', 'accent' => 'amber'],
            default => ['title' => 'Individual Entries', 'description' => 'Registrations made by one person for themselves.', 'icon' => 'users', 'accent' => 'blue'],
        };
    @endphp

    <x-admin.settings-shell
        title="Participants"
        description="Everyone who has registered, with what they owe and what the gateway says about it."
        :tabs="$tabs"
        :active-tab="$activeTab"
        route="admin.event.participants">

        <div class="flex flex-wrap items-start justify-between gap-4 mb-5">
            <x-admin.section-intro
                :title="$intro['title']"
                :description="$intro['description']"
                :icon="$intro['icon']"
                :accent="$intro['accent']"
                class="mb-0" />

            {{-- Money across every registration, not just this tab, so the pair
                 does not appear to contradict itself as tabs are switched. --}}
            <div class="flex gap-3 shrink-0">
                <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-2.5">
                    <span class="block text-xs font-semibold uppercase tracking-wide text-green-700">Collected</span>
                    <span class="block text-base font-bold text-green-900 tabular-nums">
                        RM {{ number_format((float) $totals['collected'], 2) }}
                    </span>
                </div>
                <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-2.5">
                    <span class="block text-xs font-semibold uppercase tracking-wide text-amber-700">Outstanding</span>
                    <span class="block text-base font-bold text-amber-900 tabular-nums">
                        RM {{ number_format((float) $totals['outstanding'], 2) }}
                    </span>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
            <x-admin.filter-bar
                :action="route('admin.event.participants')"
                :reset="$isFiltered ? route('admin.event.participants', ['tab' => $activeTab]) : null">

                <input type="hidden" name="tab" value="{{ $activeTab }}">

                <div class="relative flex-1 min-w-56">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" aria-hidden="true">
                        <x-admin.icon name="search" class="w-4 h-4" />
                    </span>
                    <label for="q" class="sr-only">Search participants</label>
                    <input type="search" id="q" name="q" value="{{ $search }}"
                           placeholder="Search name, IC, email, reference or team..."
                           class="w-full rounded-lg border border-gray-300 pl-9 pr-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
                </div>

                <label for="event" class="sr-only">Event</label>
                <select id="event" name="event" class="{{ $filterInput }}">
                    <option value="">All Events</option>
                    @foreach ($events as $id => $title)
                        <option value="{{ $id }}" @selected((string) $eventId === (string) $id)>{{ $title }}</option>
                    @endforeach
                </select>
            </x-admin.filter-bar>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-left">
                        <tr>
                            <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Reference</th>
                            <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Name</th>
                            <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Event</th>
                            <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Mode</th>
                            <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500 text-center">People</th>
                            <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500 text-right">Amount</th>
                            <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Status</th>
                            <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Payment</th>
                            <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Submitted</th>
                            <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500 text-center">Actions</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-gray-100">
                        @forelse ($registrations as $registration)
                            <tr class="hover:bg-blue-50/40 align-top">
                                <td class="px-5 py-3 whitespace-nowrap">
                                    <a href="{{ route('admin.event.participants.show', $registration) }}"
                                       class="font-semibold text-blue-600 hover:underline">
                                        {{ $registration->reference }}
                                    </a>
                                </td>

                                <td class="px-5 py-3">
                                    <span class="block font-semibold text-gray-900">{{ $registration->displayName() }}</span>
                                    @foreach ($registration->participants as $participant)
                                        <span class="block text-xs text-gray-500">
                                            {{ $participant->roleLabel() }}: {{ $participant->full_name }}
                                            <span class="text-gray-400">({{ $participant->ic_number }})</span>
                                        </span>
                                    @endforeach
                                </td>

                                <td class="px-5 py-3 text-gray-700">{{ $registration->event?->title ?? '—' }}</td>

                                <td class="px-5 py-3 whitespace-nowrap text-gray-600">{{ ucfirst($registration->mode) }}</td>

                                <td class="px-5 py-3 whitespace-nowrap text-center text-gray-600 tabular-nums">
                                    {{ $registration->participants->count() }}
                                </td>

                                <td class="px-5 py-3 whitespace-nowrap text-right text-gray-900 tabular-nums">
                                    {{ $registration->amountLabel() }}
                                    @if ($registration->addonLines->isNotEmpty())
                                        <span class="block text-xs text-gray-400">
                                            incl. {{ $registration->addonsTotalLabel() }} extras
                                        </span>
                                    @endif
                                </td>

                                <td class="px-5 py-3 whitespace-nowrap">
                                    <x-admin.badge :tone="$regTones[$registration->status] ?? 'gray'">
                                        {{ $registration->statusLabel() }}
                                    </x-admin.badge>
                                </td>

                                <td class="px-5 py-3 whitespace-nowrap">
                                    <x-admin.badge :tone="$payTones[$registration->payment_status] ?? 'gray'">
                                        {{ $registration->paymentStatusLabel() }}
                                    </x-admin.badge>
                                </td>

                                <td class="px-5 py-3 whitespace-nowrap text-xs text-gray-500">
                                    {{ $registration->created_at?->format('d M Y, g:i a') }}
                                </td>

                                <td class="px-5 py-3 whitespace-nowrap">
                                    <div class="flex items-center justify-center gap-0.5">
                                        <a href="{{ route('admin.event.participants.show', $registration) }}"
                                           class="p-1.5 rounded-lg text-blue-600 hover:bg-blue-50 transition"
                                           title="View {{ $registration->reference }}"
                                           aria-label="View {{ $registration->reference }}">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                            </svg>
                                        </a>

                                        {{-- Only offered while there is something to chase. A reminder
                                             for a settled or free entry would be nonsense. --}}
                                        @if ($canNotify && $registration->awaitingPayment())
                                            <form action="{{ route('admin.event.participants.remind', $registration) }}" method="POST"
                                                  onsubmit="return confirm('Email a payment reminder for {{ addslashes($registration->displayName()) }} ({{ $registration->amountLabel() }} due)?');">
                                                @csrf
                                                <button type="submit"
                                                        class="p-1.5 rounded-lg text-amber-600 hover:bg-amber-50 transition"
                                                        title="Send a payment reminder for {{ $registration->reference }}"
                                                        aria-label="Send a payment reminder for {{ $registration->reference }}">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                                                    </svg>
                                                </button>
                                            </form>
                                        @endif

                                        {{-- A settled payment is a financial record. The controller
                                             refuses it too; hiding the button avoids offering an
                                             action that cannot succeed. --}}
                                        @if ($canDelete && ! $registration->isPaid() && $registration->payment_status !== \App\Models\EventRegistration::PAYMENT_REFUNDED)
                                            <form action="{{ route('admin.event.participants.destroy', $registration) }}" method="POST"
                                                  onsubmit="return confirm('Delete {{ addslashes($registration->reference) }} for {{ addslashes($registration->displayName()) }}?\n\nThis removes {{ $registration->participants->count() }} {{ $registration->participants->count() === 1 ? 'person' : 'people' }} and cannot be undone. The seats go back to the event.');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit"
                                                        class="p-1.5 rounded-lg text-red-600 hover:bg-red-50 transition"
                                                        title="Delete {{ $registration->reference }}"
                                                        aria-label="Delete {{ $registration->reference }}">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                                    </svg>
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="px-5 py-12 text-center text-sm text-gray-500">
                                    @if ($isFiltered)
                                        No participants match the current filters.
                                    @else
                                        No registrations in this group yet.
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="px-5 py-3.5 border-t border-gray-200">
                @if ($registrations->hasPages())
                    {{ $registrations->links() }}
                @else
                    <p class="text-xs text-gray-500">
                        Showing {{ $registrations->total() }} {{ Str::plural('registration', $registrations->total()) }}
                    </p>
                @endif
            </div>
        </div>
    </x-admin.settings-shell>
@endsection
