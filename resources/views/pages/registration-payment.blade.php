@extends('layouts.master')
@section('title', $pageTitle)

@php
    use App\Models\EventRegistration;

    $isPaid = $registration->isPaid();
    $isRefunded = $registration->payment_status === EventRegistration::PAYMENT_REFUNDED;
    $canPay = $registration->awaitingPayment();

    /*
     | Some of it has arrived and some has not.
     |
     | Called out separately because it is neither payable here nor settled, and
     | without its own branch it fell through to "there is nothing to pay on this
     | registration" — told to somebody who still owes a balance.
     |
     | Not payable on the gateway on purpose: the checkout is built from the full
     | charge, so offering it would take the whole fee a second time. Money that
     | started arriving by hand is finished by hand.
     */
    $isPartlyPaid = $registration->isPartlyPaid();

    // 'success' means the gateway sent the payer back saying it went through, but
    // nothing is confirmed until the payment status itself says so.
    $awaitingConfirmation = $outcome === 'success' && ! $isPaid;
@endphp

@section('content')
    @include('components.page-header', [
        'title' => $pageTitle,
        'subtitle' => $pageSubtitle,
    ])

    <section class="py-16 bg-gray-50">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="max-w-3xl mx-auto">

                {{-- ---------------- Outcome banner ---------------- --}}
                @if ($isPaid)
                    <div role="alert" class="flex items-start gap-3 bg-green-50 border border-green-200 rounded-lg p-5 mb-8">
                        <svg class="w-6 h-6 shrink-0 text-green-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <div>
                            <p class="text-base font-bold text-green-900 mb-1">Payment received</p>
                            <p class="text-sm text-green-800">
                                Your place at {{ $event->title }} is confirmed under reference
                                <strong>{{ $registration->reference }}</strong>. Keep this reference for your records.
                            </p>
                        </div>
                    </div>
                @elseif ($awaitingConfirmation)
                    <div role="status" class="flex items-start gap-3 bg-blue-50 border border-blue-200 rounded-lg p-5 mb-8">
                        <svg class="w-6 h-6 shrink-0 text-blue-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <div>
                            <p class="text-base font-bold text-blue-900 mb-1">Confirming your payment</p>
                            <p class="text-sm text-blue-800">
                                {{ $gatewayLabel }} has not confirmed this one to us yet. Reload this page
                                in a moment. If it still shows as unpaid after a few minutes, contact us
                                quoting reference <strong>{{ $registration->reference }}</strong> and we
                                will check it.
                            </p>
                        </div>
                    </div>
                @elseif ($outcome === 'cancel')
                    <div role="alert" class="flex items-start gap-3 bg-amber-50 border border-amber-200 rounded-lg p-5 mb-8">
                        <svg class="w-6 h-6 shrink-0 text-amber-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M12 21a9 9 0 100-18 9 9 0 000 18z"/>
                        </svg>
                        <div>
                            <p class="text-base font-bold text-amber-900 mb-1">Payment cancelled</p>
                            <p class="text-sm text-amber-800">
                                Nothing has been charged. Your registration is being held, so you can pay below
                                whenever you are ready.
                            </p>
                        </div>
                    </div>
                @elseif ($outcome === 'failure')
                    <div role="alert" class="flex items-start gap-3 bg-red-50 border border-red-200 rounded-lg p-5 mb-8">
                        <svg class="w-6 h-6 shrink-0 text-red-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                        </svg>
                        <div>
                            <p class="text-base font-bold text-red-900 mb-1">Payment did not go through</p>
                            <p class="text-sm text-red-800">
                                Nothing has been charged. Your registration is still being held, so you can try again below.
                            </p>
                        </div>
                    </div>
                @elseif ($isRefunded)
                    <div role="alert" class="flex items-start gap-3 bg-gray-100 border border-gray-300 rounded-lg p-5 mb-8">
                        <svg class="w-6 h-6 shrink-0 text-gray-500 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/>
                        </svg>
                        <div>
                            <p class="text-base font-bold text-gray-900 mb-1">Payment refunded</p>
                            <p class="text-sm text-gray-700">
                                This registration has been refunded. Contact us quoting reference
                                <strong>{{ $registration->reference }}</strong> if that is unexpected.
                            </p>
                        </div>
                    </div>
                @else
                    <div role="status" class="flex items-start gap-3 bg-blue-50 border border-blue-200 rounded-lg p-5 mb-8">
                        <svg class="w-6 h-6 shrink-0 text-blue-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <div>
                            <p class="text-base font-bold text-blue-900 mb-1">Registration received</p>
                            <p class="text-sm text-blue-800">
                                Your details are saved under reference <strong>{{ $registration->reference }}</strong>.
                                Your place is held until the payment below is settled.
                            </p>
                        </div>
                    </div>
                @endif

                @if ($errors->any())
                    <div role="alert" class="bg-red-50 border border-red-200 rounded-lg p-5 mb-8">
                        <p class="text-base font-bold text-red-900 mb-1">Payment could not be started</p>
                        <ul class="text-sm text-red-800 space-y-0.5">
                            @foreach ($errors->all() as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- ---------------- Invoice ---------------- --}}
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">

                    <div class="flex flex-wrap items-start justify-between gap-4 px-6 py-5 border-b border-gray-200 bg-gray-50">
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Registration</p>
                            <h2 class="text-xl font-bold text-gray-900 mt-0.5">{{ $event->title }}</h2>
                            <p class="text-sm text-gray-600 mt-1">
                                {{ $event->starts_at->format('d M Y') }}
                                @unless ($event->starts_at->isSameDay($event->ends_at))
                                    &ndash; {{ $event->ends_at->format('d M Y') }}
                                @endunless
                                &middot; {{ $event->location }}
                            </p>
                        </div>

                        <div class="text-right shrink-0">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Reference</p>
                            <p class="text-base font-bold text-gray-900 mt-0.5">{{ $registration->reference }}</p>
                            <span @class([
                                'inline-block mt-2 rounded-full px-2.5 py-0.5 text-xs font-semibold',
                                'bg-green-100 text-green-800' => $isPaid,
                                'bg-gray-200 text-gray-700' => $isRefunded,
                                'bg-amber-100 text-amber-800' => ! $isPaid && ! $isRefunded,
                            ])>
                                {{ $registration->paymentStatusLabel() }}
                            </span>
                        </div>
                    </div>

                    @if (filled($registration->team_name))
                        <div class="px-6 py-3 border-b border-gray-100 text-sm text-gray-700">
                            <span class="font-semibold text-gray-500">Team:</span> {{ $registration->team_name }}
                            <span class="text-gray-400 mx-1.5">&middot;</span>
                            {{ $registration->participants->count() }}
                            {{ $registration->participants->count() === 1 ? 'person' : 'people' }}
                        </div>
                    @endif

                    {{-- Line items --}}
                    <div class="px-6 py-5">
                        <table class="w-full text-sm">
                            <caption class="sr-only">What is being charged for registration {{ $registration->reference }}</caption>
                            <thead>
                                <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500 border-b border-gray-200">
                                    <th scope="col" class="pb-2">Item</th>
                                    <th scope="col" class="pb-2 text-center w-14">Qty</th>
                                    <th scope="col" class="pb-2 text-right w-24 sm:w-28">Unit</th>
                                    <th scope="col" class="pb-2 text-right w-24 sm:w-28">Total</th>
                                </tr>
                            </thead>
                            {{-- tabular-nums on every money cell, so the decimal
                                 points line up down each column. --}}
                            <tbody class="divide-y divide-gray-100">
                                @if ((float) $registration->registration_fee > 0)
                                    <tr>
                                        <td class="py-2.5 text-gray-900">
                                            Event registration
                                            <span class="block text-xs text-gray-500">{{ $event->feeBasisLabel() }}</span>
                                        </td>
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
                            </tbody>
                            <tfoot>
                                @if ($registration->addonLines->isNotEmpty())
                                    <tr class="border-t border-gray-200">
                                        <td colspan="3" class="pt-3 text-right text-gray-600">Add-ons</td>
                                        <td class="pt-3 text-right font-semibold text-gray-900 tabular-nums whitespace-nowrap">{{ $registration->addonsTotalLabel() }}</td>
                                    </tr>
                                @endif
                                <tr>
                                    <td colspan="3" class="pt-3 text-right text-base font-bold text-gray-900">
                                        {{ $isPaid ? 'Total paid' : 'Total' }}
                                    </td>
                                    <td class="pt-3 text-right text-base font-bold text-blue-700 tabular-nums whitespace-nowrap">{{ $registration->amountLabel() }}</td>
                                </tr>

                                @if ($isPartlyPaid)
                                    {{-- Both figures, because the total on its own no longer
                                         tells this payer what they owe. --}}
                                    <tr>
                                        <td colspan="3" class="pt-2 text-right text-sm text-gray-600">Received so far</td>
                                        <td class="pt-2 text-right text-sm font-semibold text-green-700 tabular-nums whitespace-nowrap">
                                            {{ $registration->amountPaidLabel() }}
                                        </td>
                                    </tr>
                                    <tr>
                                        <td colspan="3" class="pt-1 text-right text-base font-bold text-gray-900">Still to pay</td>
                                        <td class="pt-1 text-right text-base font-bold text-amber-700 tabular-nums whitespace-nowrap">
                                            {{ $registration->outstandingAmountLabel() }}
                                        </td>
                                    </tr>
                                @endif
                            </tfoot>
                        </table>
                    </div>

                    {{-- ---------------- Action ---------------- --}}
                    <div class="px-6 py-5 border-t border-gray-200 bg-gray-50">
                        @if ($isPaid)
                            <div class="flex flex-wrap items-center justify-between gap-4">
                                <p class="text-sm text-gray-600">
                                    Nothing further is needed. We will be in touch with the event details.
                                </p>
                                <a href="{{ route('registration') }}"
                                   class="inline-flex items-center gap-2 bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-md shrink-0">
                                    Back to Events
                                </a>
                            </div>
                        @elseif ($isPartlyPaid)
                            <div class="flex items-start gap-3">
                                <svg class="w-5 h-5 shrink-0 text-amber-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                <p class="text-sm text-gray-700">
                                    We have received <strong>{{ $registration->amountPaidLabel() }}</strong> of
                                    {{ $registration->amountLabel() }}, so
                                    <strong>{{ $registration->outstandingAmountLabel() }}</strong> is still
                                    outstanding. Your place is held. Please
                                    <a href="{{ route('contact') }}" class="text-blue-600 font-semibold hover:underline">contact us</a>
                                    quoting reference <strong>{{ $registration->reference }}</strong> to settle
                                    the balance the same way you paid the first part.
                                </p>
                            </div>
                        @elseif (! $canPay)
                            <p class="text-sm text-gray-600">
                                There is nothing to pay on this registration. Contact us quoting reference
                                <strong>{{ $registration->reference }}</strong> if you think that is wrong.
                            </p>
                        @elseif ($gatewayReady)
                            <form action="{{ $payUrl }}" method="POST" class="flex flex-wrap items-center justify-between gap-4">
                                @csrf
                                <p class="text-sm text-gray-600">
                                    You will be taken to {{ $gatewayLabel }} to pay
                                    <strong>{{ $registration->amountLabel() }}</strong> securely. We never see
                                    your card details.
                                </p>
                                <button type="submit"
                                        class="inline-flex items-center gap-2 bg-blue-600 text-white px-6 py-3 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-md shrink-0">
                                    Pay {{ $registration->amountLabel() }}
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
                                    </svg>
                                </button>
                            </form>
                        @else
                            {{-- No working gateway. Saying so is better than a button
                                 that cannot do anything. --}}
                            <div class="flex items-start gap-3">
                                <svg class="w-5 h-5 shrink-0 text-amber-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                                </svg>
                                <p class="text-sm text-gray-700">
                                    Online payment is not available at the moment, so
                                    <strong>{{ $registration->amountLabel() }}</strong> is still outstanding.
                                    Your place is held. Please
                                    <a href="{{ route('contact') }}" class="text-blue-600 font-semibold hover:underline">contact us</a>
                                    quoting reference <strong>{{ $registration->reference }}</strong> to settle it.
                                </p>
                            </div>
                        @endif
                    </div>
                </div>

                <p class="text-xs text-gray-500 text-center mt-6">
                    Keep this page's link to come back to your payment. It stops working after 30 days.
                </p>
            </div>
        </div>
    </section>
@endsection
