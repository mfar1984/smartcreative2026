{{--
    Checkout. No account: name, address, phone, email, and a payment method.

    Postage is not shown as a final figure until the state is chosen, because it is
    banded by destination. The script below only previews the flat rate the server
    will apply; the order is priced again server side when it is placed.
--}}
@extends('layouts.master')

@section('title', $pageTitle)

@php
    $field = 'w-full rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';
    $label = 'block text-sm font-semibold text-gray-700 mb-1.5';
@endphp

@section('content')
    @include('components.page-header', [
        'title' => $pageTitle,
        'subtitle' => $isOffline
            ? 'Who is collecting, and how you would like to pay.'
            : 'Where the parcel goes, and how you would like to pay.',
    ])

    <section class="py-14 bg-white">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">

            @if ($errors->any())
                <div class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3.5">
                    <p class="text-sm font-semibold text-red-900">Please check the form</p>
                    <ul class="mt-1 space-y-0.5">
                        @foreach ($errors->all() as $message)
                            <li class="text-sm text-red-800">{{ $message }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form action="{{ route('checkout.place') }}" method="POST">
                @csrf

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

                    {{-- ---------------- Details ---------------- --}}
                    <div class="lg:col-span-2 space-y-8">

                        <div>
                            <h2 class="text-xl font-bold text-gray-900 mb-4">Your details</h2>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div class="sm:col-span-2">
                                    <label for="customer_name" class="{{ $label }}">Full Name <span class="text-red-600" aria-hidden="true">*</span></label>
                                    <input type="text" id="customer_name" name="customer_name" required maxlength="190"
                                           value="{{ old('customer_name') }}" autocomplete="name" class="{{ $field }}">
                                </div>

                                <div>
                                    <label for="customer_email" class="{{ $label }}">Email <span class="text-red-600" aria-hidden="true">*</span></label>
                                    <input type="email" id="customer_email" name="customer_email" required maxlength="190"
                                           value="{{ old('customer_email') }}" autocomplete="email" class="{{ $field }}">
                                    <p class="text-xs text-gray-500 mt-1">Your order confirmation goes here.</p>
                                </div>

                                <div>
                                    <label for="customer_phone" class="{{ $label }}">Phone <span class="text-red-600" aria-hidden="true">*</span></label>
                                    <input type="tel" id="customer_phone" name="customer_phone" required maxlength="40"
                                           value="{{ old('customer_phone') }}" autocomplete="tel" class="{{ $field }}">
                                    <p class="text-xs text-gray-500 mt-1">
                                        {{ $isOffline ? 'We call this if there is a problem with your collection.' : 'The courier calls this if they cannot find you.' }}
                                    </p>
                                </div>

                                @if ($isOffline)
                                    {{-- Only asked for on a collected order, because it is only
                                         checked at a counter. --}}
                                    <div class="sm:col-span-2">
                                        <label for="identity_card" class="{{ $label }}">
                                            Identity Card <span class="text-red-600" aria-hidden="true">*</span>
                                        </label>
                                        <input type="text" id="identity_card" name="identity_card" required minlength="6" maxlength="30"
                                               value="{{ old('identity_card') }}" class="{{ $field }}"
                                               placeholder="e.g. 900101-14-5678">
                                        <p class="text-xs text-gray-500 mt-1">
                                            Bring this document with you. Our counter checks it against this number
                                            before handing your order over. A passport number is fine if you do not
                                            hold a Malaysian identity card.
                                        </p>
                                    </div>
                                @endif
                            </div>
                        </div>

                        @if ($isOffline)
                            {{-- Where they are turning up. Stated before the address so it is
                                 not mistaken for somewhere a parcel is going. --}}
                            <div>
                                <h2 class="text-xl font-bold text-gray-900 mb-4">Where you collect this</h2>

                                <div class="rounded-lg border border-blue-200 bg-blue-50 p-5">
                                    @if ($collectionPoint)
                                        <p class="text-sm font-bold text-blue-900">{{ $collectionPoint['label'] }}</p>

                                        @if (filled($collectionPoint['location']))
                                            <p class="text-sm text-blue-900 mt-1">{{ $collectionPoint['location'] }}</p>
                                        @endif

                                        @if ($collectionPoint['at'])
                                            <p class="text-sm font-semibold text-blue-900 mt-1">
                                                {{ $collectionPoint['at']->format('l') }}, {{ \App\Support\LocalTime::dateWallClock($collectionPoint['at']) }} at {{ \App\Support\LocalTime::timeWallClock($collectionPoint['at']) }}
                                            </p>
                                        @endif
                                    @endif

                                    <p class="text-xs text-blue-800 mt-3 pt-3 border-t border-blue-200 leading-relaxed">
                                        Nothing is posted. There is no delivery charge. We email you the collection
                                        details again once your payment is confirmed, and you bring your identity
                                        card to the counter.
                                    </p>
                                </div>
                            </div>
                        @endif

                        <div>
                            <h2 class="text-xl font-bold text-gray-900 mb-4">
                                {{ $isOffline ? 'Your address' : 'Delivery address' }}
                            </h2>

                            @if ($isOffline)
                                <p class="text-sm text-gray-600 -mt-2 mb-4">
                                    Kept on the order as your contact details. Nothing is sent here.
                                </p>
                            @endif

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div class="sm:col-span-2">
                                    <label for="address_line_1" class="{{ $label }}">Address Line 1 <span class="text-red-600" aria-hidden="true">*</span></label>
                                    <input type="text" id="address_line_1" name="address_line_1" required maxlength="190"
                                           value="{{ old('address_line_1') }}" autocomplete="address-line1" class="{{ $field }}">
                                </div>

                                <div class="sm:col-span-2">
                                    <label for="address_line_2" class="{{ $label }}">Address Line 2</label>
                                    <input type="text" id="address_line_2" name="address_line_2" maxlength="190"
                                           value="{{ old('address_line_2') }}" autocomplete="address-line2" class="{{ $field }}">
                                </div>

                                <div>
                                    <label for="postcode" class="{{ $label }}">Postcode <span class="text-red-600" aria-hidden="true">*</span></label>
                                    <input type="text" id="postcode" name="postcode" required maxlength="10"
                                           value="{{ old('postcode') }}" autocomplete="postal-code" class="{{ $field }}">
                                </div>

                                <div>
                                    <label for="city" class="{{ $label }}">City <span class="text-red-600" aria-hidden="true">*</span></label>
                                    <input type="text" id="city" name="city" required maxlength="120"
                                           value="{{ old('city') }}" autocomplete="address-level2" class="{{ $field }}">
                                </div>

                                <div class="sm:col-span-2">
                                    <label for="state" class="{{ $label }}">State <span class="text-red-600" aria-hidden="true">*</span></label>
                                    <select id="state" name="state" required data-checkout-state class="{{ $field }} bg-white">
                                        <option value="">Choose a state</option>
                                        @foreach ($states as $value => $text)
                                            <option value="{{ $value }}" @selected(old('state') === $value)>{{ $text }}</option>
                                        @endforeach
                                    </select>
                                    @unless ($isOffline)
                                        <p class="text-xs text-gray-500 mt-1">Delivery to Sabah and Sarawak costs more, so this sets the postage.</p>
                                    @endunless
                                </div>
                            </div>
                        </div>

                        <div>
                            <h2 class="text-xl font-bold text-gray-900 mb-4">How would you like to pay?</h2>

                            <div class="space-y-3">
                                @foreach ($methods as $slug => $text)
                                    <label class="flex gap-3 rounded-lg border border-gray-300 p-4 cursor-pointer hover:border-blue-400 has-checked:border-blue-600 has-checked:bg-blue-50 transition">
                                        <input type="radio" name="payment_method" value="{{ $slug }}" required
                                               @checked(old('payment_method', array_key_first($methods)) === $slug)
                                               class="mt-0.5 text-blue-600 focus:ring-blue-500/40">

                                        <span class="min-w-0">
                                            <span class="block text-sm font-semibold text-gray-900">{{ $text }}</span>

                                            @if ($slug === App\Models\ShopOrder::METHOD_GATEWAY)
                                                <span class="block text-sm text-gray-600 mt-0.5">
                                                    Paid now on our gateway's own page. We never see your card number.
                                                </span>

                                                @if ($isOffline)
                                                    {{-- Said plainly, so a counter buyer is not confused by
                                                         paying online: how it is paid for and how it reaches
                                                         them are two separate things. --}}
                                                    <span class="block text-sm text-gray-600 mt-0.5">
                                                        You still collect in person; only the payment happens online.
                                                    </span>
                                                @endif
                                            @elseif ($slug === App\Models\ShopOrder::METHOD_BANK_TRANSFER)
                                                <span class="block text-sm text-gray-600 mt-0.5">
                                                    {{ $bankNote ?: 'Transfer to our account and send us the receipt. Your order is released once the payment shows.' }}
                                                </span>

                                                @if ($bankAccount)
                                                    <span class="block text-sm text-gray-700 mt-2 rounded bg-white border border-gray-200 px-3 py-2">
                                                        <span class="block">{{ $bankAccount['name'] }}</span>
                                                        <span class="block">{{ $bankAccount['bank'] }}</span>
                                                        <span class="block tabular-nums font-semibold">{{ $bankAccount['number'] }}</span>
                                                    </span>
                                                @endif
                                            @else
                                                <span class="block text-sm text-gray-600 mt-0.5">
                                                    {{ $codNote ?: 'Pay the courier when the parcel arrives.' }}
                                                </span>
                                            @endif
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    {{-- ---------------- Summary ---------------- --}}
                    <div>
                        <div class="rounded-lg border border-gray-200 bg-gray-50 p-6 lg:sticky lg:top-24">
                            <h2 class="text-lg font-bold text-gray-900 mb-4">Your order</h2>

                            <ul class="space-y-3 pb-4 border-b border-gray-200">
                                @foreach ($lines as $line)
                                    <li class="flex justify-between gap-3 text-sm">
                                        <span class="min-w-0">
                                            <span class="block text-gray-900">{{ $line['product']->name }}</span>
                                            @if ($line['variant'])
                                                <span class="block text-xs text-gray-500">{{ $line['variant']->label }}</span>
                                            @endif
                                            <span class="block text-xs text-gray-500 tabular-nums">&times; {{ $line['quantity'] }}</span>
                                        </span>
                                        <span class="font-semibold text-gray-900 tabular-nums whitespace-nowrap">
                                            {{ App\Support\PaymentFigures::money($line['line_total']) }}
                                        </span>
                                    </li>
                                @endforeach
                            </ul>

                            <dl class="space-y-2.5 py-4 border-b border-gray-200">
                                <div class="flex justify-between gap-4 text-sm">
                                    <dt class="text-gray-600">Goods</dt>
                                    <dd class="font-semibold text-gray-900 tabular-nums">
                                        {{ App\Support\PaymentFigures::money($itemsTotal) }}
                                    </dd>
                                </div>

                                {{-- Filled in once a code has been checked. A preview: the
                                     order is priced again on the server when it is placed,
                                     and the discount comes off the goods only. --}}
                                <div class="hidden justify-between gap-4 text-sm" data-checkout-voucher-line>
                                    <dt class="text-green-700">
                                        Voucher
                                        <span class="font-mono font-semibold" data-checkout-voucher-code></span>
                                    </dt>
                                    <dd class="font-semibold text-green-700 tabular-nums" data-checkout-voucher-amount></dd>
                                </div>

                                <div class="flex justify-between gap-4 text-sm">
                                    <dt class="text-gray-600">{{ $isOffline ? 'Collection' : 'Delivery' }}</dt>
                                    @if ($isOffline)
                                        <dd class="font-semibold text-gray-900">No charge</dd>
                                    @else
                                        <dd class="font-semibold text-gray-900 tabular-nums" data-checkout-shipping>
                                            Choose a state
                                        </dd>
                                    @endif
                                </div>
                            </dl>

                            <div class="flex justify-between gap-4 pt-4">
                                <span class="text-base font-bold text-gray-900">Total</span>
                                <span class="text-lg font-bold text-gray-900 tabular-nums" data-checkout-total>
                                    {{ App\Support\PaymentFigures::money($itemsTotal) }}
                                </span>
                            </div>

                            @if ($coupons->isNotEmpty())
                                {{-- Something in the basket carries a usable ticked coupon,
                                     which is the only thing that puts this box on screen. --}}
                                <div class="mt-5">
                                    <x-voucher-field
                                        :scope="\App\Models\Coupon::KIND_SHOP"
                                        uid="checkout" />
                                </div>
                            @endif

                            <button type="submit"
                                    class="mt-5 flex items-center justify-center gap-2 w-full bg-blue-600 text-white px-6 py-3.5 rounded-lg font-semibold hover:bg-blue-700 transition shadow-sm">
                                <span data-checkout-submit-label>Place order</span>
                            </button>

                            <a href="{{ route('cart') }}" class="block text-center text-sm font-semibold text-gray-600 hover:text-blue-700 transition mt-3">
                                Back to basket
                            </a>

                            @if (filled($shippingNote))
                                <p class="text-xs text-gray-500 mt-5 pt-4 border-t border-gray-200">{{ $shippingNote }}</p>
                            @endif

                            <p class="text-xs text-gray-500 mt-3">
                                By placing this order you accept our
                                <a href="{{ route('legal.terms') }}" class="text-blue-600 hover:underline">Terms of Service</a>,
                                <a href="{{ route('legal.refund') }}" class="text-blue-600 hover:underline">Refund Policy</a>
                                and
                                <a href="{{ route('legal.shipping') }}" class="text-blue-600 hover:underline">Shipping Policy</a>.
                            </p>
                        </div>
                    </div>

                </div>
            </form>

        </div>
    </section>
@endsection

@push('scripts')
<script>
    /*
     | The running total: the postage as soon as a state is chosen, and the voucher as
     | soon as one has been checked, so neither is a surprise on the next page.
     |
     | Only a preview. The order is priced again on the server when it is placed, so
     | editing these numbers in the page changes nothing that is charged.
     |
     | Money is added up in cents. Summing floats would drift, and these figures sit
     | next to the ones the server charges.
     */
    (function () {
        const totalCell = document.querySelector('[data-checkout-total]');

        if (!totalCell) {
            return;
        }

        const state = document.querySelector('[data-checkout-state]');
        const shippingCell = document.querySelector('[data-checkout-shipping]');
        const submitLabel = document.querySelector('[data-checkout-submit-label]');
        const voucherLine = document.querySelector('[data-checkout-voucher-line]');
        const voucherAmount = document.querySelector('[data-checkout-voucher-amount]');
        const voucherCode = document.querySelector('[data-checkout-voucher-code]');

        // A collected order is never posted, so no postage is quoted for it at all.
        const isOffline = @json($isOffline);
        const east = @json(App\Support\ShippingSettings::EAST_MALAYSIA);
        const rates = { west: {{ $flatRateWest }}, east: {{ $flatRateEast }} };
        const goodsCents = Math.round({{ $itemsTotal }} * 100);
        const threshold = {{ $freeShippingThreshold === null ? 'null' : $freeShippingThreshold }};

        /*
         | The checked voucher's terms, or null. Never a finished figure: see the note
         | in the voucher-field component.
         */
        let voucher = null;

        function money(cents) {
            return 'RM ' + (cents / 100).toFixed(2);
        }

        /** What the voucher takes off the GOODS, in cents, capped at them. */
        function discountCents() {
            if (!voucher || goodsCents <= 0 || !(voucher.value > 0)) {
                return 0;
            }

            if (voucher.type === 'percentage') {
                const percent = Math.min(100, voucher.value);

                return Math.min(goodsCents, Math.round(goodsCents * percent / 100));
            }

            return Math.min(goodsCents, Math.round(voucher.value * 100));
        }

        /** The postage in cents, or null while it is not known yet. */
        function postageCents() {
            if (isOffline) {
                return 0;
            }

            if (!state || state.value === '') {
                return null;
            }

            /*
             | Banded on the FULL goods total, not the discounted one. Quoting it after
             | the discount would hand a buyer free delivery they had not reached: the
             | coupon is a reduction on the goods, not a second promotion on the
             | postage. Same rule as ShopOrderWriter.
             */
            const free = threshold !== null && goodsCents >= Math.round(threshold * 100);

            return free ? 0 : Math.round((east.includes(state.value) ? rates.east : rates.west) * 100);
        }

        function render() {
            const discount = discountCents();

            if (voucherLine) {
                voucherLine.classList.toggle('hidden', discount === 0);
                voucherLine.classList.toggle('flex', discount > 0);

                if (voucherAmount) {
                    voucherAmount.textContent = '\u2212 ' + money(discount);
                }

                if (voucherCode) {
                    voucherCode.textContent = (voucher && voucher.code) || '';
                }
            }

            const postage = postageCents();

            if (shippingCell) {
                shippingCell.textContent = postage === null
                    ? 'Choose a state'
                    : (postage === 0 ? 'Free' : money(postage));
            }

            const total = Math.max(0, goodsCents - discount) + (postage || 0);

            totalCell.textContent = money(total);

            /*
             | Nothing left to pay, so the button must not promise a payment page. The
             | order is placed and settles itself; see CheckoutController::place().
             */
            if (submitLabel) {
                submitLabel.textContent = total === 0 && discount > 0 ? 'Free Submission' : 'Place order';
            }
        }

        state?.addEventListener('change', render);

        document.querySelectorAll('[data-voucher]').forEach(function (box) {
            box.addEventListener('voucher:changed', function (event) {
                voucher = event.detail;
                render();
            });
        });

        render();
    })();
</script>
@endpush
