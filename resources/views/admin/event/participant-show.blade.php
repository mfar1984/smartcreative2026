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

            @if ($canNotify && $registration->awaitingPayment())
                <form action="{{ route('admin.event.participants.remind', $registration) }}" method="POST"
                      onsubmit="return confirm('Email a payment reminder for {{ addslashes($registration->displayName()) }} ({{ $registration->amountLabel() }} due)?');">
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
                    </tfoot>
                </table>
            </div>
        </x-admin.panel>

        {{-- ---------------- People ---------------- --}}
        <x-admin.section-intro
            title="People"
            :description="$registration->participants->count() === 1 ? 'The person named on this registration.' : 'Everyone named on this registration.'"
            icon="users"
            accent="purple" />

        @forelse ($registration->participants as $participant)
            <x-admin.panel :title="$participant->roleLabel() . ' — ' . $participant->full_name" icon="users">
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
                    </tbody>
                </table>
            </x-admin.panel>
        @empty
            <x-admin.panel title="People" icon="users">
                <p class="px-5 py-6 text-sm text-gray-500">No people are recorded on this registration.</p>
            </x-admin.panel>
        @endforelse

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
</script>
@endpush
