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
            // Blue rather than green or amber: some of the money is in, so it is
            // neither settled nor untouched, and it should not read as either.
            EventRegistration::PAYMENT_PARTIAL => 'blue',
            EventRegistration::PAYMENT_PAID => 'green',
            EventRegistration::PAYMENT_FAILED => 'red',
            EventRegistration::PAYMENT_REFUNDED => 'purple',
        ];

        /*
         | Which registration had a payment form open when validation failed, so that
         | one modal can be reopened with its messages and its typed values still in
         | place. Without this a rejected amount would close the dialog and the
         | operator would see an error with no form attached to it.
         */
        $reopenPaymentFor = old('record_payment_for');

        $intro = match ($activeTab) {
            'team' => ['title' => 'Team Entries', 'description' => 'Registrations where a manager entered a squad. One entry, one payment, however many players.', 'icon' => 'identification', 'accent' => 'purple'],
            'paid' => ['title' => 'Paid', 'description' => 'Registrations settled in full, whether by the gateway or recorded by hand.', 'icon' => 'credit-card', 'accent' => 'green'],
            'unpaid' => ['title' => 'Unpaid', 'description' => 'Everything not yet settled in full: awaiting payment, part paid, failed, or never started.', 'icon' => 'lock', 'accent' => 'amber'],
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

                                        {{-- Record money that arrived outside the gateway. Offered
                                             whenever a balance is owed, which includes a part-paid
                                             entry taking its second instalment. --}}
                                        @if ($canRecordPayment && $registration->owesBalance())
                                            <button type="button"
                                                    data-open-payment="{{ $registration->id }}"
                                                    class="p-1.5 rounded-lg text-green-600 hover:bg-green-50 transition"
                                                    title="Record a payment received by hand for {{ $registration->reference }}"
                                                    aria-label="Record a payment received by hand for {{ $registration->reference }}">
                                                {{-- A banknote, matching the Confirm Payment control in
                                                     the shop, so the same act looks the same in both. --}}
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m10-6h2a2 2 0 012 2v6a2 2 0 01-2 2H9a2 2 0 01-2-2v-6a2 2 0 012-2h2m2 5a2 2 0 11-4 0 2 2 0 014 0z"/>
                                                </svg>
                                            </button>
                                        @endif

                                        {{-- Only offered while there is something to chase. A reminder
                                             for a settled or free entry would be nonsense. --}}
                                        @if ($canNotify && $registration->owesBalance())
                                            <form action="{{ route('admin.event.participants.remind', $registration) }}" method="POST"
                                                  onsubmit="return confirm('Email a payment reminder for {{ addslashes($registration->displayName()) }} ({{ $registration->outstandingAmountLabel() }} outstanding)?');">
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

    {{--
        One dialog per entry that can take a payment.

        Rendered outside the table rather than inside the row: a fixed overlay
        nested in a cell with overflow-x-auto is clipped by it, and the row is
        already the widest thing on the page.

        Each carries a real POST form, so it works the same way the rest of this
        admin does and the amount cannot be submitted without being on screen.
    --}}
    @foreach ($registrations as $registration)
        @continue (! $canRecordPayment || ! $registration->owesBalance())

        @php
            $isReopened = (int) $reopenPaymentFor === (int) $registration->id;
            $outstanding = $registration->outstandingAmount();
            $settlement = $isReopened ? old('settlement', 'full') : 'full';
        @endphp

        <div id="payment-modal-{{ $registration->id }}"
             data-payment-modal="{{ $registration->id }}"
             @class(['fixed inset-0 z-50 overflow-y-auto', 'hidden' => ! $isReopened])
             role="dialog"
             aria-modal="true"
             aria-labelledby="payment-title-{{ $registration->id }}">

            <div class="fixed inset-0 bg-gray-900/60" data-close-payment></div>

            <div class="relative min-h-full flex items-start justify-center p-4">
                <div class="relative w-full max-w-lg bg-white rounded-xl shadow-2xl my-8">

                    <div class="flex items-start justify-between gap-4 px-6 py-4 border-b border-gray-200">
                        <div class="min-w-0">
                            <h2 id="payment-title-{{ $registration->id }}" class="text-lg font-bold text-gray-900">
                                Record a payment
                            </h2>
                            <p class="text-xs text-gray-500 mt-0.5">
                                {{ $registration->reference }} &middot; {{ $registration->displayName() }}
                            </p>
                        </div>

                        <button type="button" data-close-payment
                                class="p-1 rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition shrink-0"
                                aria-label="Close">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    <form action="{{ route('admin.event.participants.payment', $registration) }}" method="POST"
                          class="px-6 py-5 space-y-4">
                        @csrf

                        {{-- Names the entry whose form was open, so a rejected submit
                             reopens this dialog and not somebody else's. --}}
                        <input type="hidden" name="record_payment_for" value="{{ $registration->id }}">

                        @if ($isReopened)
                            @error('record_payment')
                                <p class="rounded-lg border border-red-200 bg-red-50 px-3.5 py-2.5 text-sm text-red-800">{{ $message }}</p>
                            @enderror
                        @endif

                        {{-- What is owed, stated before anything is typed. --}}
                        <div class="rounded-lg border border-amber-200 bg-amber-50 px-3.5 py-3">
                            <div class="flex items-baseline justify-between gap-3">
                                <span class="text-xs font-semibold uppercase tracking-wide text-amber-700">Outstanding</span>
                                <span class="text-lg font-bold text-amber-900 tabular-nums">{{ $registration->outstandingAmountLabel() }}</span>
                            </div>

                            <p class="text-xs text-amber-800 mt-1">
                                @if ($registration->amountPaid() > 0)
                                    Charged {{ $registration->amountLabel() }}, of which
                                    {{ $registration->amountPaidLabel() }} has already been received.
                                @else
                                    Charged {{ $registration->amountLabel() }}, nothing received yet.
                                @endif
                            </p>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label for="received_date_{{ $registration->id }}" class="block text-sm font-semibold text-gray-700 mb-1.5">
                                    Date received <span class="text-red-600" aria-hidden="true">*</span>
                                </label>
                                <input type="date" id="received_date_{{ $registration->id }}" name="received_date" required
                                       max="{{ now()->toDateString() }}"
                                       value="{{ $isReopened ? old('received_date') : now()->toDateString() }}"
                                       class="{{ $filterInput }} w-full">
                                @if ($isReopened)
                                    @error('received_date') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                                @endif
                            </div>

                            <div>
                                <label for="received_time_{{ $registration->id }}" class="block text-sm font-semibold text-gray-700 mb-1.5">
                                    Time received <span class="text-red-600" aria-hidden="true">*</span>
                                </label>
                                <input type="time" id="received_time_{{ $registration->id }}" name="received_time" required
                                       value="{{ $isReopened ? old('received_time') : now()->format('H:i') }}"
                                       class="{{ $filterInput }} w-full">
                                @if ($isReopened)
                                    @error('received_time') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                                @endif
                            </div>
                        </div>

                        <p class="text-xs text-gray-500 -mt-1">
                            When the money actually arrived, not now. This is the date a bank statement
                            will agree with, and it is what the Settlements screen groups by.
                        </p>

                        <div>
                            <label for="reference_{{ $registration->id }}" class="block text-sm font-semibold text-gray-700 mb-1.5">
                                Reference
                            </label>
                            <input type="text" id="reference_{{ $registration->id }}" name="reference" maxlength="190"
                                   value="{{ $isReopened ? old('reference') : '' }}"
                                   placeholder="Transfer or receipt number"
                                   class="{{ $filterInput }} w-full">
                            <p class="text-xs text-gray-500 mt-1">
                                Optional, because cash across a counter has none. Worth filling in for a
                                transfer: it becomes searchable on this screen.
                            </p>
                            @if ($isReopened)
                                @error('reference') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                            @endif
                        </div>

                        <fieldset>
                            <legend class="block text-sm font-semibold text-gray-700 mb-1.5">
                                How much arrived <span class="text-red-600" aria-hidden="true">*</span>
                            </legend>

                            <div class="space-y-2">
                                <label class="flex items-center gap-3 rounded-lg border border-gray-300 px-3.5 py-2.5 cursor-pointer transition hover:border-blue-300 has-checked:border-blue-600 has-checked:bg-blue-50">
                                    <input type="radio" name="settlement" value="full" required
                                           data-settlement="{{ $registration->id }}"
                                           @checked($settlement === 'full')
                                           class="shrink-0 text-blue-600 focus:ring-2 focus:ring-blue-500/40">
                                    <span class="text-sm text-gray-900">
                                        <span class="font-semibold">The full balance</span>
                                        <span class="text-gray-500">&middot; {{ $registration->outstandingAmountLabel() }}</span>
                                    </span>
                                </label>

                                <label class="flex items-center gap-3 rounded-lg border border-gray-300 px-3.5 py-2.5 cursor-pointer transition hover:border-blue-300 has-checked:border-blue-600 has-checked:bg-blue-50">
                                    <input type="radio" name="settlement" value="partial"
                                           data-settlement="{{ $registration->id }}"
                                           @checked($settlement === 'partial')
                                           class="shrink-0 text-blue-600 focus:ring-2 focus:ring-blue-500/40">
                                    <span class="text-sm font-semibold text-gray-900">Part of it</span>
                                </label>
                            </div>

                            @if ($isReopened)
                                @error('settlement') <p class="text-xs text-red-600 mt-1.5">{{ $message }}</p> @enderror
                            @endif

                            <div data-partial-amount="{{ $registration->id }}"
                                 @class(['mt-3', 'hidden' => $settlement !== 'partial'])>
                                <label for="amount_{{ $registration->id }}" class="block text-sm font-semibold text-gray-700 mb-1.5">
                                    Amount received (RM)
                                </label>
                                <input type="number" id="amount_{{ $registration->id }}" name="amount"
                                       step="0.01" min="0.01" max="{{ $outstanding }}"
                                       value="{{ $isReopened ? old('amount') : '' }}"
                                       class="{{ $filterInput }} w-full max-w-40 text-right tabular-nums">
                                <p class="text-xs text-gray-500 mt-1">
                                    At most {{ $registration->outstandingAmountLabel() }}. More than is owed is
                                    refused rather than left as a credit nobody is tracking.
                                </p>
                                @if ($isReopened)
                                    @error('amount') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                                @endif
                            </div>
                        </fieldset>

                        <div>
                            <label for="note_{{ $registration->id }}" class="block text-sm font-semibold text-gray-700 mb-1.5">
                                Note
                            </label>
                            <input type="text" id="note_{{ $registration->id }}" name="note" maxlength="255"
                                   value="{{ $isReopened ? old('note') : '' }}"
                                   placeholder="e.g. Transferred to Maybank, receipt seen by phone"
                                   class="{{ $filterInput }} w-full">
                            @if ($isReopened)
                                @error('note') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                            @endif
                        </div>

                        {{-- Said next to the button, because pressing it is an assertion
                             that somebody saw the money, and nothing here can check it. --}}
                        <p class="text-xs text-gray-500 leading-relaxed pt-1 border-t border-gray-100">
                            Only record this once the money is actually in the account. It counts towards
                            the event's takings, and settling the balance in full confirms the entry and
                            emails the entrant.
                        </p>

                        <div class="flex items-center justify-end gap-3 pt-1">
                            <button type="button" data-close-payment
                                    class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                                Cancel
                            </button>

                            <button type="submit"
                                    class="inline-flex items-center gap-2 bg-green-600 text-white px-5 py-2.5 rounded-lg text-sm font-semibold hover:bg-green-700 transition shadow-sm">
                                <x-admin.icon name="cash" class="w-4 h-4" />
                                Record payment
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endforeach
@endsection

@push('scripts')
<script>
    /*
     | Opening and closing the record-a-payment dialogs.
     |
     | One dialog per row, each holding an ordinary POST form. The script only shows
     | and hides them and toggles the amount box; nothing about the submission
     | depends on JavaScript, and a dialog reopened by the server after a failed
     | validation is already visible before this runs.
     */
    (function () {
        const dialogs = Array.from(document.querySelectorAll('[data-payment-modal]'));

        if (dialogs.length === 0) {
            return;
        }

        function dialogFor(id) {
            return dialogs.find((node) => node.dataset.paymentModal === String(id)) || null;
        }

        function open(dialog) {
            dialog.classList.remove('hidden');

            // The scroll lock belongs to whichever dialog is open, and only one can
            // be, so it is set here rather than counted.
            document.body.classList.add('overflow-hidden');

            const first = dialog.querySelector('input:not([type=hidden]):not([disabled])');

            if (first) {
                first.focus();
            }
        }

        function close(dialog) {
            dialog.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }

        function closeAll() {
            dialogs.forEach(close);
        }

        document.querySelectorAll('[data-open-payment]').forEach(function (trigger) {
            trigger.addEventListener('click', function () {
                const dialog = dialogFor(trigger.dataset.openPayment);

                if (dialog) {
                    closeAll();
                    open(dialog);
                }
            });
        });

        dialogs.forEach(function (dialog) {
            dialog.querySelectorAll('[data-close-payment]').forEach(function (trigger) {
                trigger.addEventListener('click', function () {
                    close(dialog);
                });
            });
        });

        // Escape closes whichever is open, which is what anybody expects of a dialog.
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeAll();
            }
        });

        // The amount box only applies to a part payment. Hidden rather than removed
        // so a value typed before switching to full is still there on switching back.
        document.querySelectorAll('[data-settlement]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                const box = document.querySelector('[data-partial-amount="' + radio.dataset.settlement + '"]');

                if (box) {
                    box.classList.toggle('hidden', radio.value !== 'partial');
                }
            });
        });

        // A server-reopened dialog is visible from the markup, so the scroll lock
        // has to be applied to match it.
        if (dialogs.some((dialog) => !dialog.classList.contains('hidden'))) {
            document.body.classList.add('overflow-hidden');
        }
    })();
</script>
@endpush
