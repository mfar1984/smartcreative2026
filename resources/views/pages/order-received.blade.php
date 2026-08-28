{{--
    The page a buyer lands on from the link we send when a parcel goes out.

    Reached through a signed link. The GET only shows a button; the POST records the
    confirmation, because mail clients prefetch links to build previews and a GET that
    wrote would report parcels received that nobody had touched.
--}}
@extends('layouts.master')

@section('title', $pageTitle)

@section('content')
    @include('components.page-header', [
        'title' => 'Order ' . $order->reference,
        'subtitle' => $order->isReceiptConfirmed() ? 'Thank you, this is all recorded.' : 'Did your parcel arrive?',
    ])

    <section class="py-14 bg-white">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="max-w-xl mx-auto">

                @if ($order->isReceiptConfirmed())
                    <div class="rounded-lg border border-green-200 bg-green-50 p-6 text-center">
                        <svg class="w-14 h-14 mx-auto text-green-600 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>

                        <h2 class="text-xl font-bold text-green-900 mb-2">Thank you</h2>

                        <p class="text-base text-green-800">
                            You confirmed this arrived on
                            {{ $order->received_confirmed_at->format('d M Y') }}. There is nothing else to do.
                        </p>
                    </div>
                @else
                    <div class="rounded-lg border border-gray-200 p-6">
                        <h2 class="text-xl font-bold text-gray-900 mb-3">Confirm your parcel arrived</h2>

                        <p class="text-base text-gray-700 leading-relaxed mb-5">
                            Press the button below once you have the parcel in your hands.
                            @if ($order->payment_method === App\Models\ShopOrder::METHOD_COD)
                                This also records that you paid the
                                <span class="font-semibold">{{ $order->grandTotalLabel() }}</span>
                                to the courier on delivery.
                            @endif
                        </p>

                        {{-- What they are confirming, so nobody presses it for the wrong parcel. --}}
                        <ul class="rounded-lg bg-gray-50 border border-gray-200 divide-y divide-gray-100 mb-5">
                            @foreach ($order->items as $item)
                                <li class="flex justify-between gap-3 px-4 py-2.5 text-sm">
                                    <span class="text-gray-700">{{ $item->label() }}</span>
                                    <span class="text-gray-500 tabular-nums">&times; {{ $item->quantity }}</span>
                                </li>
                            @endforeach
                        </ul>

                        <form action="{{ url()->full() }}" method="POST">
                            @csrf

                            <button type="submit"
                                    class="w-full inline-flex items-center justify-center gap-2 bg-green-600 text-white px-6 py-3.5 rounded-lg font-semibold hover:bg-green-700 transition shadow-sm">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                </svg>
                                Yes, it arrived
                            </button>
                        </form>

                        <p class="text-sm text-gray-500 mt-4">
                            If it has not arrived, do not press this.
                            <a href="{{ route('contact') }}" class="text-blue-600 hover:underline font-semibold">Tell us instead</a>
                            and we will chase the courier.
                        </p>
                    </div>
                @endif

                <div class="mt-8 text-center">
                    <a href="{{ route('shop') }}" class="text-sm font-semibold text-blue-600 hover:underline">
                        Back to the shop
                    </a>
                </div>

            </div>
        </div>
    </section>
@endsection
