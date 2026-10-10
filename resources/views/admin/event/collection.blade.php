@extends('layouts.admin')

@section('title', 'Collection')

@section('breadcrumb')
    @include('admin.partials.breadcrumb-root')
    <span>Event</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">Collection</span>
@endsection

@section('content')
    @php
        use App\Models\EventRegistration;
        use App\Support\HandoverSheet;

        $filterInput = 'rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 bg-white focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';

        $payTones = [
            EventRegistration::PAYMENT_UNPAID => 'gray',
            EventRegistration::PAYMENT_PENDING => 'amber',
            EventRegistration::PAYMENT_PARTIAL => 'blue',
            EventRegistration::PAYMENT_PAID => 'green',
            EventRegistration::PAYMENT_FAILED => 'red',
            EventRegistration::PAYMENT_REFUNDED => 'purple',
        ];

        // Carried onto the export link and the reset, so the file and the screen
        // always describe the same rows.
        $activeFilters = array_filter([
            'q' => $search,
            'event' => $eventId,
            'state' => $state,
            'choice' => $choice,
            'payment' => $payment,
        ], fn ($value) => $value !== '');

        /*
         | The page's rows, grouped by entry.
         |
         | Grouped because a handover is a batch: a grouping of six takes six shirts in
         | one press, and the group header is where that press lives. Built from the
         | already-loaded participants, one row per person per item they are owed.
         */
        $grouped = $participants
            ->groupBy('event_registration_id');
    @endphp

    <x-admin.page-card
        title="Collection"
        description="Hand the shirts out. One row per person per item, so a grouping of six can collect six sizes at six different moments."
        :flush="true">

        <x-slot:actions>
            {{-- Handed over against total, over the rows the filters describe. The
                 caption under it names the scope, the way the Participants screen
                 names the scope of its money badges: a figure that quietly covered
                 more than the screen is how an operator comes to believe a job is
                 finished. --}}
            <div class="flex flex-wrap items-center gap-2">
                <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-2">
                    <span class="block text-xs font-semibold uppercase tracking-wide text-green-700">Handed Over</span>
                    <span class="block text-base font-bold text-green-900 tabular-nums">
                        {{ $figures['collected'] }} of {{ $figures['total'] }}
                    </span>
                </div>

                <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-2">
                    <span class="block text-xs font-semibold uppercase tracking-wide text-amber-700">Still Waiting</span>
                    <span class="block text-base font-bold text-amber-900 tabular-nums">
                        {{ $figures['outstanding'] }}
                    </span>
                </div>

                @if ($figures['missing'] > 0)
                    <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-2">
                        <span class="block text-xs font-semibold uppercase tracking-wide text-red-700">No Option Yet</span>
                        <span class="block text-base font-bold text-red-900 tabular-nums">
                            {{ $figures['missing'] }}
                        </span>
                    </div>
                @endif
            </div>
        </x-slot:actions>

        <div class="px-6 pt-4">
            @include('admin.partials.flash')

            <p class="text-xs text-gray-500 mb-4">{{ $scopeLabel }}</p>
        </div>

        <x-admin.filter-bar
            :action="route('admin.event.collection')"
            :reset="$isFiltered ? route('admin.event.collection') : null">

            <div class="relative flex-1 min-w-56">
                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" aria-hidden="true">
                    <x-admin.icon name="search" class="w-4 h-4" />
                </span>
                <label for="q" class="sr-only">Search by identity card, name or reference</label>
                {{-- autofocus, because the first thing a counter does is type or scan
                     an identity card with people waiting. --}}
                <input type="search" id="q" name="q" value="{{ $search }}" autofocus
                       placeholder="Scan or type an IC, a name, or a reference..."
                       class="w-full rounded-lg border border-gray-300 pl-9 pr-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
            </div>

            <label for="event" class="sr-only">Event</label>
            <select id="event" name="event" class="{{ $filterInput }}">
                <option value="">All Events</option>
                @foreach ($events as $id => $title)
                    <option value="{{ $id }}" @selected((string) $eventId === (string) $id)>{{ $title }}</option>
                @endforeach
            </select>

            <label for="state" class="sr-only">Collected or not</label>
            <select id="state" name="state" class="{{ $filterInput }}">
                <option value="">Collected &amp; Not</option>
                @foreach ($states as $value => $label)
                    <option value="{{ $value }}" @selected($state === $value)>{{ $label }}</option>
                @endforeach
            </select>

            <label for="choice" class="sr-only">Option recorded</label>
            <select id="choice" name="choice" class="{{ $filterInput }}">
                <option value="">Any Option</option>
                <option value="missing" @selected($choice === 'missing')>No Option Recorded</option>
            </select>

            <label for="payment" class="sr-only">Payment position</label>
            <select id="payment" name="payment" class="{{ $filterInput }}">
                <option value="">Any Payment</option>
                @foreach ($payments as $value => $label)
                    <option value="{{ $value }}" @selected($payment === $value)>{{ $label }}</option>
                @endforeach
            </select>

            @if ($canExport)
                <x-slot:actions>
                    {{-- The printed fallback. Venue signal is unreliable and a counter
                         that loses this screen still has four hundred shirts to give
                         out, so the file is ordered the way the boxes are stacked.
                         Scoped to one event, like the Participants export it follows:
                         one file holding every identity card number ever collected is
                         a different risk from one event's. --}}
                    @if ($eventId !== '')
                        <a href="{{ route('admin.event.collection.export', $activeFilters) }}"
                           title="One row per person per item, grouped by option. Carries identity card numbers."
                           class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3.5 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                            <x-admin.icon name="archive" class="w-4 h-4" />
                            Export CSV
                        </a>
                    @else
                        <span title="Choose an event first. A file covering every event would carry more personal data than any one counter needs."
                              aria-disabled="true"
                              class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-gray-50 px-3.5 py-2 text-sm font-semibold text-gray-400 cursor-not-allowed">
                            <x-admin.icon name="archive" class="w-4 h-4" />
                            Export CSV
                        </span>
                    @endif
                </x-slot:actions>
            @endif
        </x-admin.filter-bar>

        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500">Participant</th>
                        <th scope="col" class="px-6 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500">Entry</th>
                        <th scope="col" class="px-6 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500">Item &amp; Option</th>
                        <th scope="col" class="px-6 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500">Payment</th>
                        <th scope="col" class="px-6 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500">Collected</th>
                        <th scope="col" class="px-6 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500 text-right">Action</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-100">
                    @forelse ($grouped as $registrationId => $people)
                        @php
                            $entry = $people->first()->registration;
                            $outstanding = $entry === null ? [] : HandoverSheet::outstandingFor($entry);
                        @endphp

                        {{-- The batch press. One collector and one code covering every
                             row on the entry, because pressing six buttons for one
                             family is how a counter falls behind. --}}
                        <tr class="bg-gray-50/80">
                            <td colspan="6" class="px-6 py-2">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <p class="text-xs font-semibold text-gray-600">
                                        <span class="tabular-nums">{{ $entry?->reference }}</span>
                                        <span class="text-gray-300 mx-1">/</span>
                                        {{ $entry?->displayName() }}
                                        <span class="text-gray-300 mx-1">/</span>
                                        {{ $entry?->event?->title }}
                                    </p>

                                    @if ($canCollect && count($outstanding) > 1)
                                        <button type="button"
                                                data-open-dialog="collect-entry-{{ $registrationId }}"
                                                data-collection-all="1"
                                                class="inline-flex items-center gap-1.5 rounded-lg border border-green-300 bg-green-50 px-3 py-1.5 text-xs font-semibold text-green-800 hover:bg-green-100 transition">
                                            <x-admin.icon name="bag" class="w-3.5 h-3.5" />
                                            Hand Over All {{ count($outstanding) }}
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>

                        @foreach ($people as $person)
                            @foreach (HandoverSheet::rowsForParticipant($person) as $row)
                                @php
                                    $handover = $row['handover'];
                                    $registration = $row['registration'];
                                @endphp

                                <tr class="hover:bg-gray-50/60 transition">
                                    {{-- Name and identity card together, because the
                                         card is what the counter actually checks: the
                                         person is verified by the document in their
                                         hand matching the number on the record. --}}
                                    <td class="px-6 py-3 align-top">
                                        <p class="text-sm font-semibold text-gray-900">{{ $row['participant']->full_name }}</p>
                                        <p class="text-xs text-gray-500 tabular-nums mt-0.5">
                                            {{ $row['participant']->ic_number ?: 'No IC on record' }}
                                        </p>
                                    </td>

                                    <td class="px-6 py-3 align-top">
                                        <p class="text-sm text-gray-900 tabular-nums">{{ $registration->reference }}</p>
                                        <p class="text-xs text-gray-500 mt-0.5">{{ $registration->displayName() }}</p>
                                    </td>

                                    <td class="px-6 py-3 align-top">
                                        <p class="text-sm text-gray-900">{{ $row['addon']->name }}</p>
                                        @if ($row['option'] !== null)
                                            <p class="text-xs font-semibold text-gray-700 mt-0.5">{{ $row['option'] }}</p>
                                        @elseif ($row['collects_choice'])
                                            {{-- The common case, and the reason this
                                                 screen can take a choice at the desk:
                                                 most people never answered the size
                                                 link. --}}
                                            <x-admin.badge tone="red" class="mt-0.5">No option recorded</x-admin.badge>
                                        @else
                                            <p class="text-xs text-gray-400 mt-0.5">No option</p>
                                        @endif
                                    </td>

                                    {{-- Prominent on every row, not only on the entry.
                                         A part-paid grouping will turn up on the day
                                         and the operator has to see it before they
                                         hand anything over. --}}
                                    <td class="px-6 py-3 align-top">
                                        <x-admin.badge :tone="$payTones[$registration->payment_status] ?? 'gray'" :dot="true">
                                            {{ $registration->paymentStatusLabel() }}
                                        </x-admin.badge>
                                        @if ($registration->owesBalance())
                                            <p class="text-xs font-semibold text-amber-700 tabular-nums mt-1">
                                                {{ $registration->outstandingAmountLabel() }} owed
                                            </p>
                                        @endif
                                    </td>

                                    <td class="px-6 py-3 align-top">
                                        @if ($handover === null)
                                            <x-admin.badge tone="gray">Not collected</x-admin.badge>
                                        @else
                                            <x-admin.badge :tone="$handover->isVerified() ? 'green' : ($handover->byBuyer() ? 'blue' : 'red')" :dot="true">
                                                {{ $handover->isVerified() ? 'SMS verified' : ($handover->byBuyer() ? 'IC checked' : 'No SMS check') }}
                                            </x-admin.badge>

                                            <p class="text-xs text-gray-700 mt-1">{{ $handover->collector_name }}</p>
                                            <p class="text-xs text-gray-500 tabular-nums">
                                                {{ \App\Support\LocalTime::format($handover->collected_at) }}
                                            </p>
                                            @if ($handover->confirmed_by_label)
                                                <p class="text-xs text-gray-400 mt-0.5">by {{ $handover->confirmed_by_label }}</p>
                                            @endif
                                            @if ($handover->wasHandedOverUnpaid())
                                                <p class="text-xs text-red-600 mt-0.5">Money owed: {{ $handover->payment_override_reason }}</p>
                                            @endif
                                        @endif
                                    </td>

                                    <td class="px-6 py-3 align-top text-right">
                                        @if ($handover !== null)
                                            <span class="text-xs text-gray-400">Done</span>
                                        @elseif ($canCollect)
                                            <button type="button"
                                                    data-open-dialog="collect-entry-{{ $registrationId }}"
                                                    data-collection-only="{{ $row['key'] }}"
                                                    class="inline-flex items-center gap-1.5 rounded-lg bg-green-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-green-700 transition shadow-sm">
                                                <x-admin.icon name="check" class="w-3.5 h-3.5" />
                                                Hand Over
                                            </button>
                                        @else
                                            <span class="text-xs text-gray-400">No permission</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        @endforeach
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-16 text-center">
                                <x-admin.icon name="bag" class="w-10 h-10 mx-auto text-gray-300" />
                                <p class="text-sm font-semibold text-gray-700 mt-3">Nothing to hand over</p>
                                <p class="text-sm text-gray-500 mt-1">
                                    @if ($isFiltered)
                                        No row matches these filters. Clear them and try again.
                                    @else
                                        This appears once an event has an add-on ticked as handed over at the event, and somebody has registered for it.
                                    @endif
                                </p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($participants->hasPages())
            <div class="px-6 py-4 border-t border-gray-200">
                {{ $participants->links() }}
            </div>
        @endif
    </x-admin.page-card>

    {{-- One dialog per entry on the page, declared outside the table because a div is
         not valid inside a tbody. Same hand-written pattern as the shop's own
         Confirm Collection: a hidden div with role=dialog, a backdrop that closes it,
         and the small script below. No library. --}}
    @if ($canCollect)
        @foreach ($entries as $entry)
            @include('admin.event.partials.collection-dialog', ['entry' => $entry])
        @endforeach
    @endif
@endsection

@push('scripts')
    @if ($canCollect)
        @include('admin.event.partials.collection-script')
    @endif
@endpush
