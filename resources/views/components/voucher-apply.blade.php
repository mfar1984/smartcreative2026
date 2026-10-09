{{--
    Applying a voucher code to something already submitted.

    A plain form of its own, posting straight to an endpoint that claims the code and
    moves the figures. No preview and no JavaScript: there is nothing still being
    chosen here, so a check followed by an apply would only be two chances for the
    answer to change in between.

    Rendered only while nothing has been paid. The caller decides that, and the
    endpoint refuses on the same terms, because reducing a charge below money already
    received creates a credit nobody has decided how to refund.

    @param string $action   where to post
    @param string $noun     'registration' or 'order', for the wording
--}}

@props(['action', 'noun' => 'registration'])

<form action="{{ $action }}" method="POST"
      class="rounded-lg border border-blue-200 bg-blue-50/50 px-4 py-4">
    @csrf

    <label for="voucher_code_apply" class="block text-sm font-semibold text-gray-900">
        Voucher Code
    </label>

    <p id="voucher_apply_help" class="text-xs text-gray-600 mt-0.5">
        Have a coupon for this {{ $noun }}? Enter it and we will take it off what you owe.
        It can only be applied while nothing has been paid.
    </p>

    <div class="flex flex-col sm:flex-row items-stretch gap-2 mt-2.5">
        <input type="text"
               id="voucher_code_apply"
               name="voucher_code"
               value="{{ old('voucher_code') }}"
               maxlength="64"
               required
               autocomplete="off"
               spellcheck="false"
               aria-describedby="voucher_apply_help"
               class="w-full rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm font-mono uppercase tracking-widest text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">

        <button type="submit"
                class="shrink-0 rounded-lg border border-blue-600 bg-white px-5 py-2.5 text-sm font-semibold text-blue-700 hover:bg-blue-50 transition">
            Apply
        </button>
    </div>

    @error('voucher_code')
        <p role="alert" class="text-sm font-semibold text-red-700 mt-2">{{ $message }}</p>
    @enderror
</form>
