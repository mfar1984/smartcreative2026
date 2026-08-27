{{--
    Add-on picker inside a registration modal.

    Quantities are posted as addons[addonId][variantId], or
    addons[addonId][base] for an add-on with no options. Only quantities are
    sent: every price is settled against the database by App\Support\AddonOrder,
    so the figures rendered here are for the visitor's benefit and carry no
    authority.

    @param \App\Models\Event $event
    @param bool              $isOpenModal  replay old input when reopening after an error
--}}
@php
    $addons = $event->purchasableAddons();
@endphp

@if ($addons->isNotEmpty())
    @php
        $submittedAddons = $isOpenModal ? old('addons', []) : [];
        $submittedAddons = is_array($submittedAddons) ? $submittedAddons : [];

        // One grid definition shared by the add-on header and every option row:
        // name | price | quantity. Declared once so the three columns cannot
        // drift apart, which is what leaves a column of prices looking ragged.
        // The price and quantity columns are fixed widths rather than auto,
        // because each row is its own grid and auto would size to that row's
        // own content instead of lining up with its neighbours.
        $addonGrid = 'grid grid-cols-[minmax(0,1fr)_5.5rem_3.5rem] gap-x-2 sm:grid-cols-[minmax(0,1fr)_6.5rem_5rem] sm:gap-x-3';

        // tabular-nums keeps digits the same width, so the decimal points line
        // up down the column instead of wandering.
        $priceCell = 'text-sm font-semibold text-gray-900 text-right tabular-nums whitespace-nowrap';

        $qtyInput = 'w-full rounded-lg border border-gray-300 px-1.5 sm:px-2.5 py-1.5 text-sm text-center tabular-nums text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition disabled:bg-gray-100 disabled:text-gray-400';
    @endphp

    <div class="mt-6" data-addon-picker>
        <div class="flex items-baseline justify-between gap-3 mb-2">
            <h3 class="text-sm font-bold text-gray-900">Extras</h3>
            <p class="text-xs text-gray-500">Added to the amount due</p>
        </div>

        <div class="space-y-3">
            @foreach ($addons as $addon)
                @php
                    $chosen = $submittedAddons[$addon->id] ?? [];
                    $chosen = is_array($chosen) ? $chosen : [];
                    $cap = $addon->perOrderCap();
                @endphp

                <div class="rounded-lg border border-gray-200 bg-white p-4"
                     data-addon="{{ $addon->id }}"
                     @if ($cap !== null) data-addon-cap="{{ $cap }}" @endif>

                    {{-- Header. The third column is left empty so the add-on's
                         own price sits directly above the option prices. --}}
                    <div class="{{ $addonGrid }} items-start">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-gray-900">
                                {{ $addon->name }}
                                @if ($addon->is_required)
                                    <span class="ml-1 inline-block rounded-full bg-blue-100 px-2 py-0.5 text-[11px] font-semibold text-blue-800 align-middle">Required</span>
                                @endif
                            </p>
                            @if (filled($addon->description))
                                <p class="text-xs text-gray-500 mt-0.5">{{ $addon->description }}</p>
                            @endif
                        </div>

                        <p class="{{ $priceCell }} font-bold">{{ $addon->priceSummaryLabel() }}</p>

                        <span aria-hidden="true"></span>
                    </div>

                    @error('addons.' . $addon->id)
                        <p class="text-xs text-red-600 mt-1.5">{{ $message }}</p>
                    @enderror

                    @if ($addon->hasVariants())
                        <div class="mt-3 divide-y divide-gray-100 border-t border-gray-100">
                            @foreach ($addon->variants as $variant)
                                @php
                                    $soldOut = $variant->isSoldOut();
                                    $left = $variant->stockLeft();
                                    $inputId = "addon-{$event->slug}-{$addon->id}-{$variant->id}";

                                    // The lowest of what is left and the per order cap;
                                    // either may be absent.
                                    $limit = collect([$left, $cap])->filter(fn ($v) => $v !== null)->min();
                                @endphp

                                {{-- Row and its error message wrapped together, so
                                     the divider sits between options rather than
                                     between an option and its own message. --}}
                                <div class="py-2">
                                    <div class="{{ $addonGrid }} items-center">
                                        {{-- Left to wrap rather than truncated: the
                                             stock note lives in here, and hiding it
                                             on a narrow screen would cost the
                                             visitor something they need. --}}
                                        <label for="{{ $inputId }}" class="text-sm text-gray-700 min-w-0">
                                            {{ $variant->label }}

                                            @if ($soldOut)
                                                <span class="ml-1.5 text-xs font-semibold text-red-600">Sold out</span>
                                            @elseif ($left !== null && $left <= 10)
                                                <span class="ml-1.5 text-xs text-amber-700">{{ $left }} left</span>
                                            @endif
                                        </label>

                                        <span class="{{ $priceCell }}">RM {{ number_format($variant->unitPrice(), 2) }}</span>

                                        <input type="number"
                                               id="{{ $inputId }}"
                                               name="addons[{{ $addon->id }}][{{ $variant->id }}]"
                                               value="{{ $chosen[$variant->id] ?? 0 }}"
                                               min="0"
                                               @if ($limit !== null) max="{{ $limit }}" @endif
                                               @disabled($soldOut)
                                               inputmode="numeric"
                                               data-addon-qty
                                               data-price="{{ number_format($variant->unitPrice(), 2, '.', '') }}"
                                               data-label="{{ $addon->name }} ({{ $variant->label }})"
                                               class="{{ $qtyInput }}">
                                    </div>

                                    @error('addons.' . $addon->id . '.' . $variant->id)
                                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                                    @enderror
                                </div>
                            @endforeach
                        </div>
                    @else
                        @php $inputId = "addon-{$event->slug}-{$addon->id}-base"; @endphp

                        {{-- No options to price separately, so the middle column
                             stays empty and only the box lines up. --}}
                        <div class="{{ $addonGrid }} items-center mt-3 pt-3 border-t border-gray-100">
                            <label for="{{ $inputId }}" class="text-sm text-gray-700 min-w-0">How many?</label>

                            <span aria-hidden="true"></span>

                            <input type="number"
                                   id="{{ $inputId }}"
                                   name="addons[{{ $addon->id }}][base]"
                                   value="{{ $chosen['base'] ?? 0 }}"
                                   min="0"
                                   @if ($cap !== null) max="{{ $cap }}" @endif
                                   inputmode="numeric"
                                   data-addon-qty
                                   data-price="{{ number_format($addon->unitPrice(), 2, '.', '') }}"
                                   data-label="{{ $addon->name }}"
                                   class="{{ $qtyInput }}">
                        </div>

                        @error('addons.' . $addon->id . '.base')
                            <p class="text-xs text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                    @endif

                    @if ($cap !== null)
                        <p class="text-xs text-gray-500 mt-2">At most {{ $cap }} per registration, counting every option together.</p>

                        {{-- Filled in by JS when the options add up past the cap.
                             The server enforces it regardless; this is so the
                             visitor is not told only after submitting. --}}
                        <p class="hidden text-xs font-semibold text-red-600 mt-1" role="alert" data-addon-cap-warning></p>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
@endif
