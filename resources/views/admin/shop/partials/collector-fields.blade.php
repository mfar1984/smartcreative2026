{{--
    Who is collecting, for a counter handover.

    Shared by the dialog on the orders list and the panel on the order page, so the
    two cannot drift into asking for different things on the same act.

    @param \App\Models\ShopOrder $order
    @param string                $uid    unique suffix for the ids on this copy
--}}
@php
    $field = 'w-full rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';
@endphp

{{-- The whole block is one unit for the script: it finds the radios, the detail
     box and the submit button by looking inside this element, so two copies on one
     page never talk to each other's fields. --}}
<div data-collector="{{ $order->id }}"
     data-code-url="{{ route('admin.shop.orders.collection-code', $order) }}">

    <fieldset>
        <legend class="block text-sm font-semibold text-gray-700 mb-2">
            Who is collecting?
        </legend>

        <div class="space-y-2">
            {{-- Default, and deliberately so. The buyer turning up for their own
                 order is the common case and it is already verified by the identity
                 card check: an SMS code there costs counter time and proves nothing
                 that the card does not. --}}
            <label class="flex items-start gap-2.5 rounded-lg border border-gray-200 px-3.5 py-2.5 cursor-pointer hover:bg-gray-50 transition">
                <input type="radio" name="collector" value="buyer" checked
                       id="collector-buyer-{{ $uid }}"
                       data-collector-choice="buyer"
                       class="mt-0.5 w-4 h-4 text-blue-600 border-gray-300 focus:ring-blue-500">
                <span class="min-w-0">
                    <span class="block text-sm font-semibold text-gray-900">
                        The buyer, {{ $order->customer_name }}
                    </span>
                    <span class="block text-xs text-gray-500 mt-0.5">
                        Check the identity card above against the document you are shown. No code needed.
                    </span>
                </span>
            </label>

            <label class="flex items-start gap-2.5 rounded-lg border border-gray-200 px-3.5 py-2.5 cursor-pointer hover:bg-gray-50 transition">
                <input type="radio" name="collector" value="other"
                       id="collector-other-{{ $uid }}"
                       data-collector-choice="other"
                       class="mt-0.5 w-4 h-4 text-blue-600 border-gray-300 focus:ring-blue-500">
                <span class="min-w-0">
                    <span class="block text-sm font-semibold text-gray-900">
                        Somebody else, on the buyer's behalf
                    </span>
                    <span class="block text-xs text-gray-500 mt-0.5">
                        Their own identity card, and a code texted to their phone before anything is handed over.
                    </span>
                </span>
            </label>
        </div>
    </fieldset>

    {{-- Only on screen once somebody says the buyer is not the one collecting, so
         the common case stays two presses. --}}
    <div data-collector-details
         class="hidden mt-3 space-y-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3.5">

        <p class="text-xs text-amber-800">
            Record who is actually taking the goods. If anything goes missing, this is
            the only thing that answers for it.
        </p>

        <div>
            <label for="collector_name-{{ $uid }}" class="block text-sm font-semibold text-gray-700 mb-1.5">
                Their Full Name
            </label>
            <input type="text" id="collector_name-{{ $uid }}" name="collector_name" maxlength="190"
                   value="{{ old('collector_name') }}" autocomplete="off"
                   data-collector-field="name"
                   class="{{ $field }}">
            @error('collector_name')
                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
            @enderror
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
            <div>
                <label for="collector_ic-{{ $uid }}" class="block text-sm font-semibold text-gray-700 mb-1.5">
                    Their IC Number
                </label>
                <input type="text" id="collector_ic-{{ $uid }}" name="collector_ic" maxlength="30"
                       value="{{ old('collector_ic') }}" autocomplete="off"
                       data-collector-field="ic"
                       class="{{ $field }} tabular-nums">
                @error('collector_ic')
                    <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="collector_phone-{{ $uid }}" class="block text-sm font-semibold text-gray-700 mb-1.5">
                    Their Phone Number
                </label>
                <input type="tel" id="collector_phone-{{ $uid }}" name="collector_phone" maxlength="30"
                       value="{{ old('collector_phone') }}" autocomplete="off"
                       placeholder="017-859 1411"
                       data-collector-field="phone"
                       class="{{ $field }} tabular-nums">
                @error('collector_phone')
                    <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                @enderror
            </div>
        </div>

        {{-- Sent straight out rather than queued, so a failure shows up here and now
             instead of being discovered after the person has walked off. --}}
        <div class="rounded-lg border border-amber-300 bg-white px-3.5 py-3">
            <div class="flex flex-wrap items-end gap-3">
                <div class="flex-1 min-w-40">
                    <label for="code-{{ $uid }}" class="block text-sm font-semibold text-gray-700 mb-1.5">
                        Code They Read Out
                    </label>
                    <input type="text" id="code-{{ $uid }}" name="code"
                           inputmode="numeric" maxlength="6" autocomplete="one-time-code"
                           placeholder="000000"
                           data-collector-code
                           class="{{ $field }} tabular-nums tracking-widest">
                </div>

                <button type="button" data-collector-send
                        class="shrink-0 inline-flex items-center gap-2 rounded-lg border border-amber-400 bg-amber-100 px-4 py-2.5 text-sm font-semibold text-amber-900 hover:bg-amber-200 transition">
                    <x-admin.icon name="send" class="w-4 h-4" />
                    Send Code
                </button>
            </div>

            @error('code')
                <p class="text-xs text-red-600 mt-2">{{ $message }}</p>
            @enderror

            {{-- aria-live, so the gateway's answer is announced rather than only
                 drawn. Staff at a counter are not watching this corner of the
                 screen. --}}
            <p data-collector-status role="status" aria-live="polite"
               class="hidden text-xs mt-2"></p>

            <p class="text-xs text-gray-500 mt-2">
                Six digits, texted to the number above. It expires, and it stops working
                after a few wrong tries.
            </p>
        </div>

        {{-- The way out, and it has to exist. Stadium signal is poor, numbers get
             mistyped and gateways go down; a counter with no way through will record
             the collector as the buyer instead, and then the record is a lie. An
             audited override is worth far more than that. --}}
        <details class="rounded-lg border border-red-200 bg-white px-3.5 py-3" data-collector-override>
            <summary class="text-sm font-semibold text-red-700 cursor-pointer">
                The code will not go through
            </summary>

            <div class="mt-3">
                <label for="override_reason-{{ $uid }}" class="block text-sm font-semibold text-gray-700 mb-1.5">
                    Why You Are Skipping The Code
                </label>
                <input type="text" id="override_reason-{{ $uid }}" name="override_reason" maxlength="255"
                       value="{{ old('override_reason') }}"
                       placeholder="e.g. no signal in the hall, number no longer in use"
                       data-collector-reason
                       class="{{ $field }}">
                @error('override_reason')
                    <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                @enderror
                <p class="text-xs text-gray-500 mt-1">
                    Fill this in and the handover goes through without a code, marked on the
                    record as handed over without SMS verification, against your name.
                </p>
            </div>
        </details>
    </div>
</div>
