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

                    /*
                     | Offered ticked. On a first view the box starts ticked; after a
                     | failed submit it follows what actually came back, so somebody
                     | who deliberately declined is not silently opted back in.
                     */
                    $offeredTicked = $addon->isCheckedByDefault();
                    $reminder = $addon->uncheckReminder();

                    $wasSubmitted = $isOpenModal && $submittedAddons !== [];
                    $chosenAny = collect($chosen)->contains(fn ($q) => (int) $q > 0);
                    $startTicked = $offeredTicked && (! $wasSubmitted || $chosenAny);

                    $toggleId = "addon-toggle-{$event->slug}-{$addon->id}";
                @endphp

                {{-- data-addon-once is the add-on's own price, charged a single time
                     when anything at all is taken from it. The quantity boxes carry
                     only the per size surcharge, so the running total adds this once
                     rather than multiplying it. --}}
                <div class="rounded-lg border border-gray-200 bg-white p-4"
                     data-addon="{{ $addon->id }}"
                     data-addon-once="{{ number_format($addon->unitPrice(), 2, '.', '') }}"
                     data-addon-name="{{ $addon->name }}"
                     @if ($cap !== null) data-addon-cap="{{ $cap }}" @endif>

                    {{-- Header. The third column is left empty so the add-on's
                         own price sits directly above the option prices. --}}
                    <div class="{{ $addonGrid }} items-start">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-gray-900">
                                @if ($offeredTicked)
                                    {{-- The name doubles as the opt out. Nothing is
                                         submitted by this box: it only drives the
                                         quantities below, which remain what the
                                         server reads. --}}
                                    <label for="{{ $toggleId }}" class="inline-flex items-start gap-2 cursor-pointer">
                                        <input type="checkbox" id="{{ $toggleId }}"
                                               @checked($startTicked)
                                               data-addon-toggle
                                               class="mt-0.5 h-4 w-4 shrink-0 rounded border-gray-400 text-blue-600 focus:ring-2 focus:ring-blue-500/40">
                                        <span>{{ $addon->name }}</span>
                                    </label>
                                @else
                                    {{ $addon->name }}
                                @endif

                                @if ($addon->is_required)
                                    <span class="ml-1 inline-block rounded-full bg-blue-100 px-2 py-0.5 text-[11px] font-semibold text-blue-800 align-middle">Required</span>
                                @elseif ($offeredTicked)
                                    <span class="ml-1 inline-block rounded-full bg-green-100 px-2 py-0.5 text-[11px] font-semibold text-green-800 align-middle">Included</span>
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

                    @if ($reminder !== null)
                        {{-- role=status rather than an alert: this is a consequence
                             of a deliberate choice, not an error, and a dialog here
                             would block somebody who meant it. Hidden until they
                             actually clear the box. --}}
                        <div @class(['mt-2.5 flex items-start gap-2.5 rounded-lg border border-amber-200 bg-amber-50 p-3', 'hidden' => $startTicked])
                             role="status" data-addon-reminder>
                            <svg class="mt-0.5 h-4 w-4 shrink-0 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 9v4m0 4h.01M12 3l9 16H3l9-16z"/>
                            </svg>
                            <p class="text-xs leading-relaxed text-amber-800">{{ $reminder }}</p>
                        </div>
                    @endif

                    @if ($addon->hasVariants())
                        @php
                            /*
                             | With options, "included" has to land on one of them or
                             | the tick above would promise something the total does
                             | not charge for. The first option still in stock takes
                             | it, and the buyer changes the size if it is wrong.
                             |
                             | Only on a first view: after a failed submit the boxes
                             | replay what came back, so a chosen size is kept and a
                             | deliberate decline is not undone.
                             */
                            $defaultVariantId = $startTicked && ! $wasSubmitted
                                ? $addon->variants->first(fn ($v) => ! $v->isSoldOut())?->id
                                : null;
                        @endphp

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

                                        {{-- A free option shows no money at all. Printing
                                             "RM 0.00" against a size that was deliberately
                                             set to zero is noise, and it reads as though
                                             something is still being charged. When only
                                             some options are free the word says so, since
                                             an empty cell beside a priced one looks like a
                                             rendering fault. --}}
                                        <span class="{{ $priceCell }}">
                                            @if (! $variant->isFree())
                                                RM {{ number_format($variant->unitPrice(), 2) }}
                                            @elseif (! $addon->costsNothing())
                                                <span class="text-gray-400">Free</span>
                                            @endif
                                        </span>

                                        <input type="number"
                                               id="{{ $inputId }}"
                                               name="addons[{{ $addon->id }}][{{ $variant->id }}]"
                                               value="{{ $chosen[$variant->id] ?? ($variant->id === $defaultVariantId ? 1 : 0) }}"
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
                        @php
                            $inputId = "addon-{$event->slug}-{$addon->id}-base";

                            // An add-on offered ticked starts at one rather than
                            // zero, which is what "already included" has to mean
                            // for the figure below to match the tick above it.
                            $baseValue = $chosen['base'] ?? ($startTicked ? 1 : 0);
                        @endphp

                        {{-- No options to price separately, so the middle column
                             stays empty and only the box lines up. --}}
                        <div class="{{ $addonGrid }} items-center mt-3 pt-3 border-t border-gray-100">
                            <label for="{{ $inputId }}" class="text-sm text-gray-700 min-w-0">How many?</label>

                            <span aria-hidden="true"></span>

                            <input type="number"
                                   id="{{ $inputId }}"
                                   name="addons[{{ $addon->id }}][base]"
                                   value="{{ $baseValue }}"
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
