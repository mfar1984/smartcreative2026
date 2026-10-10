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
        $codeMode = old('mode', $coupon->mode ?: Coupon::MODE_SHARED);
        $discountType = old('discount_type', $coupon->discount_type ?: Coupon::DISCOUNT_PERCENTAGE);
        $quantity = old('quantity', $coupon->quantity ?? 0);

        // $design comes from the controller: the picker opens on the group that choice
        // belongs to, so the chosen key has to be worked out before the view.

        // What it applies to is locked once anybody has used the coupon: it decides
        // which forms offer it and which records its uses point at. The limit is NOT
        // locked — raising it is safe. See CouponRequest.
        $used = $coupon->exists ? $coupon->redeemedCount() : 0;
        $isUsed = $used > 0;

        // How the codes work is locked as soon as there is anything behind it: issued
        // codes in somebody's hands, or uses already honoured.
        $isUnique = $codeMode === Coupon::MODE_UNIQUE;
        $hasIssued = $coupon->exists && $coupon->isUnique() && $coupon->issuedCount() > 0;
        $isModeLocked = $isUsed || $hasIssued;
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
                    label="How The Codes Work"
                    help="One code everybody types, or individual codes you hand out."
                    :required="true"
                    error="mode">

                    <div class="space-y-2">
                        @foreach ($codeModes as $value => $option)
                            <label class="flex items-start gap-3 rounded-lg border px-3.5 py-3 cursor-pointer transition hover:border-blue-300 has-checked:border-blue-600 has-checked:bg-blue-50 border-gray-300">
                                <input type="radio" name="mode" value="{{ $value }}"
                                       @checked($codeMode === $value)
                                       @disabled($isModeLocked)
                                       data-code-mode
                                       class="mt-0.5 shrink-0 text-blue-600 focus:ring-2 focus:ring-blue-500/40">
                                <span class="text-sm text-gray-900">
                                    <span class="block font-semibold">{{ $option['label'] }}</span>
                                    <span class="block text-xs text-gray-600 mt-0.5">{{ $option['help'] }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>

                    @if ($isModeLocked)
                        {{-- A disabled radio sends nothing, so the stored value has to be
                             carried or the save would wipe it. --}}
                        <input type="hidden" name="mode" value="{{ $coupon->mode }}">
                        <p class="text-xs text-amber-700 mt-2 font-semibold">
                            This coupon already has codes or uses behind it, so how its codes
                            work is fixed.
                        </p>
                    @endif
                </x-admin.field-row>

                <x-admin.field-row
                    label="Coupon Code"
                    help="The name of the coupon. In shared mode it is also what people type."
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
                        @if ($isUnique)
                            A label for this batch. The codes people type are generated below,
                            and this name is not one of them.
                        @elseif ((int) $quantity > 0)
                            Everybody types this same code, up to the limit below.
                        @else
                            Everybody types this same code, and there is no limit on it.
                        @endif
                    </p>
                </x-admin.field-row>

                {{-- Wrapped so the script can find this row's own label and helper.

                     THE LABEL FOLLOWS THE MODE. In unique mode the figure typed here
                     is how many CODES to generate; in shared mode it is how many USES
                     the one code allows. The two numbers are equal in unique mode, so
                     a single label was not wrong — but this feature has already sent
                     the owner down a wrong path once with a batch name that read like
                     a code, and a label that still says "uses" while he is generating
                     codes is the same mistake. The server renders the right one on
                     load and the script keeps it right when the mode is switched. --}}
                <div data-quantity-row>
                <x-admin.field-row
                    :label="$isUnique ? 'How Many Codes' : 'How Many Uses'"
                    :help="$isUnique ? 'How many individual codes to generate now.' : 'How many times the code may be used. 0 means no limit.'"
                    for="quantity"
                    :required="true"
                    error="quantity">

                    @if ($hasIssued)
                        {{-- Not the operator's to type once codes exist: the figure IS the
                             stock. More codes come from the Issue panel on the report, as a
                             new block with its own handler. --}}
                        <input type="number" id="quantity" value="{{ $coupon->quantity }}" disabled
                               class="{{ $input }} bg-gray-50 text-gray-500">
                        <input type="hidden" name="quantity" value="{{ $coupon->quantity }}">

                        <p class="text-xs text-gray-600 mt-1.5">
                            {{ number_format($coupon->quantity) }} codes have been generated across
                            {{ number_format($coupon->allocations()->count()) }}
                            {{ Str::plural('block', $coupon->allocations()->count()) }}.
                            <a href="{{ route('admin.coupons.report.show', $coupon) }}"
                               class="font-semibold text-blue-600 hover:underline">Generate more</a>
                            as a new block with its own handler.
                        </p>
                    @else
                        <input type="number" id="quantity" name="quantity" required
                               min="{{ $isUnique ? 1 : 0 }}" max="{{ $maxQuantity }}"
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

                        <p class="text-xs text-gray-500 mt-1.5" data-quantity-note>
                            @if ($isUnique)
                                1,000 codes fund 1,000 participants. Generate more later as a
                                separate block for a different handler.
                            @else
                                Set 50 and the code works for the first fifty people who type it.
                                Set 0 and it works for everybody.
                            @endif
                        </p>
                    @endif
                </x-admin.field-row>
                </div>

                {{-- ---------------- Who funded the batch ---------------- --}}
                @if ($canSetSponsor)
                    <x-admin.field-row
                        label="Sponsorship"
                        help="Optional. The account that paid for this batch, so they can watch it being used."
                        for="sponsor_user_id"
                        error="sponsor_user_id">

                        @if ($sponsors->isEmpty())
                            {{-- An empty dropdown here would leave the operator hunting
                                 for something that does not exist yet, which is exactly
                                 what happened when this was only on the Report screen.
                                 So the field says so, and says where to go. --}}
                            <p class="text-sm text-gray-700">
                                There are no sponsorship accounts yet.
                                <a href="{{ route('admin.settings.users', ['tab' => 'sponsorship']) }}"
                                   class="font-semibold text-blue-600 hover:underline">
                                    Create one under Settings, User Management, Sponsorship
                                </a>
                                and it will be offered here.
                            </p>
                        @else
                            <select id="sponsor_user_id" name="sponsor_user_id" class="{{ $input }}">
                                <option value="">Not sponsored</option>
                                @foreach ($sponsors as $account)
                                    <option value="{{ $account->id }}" @selected((int) $sponsorId === (int) $account->id)>
                                        {{ $account->name }}
                                    </option>
                                @endforeach
                            </select>

                            {{-- THE RULE, said here as well as in the code, because two
                                 places that can set the same thing is how the quantity
                                 field confused this feature the first time round. See
                                 CouponAllocation::effectiveSponsorId(). --}}
                            <p class="text-xs text-gray-500 mt-1.5">
                                This applies to the whole batch, including a single shared code.
                                <span class="font-semibold">Every block in it follows this
                                sponsorship unless that block names its own</span> on the
                                batch report, which overrides it for that block only.
                            </p>
                        @endif
                    </x-admin.field-row>
                @endif

                {{-- ---------------- Who handles the first block ---------------- --}}
                @if ($mode === 'create')
                    <div data-handler-row @class(['hidden' => ! $isUnique])>
                        <x-admin.field-row
                            label="Who Will Handle These"
                            help="Optional. The representative these codes are given to, so a code can be traced back to whoever is holding it."
                            error="holder_full_name">

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label for="holder_full_name" class="block text-xs font-semibold text-gray-600 mb-1">
                                        Name, NGO or company
                                    </label>
                                    {{-- No picklist here: the batch does not exist yet, so
                                         there are no handlers on it to offer. The Issue
                                         Codes panel on the report offers the growing list. --}}
                                    <input type="text" id="holder_full_name" name="holder_full_name" maxlength="190"
                                           value="{{ old('holder_full_name') }}"
                                           class="{{ $input }}">
                                </div>

                                <div>
                                    <label for="holder_email" class="block text-xs font-semibold text-gray-600 mb-1">
                                        Email
                                    </label>
                                    <input type="email" id="holder_email" name="holder_email" maxlength="190"
                                           value="{{ old('holder_email') }}"
                                           class="{{ $input }}">
                                </div>

                                <div>
                                    <label for="holder_ic_number" class="block text-xs font-semibold text-gray-600 mb-1">
                                        IC number
                                    </label>
                                    <input type="text" id="holder_ic_number" name="holder_ic_number" maxlength="32"
                                           value="{{ old('holder_ic_number') }}"
                                           class="{{ $input }}">
                                </div>

                                <div>
                                    <label for="holder_phone" class="block text-xs font-semibold text-gray-600 mb-1">
                                        Phone
                                    </label>
                                    <input type="text" id="holder_phone" name="holder_phone" maxlength="32"
                                           value="{{ old('holder_phone') }}"
                                           class="{{ $input }}">
                                </div>
                            </div>

                            @error('holder_email')
                                <p class="text-xs text-red-600 mt-1.5 font-semibold">{{ $message }}</p>
                            @enderror

                            <p class="text-xs text-gray-500 mt-2">
                                All four are optional. Leave them blank and the codes are generated
                                unassigned. A phone number on its own is not enough to identify a
                                handler, so pair it with a name, an email or an IC number.
                            </p>
                        </x-admin.field-row>
                    </div>
                @endif

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

                <x-admin.field-row
                    label="Sponsorship Committed"
                    help="Optional. What the sponsor said they would give, in RM."
                    for="committed_amount"
                    error="committed_amount">

                    <div class="flex items-center gap-2">
                        <span class="text-sm font-semibold text-gray-500 shrink-0 w-6">RM</span>
                        <input type="number" id="committed_amount" name="committed_amount"
                               step="0.01" min="0"
                               value="{{ old('committed_amount', $coupon->committed_amount) }}"
                               class="{{ $input }}">
                    </div>

                    <p class="text-xs text-gray-500 mt-1.5">
                        Recorded as typed and never used in any calculation. The report shows it
                        beside what the codes are estimated to be worth and what they have
                        actually given away, kept plainly apart.
                    </p>
                </x-admin.field-row>
            </x-admin.panel>

            {{-- ---------------- The design ---------------- --}}
            <x-admin.panel title="Design" icon="photo">
                <x-admin.field-row
                    label="Design Coupon"
                    help="How it is drawn where the public can see it. The one in use is shown; change it to pick another."
                    :required="true"
                    error="design">

                    {{-- A visual chooser rather than a dropdown, because a design is the
                         one field on this form whose name tells you nothing about what you
                         are choosing.

                         The current choice is drawn here and the rest are behind a button,
                         so this section is the same height at six designs as at six
                         hundred. See the partial for why it is not a grid and not a scroll
                         box. --}}
                    @include('admin.coupon.partials.design-chooser')

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
        const quantityNote = document.querySelector('[data-quantity-note]');

        // This row's own label and helper. Switching the mode changes what the
        // figure MEANS — codes to generate, or uses of one code — so the words have
        // to move with it rather than only on the next page load.
        const quantityRow = document.querySelector('[data-quantity-row]');
        const quantityLabel = quantityRow?.querySelector('label[for="quantity"]');
        const quantityHelp = quantityLabel?.parentElement?.querySelector('p');
        const handlerRow = document.querySelector('[data-handler-row]');
        const unit = document.querySelector('[data-unit]');
        const value = document.querySelector('[data-discount-value]');
        const percentageNote = document.querySelector('[data-percentage-note]');
        const fixedNote = document.querySelector('[data-fixed-note]');

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

        function isUniqueMode() {
            return document.querySelector('[data-code-mode]:checked')?.value === 'unique';
        }

        function syncQuantity() {
            const unique = isUniqueMode();

            if (codeNote) {
                codeNote.textContent = unique
                    ? 'A label for this batch. The codes people type are generated below, and this name is not one of them.'
                    : (Number(quantity?.value || 0) > 0
                        ? 'Everybody types this same code, up to the limit below.'
                        : 'Everybody types this same code, and there is no limit on it.');
            }

            if (quantityNote) {
                quantityNote.textContent = unique
                    ? '1,000 codes fund 1,000 participants. Generate more later as a separate block for a different handler.'
                    : 'Set 50 and the code works for the first fifty people who type it. Set 0 and it works for everybody.';
            }

            // Only the label's own text node, so the required asterisk beside it is
            // left alone.
            if (quantityLabel?.firstChild) {
                quantityLabel.firstChild.textContent = unique ? 'How Many Codes' : 'How Many Uses';
            }

            if (quantityHelp) {
                quantityHelp.textContent = unique
                    ? 'How many individual codes to generate now.'
                    : 'How many times the code may be used. 0 means no limit.';
            }

            // Unlimited has no meaning for codes you print and hand out, so the floor
            // moves with the mode rather than being refused only on save.
            if (quantity) {
                quantity.min = unique ? '1' : '0';

                if (unique && Number(quantity.value || 0) < 1) {
                    quantity.value = '';
                }
            }

            // The handler fields belong to a block of codes, so they only exist when
            // there are blocks.
            handlerRow?.classList.toggle('hidden', !unique);
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

        quantity?.addEventListener('input', syncQuantity);
        document.querySelectorAll('[data-code-mode]').forEach((el) => el.addEventListener('change', syncQuantity));
        document.querySelectorAll('[data-discount-type]').forEach((el) => el.addEventListener('change', syncDiscount));

        // The design field is the chooser's own: it owns the radios, the inline
        // preview and the upload row that follows the choice. See
        // admin/coupon/partials/design-chooser.blade.php.

        syncQuantity();
        syncDiscount();
    })();
</script>
@endpush
