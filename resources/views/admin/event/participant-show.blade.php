@extends('layouts.admin')

@section('title', 'Participant ' . $registration->reference)

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>Event</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <a href="{{ route('admin.event.participants') }}" class="hover:text-gray-700 transition">Participants</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">{{ $registration->reference }}</span>
@endsection

@section('content')
    @php
        use App\Models\EventRegistration;
        use App\Support\ParticipantOptions;

        /*
         | Which person's correction dialog was open when validation failed, so that
         | one dialog reopens with its messages and its typed values still in place.
         | Without it a rejected card number would close the form and leave an error
         | on screen with nothing attached to it.
         */
        $reopenPersonFor = old('editing_person');

        $personInput = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 bg-white focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';
        $personLabel = 'block text-xs font-semibold text-gray-700 mb-1';

        $regTones = [
            EventRegistration::STATUS_PENDING => 'amber',
            EventRegistration::STATUS_CONFIRMED => 'green',
            EventRegistration::STATUS_WAITLISTED => 'blue',
            EventRegistration::STATUS_CANCELLED => 'red',
        ];

        $payTones = [
            EventRegistration::PAYMENT_UNPAID => 'gray',
            EventRegistration::PAYMENT_PENDING => 'amber',
            // Blue: some of the money is in, so it reads as neither settled nor untouched.
            EventRegistration::PAYMENT_PARTIAL => 'blue',
            EventRegistration::PAYMENT_PAID => 'green',
            EventRegistration::PAYMENT_FAILED => 'red',
            EventRegistration::PAYMENT_REFUNDED => 'purple',
        ];

        // Green once the gateway says paid, amber while it is still moving.
        $gatewayTone = match ($payment?->status()) {
            'paid', 'settled', 'captured' => 'green',
            'refunded', 'partially_refunded' => 'purple',
            'error', 'expired', 'cancelled', 'blocked' => 'red',
            null => 'gray',
            default => 'amber',
        };

        $label = 'px-5 py-2.5 text-xs font-semibold uppercase tracking-wide text-gray-500 align-top w-52';
        $value = 'px-5 py-2.5 text-sm text-gray-900';
    @endphp

    <x-admin.page-card
        :title="'Registration ' . $registration->reference"
        :description="$event?->title"
        :back="route('admin.event.participants')">

        <x-slot:actions>
            <x-admin.badge :tone="$regTones[$registration->status] ?? 'gray'" dot>
                {{ $registration->statusLabel() }}
            </x-admin.badge>
            <x-admin.badge :tone="$payTones[$registration->payment_status] ?? 'gray'" dot>
                {{ $registration->paymentStatusLabel() }}
            </x-admin.badge>

            @if ($event)
                <a href="{{ route('admin.event.registration.show', $event) }}"
                   class="rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                    Open Event
                </a>
            @endif

            @if ($canNotify && $registration->owesBalance())
                <form action="{{ route('admin.event.participants.remind', $registration) }}" method="POST"
                      onsubmit="return confirm('Email a payment reminder for {{ addslashes($registration->displayName()) }} ({{ $registration->outstandingAmountLabel() }} outstanding)?');">
                    @csrf
                    <button type="submit"
                            class="inline-flex items-center gap-2 rounded-lg border border-amber-300 bg-white px-4 py-2.5 text-sm font-semibold text-amber-700 hover:bg-amber-50 transition">
                        <x-admin.icon name="credit-card" class="w-4 h-4" />
                        Send Payment Reminder
                    </button>
                </form>
            @endif

            @if ($canDelete && ! $registration->isPaid() && $registration->payment_status !== \App\Models\EventRegistration::PAYMENT_REFUNDED)
                <form action="{{ route('admin.event.participants.destroy', $registration) }}" method="POST"
                      onsubmit="return confirm('Delete {{ addslashes($registration->reference) }} for {{ addslashes($registration->displayName()) }}?\n\nThis removes {{ $registration->participants->count() }} {{ $registration->participants->count() === 1 ? 'person' : 'people' }} and cannot be undone. The seats go back to the event.');">
                    @csrf
                    @method('DELETE')
                    <button type="submit"
                            class="rounded-lg border border-red-300 bg-white px-4 py-2.5 text-sm font-semibold text-red-700 hover:bg-red-50 transition">
                        Delete
                    </button>
                </form>
            @endif
        </x-slot:actions>

        {{-- Correcting and removing a person both come back here, so this is where
             the outcome has to be readable. Same partial the rest of the admin uses. --}}
        @include('admin.partials.flash')

        {{-- ---------------- Registration ---------------- --}}
        <x-admin.section-intro
            title="Registration"
            description="What was submitted, and what it came to."
            icon="clipboard" />

        <x-admin.panel title="Entry" icon="clipboard">
            <table class="w-full text-sm">
                <tbody class="divide-y divide-gray-100">
                    <tr>
                        <th scope="row" class="{{ $label }} text-left">Reference</th>
                        <td class="{{ $value }}"><code class="text-xs">{{ $registration->reference }}</code></td>
                    </tr>
                    <tr>
                        <th scope="row" class="{{ $label }} text-left">Event</th>
                        <td class="{{ $value }}">
                            {{ $event?->title ?? '—' }}
                            @if ($event)
                                <span class="block text-xs text-gray-500 mt-0.5">
                                    {{ $event->starts_at->format('d M Y') }}
                                    @unless ($event->starts_at->isSameDay($event->ends_at))
                                        &ndash; {{ $event->ends_at->format('d M Y') }}
                                    @endunless
                                    &middot; {{ $event->location }}
                                </span>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <th scope="row" class="{{ $label }} text-left">Mode</th>
                        <td class="{{ $value }}">{{ ucfirst($registration->mode) }}</td>
                    </tr>
                    @if (filled($registration->team_name))
                        <tr>
                            <th scope="row" class="{{ $label }} text-left">Team</th>
                            <td class="{{ $value }}">{{ $registration->team_name }}</td>
                        </tr>
                    @endif

                    @if ($registration->hasLogo() || $event?->asksLogo())
                        <tr>
                            <th scope="row" class="{{ $label }} text-left">{{ $event?->logoLabel() ?? 'Logo' }}</th>
                            <td class="{{ $value }}">
                                @if ($registration->hasLogo())
                                    <a href="{{ $registration->logoUrl() }}" target="_blank" rel="noopener noreferrer"
                                       class="inline-block rounded-lg border border-gray-200 bg-gray-50 p-1 hover:border-blue-400 transition"
                                       title="Open the full size image">
                                        <img src="{{ $registration->logoUrl() }}"
                                             alt="Logo for {{ $registration->displayName() }}"
                                             class="w-20 h-20 object-contain">
                                    </a>
                                @else
                                    <span class="text-amber-700">Not uploaded</span>
                                @endif
                            </td>
                        </tr>
                    @endif
                    <tr>
                        <th scope="row" class="{{ $label }} text-left">People</th>
                        <td class="{{ $value }} tabular-nums">{{ $registration->participants->count() }}</td>
                    </tr>
                    <tr>
                        <th scope="row" class="{{ $label }} text-left">Submitted</th>
                        <td class="{{ $value }}">
                            {{ $registration->created_at?->format('d M Y, g:i a') ?? '—' }}
                            @if (filled($registration->ip_address))
                                <span class="text-gray-400">from {{ $registration->ip_address }}</span>
                            @endif
                        </td>
                    </tr>
                    @if (filled($registration->notes))
                        <tr>
                            <th scope="row" class="{{ $label }} text-left">Notes</th>
                            <td class="{{ $value }} whitespace-pre-line">{{ $registration->notes }}</td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </x-admin.panel>

        {{-- ---------------- What we invoiced ---------------- --}}
        <x-admin.panel title="Amount Invoiced" icon="credit-card">
            <div class="px-5 py-4">
                <table class="w-full text-sm">
                    <caption class="sr-only">Amount invoiced for {{ $registration->reference }}</caption>
                    <thead>
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500 border-b border-gray-200">
                            <th scope="col" class="pb-2">Item</th>
                            <th scope="col" class="pb-2 text-center w-16">Qty</th>
                            <th scope="col" class="pb-2 text-right w-28">Unit</th>
                            <th scope="col" class="pb-2 text-right w-28">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @if ((float) $registration->registration_fee > 0)
                            <tr>
                                <td class="py-2.5 text-gray-900">Event registration</td>
                                <td class="py-2.5 text-center text-gray-600 tabular-nums">1</td>
                                <td class="py-2.5 text-right text-gray-600 tabular-nums whitespace-nowrap">{{ $registration->registrationFeeLabel() }}</td>
                                <td class="py-2.5 text-right font-semibold text-gray-900 tabular-nums whitespace-nowrap">{{ $registration->registrationFeeLabel() }}</td>
                            </tr>
                        @endif

                        @foreach ($registration->addonLines as $line)
                            <tr>
                                <td class="py-2.5 text-gray-900">{{ $line->describe() }}</td>
                                <td class="py-2.5 text-center text-gray-600 tabular-nums">{{ $line->quantity }}</td>
                                <td class="py-2.5 text-right text-gray-600 tabular-nums whitespace-nowrap">{{ $line->unitPriceLabel() }}</td>
                                <td class="py-2.5 text-right font-semibold text-gray-900 tabular-nums whitespace-nowrap">{{ $line->lineTotalLabel() }}</td>
                            </tr>
                        @endforeach

                        @if ((float) $registration->registration_fee <= 0 && $registration->addonLines->isEmpty())
                            <tr>
                                <td colspan="4" class="py-4 text-center text-sm text-gray-500">
                                    This registration is free of charge.
                                </td>
                            </tr>
                        @endif
                    </tbody>
                    <tfoot>
                        <tr class="border-t border-gray-200">
                            <td colspan="3" class="pt-3 text-right text-base font-bold text-gray-900">Total</td>
                            <td class="pt-3 text-right text-base font-bold text-blue-700 tabular-nums whitespace-nowrap">
                                {{ $registration->amountLabel() }}
                            </td>
                        </tr>

                        {{-- Received and owed, shown only when they differ from the
                             total. On a settled or untouched entry they would repeat
                             what the line above already said. --}}
                        @if ($registration->amountPaid() > 0 && ! $registration->isPaid())
                            <tr>
                                <td colspan="3" class="pt-2 text-right text-sm text-gray-600">Received</td>
                                <td class="pt-2 text-right text-sm font-semibold text-green-700 tabular-nums whitespace-nowrap">
                                    {{ $registration->amountPaidLabel() }}
                                </td>
                            </tr>
                            <tr>
                                <td colspan="3" class="pt-1 text-right text-sm font-bold text-gray-900">Outstanding</td>
                                <td class="pt-1 text-right text-sm font-bold text-amber-700 tabular-nums whitespace-nowrap">
                                    {{ $registration->outstandingAmountLabel() }}
                                </td>
                            </tr>
                        @endif
                    </tfoot>
                </table>
            </div>
        </x-admin.panel>

        {{--
            Every receipt against this entry.

            Shown as a list rather than a single figure because that is the question
            somebody asks here: not how much has arrived, but which arrivals make it
            up, so a bank statement line can be matched to one of them.

            Only rendered when there is something to show. An entry paid in one go on
            the gateway has one row, which is still worth seeing.
        --}}
        @if ($registration->payments->isNotEmpty())
            <x-admin.panel title="Payments Received" icon="cash">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-left">
                            <tr>
                                <th scope="col" class="{{ $label }}">Received</th>
                                <th scope="col" class="{{ $label }} text-right">Amount</th>
                                <th scope="col" class="{{ $label }}">Reference</th>
                                <th scope="col" class="{{ $label }}">Source</th>
                                <th scope="col" class="{{ $label }}">Recorded By</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-100">
                            @foreach ($registration->payments as $receipt)
                                <tr>
                                    <td class="px-5 py-3 whitespace-nowrap text-gray-700">
                                        {{ $receipt->received_at?->format('d M Y') }}
                                        <span class="block text-xs text-gray-400">{{ $receipt->received_at?->format('g:i a') }}</span>
                                    </td>

                                    <td class="px-5 py-3 text-right font-semibold text-gray-900 tabular-nums whitespace-nowrap">
                                        {{ $receipt->amountLabel() }}
                                    </td>

                                    <td class="px-5 py-3 text-gray-600">
                                        @if (filled($receipt->reference))
                                            <code class="text-xs break-all">{{ $receipt->reference }}</code>
                                        @else
                                            <span class="text-gray-400">&mdash;</span>
                                        @endif

                                        @if (filled($receipt->note))
                                            <span class="block text-xs text-gray-500 mt-0.5">{{ $receipt->note }}</span>
                                        @endif

                                        @if ($receipt->hasProof())
                                            {{-- Linked rather than shown inline here. This page is a
                                                 record to read; the counter screen is where somebody
                                                 needs the slip in front of them. --}}
                                            <a href="{{ $receipt->proofUrl() }}" target="_blank" rel="noopener"
                                               class="mt-1.5 inline-flex items-center gap-1.5 text-xs font-semibold text-blue-600 hover:text-blue-700 hover:underline">
                                                <x-admin.icon name="clipboard" class="w-3.5 h-3.5" />
                                                <span class="break-all">{{ $receipt->proofName() }}</span>
                                            </a>
                                        @endif
                                    </td>

                                    <td class="px-5 py-3 whitespace-nowrap">
                                        <x-admin.badge :tone="$receipt->isManual() ? 'amber' : 'green'">
                                            {{ $receipt->sourceLabel() }}
                                        </x-admin.badge>
                                    </td>

                                    <td class="px-5 py-3 text-xs text-gray-500">
                                        {{ $receipt->actor() }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>

                        <tfoot class="bg-gray-50">
                            <tr>
                                <td class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Total received</td>
                                <td class="px-5 py-3 text-right text-base font-bold text-green-700 tabular-nums whitespace-nowrap">
                                    {{ $registration->amountPaidLabel() }}
                                </td>
                                <td colspan="3" class="px-5 py-3 text-xs text-gray-500">
                                    @if ($registration->outstandingAmount() > 0.005)
                                        {{ $registration->outstandingAmountLabel() }} of {{ $registration->amountLabel() }} still outstanding.
                                    @else
                                        Settled in full.
                                    @endif
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </x-admin.panel>
        @endif

        {{-- ---------------- People ---------------- --}}
        <x-admin.section-intro
            title="People"
            :description="$registration->participants->count() === 1 ? 'The person named on this registration.' : 'Everyone named on this registration.'"
            icon="users"
            accent="purple" />

        @forelse ($registration->participants as $participant)
            @php
                // Taking somebody off is the model's decision, not this screen's, so
                // the counter and this page cannot drift apart on who may go. The
                // reason is kept because a disabled control that says why is more
                // use than one that has silently vanished.
                $removalBlocked = $participant->removalBlockedReason();
            @endphp

            <x-admin.panel :title="$participant->roleLabel() . ' — ' . $participant->full_name" icon="users"
                           :id="'person-' . $participant->id">

                @if ($canUpdatePerson || $canRemovePerson)
                    <x-slot:actions>
                        @if ($canUpdatePerson)
                            <button type="button"
                                    data-open-person="{{ $participant->id }}"
                                    class="p-1.5 rounded-lg text-blue-600 hover:bg-blue-100 transition"
                                    title="Correct {{ $participant->full_name }}'s details"
                                    aria-label="Correct {{ $participant->full_name }}'s details">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                </svg>
                            </button>
                        @endif

                        @if ($canRemovePerson)
                            @if ($removalBlocked === null)
                                <form action="{{ route('admin.event.participants.person.remove', [$registration, $participant]) }}" method="POST"
                                      onsubmit="return confirm('Remove {{ addslashes($participant->full_name) }} from {{ addslashes($registration->reference) }}?\n\nThis cannot be undone. Their answers and anything ordered in their size go with them.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit"
                                            class="p-1.5 rounded-lg text-red-600 hover:bg-red-100 transition"
                                            title="Remove {{ $participant->full_name }} from this entry"
                                            aria-label="Remove {{ $participant->full_name }} from this entry">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                        </svg>
                                    </button>
                                </form>
                            @else
                                {{-- Shown disabled with the reason on hover rather than
                                     hidden, so somebody looking for the control is told
                                     why it will not work instead of hunting for it. --}}
                                <span class="p-1.5 rounded-lg text-gray-300 cursor-not-allowed"
                                      title="{{ $removalBlocked }}"
                                      aria-label="Cannot remove {{ $participant->full_name }}: {{ $removalBlocked }}">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                    </svg>
                                </span>
                            @endif
                        @endif
                    </x-slot:actions>
                @endif

                <table class="w-full text-sm">
                    <tbody class="divide-y divide-gray-100">
                        <tr>
                            <th scope="row" class="{{ $label }} text-left">Identity Card</th>
                            <td class="{{ $value }} tabular-nums">{{ $participant->ic_number }}</td>
                        </tr>

                        {{-- Only for events that ask for one, unless an older row
                             happens to carry it after the setting was changed. --}}
                        @if ($event?->asksIgn() || $participant->hasIgn())
                            <tr>
                                <th scope="row" class="{{ $label }} text-left">In-Game</th>
                                <td class="{{ $value }}">
                                    @if ($participant->hasIgn())
                                        {{ $participant->ignLabel() }}
                                    @else
                                        <span class="text-amber-700">Not recorded</span>
                                    @endif
                                </td>
                            </tr>
                        @endif
                        <tr>
                            <th scope="row" class="{{ $label }} text-left">Date of Birth</th>
                            <td class="{{ $value }}">
                                @if ($participant->date_of_birth)
                                    {{ $participant->date_of_birth->format('d M Y') }}
                                    <span class="text-gray-400">({{ $participant->age() }} years)</span>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th scope="row" class="{{ $label }} text-left">Gender / Race</th>
                            <td class="{{ $value }}">{{ $participant->genderLabel() }} &middot; {{ $participant->raceLabel() }}</td>
                        </tr>
                        <tr>
                            <th scope="row" class="{{ $label }} text-left">Telephone</th>
                            <td class="{{ $value }}">
                                <a href="tel:{{ $participant->phone }}" class="text-blue-600 hover:underline">{{ $participant->phone }}</a>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row" class="{{ $label }} text-left">Email</th>
                            <td class="{{ $value }}">
                                <a href="mailto:{{ $participant->email }}" class="text-blue-600 hover:underline">{{ $participant->email }}</a>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row" class="{{ $label }} text-left">Address</th>
                            <td class="{{ $value }}">{{ $participant->addressLine() ?: '—' }}</td>
                        </tr>
                        <tr>
                            <th scope="row" class="{{ $label }} text-left">Emergency Contact</th>
                            <td class="{{ $value }}">
                                @if (filled($participant->emergency_contact_name) || filled($participant->emergency_contact_phone))
                                    {{ $participant->emergency_contact_name ?: 'Not named' }}
                                    @if (filled($participant->emergency_contact_phone))
                                        <span class="text-gray-400">&middot; {{ $participant->emergency_contact_phone }}</span>
                                    @endif
                                @else
                                    —
                                @endif
                            </td>
                        </tr>

                        {{-- Extras chosen for this person. This is the row that
                             answers "what size does this player wear", which a bulk
                             quantity on the entry cannot. --}}
                        @php
                            $ownLines = $registration->addonLines->where('event_participant_id', $participant->id);
                        @endphp

                        @foreach ($ownLines as $line)
                            <tr>
                                <th scope="row" class="{{ $label }} text-left">{{ $line->name }}</th>
                                <td class="{{ $value }}">
                                    <span class="font-semibold text-gray-900">{{ $line->variant_label ?: '—' }}</span>
                                    @if ((float) $line->unit_price > 0)
                                        <span class="text-xs text-gray-400 ml-1">+{{ $line->unitPriceLabel() }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach

                        {{-- The organiser's own questions, as this person answered
                             them. The wording shown is the copy stored with the
                             answer, not the question's current text, so editing the
                             terms afterwards cannot rewrite this. --}}
                        @foreach ($participant->answers as $answer)
                            <tr>
                                <th scope="row" class="{{ $label }} text-left">
                                    {{ $answer->question_title }}
                                    @if ($answer->was_required)
                                        <span class="block text-xs font-normal text-gray-400">Compulsory</span>
                                    @endif
                                </th>
                                <td class="{{ $value }}">
                                    @if ($answer->answered)
                                        <x-admin.badge tone="green">Yes</x-admin.badge>
                                        @if ($answer->answered_at)
                                            <span class="text-xs text-gray-400 ml-1">{{ $answer->answered_at->format('d M Y, g:ia') }}</span>
                                        @endif
                                    @else
                                        <x-admin.badge tone="gray">No</x-admin.badge>
                                    @endif

                                    @if (filled($answer->question_body))
                                        <details class="mt-1.5">
                                            <summary class="text-xs text-blue-600 cursor-pointer hover:underline">
                                                What they were shown
                                            </summary>
                                            <p class="text-xs text-gray-600 mt-1.5 whitespace-pre-line leading-relaxed border-l-2 border-gray-200 pl-3">{{ $answer->question_body }}</p>
                                        </details>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-admin.panel>
        @empty
            <x-admin.panel title="People" icon="users">
                <p class="px-5 py-6 text-sm text-gray-500">No people are recorded on this registration.</p>
            </x-admin.panel>
        @endforelse

        {{--
            One correction dialog per person, drawn outside the panels above.

            A fixed overlay nested inside a card gets clipped by the card's own
            overflow rule, so these live at the end of the page and are matched to
            their button by id.

            Which fields appear is the event's decision, not this screen's: a game
            account field the event never asked for is not drawn, so it cannot be
            filled in by accident and would not be validated if it were.
        --}}
        @if ($canUpdatePerson)
            @foreach ($registration->participants as $participant)
                @php
                    $isPersonReopened = (int) $reopenPersonFor === (int) $participant->id;

                    // old() only belongs to the one dialog that failed. Reading it on
                    // the others would put one person's typed name into everybody's
                    // form the moment a single card number was rejected.
                    $valueOf = fn (string $field, $fallback = null) => $isPersonReopened
                        ? old($field, $fallback)
                        : $fallback;
                @endphp

                <div id="person-modal-{{ $participant->id }}"
                     data-person-modal="{{ $participant->id }}"
                     @class(['fixed inset-0 z-50 overflow-y-auto', 'hidden' => ! $isPersonReopened])
                     role="dialog"
                     aria-modal="true"
                     aria-labelledby="person-title-{{ $participant->id }}">

                    <div class="fixed inset-0 bg-gray-900/60" data-close-person></div>

                    <div class="relative min-h-full flex items-center justify-center p-4">
                        <div class="relative w-full max-w-2xl bg-white rounded-xl shadow-2xl my-8">

                            <div class="flex items-start justify-between gap-4 px-6 py-4 border-b border-gray-200">
                                <div class="min-w-0">
                                    <h2 id="person-title-{{ $participant->id }}" class="text-lg font-bold text-gray-900">
                                        Correct this person's details
                                    </h2>
                                    <p class="text-xs text-gray-500 mt-0.5">
                                        {{ $participant->roleLabel() }} on {{ $registration->reference }}
                                    </p>
                                </div>

                                <button type="button" data-close-person
                                        class="p-1 rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition shrink-0"
                                        aria-label="Close">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                    </svg>
                                </button>
                            </div>

                            <form action="{{ route('admin.event.participants.person.update', [$registration, $participant]) }}" method="POST"
                                  class="px-6 py-5 space-y-4">
                                @csrf
                                @method('PUT')

                                {{-- Tells the server which dialog to reopen if anything
                                     is rejected. --}}
                                <input type="hidden" name="editing_person" value="{{ $participant->id }}">

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div class="sm:col-span-2">
                                        <label for="full_name_{{ $participant->id }}" class="{{ $personLabel }}">
                                            Full name <span class="text-red-600" aria-hidden="true">*</span>
                                        </label>
                                        <input type="text" id="full_name_{{ $participant->id }}" name="full_name" required
                                               value="{{ $valueOf('full_name', $participant->full_name) }}"
                                               class="{{ $personInput }}">
                                        @if ($isPersonReopened)
                                            @error('full_name') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                                        @endif
                                    </div>

                                    <div>
                                        <label for="ic_number_{{ $participant->id }}" class="{{ $personLabel }}">
                                            Identity card <span class="text-red-600" aria-hidden="true">*</span>
                                        </label>
                                        <input type="text" id="ic_number_{{ $participant->id }}" name="ic_number" required
                                               value="{{ $valueOf('ic_number', $participant->ic_number) }}"
                                               class="{{ $personInput }} tabular-nums">
                                        <p class="text-xs text-gray-400 mt-1">Stored without spaces or hyphens.</p>
                                        @if ($isPersonReopened)
                                            @error('ic_number') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                                        @endif
                                    </div>

                                    <div>
                                        <label for="date_of_birth_{{ $participant->id }}" class="{{ $personLabel }}">Date of birth</label>
                                        <input type="date" id="date_of_birth_{{ $participant->id }}" name="date_of_birth"
                                               value="{{ $valueOf('date_of_birth', $participant->date_of_birth?->format('Y-m-d')) }}"
                                               class="{{ $personInput }}">
                                        @if ($isPersonReopened)
                                            @error('date_of_birth') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                                        @endif
                                    </div>

                                    {{-- Only the game account fields this event asks for. --}}
                                    @foreach ($event?->ignFieldsAsked() ?? [] as $field => $ignLabel)
                                        <div>
                                            <label for="{{ $field }}_{{ $participant->id }}" class="{{ $personLabel }}">
                                                {{ $ignLabel }}
                                                @if ($event->requiresIgnField($field))
                                                    <span class="text-red-600" aria-hidden="true">*</span>
                                                @endif
                                            </label>
                                            <input type="text" id="{{ $field }}_{{ $participant->id }}" name="{{ $field }}"
                                                   value="{{ $valueOf($field, $participant->{$field}) }}"
                                                   class="{{ $personInput }}">
                                            @if ($isPersonReopened)
                                                @error($field) <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                                            @endif
                                        </div>
                                    @endforeach

                                    <div>
                                        <label for="gender_{{ $participant->id }}" class="{{ $personLabel }}">Gender</label>
                                        <select id="gender_{{ $participant->id }}" name="gender" class="{{ $personInput }}">
                                            <option value="">Not recorded</option>
                                            @foreach (ParticipantOptions::GENDERS as $key => $text)
                                                <option value="{{ $key }}" @selected($valueOf('gender', $participant->gender) === $key)>{{ $text }}</option>
                                            @endforeach
                                        </select>
                                        @if ($isPersonReopened)
                                            @error('gender') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                                        @endif
                                    </div>

                                    <div>
                                        <label for="race_{{ $participant->id }}" class="{{ $personLabel }}">Race</label>
                                        <select id="race_{{ $participant->id }}" name="race" class="{{ $personInput }}">
                                            <option value="">Not recorded</option>
                                            @foreach (ParticipantOptions::RACES as $key => $text)
                                                <option value="{{ $key }}" @selected($valueOf('race', $participant->race) === $key)>{{ $text }}</option>
                                            @endforeach
                                        </select>
                                        @if ($isPersonReopened)
                                            @error('race') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                                        @endif
                                    </div>

                                    <div>
                                        <label for="phone_{{ $participant->id }}" class="{{ $personLabel }}">
                                            Telephone <span class="text-red-600" aria-hidden="true">*</span>
                                        </label>
                                        <input type="text" id="phone_{{ $participant->id }}" name="phone" required
                                               value="{{ $valueOf('phone', $participant->phone) }}"
                                               class="{{ $personInput }}">
                                        @if ($isPersonReopened)
                                            @error('phone') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                                        @endif
                                    </div>

                                    <div>
                                        <label for="email_{{ $participant->id }}" class="{{ $personLabel }}">
                                            Email <span class="text-red-600" aria-hidden="true">*</span>
                                        </label>
                                        <input type="email" id="email_{{ $participant->id }}" name="email" required
                                               value="{{ $valueOf('email', $participant->email) }}"
                                               class="{{ $personInput }}">
                                        @if ($isPersonReopened)
                                            @error('email') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                                        @endif
                                    </div>

                                    <div class="sm:col-span-2">
                                        <label for="address_line_1_{{ $participant->id }}" class="{{ $personLabel }}">Address line 1</label>
                                        <input type="text" id="address_line_1_{{ $participant->id }}" name="address_line_1"
                                               value="{{ $valueOf('address_line_1', $participant->address_line_1) }}"
                                               class="{{ $personInput }}">
                                        @if ($isPersonReopened)
                                            @error('address_line_1') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                                        @endif
                                    </div>

                                    <div class="sm:col-span-2">
                                        <label for="address_line_2_{{ $participant->id }}" class="{{ $personLabel }}">Address line 2</label>
                                        <input type="text" id="address_line_2_{{ $participant->id }}" name="address_line_2"
                                               value="{{ $valueOf('address_line_2', $participant->address_line_2) }}"
                                               class="{{ $personInput }}">
                                    </div>

                                    <div>
                                        <label for="postcode_{{ $participant->id }}" class="{{ $personLabel }}">Postcode</label>
                                        <input type="text" id="postcode_{{ $participant->id }}" name="postcode"
                                               value="{{ $valueOf('postcode', $participant->postcode) }}"
                                               class="{{ $personInput }} tabular-nums">
                                    </div>

                                    <div>
                                        <label for="city_{{ $participant->id }}" class="{{ $personLabel }}">City</label>
                                        <input type="text" id="city_{{ $participant->id }}" name="city"
                                               value="{{ $valueOf('city', $participant->city) }}"
                                               class="{{ $personInput }}">
                                        @if ($isPersonReopened)
                                            @error('city') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                                        @endif
                                    </div>

                                    <div>
                                        <label for="state_{{ $participant->id }}" class="{{ $personLabel }}">State</label>
                                        <select id="state_{{ $participant->id }}" name="state" class="{{ $personInput }}">
                                            <option value="">Not recorded</option>
                                            @foreach (ParticipantOptions::STATES as $key => $text)
                                                <option value="{{ $key }}" @selected($valueOf('state', $participant->state) === $key)>{{ $text }}</option>
                                            @endforeach
                                        </select>
                                        @if ($isPersonReopened)
                                            @error('state') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                                        @endif
                                    </div>

                                    <div>
                                        <label for="country_{{ $participant->id }}" class="{{ $personLabel }}">Country</label>
                                        <select id="country_{{ $participant->id }}" name="country" class="{{ $personInput }}">
                                            <option value="">Not recorded</option>
                                            @foreach (ParticipantOptions::COUNTRIES as $key => $text)
                                                <option value="{{ $key }}" @selected($valueOf('country', $participant->country) === $key)>{{ $text }}</option>
                                            @endforeach
                                        </select>
                                        @if ($isPersonReopened)
                                            @error('country') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                                        @endif
                                    </div>

                                    <div>
                                        <label for="emergency_contact_name_{{ $participant->id }}" class="{{ $personLabel }}">Emergency contact</label>
                                        <input type="text" id="emergency_contact_name_{{ $participant->id }}" name="emergency_contact_name"
                                               value="{{ $valueOf('emergency_contact_name', $participant->emergency_contact_name) }}"
                                               class="{{ $personInput }}">
                                    </div>

                                    <div>
                                        <label for="emergency_contact_phone_{{ $participant->id }}" class="{{ $personLabel }}">Emergency number</label>
                                        <input type="text" id="emergency_contact_phone_{{ $participant->id }}" name="emergency_contact_phone"
                                               value="{{ $valueOf('emergency_contact_phone', $participant->emergency_contact_phone) }}"
                                               class="{{ $personInput }}">
                                        @if ($isPersonReopened)
                                            @error('emergency_contact_phone') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                                        @endif
                                    </div>
                                </div>

                                {{-- Said plainly, because somebody looking for it here is
                                     the most likely person to need it and the least
                                     likely to guess where it lives. --}}
                                <p class="text-xs text-gray-500 leading-relaxed pt-1 border-t border-gray-100">
                                    This corrects the same person's details. It does not change whether they
                                    are the manager or a player, because that decides how many playing places
                                    this entry fills and is checked against the event's own limits.
                                </p>

                                <div class="flex items-center justify-end gap-2 pt-1">
                                    <button type="button" data-close-person
                                            class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                                        Cancel
                                    </button>
                                    <button type="submit"
                                            class="rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition shadow-sm">
                                        Save Changes
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            @endforeach
        @endif

        {{-- ---------------- Payment ---------------- --}}
        <x-admin.section-intro
            title="Payment"
            :description="'What ' . $gatewayLabel . ' holds about this payment.'"
            icon="shield"
            accent="green" />

        @if ($payment === null)
            <x-admin.panel title="Gateway Record" icon="credit-card">
                <div class="px-5 py-6">
                    @if (blank($registration->payment_reference))
                        <p class="text-sm text-gray-600">
                            No payment has been started for this registration, so the gateway has
                            nothing on file.
                            @if ($registration->isFree())
                                It is free of charge.
                            @endif
                        </p>
                    @else
                        <div class="flex items-start gap-2">
                            <x-admin.icon name="lock" class="w-4 h-4 mt-0.5 shrink-0 text-amber-600" />
                            <p class="text-sm text-gray-700">
                                A payment exists at the gateway under
                                <code class="text-xs">{{ $registration->payment_reference }}</code>,
                                but {{ $gatewayLabel }} could not be reached and nothing has been
                                stored yet. Reload to try again.
                            </p>
                        </div>
                    @endif
                </div>
            </x-admin.panel>
        @else
            {{-- Says whether this came from the gateway just now or from store,
                 so a stale figure is never passed off as current. --}}
            <div @class([
                'flex items-start gap-2 rounded-lg border p-3 mb-4',
                'bg-green-50 border-green-200' => $reachedGateway,
                'bg-amber-50 border-amber-200' => ! $reachedGateway,
            ])>
                <x-admin.icon :name="$reachedGateway ? 'shield' : 'archive'"
                              @class(['w-4 h-4 mt-0.5 shrink-0', 'text-green-600' => $reachedGateway, 'text-amber-600' => ! $reachedGateway]) />
                <p @class(['text-xs', 'text-green-800' => $reachedGateway, 'text-amber-800' => ! $reachedGateway])>
                    @if ($reachedGateway)
                        <span class="font-semibold">Live.</span>
                        Read from {{ $gatewayLabel }} just now.
                    @else
                        <span class="font-semibold">Stored copy.</span>
                        {{ $gatewayLabel }} could not be reached, so this is the last record we
                        received{{ $registration->payment_synced_at ? ', taken ' . $registration->payment_synced_at->diffForHumans() : '' }}.
                    @endif

                    @if ($payment->isTest())
                        <span class="ml-1 font-semibold">This is a test mode payment.</span>
                    @endif
                </p>
            </div>

            <x-admin.panel title="Payment Details" icon="credit-card">
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-gray-100">
                        <tr>
                            <th scope="row" class="{{ $label }} text-left">Status</th>
                            <td class="{{ $value }}">
                                <x-admin.badge :tone="$gatewayTone">{{ $payment->statusLabel() }}</x-admin.badge>
                                @if ($payment->markedAsPaid())
                                    <span class="ml-1.5 text-xs text-gray-500">marked as paid manually</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th scope="row" class="{{ $label }} text-left">Purchase ID</th>
                            <td class="{{ $value }}"><code class="text-xs break-all">{{ $payment->id() ?? '—' }}</code></td>
                        </tr>
                        <tr>
                            <th scope="row" class="{{ $label }} text-left">Reference</th>
                            <td class="{{ $value }}">
                                <code class="text-xs">{{ $payment->reference() ?? '—' }}</code>
                                @if ($payment->referenceGenerated())
                                    <span class="text-gray-400">&middot; generated {{ $payment->referenceGenerated() }}</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th scope="row" class="{{ $label }} text-left">Amount</th>
                            <td class="{{ $value }} tabular-nums">
                                @if ($payment->amount() !== null)
                                    {{ $payment->currency() }} {{ number_format($payment->amount(), 2) }}
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                        @if ($payment->feeAmount() !== null)
                            <tr>
                                <th scope="row" class="{{ $label }} text-left">Gateway Fee / Net</th>
                                <td class="{{ $value }} tabular-nums">
                                    {{ $payment->currency() }} {{ number_format($payment->feeAmount(), 2) }}
                                    <span class="text-gray-400">fee</span>
                                    @if ($payment->netAmount() !== null)
                                        <span class="text-gray-300 mx-1">|</span>
                                        {{ $payment->currency() }} {{ number_format($payment->netAmount(), 2) }}
                                        <span class="text-gray-400">net</span>
                                    @endif
                                </td>
                            </tr>
                        @endif
                        <tr>
                            <th scope="row" class="{{ $label }} text-left">Payment Method</th>
                            <td class="{{ $value }}">
                                @if ($payment->paymentMethod())
                                    <span class="font-semibold">{{ $payment->paymentMethod() }}</span>
                                    @if ($payment->flow())
                                        <span class="text-gray-400">&middot; {{ $payment->flow() }}</span>
                                    @endif
                                    @if ($payment->country())
                                        <span class="text-gray-400">&middot; {{ $payment->country() }}</span>
                                    @endif
                                @else
                                    <span class="text-gray-500">Not chosen yet</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th scope="row" class="{{ $label }} text-left">Issued / Paid</th>
                            <td class="{{ $value }}">
                                {{ $payment->issued() ?? '—' }}
                                @if ($payment->paidOn())
                                    <span class="text-gray-300 mx-1">|</span>
                                    paid {{ $payment->paidOn()->format('d M Y, g:i a') }}
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th scope="row" class="{{ $label }} text-left">Platform / Source IP</th>
                            <td class="{{ $value }}">
                                {{ $payment->platform() ?? '—' }}
                                @if ($payment->createdFromIp())
                                    <span class="text-gray-400">&middot; {{ $payment->createdFromIp() }}</span>
                                @endif
                            </td>
                        </tr>
                        @if ($payment->refundableAmount() !== null)
                            <tr>
                                <th scope="row" class="{{ $label }} text-left">Refundable</th>
                                <td class="{{ $value }} tabular-nums">
                                    {{ $payment->currency() }} {{ number_format($payment->refundableAmount(), 2) }}
                                    @if ($payment->refundAvailability())
                                        <span class="text-gray-400">&middot; {{ $payment->refundAvailability() }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endif
                        <tr>
                            <th scope="row" class="{{ $label }} text-left">Payer On File</th>
                            <td class="{{ $value }}">
                                {{ $payment->clientName() ?? '—' }}
                                @if ($payment->clientEmail())
                                    <span class="block text-xs text-gray-500">{{ $payment->clientEmail() }}</span>
                                @endif
                                @if ($payment->clientPhone())
                                    <span class="block text-xs text-gray-500">{{ $payment->clientPhone() }}</span>
                                @endif
                            </td>
                        </tr>
                        @if ($payment->checkoutUrl())
                            <tr>
                                <th scope="row" class="{{ $label }} text-left">Checkout Link</th>
                                <td class="{{ $value }}">
                                    <a href="{{ $payment->checkoutUrl() }}" target="_blank" rel="noopener noreferrer"
                                       class="text-blue-600 hover:underline break-all">{{ $payment->checkoutUrl() }}</a>
                                </td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </x-admin.panel>

            {{-- Lines as the gateway recorded them, which may differ from our
                 invoice if the purchase was amended at the gateway. --}}
            @if ($payment->products() !== [])
                <x-admin.panel title="Checkout Summary at Gateway" icon="archive">
                    <div class="px-5 py-4">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500 border-b border-gray-200">
                                    <th scope="col" class="pb-2">Item</th>
                                    <th scope="col" class="pb-2 text-center w-16">Qty</th>
                                    <th scope="col" class="pb-2 text-right w-28">Unit</th>
                                    <th scope="col" class="pb-2 text-right w-28">Amount</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($payment->products() as $product)
                                    <tr>
                                        <td class="py-2.5 text-gray-900">{{ $product['name'] }}</td>
                                        <td class="py-2.5 text-center text-gray-600 tabular-nums">{{ $product['quantity'] }}</td>
                                        <td class="py-2.5 text-right text-gray-600 tabular-nums whitespace-nowrap">
                                            {{ $payment->currency() }} {{ number_format($product['price'], 2) }}
                                        </td>
                                        <td class="py-2.5 text-right font-semibold text-gray-900 tabular-nums whitespace-nowrap">
                                            {{ $payment->currency() }} {{ number_format($product['total'], 2) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="border-t border-gray-200">
                                    <td colspan="3" class="pt-3 text-right text-base font-bold text-gray-900">Total</td>
                                    <td class="pt-3 text-right text-base font-bold text-gray-900 tabular-nums whitespace-nowrap">
                                        {{ $payment->currency() }} {{ number_format($payment->amount() ?? 0, 2) }}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </x-admin.panel>
            @endif

            {{-- Timeline ---------------------------------------------------- --}}
            @if ($payment->timeline() !== [])
                <x-admin.panel title="Timeline" icon="activity">
                    <ol class="px-5 py-4 space-y-0">
                        @foreach (array_reverse($payment->timeline()) as $i => $entry)
                            <li class="flex gap-3">
                                <div class="flex flex-col items-center shrink-0">
                                    <span @class([
                                        'w-2.5 h-2.5 rounded-full mt-1.5',
                                        'bg-green-500' => $i === 0,
                                        'bg-gray-300' => $i !== 0,
                                    ]) aria-hidden="true"></span>
                                    @unless ($loop->last)
                                        <span class="w-px flex-1 bg-gray-200 my-1" aria-hidden="true"></span>
                                    @endunless
                                </div>

                                <div @class(['min-w-0', 'pb-4' => ! $loop->last])>
                                    <p class="text-sm font-semibold text-gray-900">{{ $entry['label'] }}</p>
                                    <p class="text-xs text-gray-500">
                                        {{ $entry['at']?->format('d M Y, g:i:s a') ?? 'Time not recorded' }}
                                    </p>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </x-admin.panel>
            @endif

            {{-- ---------------- The raw record ---------------- --}}
            <x-admin.section-intro
                title="Gateway Response"
                :description="'Exactly what ' . $gatewayLabel . ' returned, unaltered. Useful when reconciling against their dashboard or raising a support ticket.'"
                icon="database"
                accent="blue" />

            <x-admin.panel title="Raw JSON" icon="database">
                <div class="px-5 py-4">
                    <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                        <p class="text-xs text-gray-500">
                            {{ number_format(strlen($payment->toJson())) }} characters
                            @if ($registration->payment_synced_at)
                                &middot; retrieved {{ $registration->payment_synced_at->format('d M Y, g:i a') }}
                            @endif
                        </p>

                        <button type="button"
                                data-copy-json
                                class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 transition">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                            </svg>
                            <span data-copy-label>Copy JSON</span>
                        </button>
                    </div>

                    {{-- tabindex so the block can be scrolled by keyboard, since
                         it is taller than the space given to it. --}}
                    <pre id="gateway-json"
                         tabindex="0"
                         class="max-h-[32rem] overflow-auto rounded-lg bg-gray-900 p-4 text-xs leading-relaxed text-gray-100 font-mono whitespace-pre focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >{{ $payment->toJson() }}</pre>
                </div>
            </x-admin.panel>
        @endif

        {{-- ---------------- Notifications ---------------- --}}
        <x-admin.section-intro
            title="Messages"
            description="Every email raised about this entry. A bounce is invisible to the person expecting it, so anything that failed can be sent again from here."
            icon="activity"
            accent="amber" />

        @if ($canNotify)
            <x-admin.panel title="Send Again" icon="activity">
                <div class="px-5 py-4">
                    <p class="text-sm text-gray-600 mb-4">
                        Sends to whoever is on the entry now. Players who share an email address
                        receive one message between them, not one each.
                    </p>

                    <div class="flex flex-wrap gap-2">
                        @foreach ($resendable as $key => $label)
                            <form method="POST"
                                  action="{{ route('admin.event.participants.resend', $registration) }}">
                                @csrf
                                <input type="hidden" name="template_key" value="{{ $key }}">
                                <button type="submit"
                                        class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50 transition">
                                    <x-admin.icon name="activity" class="w-3.5 h-3.5 text-gray-500" />
                                    {{ $label }}
                                </button>
                            </form>
                        @endforeach
                    </div>
                </div>
            </x-admin.panel>
        @endif

        <x-admin.panel title="History" icon="database">
            @forelse ($registration->notifications as $notification)
                @php
                    $covered = $notification->coveredNames();

                    $tone = match ($notification->status) {
                        \App\Models\EventNotification::STATUS_SENT => 'green',
                        \App\Models\EventNotification::STATUS_FAILED => 'red',
                        \App\Models\EventNotification::STATUS_SKIPPED => 'amber',
                        default => 'gray',
                    };
                @endphp

                <div class="px-5 py-4 {{ $loop->first ? '' : 'border-t border-gray-100' }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-gray-900">
                                {{ $notification->templateLabel() }}
                            </p>

                            <p class="text-xs text-gray-600 mt-0.5">
                                @if (filled($notification->recipient))
                                    To <span class="font-mono">{{ $notification->recipient }}</span>
                                @else
                                    Not addressed to anyone
                                @endif
                            </p>

                            @if ($covered !== [])
                                <p class="text-xs text-gray-500 mt-1">
                                    Covers {{ count($covered) }}:
                                    {{ implode(', ', $covered) }}
                                </p>
                            @endif

                            @if (filled($notification->reason))
                                <p class="text-xs text-amber-700 mt-1">{{ $notification->reason }}</p>
                            @endif

                            @if ($notification->triggeredBy)
                                <p class="text-xs text-gray-400 mt-1">
                                    Sent by hand by {{ $notification->triggeredBy->name }}
                                </p>
                            @endif
                        </div>

                        <div class="text-right shrink-0">
                            <x-admin.badge :tone="$tone" dot>{{ $notification->statusLabel() }}</x-admin.badge>

                            {{-- Only SMS can say more than "we handed it over". Email
                                 has no equivalent report, so nothing is claimed for it. --}}
                            @if ($notification->channel === 'sms' && $notification->delivery_status !== null)
                                <p class="mt-1">
                                    <x-admin.badge :tone="$notification->delivered_at ? 'green' : 'red'">
                                        {{ $notification->delivered_at ? 'Reached the handset' : $notification->delivery_status }}
                                    </x-admin.badge>
                                </p>
                            @elseif ($notification->channel === 'sms' && $notification->wasSent())
                                <p class="text-xs text-gray-400 mt-1">No delivery report yet</p>
                            @endif

                            <p class="text-xs text-gray-500 mt-1.5">
                                {{ ($notification->sent_at ?? $notification->created_at)->format('d M Y, g:i a') }}
                            </p>
                        </div>
                    </div>
                </div>
            @empty
                <p class="px-5 py-6 text-sm text-gray-500">
                    Nothing has been sent about this entry yet.
                </p>
            @endforelse
        </x-admin.panel>
    </x-admin.page-card>
@endsection

@push('scripts')
<script>
    (function () {
        const button = document.querySelector('[data-copy-json]');
        const block = document.getElementById('gateway-json');
        const label = document.querySelector('[data-copy-label]');

        if (!button || !block) {
            return;
        }

        button.addEventListener('click', async function () {
            const original = label ? label.textContent : '';

            try {
                await navigator.clipboard.writeText(block.textContent);

                if (label) {
                    label.textContent = 'Copied';
                }
            } catch (error) {
                // Clipboard access needs a secure context, so on plain http the
                // text is selected instead and the user can copy it themselves.
                const range = document.createRange();
                range.selectNodeContents(block);
                window.getSelection()?.removeAllRanges();
                window.getSelection()?.addRange(range);

                if (label) {
                    label.textContent = 'Selected, press Ctrl+C';
                }
            }

            if (label) {
                window.setTimeout(function () {
                    label.textContent = original;
                }, 2500);
            }
        });
    })();

    /*
     | Opening and closing the per person correction dialogs.
     |
     | One dialog per person, each holding an ordinary PUT form. The script only
     | shows and hides them; nothing about the submission depends on JavaScript,
     | and a dialog reopened by the server after a failed validation is already
     | visible before this runs.
     */
    (function () {
        const dialogs = Array.from(document.querySelectorAll('[data-person-modal]'));

        if (dialogs.length === 0) {
            return;
        }

        function dialogFor(id) {
            return dialogs.find((node) => node.getAttribute('data-person-modal') === String(id)) || null;
        }

        function close(dialog) {
            dialog.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }

        function closeAll() {
            dialogs.forEach(close);
        }

        document.querySelectorAll('[data-open-person]').forEach(function (trigger) {
            trigger.addEventListener('click', function () {
                const dialog = dialogFor(trigger.getAttribute('data-open-person'));

                if (!dialog) {
                    return;
                }

                closeAll();
                dialog.classList.remove('hidden');

                // The scroll lock belongs to whichever dialog is open, and only one
                // can be, so it is set rather than counted.
                document.body.classList.add('overflow-hidden');

                dialog.querySelector('input:not([type=hidden]):not([disabled])')?.focus();
            });
        });

        dialogs.forEach(function (dialog) {
            dialog.querySelectorAll('[data-close-person]').forEach(function (trigger) {
                trigger.addEventListener('click', function () {
                    close(dialog);
                });
            });
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeAll();
            }
        });

        // A server-reopened dialog is visible from the markup, so the scroll lock
        // has to be applied to match it.
        if (dialogs.some((dialog) => !dialog.classList.contains('hidden'))) {
            document.body.classList.add('overflow-hidden');
        }
    })();
</script>
@endpush
