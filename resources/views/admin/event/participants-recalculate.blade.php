@extends('layouts.admin')

@section('title', 'Recheck totals · ' . $event->title)

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>Event</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <a href="{{ route('admin.event.participants', ['tab' => 'group', 'event' => $event->id]) }}" class="hover:text-gray-700 transition">Participants</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">Recheck Totals</span>
@endsection

@section('content')
    @php
        use App\Models\EventRegistration;

        $payTones = [
            EventRegistration::PAYMENT_UNPAID => 'gray',
            EventRegistration::PAYMENT_PENDING => 'amber',
            EventRegistration::PAYMENT_PARTIAL => 'blue',
            EventRegistration::PAYMENT_PAID => 'green',
            EventRegistration::PAYMENT_FAILED => 'red',
            EventRegistration::PAYMENT_REFUNDED => 'purple',
        ];

        // What the corrections add up to, so the operator sees the size of the press
        // before making it rather than afterwards.
        $moved = round(collect($changing)->sum(fn ($correction) => $correction->difference()), 2);
    @endphp

    <x-admin.page-card
        title="Recheck add-on totals"
        :description="$event->title"
        :back="route('admin.event.participants', ['tab' => 'group', 'event' => $event->id])">

        @include('admin.partials.flash')

        {{-- ---------------- What this is for ---------------- --}}
        <x-admin.panel title="What this does" icon="clipboard">
            <div class="px-5 py-4 space-y-3">
                <p class="text-sm text-gray-700 leading-relaxed">
                    An item's own price used to be one charge for the whole registration, so a
                    group of six each choosing a RM 40.00 shirt was charged RM 40.00 rather than
                    RM 240.00. New registrations are priced correctly once the event charges
                    items per participant. This re-prices the entries that were taken before
                    that, using the same arithmetic.
                </p>

                <p class="text-sm text-gray-700 leading-relaxed">
                    Nothing is written until you tick the confirmation at the foot of this page.
                    Entries already charging the right amount are left untouched, so running it
                    twice changes nothing the second time, and every entry it does change is
                    recorded in the activity log with its old and new totals.
                </p>

                @if ($event->chargesAddonsPerParticipant())
                    <p class="text-sm text-green-800 rounded-lg border border-green-200 bg-green-50 px-3.5 py-2.5">
                        <span class="font-semibold">{{ $event->title }}</span> charges items per
                        participant, so the figures below are what each entry should be paying.
                    </p>
                @else
                    {{-- Said plainly rather than left to be inferred from a column of
                         zeroes: without the setting on, the arithmetic cannot find
                         anything to correct and the screen would look like good news. --}}
                    <div class="rounded-lg border border-amber-200 bg-amber-50 px-3.5 py-3">
                        <p class="text-sm font-semibold text-amber-900 mb-0.5">
                            This event does not charge items per participant yet
                        </p>
                        <p class="text-sm text-amber-800">
                            So nothing here can change. Switch on
                            <span class="font-semibold">Each participant is charged for their own add-ons</span>
                            on
                            <a href="{{ route('admin.event.registration.edit', $event) }}"
                               class="font-semibold text-amber-900 underline hover:no-underline">the event's settings</a>,
                            then come back to this screen.
                        </p>
                    </div>
                @endif
            </div>
        </x-admin.panel>

        {{-- ---------------- Entries that would change ---------------- --}}
        <x-admin.panel :title="'Would change · ' . count($changing) . ' ' . Str::plural('entry', count($changing))" icon="warning" :flush="true">
            @if ($changing === [])
                <p class="px-5 py-6 text-sm text-gray-500">
                    Nothing to correct. Every entry on this event is already charging what its
                    items add up to.
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <caption class="sr-only">Entries whose total would change on {{ $event->title }}</caption>
                        <thead class="bg-gray-50 text-left">
                            <tr>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Reference</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500 text-center">People</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500 text-right">Charged now</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500 text-right">Should be</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500 text-right">Difference</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500 text-right">Received</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Payment becomes</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($changing as $correction)
                                <tr class="align-top">
                                    <td class="px-5 py-3 whitespace-nowrap">
                                        <a href="{{ route('admin.event.participants.show', $correction->registration) }}"
                                           class="font-semibold text-blue-600 hover:underline">
                                            {{ $correction->registration->reference }}
                                        </a>
                                        <span class="block text-xs text-gray-500">{{ $correction->registration->displayName() }}</span>
                                    </td>
                                    <td class="px-5 py-3 text-center text-gray-600 tabular-nums">{{ $correction->people }}</td>
                                    <td class="px-5 py-3 text-right text-gray-600 tabular-nums whitespace-nowrap">{{ $correction->currentAmountLabel() }}</td>
                                    <td class="px-5 py-3 text-right font-semibold text-gray-900 tabular-nums whitespace-nowrap">{{ $correction->correctedAmountLabel() }}</td>
                                    <td class="px-5 py-3 text-right font-semibold text-amber-700 tabular-nums whitespace-nowrap">{{ $correction->differenceLabel() }}</td>
                                    <td class="px-5 py-3 text-right text-gray-600 tabular-nums whitespace-nowrap">{{ $correction->registration->amountPaidLabel() }}</td>
                                    <td class="px-5 py-3 whitespace-nowrap">
                                        <x-admin.badge :tone="$payTones[$correction->correctedPaymentStatus()] ?? 'gray'">
                                            {{ $correction->correctedPaymentStatusLabel() }}
                                        </x-admin.badge>
                                        @if ($correction->correctedOutstanding() > 0.005)
                                            <span class="block text-xs text-gray-500 mt-0.5">
                                                {{ $correction->correctedOutstandingLabel() }} outstanding
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-gray-50">
                            <tr>
                                <td colspan="4" class="px-5 py-3 text-right text-xs font-bold uppercase tracking-wide text-gray-500">
                                    Added to what is owed
                                </td>
                                <td class="px-5 py-3 text-right text-base font-bold text-amber-800 tabular-nums whitespace-nowrap">
                                    RM {{ number_format($moved, 2) }}
                                </td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </x-admin.panel>

        {{-- ---------------- Entries that would not ---------------- --}}
        <x-admin.panel :title="'No change needed · ' . count($unchanged) . ' ' . Str::plural('entry', count($unchanged))" icon="check" :flush="true">
            @if ($unchanged === [])
                <p class="px-5 py-6 text-sm text-gray-500">There are no entries on this event.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <caption class="sr-only">Entries that would be left untouched on {{ $event->title }}</caption>
                        <thead class="bg-gray-50 text-left">
                            <tr>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Reference</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500 text-center">People</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500 text-right">Charged</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Payment</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Why it is left alone</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($unchanged as $correction)
                                <tr class="align-top">
                                    <td class="px-5 py-3 whitespace-nowrap">
                                        <a href="{{ route('admin.event.participants.show', $correction->registration) }}"
                                           class="font-semibold text-blue-600 hover:underline">
                                            {{ $correction->registration->reference }}
                                        </a>
                                        <span class="block text-xs text-gray-500">{{ $correction->registration->displayName() }}</span>
                                    </td>
                                    <td class="px-5 py-3 text-center text-gray-600 tabular-nums">{{ $correction->people }}</td>
                                    <td class="px-5 py-3 text-right text-gray-900 tabular-nums whitespace-nowrap">{{ $correction->currentAmountLabel() }}</td>
                                    <td class="px-5 py-3 whitespace-nowrap">
                                        <x-admin.badge :tone="$payTones[$correction->registration->payment_status] ?? 'gray'">
                                            {{ $correction->registration->paymentStatusLabel() }}
                                        </x-admin.badge>
                                    </td>
                                    <td class="px-5 py-3 text-xs text-gray-500">
                                        {{ $correction->blocked ?? 'Already charging the right amount.' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-admin.panel>

        {{-- ---------------- Apply ---------------- --}}
        @if ($changing !== [])
            <x-admin.panel title="Apply these corrections" icon="cash">
                <form action="{{ route('admin.event.participants.recalculate.apply', $event) }}" method="POST"
                      class="px-5 py-4 space-y-4"
                      onsubmit="return confirm('Apply the corrected totals to {{ count($changing) }} {{ count($changing) === 1 ? 'entry' : 'entries' }} on {{ addslashes($event->title) }}?\n\nThis adds RM {{ number_format($moved, 2) }} to what is owed. Entries already charging the right amount are left untouched.');">
                    @csrf

                    @error('confirm')
                        <p class="rounded-lg border border-red-200 bg-red-50 px-3.5 py-2.5 text-sm text-red-800">{{ $message }}</p>
                    @enderror

                    <label class="flex items-start gap-3 rounded-lg border border-gray-300 px-3.5 py-3 cursor-pointer transition hover:border-blue-300 has-checked:border-blue-600 has-checked:bg-blue-50">
                        <input type="checkbox" name="confirm" value="1"
                               class="mt-0.5 shrink-0 rounded text-blue-600 focus:ring-2 focus:ring-blue-500/40">
                        <span class="text-sm text-gray-900">
                            <span class="block font-semibold">
                                I have read the figures above and want them written
                            </span>
                            <span class="block text-xs text-gray-600 mt-0.5">
                                An entry that has already paid the full corrected amount keeps reading
                                Paid. One that paid the old lower figure becomes Partly Paid with the
                                balance outstanding, and can then be sent a request for that balance.
                                One that has paid nothing stays as it is and simply owes more.
                            </span>
                        </span>
                    </label>

                    <div class="flex items-center justify-end gap-3">
                        <a href="{{ route('admin.event.participants', ['tab' => 'group', 'event' => $event->id]) }}"
                           class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                            Cancel
                        </a>

                        <button type="submit"
                                class="inline-flex items-center gap-2 bg-blue-600 text-white px-5 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-sm">
                            <x-admin.icon name="cash" class="w-4 h-4" />
                            Apply corrected totals
                        </button>
                    </div>
                </form>
            </x-admin.panel>
        @endif
    </x-admin.page-card>
@endsection
