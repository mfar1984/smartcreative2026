{{--
    The counter itself.

    Search for the team or person standing at the desk, then work down their
    people: read the card, press Check In. A squad place can be handed to
    somebody else right up until that person is checked in.

    @param \Illuminate\Support\Collection $results  matching registrations
    @param \App\Models\EventRegistration|null $open  the entry at the desk
    @param bool $canUpdate
--}}
@php
    use App\Models\EventRegistration;

    $payTones = [
        EventRegistration::PAYMENT_UNPAID => 'gray',
        EventRegistration::PAYMENT_PENDING => 'amber',
        // Blue: some of the money is in, so it reads as neither settled nor untouched.
        EventRegistration::PAYMENT_PARTIAL => 'blue',
        EventRegistration::PAYMENT_PAID => 'green',
        EventRegistration::PAYMENT_FAILED => 'red',
        EventRegistration::PAYMENT_REFUNDED => 'purple',
    ];
@endphp

{{-- ---------------- Search ---------------- --}}
<form action="{{ route('admin.event.attendance') }}" method="GET"
      class="bg-white rounded-lg border border-gray-200 p-5 mb-5">
    <input type="hidden" name="tab" value="attendance">

    <label for="q" class="block text-sm font-bold text-gray-900 mb-1.5">
        Who is at the desk?
    </label>
    <p class="text-xs text-gray-500 mb-3">
        Team name, a person's name, their identity card number, or the registration reference.
    </p>

    <div class="flex flex-wrap items-center gap-2">
        <div class="relative flex-1 min-w-64">
            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" aria-hidden="true">
                <x-admin.icon name="search" class="w-4 h-4" />
            </span>
            {{-- autofocus because this is the first thing a counter touches. --}}
            <input type="search" id="q" name="q" value="{{ $search }}" autofocus
                   placeholder="e.g. Harimau Sibu, Ahmad, 900101015531, REG-2026-0002"
                   class="w-full rounded-lg border border-gray-300 pl-9 pr-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
        </div>

        <label for="event" class="sr-only">Event</label>
        <select id="event" name="event" class="{{ $filterInput }}">
            <option value="">All Events</option>
            @foreach ($events as $id => $title)
                <option value="{{ $id }}" @selected((string) $eventId === (string) $id)>{{ $title }}</option>
            @endforeach
        </select>

        <button type="submit"
                class="rounded-lg bg-blue-600 px-5 py-2 text-sm font-semibold text-white hover:bg-blue-700 transition shadow-sm">
            Search
        </button>

        @if ($isFiltered || $open)
            <a href="{{ route('admin.event.attendance') }}"
               class="rounded-lg px-4 py-2 text-sm font-semibold text-gray-500 hover:text-gray-800 transition">
                Clear
            </a>
        @endif
    </div>
</form>

{{-- ---------------- Results ---------------- --}}
@if ($search !== '' && $results->isEmpty())
    <div class="bg-white rounded-lg border border-gray-200 px-6 py-12 text-center mb-5">
        <x-admin.icon name="search" class="w-10 h-10 mx-auto text-gray-300" />
        <p class="text-sm font-semibold text-gray-700 mt-3">Nothing matched "{{ $search }}"</p>
        <p class="text-sm text-gray-500 mt-1">
            Check the spelling, or try the identity card number instead of the name.
        </p>
    </div>
@elseif ($results->count() > 1)
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-5">
        <div class="px-5 py-3 border-b border-gray-200 bg-gray-50">
            <p class="text-xs font-bold uppercase tracking-wide text-gray-500">
                {{ $results->count() }} matches, pick one
            </p>
        </div>

        <ul class="divide-y divide-gray-100">
            @foreach ($results as $result)
                @php [$arrived, $expected] = $result->attendanceCount(); @endphp

                <li>
                    <a href="{{ route('admin.event.attendance', ['tab' => 'attendance', 'q' => $search, 'event' => $eventId, 'registration' => $result->id]) }}"
                       class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 hover:bg-blue-50/50 transition">
                        <div class="min-w-0">
                            <span class="block text-sm font-semibold text-gray-900">{{ $result->displayName() }}</span>
                            <span class="block text-xs text-gray-500">
                                {{ $result->reference }}
                                <span class="text-gray-300 mx-1">&middot;</span>
                                {{ $result->event?->title }}
                            </span>
                        </div>

                        <div class="flex items-center gap-2 shrink-0">
                            <span class="text-xs text-gray-500 tabular-nums">{{ $arrived }}/{{ $expected }} in</span>
                            <x-admin.badge :tone="$payTones[$result->payment_status] ?? 'gray'">
                                {{ $result->paymentStatusLabel() }}
                            </x-admin.badge>
                        </div>
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
@endif

{{-- ---------------- The entry at the desk ---------------- --}}
@if ($open)
    @php
        [$arrived, $expected] = $open->attendanceCount();
        $warnings = $open->attendanceWarnings();
        $progress = $expected > 0 ? (int) round($arrived / $expected * 100) : 0;
    @endphp

    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">

        {{-- Entry header --}}
        <div class="px-5 py-4 border-b border-gray-200 bg-gray-50">
            <div class="flex flex-wrap items-start justify-between gap-4">
                {{-- Logo beside the name, so the counter can match it against the
                     jersey or the banner the squad turned up with. --}}
                @if ($open->hasLogo())
                    <img src="{{ $open->logoUrl() }}"
                         alt="Logo for {{ $open->displayName() }}"
                         class="w-14 h-14 shrink-0 rounded-lg border border-gray-200 bg-white object-contain p-1">
                @endif

                <div class="min-w-0 flex-1">
                    <h3 class="text-lg font-bold text-gray-900">{{ $open->displayName() }}</h3>
                    <p class="text-sm text-gray-600 mt-0.5">
                        <code class="text-xs">{{ $open->reference }}</code>
                        <span class="text-gray-300 mx-1">&middot;</span>
                        {{ $open->event?->title }}
                        <span class="text-gray-300 mx-1">&middot;</span>
                        {{ ucfirst($open->mode) }}
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2 shrink-0">
                    <x-admin.badge :tone="$payTones[$open->payment_status] ?? 'gray'" dot>
                        {{ $open->paymentStatusLabel() }}
                    </x-admin.badge>
                    <a href="{{ route('admin.event.participants.show', $open) }}"
                       class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 transition">
                        Full Record
                    </a>
                </div>
            </div>

            {{-- Arrival progress --}}
            <div class="mt-3">
                <div class="flex items-center justify-between gap-3 mb-1">
                    <span class="text-xs font-semibold text-gray-600 tabular-nums">
                        {{ $arrived }} of {{ $expected }} checked in
                    </span>
                    @if ($open->isFullyCheckedIn())
                        <span class="text-xs font-bold text-green-700">Everyone is in</span>
                    @endif
                </div>
                <div class="h-1.5 w-full rounded-full bg-gray-200 overflow-hidden"
                     role="progressbar" aria-valuenow="{{ $arrived }}" aria-valuemin="0" aria-valuemax="{{ $expected }}"
                     aria-label="Arrivals for {{ $open->displayName() }}">
                    <div @class([
                        'h-full rounded-full transition-all',
                        'bg-green-500' => $open->isFullyCheckedIn(),
                        'bg-blue-500' => ! $open->isFullyCheckedIn(),
                    ]) style="width: {{ $progress }}%"></div>
                </div>
            </div>
        </div>

        {{--
            What was paid, and the proof behind it.

            The counter is where an argument about money actually happens, so the
            receipts are in front of whoever is standing there rather than one click
            away on the full record. A slip is shown as a picture when it is one: a
            person with a queue behind them should not have to open a second tab to
            check a transfer.

            Only rendered when a payment was recorded by hand. A gateway payment needs
            no defending, and an entry nobody has paid anything towards has nothing to
            show, which the warning strip below already says.
        --}}
        @php $handRecorded = $open->payments->where('source', 'manual'); @endphp

        @if ($handRecorded->isNotEmpty())
            <div class="border-b border-gray-200 bg-blue-50/40 px-5 py-4">
                <div class="flex flex-wrap items-baseline justify-between gap-2 mb-2.5">
                    <p class="text-xs font-bold uppercase tracking-wide text-gray-600">
                        Payments recorded by hand
                    </p>
                    <p class="text-xs text-gray-600">
                        <span class="font-bold text-green-700 tabular-nums">{{ $open->amountPaidLabel() }}</span>
                        received of {{ $open->amountLabel() }}
                        @if ($open->outstandingAmount() > 0.005)
                            <span class="text-gray-300 mx-1">&middot;</span>
                            <span class="font-bold text-amber-700 tabular-nums">{{ $open->outstandingAmountLabel() }}</span>
                            outstanding
                        @endif
                    </p>
                </div>

                <ul class="space-y-2">
                    @foreach ($handRecorded as $receipt)
                        <li class="rounded-lg border border-gray-200 bg-white px-3.5 py-2.5">
                            <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                                <span class="text-sm font-bold text-gray-900 tabular-nums">{{ $receipt->amountLabel() }}</span>
                                <span class="text-xs text-gray-500">
                                    {{ $receipt->received_at?->format('d M Y, g:i a') }}
                                </span>
                            </div>

                            @if (filled($receipt->reference))
                                <p class="text-xs text-gray-600 mt-0.5">
                                    Reference <code class="break-all">{{ $receipt->reference }}</code>
                                </p>
                            @endif

                            @if (filled($receipt->note))
                                <p class="text-xs text-gray-500 mt-0.5">{{ $receipt->note }}</p>
                            @endif

                            @if ($receipt->hasProof())
                                @if ($receipt->proofIsImage())
                                    <a href="{{ $receipt->proofUrl() }}" target="_blank" rel="noopener"
                                       class="mt-2 block rounded-lg border border-gray-200 overflow-hidden hover:border-blue-400 transition max-w-xs">
                                        <img src="{{ $receipt->proofUrl() }}"
                                             alt="Proof of payment for {{ $open->reference }}"
                                             class="w-full h-auto">
                                    </a>
                                @endif

                                <a href="{{ $receipt->proofUrl() }}" target="_blank" rel="noopener"
                                   class="mt-2 inline-flex items-center gap-1.5 text-xs font-semibold text-blue-600 hover:text-blue-700 hover:underline">
                                    <x-admin.icon name="eye" class="w-3.5 h-3.5" />
                                    {{ $receipt->proofIsImage() ? 'Open full size' : $receipt->proofName() }}
                                </a>
                            @else
                                <p class="text-xs text-amber-700 mt-1.5">
                                    No proof was attached to this one.
                                </p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Things the counter should know before letting them in. Shown, not
             enforced: taking cash at the door is a normal thing to do. --}}
        @if ($warnings !== [])
            <div role="alert" class="flex items-start gap-3 border-b border-amber-200 bg-amber-50 px-5 py-3">
                <svg class="w-5 h-5 shrink-0 text-amber-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
                <div class="text-sm text-amber-800">
                    <p class="font-semibold mb-0.5">Check before letting them in</p>
                    <ul class="space-y-0.5">
                        @foreach ($warnings as $warning)
                            <li>{{ $warning }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif

        {{-- People --}}
        <ul class="divide-y divide-gray-200">
            @foreach ($open->participants as $participant)
                @include('admin.event.partials.attendance-person', [
                    'participant' => $participant,
                    'registration' => $open,
                    'canUpdate' => $canUpdate,
                    'genders' => $genders,
                    'races' => $races,
                ])
            @endforeach
        </ul>
    </div>
@elseif ($search === '')
    <div class="bg-white rounded-lg border border-dashed border-gray-300 px-6 py-14 text-center">
        <x-admin.icon name="clipboard" class="w-10 h-10 mx-auto text-gray-300" />
        <p class="text-base font-semibold text-gray-700 mt-3">Ready at the counter</p>
        <p class="text-sm text-gray-500 mt-1 max-w-md mx-auto">
            Search above for whoever is in front of you. Their people appear with the
            name and identity card number shown large, so you can compare them against
            the card in your hand before checking them in.
        </p>
    </div>
@endif
