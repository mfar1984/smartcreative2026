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
        // before making it rather than afterwards. Over the rows whose total moves
        // only: the re-itemised ones add nothing to what is owed by definition, and
        // folding them in here would make the figure unreadable.
        $moved = round(collect($changing)->sum(fn ($correction) => $correction->difference()), 2);

        // How many people the re-itemised rows account for, which is the number the
        // shirt list is short by until this is applied.
        $reitemisedPeople = collect($reitemised)->sum(fn ($correction) => $correction->people);
        $reitemisedValue = round(collect($reitemised)->sum(fn ($correction) => $correction->correctedAddonsTotal), 2);
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
                    An item's own price used to be one charge for the whole registration, and
                    before that it was the event fee itself. Either way a group of six each
                    taking a RM 40.00 shirt was charged RM 40.00 rather than RM 240.00. New
                    registrations are priced correctly once the event charges items per
                    participant. This re-prices the entries taken before that, using the same
                    arithmetic.
                </p>

                <p class="text-sm text-gray-700 leading-relaxed">
                    What each entry should pay is worked out from the number of people named on
                    it and this event's items as they stand today: this event's fee of
                    <span class="font-semibold">{{ \App\Support\PaymentFigures::money($event->registrationAmount()) }}</span>
                    charged once, plus
                    one of every required per-person item for everybody on the entry. It is not
                    read off the lines already stored, because the entries that were charged as
                    an event fee have no item lines at all, and the fee they carry is the old
                    one &mdash; counting it again would charge the same money twice.
                </p>

                {{-- The second job, said plainly. Without this paragraph the lower
                     table reads as a column of RM 0.00 differences and looks like
                     nothing worth pressing. --}}
                <p class="text-sm text-gray-700 leading-relaxed">
                    It also checks something the total cannot show. An entry taken while the
                    shirt <em>was</em> the event fee carries that money under
                    <span class="font-semibold">registration fee</span> with no item line
                    behind it. For a one-person entry that is already the right total, so there
                    is nothing to re-price &mdash; but the shirt list is built from item lines,
                    so those people are missing from it, and their money is counted as
                    registration income on an event whose fee is
                    {{ \App\Support\PaymentFigures::money($event->registrationAmount()) }}.
                    Those entries are re-itemised: the same money, moved onto a line per
                    person, and <span class="font-semibold">the amount does not change by a
                    sen</span>. Nothing about what has been received, settled or refunded is
                    touched, and no size's stock count moves.
                </p>

                <p class="text-sm text-gray-700 leading-relaxed">
                    Nothing is written until you tick the confirmation at the foot of this page.
                    Entries already charging the right amount, itemised the right way, are left
                    untouched, so running it twice changes nothing the second time, and every
                    entry it does change is recorded in the activity log with its old and new
                    figures.
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

        {{-- ---------------- The total itself is wrong ---------------- --}}
        <x-admin.panel :title="'Total is wrong · ' . count($changing) . ' ' . Str::plural('entry', count($changing))" icon="warning" :flush="true">
            @if ($changing === [])
                <p class="px-5 py-6 text-sm text-gray-500">
                    No entry on this event is charging the wrong total. Every one of them adds
                    up to what its items come to.
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
                                    <td class="px-5 py-3 text-center text-gray-600 tabular-nums">
                                        {{ $correction->people }}
                                        {{-- Said here because it is the one part of the correction
                                             that adds rows rather than changing figures, and the
                                             organiser has a size to collect at the counter. --}}
                                        @if ($correction->additionsCount() > 0)
                                            <span class="block text-xs text-gray-500">
                                                {{ $correction->additionsCount() }} with no size on record
                                            </span>
                                        @endif
                                    </td>
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

        {{-- ---------------- The total is right, the items do not describe it ----------------

             A table of its own, with the amount shown twice on purpose. The whole
             question an owner has about this press is "does my money move", and the
             answer has to be readable along the row rather than taken on trust from a
             paragraph above it. --}}
        <x-admin.panel :title="'Total is right, items do not describe it · ' . count($reitemised) . ' ' . Str::plural('entry', count($reitemised))" icon="clipboard" :flush="true">
            @if ($reitemised === [])
                <p class="px-5 py-6 text-sm text-gray-500">
                    Nothing to re-itemise. Every entry on this event already carries its charge
                    on item lines rather than as a registration fee.
                </p>
            @else
                <div class="px-5 py-4 border-b border-gray-100">
                    <p class="text-sm text-gray-700 leading-relaxed">
                        These {{ count($reitemised) }} {{ Str::plural('entry', count($reitemised)) }}
                        are charging the right total and describing it wrongly: the money sits
                        under registration fee with no item line behind it. The shirt list is
                        {{ $reitemisedPeople }} {{ Str::plural('person', $reitemisedPeople) }}
                        short because of it, and
                        {{ \App\Support\PaymentFigures::money($reitemisedValue) }}
                        of item income is being counted as registration fees. Applying this
                        moves that money onto a line per person.
                        <span class="font-semibold">No amount, payment, receipt or stock count
                        changes.</span>
                    </p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <caption class="sr-only">Entries whose charge would be re-itemised at the same amount on {{ $event->title }}</caption>
                        <thead class="bg-gray-50 text-left">
                            <tr>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Reference</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500 text-center">People</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500 text-right">Registration fee</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500 text-right">Items</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500 text-right">Amount</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Payment</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($reitemised as $correction)
                                <tr class="align-top">
                                    <td class="px-5 py-3 whitespace-nowrap">
                                        <a href="{{ route('admin.event.participants.show', $correction->registration) }}"
                                           class="font-semibold text-blue-600 hover:underline">
                                            {{ $correction->registration->reference }}
                                        </a>
                                        <span class="block text-xs text-gray-500">{{ $correction->registration->displayName() }}</span>
                                    </td>
                                    <td class="px-5 py-3 text-center text-gray-600 tabular-nums">
                                        {{ $correction->people }}
                                        @if ($correction->additionsCount() > 0)
                                            <span class="block text-xs text-gray-500">
                                                {{ $correction->additionsCount() }} with no size on record
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3 text-right tabular-nums whitespace-nowrap">
                                        <span class="text-gray-500 line-through">{{ $correction->currentRegistrationFeeLabel() }}</span>
                                        <span class="block text-xs font-semibold text-gray-900">{{ $correction->correctedRegistrationFeeLabel() }}</span>
                                    </td>
                                    <td class="px-5 py-3 text-right tabular-nums whitespace-nowrap">
                                        <span class="text-gray-500 line-through">{{ $correction->currentAddonsTotalLabel() }}</span>
                                        <span class="block text-xs font-semibold text-gray-900">{{ $correction->correctedAddonsTotalLabel() }}</span>
                                    </td>
                                    {{-- The same figure twice. That is the point of the column. --}}
                                    <td class="px-5 py-3 text-right tabular-nums whitespace-nowrap">
                                        <span class="font-semibold text-gray-900">{{ $correction->currentAmountLabel() }}</span>
                                        <span class="block text-xs text-green-700">
                                            stays {{ $correction->correctedAmountLabel() }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-3 whitespace-nowrap">
                                        <x-admin.badge :tone="$payTones[$correction->registration->payment_status] ?? 'gray'">
                                            {{ $correction->registration->paymentStatusLabel() }}
                                        </x-admin.badge>
                                        <span class="block text-xs text-gray-500 mt-0.5">unchanged</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-gray-50">
                            <tr>
                                <td colspan="4" class="px-5 py-3 text-right text-xs font-bold uppercase tracking-wide text-gray-500">
                                    Added to what is owed
                                </td>
                                <td class="px-5 py-3 text-right text-base font-bold text-green-700 tabular-nums whitespace-nowrap">
                                    RM 0.00
                                </td>
                                <td></td>
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
        @php
            // One press writes both kinds, so the confirmation has to count both.
            $pressing = count($changing) + count($reitemised);
        @endphp

        @if ($pressing > 0)
            <x-admin.panel title="Apply these corrections" icon="cash">
                <form action="{{ route('admin.event.participants.recalculate.apply', $event) }}" method="POST"
                      class="px-5 py-4 space-y-4"
                      onsubmit="return confirm('Apply these corrections to {{ $pressing }} {{ $pressing === 1 ? 'entry' : 'entries' }} on {{ addslashes($event->title) }}?\n\n{{ count($changing) }} re-priced, which adds RM {{ number_format($moved, 2) }} to what is owed.\n{{ count($reitemised) }} re-itemised at the same amount, which adds RM 0.00.\n\nEntries already charging the right amount, itemised the right way, are left untouched.');">
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
                                balance outstanding, and can then be sent a request for that balance
                                alone. One that has paid nothing stays as it is and simply owes more.
                            </span>
                            <span class="block text-xs text-gray-600 mt-1.5">
                                An entry in the re-itemised table keeps its amount, its payment, its
                                receipts and its badge exactly as they read now. Only the two columns
                                naming what the charge is <em>for</em> change, so the shirt list and
                                the income figures start counting it as an item.
                            </span>
                            <span class="block text-xs text-gray-600 mt-1.5">
                                Each person is given their own line on the invoice. Where no size was
                                ever recorded for somebody the line is written without one, because
                                the charge is owed for a required item and the size is collected at
                                the counter. No size's stock count is touched.
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
