@extends('layouts.admin')

@php
    use App\Support\CouponHolderIdentity;
    use App\Support\PaymentFigures;

    $head = 'px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500';
    $select = 'rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';
    $input = 'w-full rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';
@endphp

@section('title', $coupon->name . ' Report')

@section('breadcrumb')
    @include('admin.partials.breadcrumb-root')
    <span>Coupon</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <a href="{{ route('admin.coupons.report') }}" class="hover:text-gray-700 transition">Report</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">{{ $coupon->name }}</span>
@endsection

@section('content')
    <x-admin.page-card
        :title="$coupon->name"
        :description="$coupon->discountLabel() . ' off ' . $coupon->kindLabel() . ' · ' . $coupon->modeLabel() . ' · expires ' . $coupon->expiresLabel()"
        :back="route('admin.coupons.report')"
        :flush="true">

        <x-slot:actions>
            <a href="{{ route('admin.coupons.report.export', ['coupon' => $coupon, 'allocation' => $allocationId, 'state' => $state ?: null]) }}"
               class="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                <x-admin.icon name="download" class="w-4 h-4" />
                Export CSV
            </a>
        </x-slot:actions>

        @include('admin.partials.flash')

        {{-- ---------------- The sponsor's four figures ----------------

             Kept visibly apart on purpose. Committed is a promise somebody made,
             estimated is a guess, actual is money that really came off real
             registrations, and remaining is worked out from the actual. Conflating
             them is how a sponsor's money appears to vanish, so every card says
             which kind of figure it is holding.

             NOT DRAWN FOR A MONITORING ACCOUNT, and that is a correctness decision
             rather than a cautious one. These four are a sponsorship's money across
             the WHOLE batch — a batch routinely ticked on several organisers' events
             — so they are the one set of figures on this screen that cannot honestly
             be narrowed to one event. Showing a monitor a scoped version would put a
             different quantity under the word "committed"; showing the real one would
             hand them another organiser's pledge. So the panel is for staff, and a
             monitor reads the uses below it, which ARE theirs. --}}
        @if ($showsSponsorship)
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-px bg-gray-200 border-b border-gray-200">
            <div class="bg-white px-5 py-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Committed</p>
                <p class="text-xl font-bold text-gray-900 tabular-nums mt-1">
                    {{ $figures['committed'] === null ? '—' : PaymentFigures::money($figures['committed']) }}
                </p>
                <p class="text-xs text-gray-500 mt-0.5">
                    {{ $figures['committed'] === null ? 'not recorded on this coupon' : 'what the sponsor pledged' }}
                </p>
            </div>

            <div class="bg-white px-5 py-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-amber-700">Estimated allocated</p>
                <p class="text-xl font-bold text-gray-900 tabular-nums mt-1">
                    {{ $figures['estimated'] === null ? '—' : PaymentFigures::money($figures['estimated']) }}
                </p>
                <p class="text-xs text-gray-500 mt-0.5">
                    @if ($figures['estimated'] === null)
                        no single value: {{ $figures['basis'] }}
                    @else
                        <span class="font-semibold text-amber-700">ESTIMATE</span>
                        &middot; {{ number_format($figures['codes']) }}
                        {{ Str::plural('code', $figures['codes']) }}
                        &times; {{ PaymentFigures::money($figures['per_code']) }}
                    @endif
                </p>
            </div>

            <div class="bg-white px-5 py-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Actually given</p>
                <p class="text-xl font-bold text-gray-900 tabular-nums mt-1">
                    {{ PaymentFigures::money($figures['actual']) }}
                </p>
                <p class="text-xs text-gray-500 mt-0.5">
                    the real discount on {{ number_format($coupon->redeemedCount()) }}
                    {{ Str::plural('use', $coupon->redeemedCount()) }}
                </p>
            </div>

            <div class="bg-white px-5 py-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Sponsorship left</p>
                <p class="text-xl font-bold text-gray-900 tabular-nums mt-1">
                    {{ $figures['remaining'] === null ? '—' : PaymentFigures::money($figures['remaining']) }}
                </p>
                <p class="text-xs text-gray-500 mt-0.5">
                    {{ $figures['remaining'] === null
                        ? 'needs a committed amount'
                        : 'committed less what was actually given' }}
                </p>
            </div>
        </div>

        @unless ($figures['exact'])
            <p class="px-5 py-2.5 text-xs text-amber-800 bg-amber-50 border-b border-amber-200">
                <span class="font-semibold">Estimated allocated is a guess, not a figure.</span>
                It is {{ $figures['basis'] }}. What a percentage coupon is really worth depends
                on what the person using it was being charged, add-ons and all. Only
                <span class="font-semibold">Actually given</span> is money that moved.
            </p>
        @endunless

        {{-- ---------------- Who funded the batch ----------------

             The batch-level sponsorship, read-only here because it is set on the
             coupon form, under THE CODE. Shown on both modes: a shared batch has no
             blocks, so this is the ONLY level it can be sponsored at. --}}
        <div class="px-5 py-3 border-b border-gray-200 bg-gray-50/60">
            <p class="text-xs text-gray-600">
                <span class="font-semibold text-gray-700">Sponsorship for the whole batch:</span>
                @if ($coupon->hasSponsor())
                    <span class="font-semibold text-gray-900">{{ $coupon->sponsor?->name }}</span>
                @else
                    <span class="italic text-gray-500">Not sponsored</span>
                @endif

                @if ($canIssue)
                    &middot;
                    <a href="{{ route('admin.coupons.edit', $coupon) }}"
                       class="font-semibold text-blue-600 hover:underline">Change it on the coupon form</a>
                @endif
            </p>

            @if ($coupon->isUnique())
                {{-- THE RULE, stated where both levels are visible. See
                     CouponAllocation::effectiveSponsorId(). --}}
                <p class="text-xs text-gray-500 mt-0.5">
                    Every block below follows this unless it names its own sponsorship, which
                    overrides it for that block only.
                </p>
            @endif
        </div>
        @endif

        {{-- ---------------- The blocks ---------------- --}}
        @if ($coupon->isUnique())
            <div class="px-5 py-4 border-b border-gray-200">
                <h3 class="text-sm font-bold text-gray-900">Blocks Issued</h3>
                <p class="text-xs text-gray-500 mt-0.5">
                    Whose codes are finished, and whose have not been touched. Each block was
                    generated and handed over on its own, so a block's balance is what a group
                    registration is checked against.
                </p>

                @if ($canIssue && $sponsors->isEmpty())
                    {{-- Said out loud rather than leaving an operator looking for a
                         control that cannot exist yet, which is what this screen used
                         to do: the select simply was not drawn and nothing explained
                         why. --}}
                    <p class="text-xs text-gray-500 mt-1.5">
                        There are no sponsorship accounts yet, so there is nothing to tag a block to.
                        <a href="{{ route('admin.settings.users', ['tab' => 'sponsorship']) }}"
                           class="font-semibold text-blue-600 hover:underline">
                            Create one under Settings, User Management, Sponsorship.
                        </a>
                    </p>
                @endif

                <div class="overflow-x-auto mt-3 rounded-lg border border-gray-200">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-left">
                            <tr>
                                <th scope="col" class="{{ $head }}">Handler</th>
                                <th scope="col" class="{{ $head }}">Sponsorship</th>
                                <th scope="col" class="{{ $head }}">Issued</th>
                                <th scope="col" class="{{ $head }} text-right">Codes</th>
                                <th scope="col" class="{{ $head }} text-right">Used</th>
                                <th scope="col" class="{{ $head }} text-right">Unused</th>
                                <th scope="col" class="{{ $head }} text-center">State</th>
                                <th scope="col" class="{{ $head }} text-center">Codes</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-100">
                            @forelse ($allocations as $allocation)
                                @php
                                    $total = (int) $allocation->codes_total;
                                    $usedCodes = (int) $allocation->codes_used;
                                    $unused = max(0, $total - $usedCodes);

                                    // Read off the counted columns rather than the model's own
                                    // methods, which would each be another query per row.
                                    $tone = $unused === 0 ? 'amber' : ($usedCodes === 0 ? 'gray' : 'green');
                                    $label = $unused === 0 ? 'Finished' : ($usedCodes === 0 ? 'Untouched' : 'In use');
                                @endphp

                                <tr @class(['hover:bg-blue-50/40 align-top', 'bg-blue-50/60' => $allocationId === $allocation->id])>
                                    <td class="px-5 py-3">
                                        <span @class(['font-semibold text-gray-900', 'text-gray-500 italic' => ! $allocation->hasHolder()])>
                                            {{ $allocation->holderLabel() }}
                                        </span>

                                        @if ($allocation->holder !== null && $allocation->holder->contactLine() !== '')
                                            {{-- The HANDLER's contact details: the office's own
                                                 route back to whoever is holding these codes.
                                                 Not a participant's — a redeemer appears by
                                                 name only, further down. --}}
                                            <span class="block text-xs text-gray-500 mt-0.5">
                                                {{ $allocation->holder->contactLine() }}
                                            </span>
                                        @endif
                                    </td>

                                    {{-- Who FUNDED the block, which is not who hands it out.
                                         Tagged here by staff, because a sponsorship account
                                         is monitor-and-view only and tags nothing itself. --}}
                                    <td class="px-5 py-3">
                                        @if ($canIssue && $sponsors->isNotEmpty())
                                            <form action="{{ route('admin.coupons.allocations.sponsor', ['coupon' => $coupon, 'allocation' => $allocation]) }}"
                                                  method="POST" class="flex flex-wrap items-center gap-1.5">
                                                @csrf
                                                @method('PUT')

                                                <label for="sponsor-{{ $allocation->id }}" class="sr-only">
                                                    Sponsorship for {{ $allocation->holderLabel() }}
                                                </label>
                                                <select id="sponsor-{{ $allocation->id }}" name="sponsor_user_id"
                                                        class="rounded-lg border border-gray-300 px-2 py-1.5 text-xs text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
                                                    {{-- Blank is not "unsponsored" any more: it means
                                                         this block follows the batch. Saying which it
                                                         is matters, because the two look identical
                                                         until somebody tags the batch. --}}
                                                    <option value="">
                                                        {{ $coupon->hasSponsor()
                                                            ? 'Follow the batch (' . $coupon->sponsor?->name . ')'
                                                            : 'Not sponsored' }}
                                                    </option>
                                                    @foreach ($sponsors as $sponsor)
                                                        <option value="{{ $sponsor->id }}" @selected($allocation->sponsor_user_id === $sponsor->id)>
                                                            {{ $sponsor->name }}
                                                        </option>
                                                    @endforeach
                                                </select>

                                                <label class="inline-flex items-center gap-1 text-xs text-gray-500">
                                                    <input type="checkbox" name="apply_to" value="batch"
                                                           class="rounded border-gray-300 text-blue-600 focus:ring-blue-500/40">
                                                    All blocks
                                                </label>

                                                <button type="submit"
                                                        class="rounded-lg border border-transparent bg-gray-100 px-2.5 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-200 transition">
                                                    Save
                                                </button>
                                            </form>
                                        @else
                                            @php $effective = $allocation->effectiveSponsor(); @endphp

                                            <span @class(['text-sm', 'text-gray-900' => $effective !== null, 'text-gray-500 italic' => $effective === null])>
                                                {{ $effective?->name ?? 'Not sponsored' }}
                                            </span>
                                        @endif

                                        @if ($allocation->inheritsSponsor())
                                            <span class="block text-xs text-gray-400 mt-0.5">from the batch</span>
                                        @endif
                                    </td>

                                    <td class="px-5 py-3 text-gray-600 whitespace-nowrap tabular-nums">
                                        {{ $allocation->issuedLabel() }}
                                    </td>

                                    <td class="px-5 py-3 text-right text-gray-600 tabular-nums">
                                        {{ number_format($total) }}
                                    </td>

                                    <td class="px-5 py-3 text-right font-semibold text-gray-900 tabular-nums">
                                        {{ number_format($usedCodes) }}
                                    </td>

                                    <td class="px-5 py-3 text-right text-gray-600 tabular-nums">
                                        {{ number_format($unused) }}
                                    </td>

                                    <td class="px-5 py-3 text-center">
                                        <x-admin.badge :tone="$tone" :dot="true">{{ $label }}</x-admin.badge>
                                    </td>

                                    <td class="px-5 py-3 text-center whitespace-nowrap">
                                        <a href="{{ route('admin.coupons.report.show', ['coupon' => $coupon, 'allocation' => $allocation->id]) }}"
                                           class="text-xs font-semibold text-blue-600 hover:underline">
                                            Show
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-5 py-6 text-center text-sm text-gray-500">
                                        No codes have been generated yet.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- ---------------- Issue another block ---------------- --}}
            @if ($canIssue)
                <div class="px-5 py-4 border-b border-gray-200 bg-gray-50">
                    <h3 class="text-sm font-bold text-gray-900">Generate More Codes</h3>
                    <p class="text-xs text-gray-500 mt-0.5">
                        A new block with its own handler, never a top-up of an existing one. That
                        is what keeps "whose codes ran out" answerable.
                    </p>

                    <form action="{{ route('admin.coupons.codes.store', $coupon) }}" method="POST" class="mt-3">
                        @csrf

                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                            <div>
                                <label for="quantity" class="block text-xs font-semibold text-gray-600 mb-1">
                                    How many <span class="text-red-500">*</span>
                                </label>
                                <input type="number" id="quantity" name="quantity" required min="1" max="5000"
                                       value="{{ old('quantity') }}"
                                       class="{{ $input }}">
                            </div>

                            <div>
                                <label for="issue_holder_full_name" class="block text-xs font-semibold text-gray-600 mb-1">
                                    Handler
                                </label>

                                {{-- The growing picklist: whoever already handles a block on
                                     this coupon, offered rather than retyped. Typing a new name
                                     is still allowed — that is the point of a datalist over a
                                     dropdown — and the identity key is what stops a slightly
                                     different spelling becoming a second handler. --}}
                                <input type="text" id="issue_holder_full_name" name="holder_full_name" maxlength="190"
                                       value="{{ old('holder_full_name') }}"
                                       list="coupon-handlers" autocomplete="off"
                                       class="{{ $input }}">

                                <datalist id="coupon-handlers">
                                    @foreach ($coupon->holders as $holder)
                                        @if (filled($holder->full_name))
                                            <option value="{{ $holder->full_name }}"></option>
                                        @endif
                                    @endforeach
                                </datalist>
                            </div>

                            <div>
                                <label for="issue_holder_email" class="block text-xs font-semibold text-gray-600 mb-1">
                                    Email
                                </label>
                                <input type="email" id="issue_holder_email" name="holder_email" maxlength="190"
                                       value="{{ old('holder_email') }}"
                                       class="{{ $input }}">
                            </div>

                            <div>
                                <label for="issue_holder_ic_number" class="block text-xs font-semibold text-gray-600 mb-1">
                                    IC number
                                </label>
                                <input type="text" id="issue_holder_ic_number" name="holder_ic_number" maxlength="32"
                                       value="{{ old('holder_ic_number') }}"
                                       class="{{ $input }}">
                            </div>

                            <div>
                                <label for="issue_holder_phone" class="block text-xs font-semibold text-gray-600 mb-1">
                                    Phone
                                </label>
                                <input type="text" id="issue_holder_phone" name="holder_phone" maxlength="32"
                                       value="{{ old('holder_phone') }}"
                                       class="{{ $input }}">
                            </div>
                        </div>

                        @error('quantity')
                            <p class="text-xs text-red-600 mt-2 font-semibold">{{ $message }}</p>
                        @enderror
                        @error('holder_full_name')
                            <p class="text-xs text-red-600 mt-2 font-semibold">{{ $message }}</p>
                        @enderror
                        @error('holder_email')
                            <p class="text-xs text-red-600 mt-2 font-semibold">{{ $message }}</p>
                        @enderror

                        <div class="flex items-center justify-between gap-4 mt-3">
                            <p class="text-xs text-gray-500">
                                The handler fields are optional. Blank means an unassigned block, which
                                groups under "{{ CouponHolderIdentity::UNASSIGNED }}".
                            </p>
                            <button type="submit"
                                    class="bg-blue-600 text-white px-5 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-sm shrink-0">
                                Generate Codes
                            </button>
                        </div>
                    </form>
                </div>
            @endif
        @endif

        {{-- ---------------- The codes, or the uses ---------------- --}}
        @if ($coupon->isUnique())
            <x-admin.filter-bar
                :action="route('admin.coupons.report.show', $coupon)"
                :reset="$isFiltered ? route('admin.coupons.report.show', $coupon) : null">

                <label for="allocation" class="sr-only">Handler</label>
                <select id="allocation" name="allocation" class="{{ $select }}">
                    <option value="">Every handler</option>
                    @foreach ($allocations as $allocation)
                        <option value="{{ $allocation->id }}" @selected($allocationId === $allocation->id)>
                            {{ $allocation->holderLabel() }} ({{ number_format((int) $allocation->codes_total) }})
                        </option>
                    @endforeach
                </select>

                <label for="state" class="sr-only">Used or unused</label>
                <select id="state" name="state" class="{{ $select }}">
                    <option value="">Used and unused</option>
                    <option value="used" @selected($state === 'used')>Used only</option>
                    <option value="unused" @selected($state === 'unused')>Unused only</option>
                </select>
            </x-admin.filter-bar>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <caption class="sr-only">
                        Every individual code, who handles it, whether it has been used, and by
                        whom.
                    </caption>

                    <thead class="bg-gray-50 text-left">
                        <tr>
                            <th scope="col" class="{{ $head }}">Code</th>
                            <th scope="col" class="{{ $head }}">Handler</th>
                            <th scope="col" class="{{ $head }} text-center">State</th>
                            <th scope="col" class="{{ $head }}">Used by</th>
                            <th scope="col" class="{{ $head }}">Reference</th>
                            <th scope="col" class="{{ $head }}">When</th>
                            <th scope="col" class="{{ $head }} text-right">Discount</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-gray-100">
                        @forelse ($codes as $issued)
                            <tr class="hover:bg-blue-50/40 align-top">
                                <td class="px-5 py-3 font-mono font-semibold text-gray-900 whitespace-nowrap">
                                    {{ $issued->code }}
                                </td>

                                <td class="px-5 py-3 text-gray-600">
                                    <span @class(['text-gray-500 italic' => ! ($issued->allocation?->hasHolder() ?? false)])>
                                        {{ $issued->holderLabel() }}
                                    </span>
                                </td>

                                <td class="px-5 py-3 text-center">
                                    <x-admin.badge :tone="$issued->stateTone()" :dot="true">
                                        {{ $issued->stateLabel() }}
                                    </x-admin.badge>
                                </td>

                                {{-- The REDEEMER, by name only. Their IC, phone and payment
                                     details are on the registration and stay there: a sponsor
                                     reads this screen's shape, and a name is all they need. --}}
                                <td class="px-5 py-3 text-gray-700">
                                    {{ $issued->redeemerName() ?? '—' }}
                                </td>

                                <td class="px-5 py-3 whitespace-nowrap">
                                    @if ($issued->redemption?->registration !== null)
                                        <a href="{{ route('admin.event.participants.show', $issued->redemption->registration) }}"
                                           class="font-semibold text-blue-600 hover:underline">
                                            {{ $issued->redemption->registration->reference }}
                                        </a>
                                    @elseif ($issued->redemption?->order !== null)
                                        <a href="{{ route('admin.shop.orders.show', $issued->redemption->order) }}"
                                           class="font-semibold text-blue-600 hover:underline">
                                            {{ $issued->redemption->order->reference }}
                                        </a>
                                    @else
                                        <span class="text-xs text-gray-400">—</span>
                                    @endif
                                </td>

                                <td class="px-5 py-3 text-gray-600 whitespace-nowrap tabular-nums">
                                    {{ $issued->usedAtLabel() }}
                                </td>

                                <td class="px-5 py-3 text-right font-semibold text-gray-900 tabular-nums whitespace-nowrap">
                                    {{ $issued->redemption === null ? '—' : $issued->redemption->discountLabel() }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-12 text-center">
                                    <x-admin.icon name="tag" class="w-10 h-10 mx-auto text-gray-300" />
                                    <p class="text-sm font-semibold text-gray-700 mt-3">
                                        {{ $isFiltered ? 'Nothing matches those filters' : 'No codes generated yet' }}
                                    </p>
                                    <p class="text-sm text-gray-500 mt-1">
                                        {{ $isFiltered
                                            ? 'Clear the filters to see every code.'
                                            : 'Generate a block above and the codes appear here.' }}
                                    </p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="px-5 py-3.5 border-t border-gray-200">
                @if ($codes->hasPages())
                    {{ $codes->links() }}
                @else
                    <p class="text-xs text-gray-500">
                        Showing {{ $codes->count() }} {{ Str::plural('code', $codes->count()) }}
                        of {{ number_format($coupon->issuedCount()) }} generated.
                    </p>
                @endif
            </div>
        @else
            <div class="px-5 py-3 border-b border-gray-200">
                <h3 class="text-sm font-bold text-gray-900">Uses</h3>
                <p class="text-xs text-gray-500 mt-0.5">
                    One shared code, so there is nothing to hand out and nothing to group by.
                    Every use of it is listed here, one row per participant covered.
                </p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-left">
                        <tr>
                            <th scope="col" class="{{ $head }}">Code typed</th>
                            <th scope="col" class="{{ $head }}">Used by</th>
                            <th scope="col" class="{{ $head }}">Reference</th>
                            <th scope="col" class="{{ $head }}">Used on</th>
                            <th scope="col" class="{{ $head }}">When</th>
                            <th scope="col" class="{{ $head }} text-right">Discount</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-gray-100">
                        @forelse ($uses as $use)
                            <tr class="hover:bg-blue-50/40 align-top">
                                <td class="px-5 py-3 font-mono font-semibold text-gray-900 whitespace-nowrap">
                                    {{ $use->codeLabel() }}
                                </td>

                                <td class="px-5 py-3 text-gray-700">
                                    {{ $use->participant_name ?? '—' }}
                                </td>

                                <td class="px-5 py-3 whitespace-nowrap">
                                    @if ($use->registration !== null)
                                        <a href="{{ route('admin.event.participants.show', $use->registration) }}"
                                           class="font-semibold text-blue-600 hover:underline">
                                            {{ $use->registration->reference }}
                                        </a>
                                    @elseif ($use->order !== null)
                                        <a href="{{ route('admin.shop.orders.show', $use->order) }}"
                                           class="font-semibold text-blue-600 hover:underline">
                                            {{ $use->order->reference }}
                                        </a>
                                    @else
                                        <span class="text-xs text-gray-400">Record removed</span>
                                    @endif
                                </td>

                                <td class="px-5 py-3 text-gray-600">{{ $use->usedOnLabel() }}</td>

                                <td class="px-5 py-3 text-gray-600 whitespace-nowrap tabular-nums">
                                    {{ \App\Support\LocalTime::format($use->redeemed_at) }}
                                </td>

                                <td class="px-5 py-3 text-right font-semibold text-gray-900 tabular-nums whitespace-nowrap">
                                    {{ $use->discountLabel() }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-12 text-center">
                                    <x-admin.icon name="activity" class="w-10 h-10 mx-auto text-gray-300" />
                                    <p class="text-sm font-semibold text-gray-700 mt-3">Nothing has been used yet</p>
                                    <p class="text-sm text-gray-500 mt-1">
                                        A row appears the moment somebody types {{ $coupon->name }}.
                                    </p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="px-5 py-3.5 border-t border-gray-200">
                @if ($uses->hasPages())
                    {{ $uses->links() }}
                @else
                    <p class="text-xs text-gray-500">
                        Showing {{ $uses->count() }} {{ Str::plural('use', $uses->count()) }}.
                    </p>
                @endif
            </div>
        @endif

    </x-admin.page-card>
@endsection
