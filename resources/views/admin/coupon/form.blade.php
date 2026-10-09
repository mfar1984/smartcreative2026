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

        // What it applies to is locked once anybody has used the coupon: it decides
        // which forms offer it and which records its uses point at. The limit is NOT
        // locked — raising it is safe. See CouponRequest.
        $used = $coupon->exists ? $coupon->redeemedCount() : 0;
        $isUsed = $used > 0;
    @endphp

    <x-admin.page-card
        :title="$mode === 'create' ? 'New Coupon' : 'Edit ' . $coupon->name"
        description="The code below is what people type. Create it here, then tick it on the events or products it applies to."
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
                            This coupon has already been used, so what it applies to is fixed.
                        </p>
                    @endif
                </x-admin.field-row>
            </x-admin.panel>

            {{-- ---------------- The code ---------------- --}}
            <x-admin.panel title="The Code" icon="identification">
                <x-admin.field-row
                    label="Coupon Code"
                    help="This is what people type. Capital letters and digits only. Generate one or type your own."
                    for="name"
                    :required="true"
                    error="name">

                    <div class="flex items-stretch gap-2">
                        <input type="text" id="name" name="name" required maxlength="32"
                               value="{{ old('name', $mode === 'create' ? $suggestedCode : $coupon->name) }}"
                               data-code
                               class="{{ $input }} font-mono uppercase tracking-widest">

                        {{-- The generator runs in the browser off the same legible
                             alphabet the server uses, and the server still checks the
                             result is free: a code that collides is refused on save
                             rather than trusted because it came from a button. --}}
                        <button type="button" data-generate
                                class="shrink-0 inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                            <x-admin.icon name="bolt" class="w-4 h-4" />
                            Generate
                        </button>
                    </div>

                    <p class="text-xs text-gray-500 mt-1.5" data-code-note>
                        @if ((int) $quantity > 0)
                            Everybody types this same code, up to the limit below.
                        @else
                            Everybody types this same code, and there is no limit on it.
                        @endif
                    </p>
                </x-admin.field-row>

                <x-admin.field-row
                    label="How Many Uses"
                    help="How many times the code may be used. 0 means no limit."
                    for="quantity"
                    :required="true"
                    error="quantity">

                    <input type="number" id="quantity" name="quantity" required min="0" max="{{ $maxQuantity }}"
                           value="{{ $quantity }}"
                           data-quantity
                           class="{{ $input }}">

                    @if ($isUsed)
                        {{-- Raising it is safe, so the field stays open. Only going below
                             what has already been honoured is refused. --}}
                        <p class="text-xs text-amber-700 mt-1.5 font-semibold">
                            Used {{ $used }} {{ $used === 1 ? 'time' : 'times' }} already, so the limit
                            cannot go below {{ $used }}. Raising it is fine.
                        </p>
                    @endif

                    <p class="text-xs text-gray-500 mt-1.5">
                        Set 50 and the code works for the first fifty people who type it.
                        Set 0 and it works for everybody.
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
                    help="How it is drawn where the public can see it. Pick one to see it."
                    :required="true"
                    error="design">

                    {{-- A visual chooser rather than a dropdown, because a design is the
                         one field on this form whose name tells you nothing about what you
                         are choosing.

                         Every tile carries a real radio, so the keyboard reaches it, the
                         group is announced, and the posted field is still `design` with the
                         same values it always had. The preview beside it is decoration with
                         a click handler, and is aria-hidden: a screen reader gets the
                         label, not a second reading of the sample figures.

                         Drawn from Coupon::DESIGNS, so a new design appears here the moment
                         its key and component file exist. --}}
                    <fieldset data-design-picker>
                        <legend class="sr-only">Coupon design</legend>

                        <div class="grid grid-cols-1 gap-3 xl:grid-cols-2">
                            @foreach ($designs as $value => $label)
                                <div @class([
                                    'rounded-xl border-2 p-3 transition',
                                    'has-checked:border-blue-600 has-checked:bg-blue-50/60',
                                    'border-gray-200 hover:border-blue-300',
                                ])>
                                    <label for="design-{{ $value }}" class="flex items-start gap-2.5 cursor-pointer">
                                        <input type="radio" id="design-{{ $value }}" name="design" value="{{ $value }}" required
                                               @checked($design === $value)
                                               data-design
                                               class="mt-0.5 shrink-0 text-blue-600 focus:ring-2 focus:ring-blue-500/40">
                                        <span class="text-sm font-semibold text-gray-900">{{ $label }}</span>
                                    </label>

                                    <div class="mt-3 cursor-pointer" data-design-preview="{{ $value }}" aria-hidden="true">
                                        <x-coupon.ticket
                                            :coupon="$designSamples[$value]"
                                            :subject="$designSubject"
                                            :compact="true" />
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </fieldset>

                    <p class="text-xs text-gray-500 mt-2">
                        Drawn with sample figures. The real coupon shows its own discount, what
                        it is for, its code and its expiry, whichever design is chosen. More
                        designs will be added over time.
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
        // The server's legible alphabet, read from it rather than retyped: the
        // characters that get misread off a screen (0/O, 1/I/L, 8/B, 5/S, 2/Z, J/3)
        // are left out of both. See Coupon::CODE_EXCLUDED.
        const ALPHABET = @json(\App\Models\Coupon::CODE_ALPHABET);

        const code = document.querySelector('[data-code]');
        const generate = document.querySelector('[data-generate]');
        const quantity = document.querySelector('[data-quantity]');
        const codeNote = document.querySelector('[data-code-note]');
        const unit = document.querySelector('[data-unit]');
        const value = document.querySelector('[data-discount-value]');
        const percentageNote = document.querySelector('[data-percentage-note]');
        const fixedNote = document.querySelector('[data-fixed-note]');
        const designs = Array.from(document.querySelectorAll('[data-design]'));
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
                ? 'Everybody types this same code, up to the limit below.'
                : 'Everybody types this same code, and there is no limit on it.';
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
            const chosen = designs.find((radio) => radio.checked)?.value;

            customRow?.classList.toggle('hidden', chosen !== 'custom');
        }

        quantity?.addEventListener('input', syncQuantity);
        document.querySelectorAll('[data-discount-type]').forEach((el) => el.addEventListener('change', syncDiscount));
        designs.forEach((radio) => radio.addEventListener('change', syncDesign));

        /*
         | Clicking the preview picks that design.
         |
         | A convenience on top of the radio, never instead of it: the radio is what
         | the keyboard reaches and what posts, and this only sets it. change has to
         | be dispatched by hand because setting checked in script does not raise it.
         */
        document.querySelectorAll('[data-design-preview]').forEach(function (preview) {
            preview.addEventListener('click', function () {
                const radio = document.getElementById('design-' + preview.dataset.designPreview);

                if (radio && !radio.checked) {
                    radio.checked = true;
                    radio.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
        });

        syncQuantity();
        syncDiscount();
        syncDesign();
    })();
</script>
@endpush
