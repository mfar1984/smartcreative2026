{{--
    Where a buyer sends proof of a bank transfer.

    Reached from a signed link in the email, so there is no login. Uploading here
    changes nothing about the order's status: it attaches evidence that somebody then
    checks against the bank. The page says so plainly, because a buyer who thinks an
    upload confirmed their order will turn up expecting goods.
--}}
@extends('layouts.master')

@section('title', $pageTitle)

@php
    $field = 'w-full rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';
@endphp

@section('content')
    @include('components.page-header', [
        'title' => 'Payment receipt',
        'subtitle' => 'Order ' . $order->reference,
    ])

    <section class="py-14 bg-white">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="max-w-2xl mx-auto">

                @if (session('status'))
                    <div role="status" class="mb-6 rounded-lg border border-green-200 bg-green-50 px-4 py-3.5">
                        <p class="text-sm font-semibold text-green-900">{{ session('status') }}</p>
                    </div>
                @endif

                @if ($errors->any())
                    <div class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3.5">
                        <ul class="space-y-0.5">
                            @foreach ($errors->all() as $message)
                                <li class="text-sm text-red-800">{{ $message }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- The amount owed and the reference to quote, first. --}}
                <div class="rounded-lg border border-blue-200 bg-blue-50 p-6">
                    <p class="text-xs font-semibold uppercase tracking-wide text-blue-800">Amount to transfer</p>
                    <p class="text-3xl font-bold text-blue-900 tabular-nums mt-1">{{ $order->grandTotalLabel() }}</p>
                    <p class="text-sm text-blue-900 mt-2">
                        Quote <span class="font-bold">{{ $order->reference }}</span> as the payment reference.
                    </p>

                    @if ($bankAccount)
                        <dl class="mt-4 pt-4 border-t border-blue-200 space-y-1.5 text-sm">
                            <div class="flex flex-wrap gap-x-2">
                                <dt class="text-blue-800">Account name</dt>
                                <dd class="font-semibold text-blue-900">{{ $bankAccount['name'] }}</dd>
                            </div>
                            <div class="flex flex-wrap gap-x-2">
                                <dt class="text-blue-800">Bank</dt>
                                <dd class="font-semibold text-blue-900">{{ $bankAccount['bank'] }}</dd>
                            </div>
                            <div class="flex flex-wrap gap-x-2">
                                <dt class="text-blue-800">Account number</dt>
                                <dd class="font-semibold text-blue-900 tabular-nums">{{ $bankAccount['number'] }}</dd>
                            </div>
                        </dl>
                    @endif

                    @if (filled($bankNote))
                        <p class="text-sm text-blue-900 mt-4 pt-4 border-t border-blue-200 leading-relaxed">{{ $bankNote }}</p>
                    @endif
                </div>

                @if ($order->hasPaymentReceipt())
                    <div class="mt-6 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3.5">
                        <p class="text-sm font-semibold text-gray-900">Receipt received</p>
                        <p class="text-sm text-gray-600 mt-0.5">
                            Uploaded {{ $order->payment_receipt_uploaded_at?->format('d M Y, g:i a') }}.
                            <a href="{{ $order->paymentReceiptUrl() }}" target="_blank" rel="noopener"
                               class="font-semibold text-blue-600 hover:underline">View what you sent</a>
                        </p>

                        @if ($canUpload)
                            <p class="text-xs text-gray-500 mt-2">
                                Sent the wrong file? Upload another below and it replaces this one.
                            </p>
                        @endif
                    </div>
                @endif

                @if ($canUpload)
                    <form action="{{ route('shop.order.receipt.store', ['reference' => $order->reference]) }}"
                          method="POST" enctype="multipart/form-data" class="mt-8">
                        @csrf

                        <h2 class="text-xl font-bold text-gray-900">
                            {{ $order->hasPaymentReceipt() ? 'Replace your receipt' : 'Upload your receipt' }}
                        </h2>

                        <p class="text-sm text-gray-600 mt-1.5 leading-relaxed">
                            A photo or a PDF of the transfer, up to 4 MB. Make sure the amount, the date and
                            the account it went to are readable.
                        </p>

                        <label for="receipt" class="block text-sm font-semibold text-gray-700 mt-5 mb-1.5">
                            Receipt file <span class="text-red-600" aria-hidden="true">*</span>
                        </label>
                        <input type="file" id="receipt" name="receipt" required
                               accept="image/jpeg,image/png,image/webp,application/pdf,.pdf"
                               class="block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-blue-600 file:px-4 file:py-2.5 file:text-sm file:font-semibold file:text-white hover:file:bg-blue-700 file:cursor-pointer">

                        <button type="submit"
                                class="mt-5 inline-flex items-center justify-center gap-2 bg-blue-600 text-white px-6 py-3 rounded-lg font-semibold hover:bg-blue-700 transition shadow-sm">
                            Send receipt
                        </button>

                        {{-- Stated on the button's own doorstep. Somebody who believes this
                             upload confirmed their order will turn up expecting goods. --}}
                        <p class="text-xs text-gray-500 mt-4 leading-relaxed">
                            Sending this does not confirm your order by itself. We check it against our
                            account by hand and email you once the payment is confirmed.
                        </p>
                    </form>
                @else
                    <div role="status" class="mt-6 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3.5">
                        <p class="text-sm text-gray-700">{{ $closedReason }}</p>
                    </div>
                @endif

                <div class="mt-10 pt-6 border-t border-gray-200">
                    <h2 class="text-sm font-bold text-gray-900">What you ordered</h2>

                    <ul class="mt-3 space-y-2">
                        @foreach ($order->items as $item)
                            <li class="flex justify-between gap-3 text-sm">
                                <span class="min-w-0 text-gray-700">
                                    {{ $item->name }}
                                    @if ($item->variant_label)
                                        <span class="text-gray-500">({{ $item->variant_label }})</span>
                                    @endif
                                    <span class="text-gray-500 tabular-nums">&times; {{ $item->quantity }}</span>
                                </span>
                                <span class="font-semibold text-gray-900 tabular-nums whitespace-nowrap">
                                    {{ App\Support\PaymentFigures::money((float) $item->line_total) }}
                                </span>
                            </li>
                        @endforeach
                    </ul>

                    @if ($order->isOffline())
                        <p class="text-sm text-gray-600 mt-4 rounded-lg bg-gray-50 border border-gray-200 px-3.5 py-2.5 leading-relaxed">
                            This order is collected in person, not posted. We send you the place, the date and
                            the time once your payment is confirmed. Please bring your identity card.
                        </p>
                    @endif
                </div>

            </div>
        </div>
    </section>
@endsection
