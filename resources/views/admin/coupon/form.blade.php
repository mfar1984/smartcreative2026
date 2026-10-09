@extends('layouts.admin')

@section('title', $mode === 'create' ? 'New Coupon' : 'Edit ' . $coupon->name)

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>Coupon</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <a href="{{ route('admin.coupons.index') }}" class="hover:text-gray-700 transition">Coupon</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">{{ $mode === 'create' ? 'New' : $coupon->name }}</span>
@endsection

@section('content')
    @php
        use App\Models\Coupon;

        $input = 'w-full rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';

        $kind = old('kind', $coupon->kind ?: Coupon::KIND_EVENT);
        $discountType = old('discount_type', $coupon->discount_type ?: Coupon::DISCOUNT_PERCENTAGE);
        $design = old('design', $coupon->design ?: 'classic');
        $quantity = old('quantity', $coupon->quantity ?? 0);

        // Locked once anybody has used the batch: changing either would mean
        // reprinting codes somebody is already holding. See CouponRequest.
        $isUsed = $coupon->exists && $coupon->redeemedCount() > 0;
    @endphp

    <x-admin.page-card
        :title="$mode === 'create' ? 'New Coupon' : 'Edit ' . $coupon->name"
        description="A coupon is a batch. Create it here, then tick it on the events or products it applies to."
        :back="route('admin.coupons.index')">

        @include('admin.partials.flash')

        <form action="{{ $mode === 'create' ? route('admin.coupons.store') : route('admin.coupons.update', $coupon) }}"
              method="POST" enctype="multipart/form-data">
            @csrf
            @if ($mode !== 'create') @method('PUT') @endif

            {{-- ---------------- What it discounts ---------------- --}}
            <x-admin.panel title="What It Applies To" icon="tag">
                <x-admin.field-row
                    label="Used For"
                    help="One or the other. Only the matching forms offer this coupon."
                    :required="true"
                    error="kind">

                    <div class="space-y-2">
                        @foreach ($kinds as $value => $label)
                            <label class="flex items-start gap-3 rounded-lg border px-3.5 py-3 cursor-pointer transition hover:border-blue-300 has-checked:border-blue-600 has-checked:bg-blue-50 border-gray-300">
                                <input type="radio" name="kind" value="{{ $value }}"
                                       @checked($kind === $value)
                                       @disabled($isUsed)
                                       class="mt-0.5 shrink-0 text-blue-600 focus:ring-2 focus:ring-blue-500/40">
                                <span class="text-sm text-gray-900">
                                    <span class="block font-semibold">{{ $label }}</span>
                                    <span class="block text-xs text-gray-600 mt-0.5">
                                        {{ $value === Coupon::KIND_EVENT
                                            ? 'Offered on the event form, and taken off the registration total.'
                                            : 'Offered on the product form, and taken off the goods at checkout. Never off the postage.' }}
                                    </span>
                                </span>
                            </label>
                        @endforeach
                    </div>

                    @if ($isUsed)
                        {{-- A disabled radio sends nothing, so the stored value has to be
                             carried or the save would wipe it. --}}
                        <input type="hidden" name="kind" value="{{ $coupon->kind }}">
                        <p class="text-xs text-amber-700 mt-2 font-semibold">
                            This batch has already been used, so what it applies to is fixed.
                        </p>
                    @endif
                </x-admin.field-row>
            </x-admin.panel>

            {{-- ---------------- The code ---------------- --}}
            <x-admin.panel title="The Code" icon="identification">
                <x-admin.field-row
                    label="Name of Coupon"
                    help="Capital letters and digits only. Generate one or type your own."
                    for="name"
                    :required="true"
                    error="name">

                    <div class="flex items-stretch gap-2">
                        <input type="text" id="name" name="name" required maxlength="32"
                               value="{{ old('name', $mode === 'create' ? $suggestedCode : $coupon->name) }}"
                               data-code
                               class="{{ $input }} font-mono uppercase tracking-widest">

                        {{-- The generator runs in the browser off the same alphabet the
                             server uses, and the server still checks the result is free:
                             a code that collides is refused on save rather than trusted
                             because it came from a button. --}}
                        <button type="button" data-generate
                                class="shrink-0 inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                            <x-admin.icon name="bolt" class="w-4 h-4" />
                            Generate
                        </button>
                    </div>

                    <p class="text-xs text-gray-500 mt-1.5" data-code-note>
                        @if ((int) $quantity > 0)
                            This is the name of the batch. Each person gets their own unique code.
                        @else
                            This is what people type, and it can be used without limit.
                        @endif
                    </p>
                </x-admin.field-row>

                <x-admin.field-row
                    label="Number of Coupon"
                    help="How many people may use it. 0 is unlimited."
                    for="quantity"
                    :required="true"
                    error="quantity">

                    <input type="number" id="quantity" name="quantity" required min="0" max="{{ $maxQuantity }}"
                           value="{{ $quantity }}"
                           @disabled($isUsed)
                           data-quantity
                           class="{{ $input }}">

                    @if ($isUsed)
                        <input type="hidden" name="quantity" value="{{ $coupon->quantity }}">
                        <p class="text-xs text-amber-700 mt-1.5 font-semibold">
                            {{ $coupon->redeemedCount() }} already used, so the number of coupons is fixed.
                            Create a new batch instead.
                        </p>
                    @endif

                    <p class="text-xs text-gray-500 mt-1.5">
                        Set 50 and fifty unique codes are minted, one each, each usable once.
                        Set 0 and nothing is minted: the name above is the code and there is no
                        limit on it.
                    </p>
                </x-admin.field-row>

                <x-admin.field-row
                    label="Date Expired"
                    help="The last day it works, on the office clock."
                    for="expires_at"
                    :required="true"
                    error="expires_at">

                    <input type="date" id="expires_at" name="expires_at" required
                           value="{{ old('expires_at', $coupon->expires_at?->toDateString() ?? now()->addMonth()->toDateString()) }}"
                           class="{{ $input }}">
                </x-admin.field-row>
            </x-admin.panel>

            {{-- ---------------- The discount ---------------- --}}
            <x-admin.panel title="The Discount" icon="credit-card">
                <x-admin.field-row
                    label="Percentage or RM"
                    help="One or the other."
                    :required="true"
                    error="discount_type">

                    <div class="space-y-2">
                        @foreach ($discountTypes as $value => $label)
                            <label class="flex items-start gap-3 rounded-lg border border-gray-300 px-3.5 py-3 cursor-pointer transition hover:border-blue-300 has-checked:border-blue-600 has-checked:bg-blue-50">
                                <input type="radio" name="discount_type" value="{{ $value }}"
                                       @checked($discountType === $value)
                                       data-discount-type
                                       class="mt-0.5 shrink-0 text-blue-600 focus:ring-2 focus:ring-blue-500/40">
                                <span class="text-sm font-semibold text-gray-900">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                </x-admin.field-row>

                <x-admin.field-row
                    label="Discount"
                    for="discount_value"
                    :required="true"
                    error="discount_value">

                    <div class="flex items-center gap-2">
                        <span class="text-sm font-semibold text-gray-500 shrink-0 w-6" data-unit>
                            {{ $discountType === Coupon::DISCOUNT_PERCENTAGE ? '%' : 'RM' }}
                        </span>
                        <input type="number" id="discount_value" name="discount_value" required
                               step="0.01" min="0.01"
                               value="{{ old('discount_value', $coupon->discount_value) }}"
                               data-discount-value
                               class="{{ $input }}">
                    </div>

                    <p class="text-xs text-gray-500 mt-1.5" data-percentage-note
                       @class(['hidden' => $discountType !== Coupon::DISCOUNT_PERCENTAGE])>
                        1 to 100. At 100 there is nothing left to pay, so the entry settles
                        itself and no payment is raised.
                    </p>

                    <p class="text-xs text-gray-500 mt-1.5" data-fixed-note
                       @class(['hidden' => $discountType === Coupon::DISCOUNT_PERCENTAGE])>
                        Capped at whatever is being charged, so it can never make a total
                        negative. On a grouping event that charges add-ons per participant, a
                        fixed amount is owed once per head.
                    </p>
                </x-admin.field-row>
            </x-admin.panel>

            {{-- ---------------- The design ---------------- --}}
            <x-admin.panel title="Design" icon="photo">
                <x-admin.field-row
                    label="Design Coupon"
                    help="How it is drawn where the public can see it."
                    for="design"
                    :required="true"
                    error="design">

                    <select id="design" name="design" required data-design class="{{ $input }} bg-white">
                        @foreach ($designs as $value => $label)
                            <option value="{{ $value }}" @selected($design === $value)>{{ $label }}</option>
                        @endforeach
                    </select>

                    <p class="text-xs text-gray-500 mt-1.5">
                        More presets are coming. The choice is stored now so a coupon created
                        today still names a design the public page will understand later.
                    </p>
                </x-admin.field-row>

                <div data-custom-row @class(['hidden' => $design !== Coupon::DESIGN_CUSTOM])>
                    <x-admin.field-row
                        label="Your Own Artwork"
                        help="JPG, PNG or WebP, up to 4 MB."
                        for="design_image"
                        error="design_image">

                        @if ($coupon->hasCustomDesign())
                            <div class="flex items-start gap-3 mb-3">
                                <img src="{{ $coupon->designUrl() }}" alt="Current coupon design"
                                     class="w-40 h-24 object-cover rounded border border-gray-200">

                                <label class="inline-flex items-start gap-2 cursor-pointer">
                                    <input type="checkbox" name="remove_design_image" value="1"
                                           class="mt-0.5 h-4 w-4 shrink-0 rounded border-gray-400 text-red-600 focus:ring-2 focus:ring-red-500/40">
                                    <span class="text-sm text-gray-700">Remove this picture</span>
                                </label>
                            </div>
                        @endif

                        <input type="file" id="design_image" name="design_image"
                               accept="image/jpeg,image/png,image/webp"
                               class="{{ $input }}">
                    </x-admin.field-row>
                </div>
            </x-admin.panel>

            <div class="flex items-center justify-between gap-4 bg-white rounded-lg border border-gray-200 px-5 py-4 mt-5">
                <p class="text-xs text-gray-500">
                    Nothing is discounted until this coupon is ticked on an event or a product.
                </p>
                <button type="submit"
                        class="bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-sm shrink-0">
                    {{ $mode === 'create' ? 'Create Coupon' : 'Save Changes' }}
                </button>
            </div>
        </form>
    </x-admin.page-card>
@endsection

@push('scripts')
<script>
    (function () {
        const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

        const code = document.querySelector('[data-code]');
        const generate = document.querySelector('[data-generate]');
        const quantity = document.querySelector('[data-quantity]');
        const codeNote = document.querySelector('[data-code-note]');
        const unit = document.querySelector('[data-unit]');
        const value = document.querySelector('[data-discount-value]');
        const percentageNote = document.querySelector('[data-percentage-note]');
        const fixedNote = document.querySelector('[data-fixed-note]');
        const design = document.querySelector('[data-design]');
        const customRow = document.querySelector('[data-custom-row]');

        generate?.addEventListener('click', function () {
            // crypto rather than Math.random: a guessable coupon code is money.
            const bytes = new Uint32Array(6);
            window.crypto.getRandomValues(bytes);

            code.value = Array.from(bytes, (n) => ALPHABET[n % ALPHABET.length]).join('');
        });

        // Typed lowercase is folded here as well as on the server, so what is on
        // screen is what gets stored.
        code?.addEventListener('input', function () {
            const caret = code.selectionStart;
            code.value = code.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
            code.setSelectionRange(caret, caret);
        });

        function syncQuantity() {
            if (!codeNote) {
                return;
            }

            codeNote.textContent = Number(quantity?.value || 0) > 0
                ? 'This is the name of the batch. Each person gets their own unique code.'
                : 'This is what people type, and it can be used without limit.';
        }

        function syncDiscount() {
            const isPercentage = document.querySelector('[data-discount-type]:checked')?.value === 'percentage';

            if (unit) {
                unit.textContent = isPercentage ? '%' : 'RM';
            }

            if (value) {
                // The server caps a percentage at 100 regardless; this stops the
                // operator typing 150 and only finding out on save.
                value.max = isPercentage ? '100' : '999999.99';
            }

            percentageNote?.classList.toggle('hidden', !isPercentage);
            fixedNote?.classList.toggle('hidden', isPercentage);
        }

        function syncDesign() {
            customRow?.classList.toggle('hidden', design?.value !== 'custom');
        }

        quantity?.addEventListener('input', syncQuantity);
        document.querySelectorAll('[data-discount-type]').forEach((el) => el.addEventListener('change', syncDiscount));
        design?.addEventListener('change', syncDesign);

        syncQuantity();
        syncDiscount();
        syncDesign();
    })();
</script>
@endpush
