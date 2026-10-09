{{--
    The public Voucher Code box.

    Rendered only where a coupon has actually been ticked and could still be used —
    the caller decides that through CouponAvailability, and when nothing is on offer
    this component is not rendered at all. There is no disabled state and no box that
    refuses everything: the owner's rule is that ticking a coupon is what makes the
    field appear.

    WHAT THE BUTTON DOES, AND WHAT IT DOES NOT
    Apply only CHECKS the code. Nothing is claimed until the form itself is submitted,
    which is where the code is spent inside the same flow that writes the record. So a
    visitor who checks a code and never submits has not used it, and one who checks
    the last code and loses the race to somebody else is told at submit and charged
    the normal price.

    The input is an ordinary field inside the caller's form, so the typed code posts
    with everything else and the whole thing still works with no JavaScript at all:
    without it the code is simply checked on the server when the form arrives.

    Nothing about the batches on offer is drawn here, deliberately. A visitor is told
    whether the code they hold is worth anything; they are not shown what exists. On an
    unlimited batch the name IS the shared code, so listing one would hand the discount
    to everybody who opened the page.

    @param string $scope      'event' or 'shop', matching Coupon::KIND_*
    @param string $uid        unique per instance; the registration page draws one per event
    @param string|null $eventSlug  which event's coupons to check against
--}}

@props([
    'scope',
    'uid' => 'default',
    'eventSlug' => null,
])

@php
    $fieldId = 'voucher_code_' . $uid;
    $helpId = 'voucher_help_' . $uid;
    $resultId = 'voucher_result_' . $uid;
@endphp

<div class="rounded-lg border border-blue-200 bg-blue-50/50 px-4 py-4"
     data-voucher
     data-voucher-scope="{{ $scope }}"
     data-voucher-event="{{ $eventSlug }}"
     data-voucher-url="{{ route('voucher.check') }}"
     data-voucher-token="{{ csrf_token() }}">

    <label for="{{ $fieldId }}" class="block text-sm font-semibold text-gray-900">
        Voucher Code
    </label>

    <p id="{{ $helpId }}" class="text-xs text-gray-600 mt-0.5">
        Have a coupon? Type it here and press Apply to see what it takes off.
    </p>

    <div class="flex flex-col sm:flex-row items-stretch gap-2 mt-2.5">
        <input type="text"
               id="{{ $fieldId }}"
               name="voucher_code"
               value="{{ old('voucher_code') }}"
               maxlength="64"
               autocomplete="off"
               spellcheck="false"
               aria-describedby="{{ $helpId }} {{ $resultId }}"
               data-voucher-input
               class="w-full rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm font-mono uppercase tracking-widest text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">

        <button type="button"
                data-voucher-check
                class="shrink-0 rounded-lg border border-blue-600 bg-white px-5 py-2.5 text-sm font-semibold text-blue-700 hover:bg-blue-50 transition">
            Apply
        </button>
    </div>

    {{-- aria-live, so the answer is announced rather than only drawn. --}}
    <p id="{{ $resultId }}" role="status" aria-live="polite"
       class="hidden text-sm font-semibold mt-2" data-voucher-message></p>

    <div class="hidden mt-3" data-voucher-ticket></div>

    @error('voucher_code')
        <p class="text-xs text-red-600 mt-2">{{ $message }}</p>
    @enderror
</div>

@once
@push('scripts')
<script>
    /*
     | The Voucher Code box.
     |
     | Checks a code against the server and reports what it would take off. It never
     | decides money: the response carries the batch's terms and the page applies them
     | to the running total it already keeps for display, while the server works the
     | real figure out again from the database when the form is posted.
     |
     | Emits a `voucher:changed` event on the container carrying the terms, or nulls
     | when the code was refused or cleared. Each page listens for that and refreshes
     | its own total, so this file knows nothing about registrations or baskets.
     */
    (function () {
        document.querySelectorAll('[data-voucher]').forEach(function (box) {
            const input = box.querySelector('[data-voucher-input]');
            const button = box.querySelector('[data-voucher-check]');
            const message = box.querySelector('[data-voucher-message]');
            const ticket = box.querySelector('[data-voucher-ticket]');

            if (!input || !button) {
                return;
            }

            function announce(text, ok) {
                if (!message) {
                    return;
                }

                message.textContent = text;
                message.classList.toggle('hidden', text === '');
                message.classList.toggle('text-green-700', ok === true);
                message.classList.toggle('text-red-700', ok === false);
            }

            function publish(detail) {
                box.dispatchEvent(new CustomEvent('voucher:changed', { bubbles: true, detail: detail }));
            }

            function clear() {
                announce('', null);

                if (ticket) {
                    ticket.innerHTML = '';
                    ticket.classList.add('hidden');
                }

                publish(null);
            }

            function check() {
                const code = input.value.trim().toUpperCase();

                input.value = code;

                if (code === '') {
                    clear();

                    return;
                }

                button.disabled = true;
                announce('Checking…', null);

                fetch(box.dataset.voucherUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': box.dataset.voucherToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        code: code,
                        scope: box.dataset.voucherScope,
                        event: box.dataset.voucherEvent || null,
                    }),
                })
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        button.disabled = false;

                        if (!data || data.ok !== true) {
                            announce((data && data.message) || 'That coupon code was not recognised.', false);

                            if (ticket) {
                                ticket.innerHTML = '';
                                ticket.classList.add('hidden');
                            }

                            publish(null);

                            return;
                        }

                        announce(data.message, true);

                        if (ticket) {
                            ticket.innerHTML = data.ticket || '';
                            ticket.classList.toggle('hidden', !data.ticket);
                        }

                        publish({
                            code: data.code,
                            type: data.discount_type,
                            value: Number(data.discount_value) || 0,
                            perHead: data.per_head === true,
                        });
                    })
                    .catch(function () {
                        button.disabled = false;

                        // The code may well be fine; we could not ask. Said plainly, and
                        // the field still posts, so the server checks it on submit.
                        announce('We could not check that code just now. Submit and we will check it then.', false);
                        publish(null);
                    });
            }

            button.addEventListener('click', check);

            // Enter inside the box checks the code rather than submitting the whole
            // form, which is what a visitor means by it here.
            input.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    check();
                }
            });

            // Editing the code drops the applied discount, so the total on screen
            // cannot keep claiming a coupon that is no longer the one in the box.
            input.addEventListener('input', clear);
        });
    })();
</script>
@endpush
@endonce
