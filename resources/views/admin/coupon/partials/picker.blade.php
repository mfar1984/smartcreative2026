{{--
    The coupon tick list, shared by the event form and the shop product form.

    One partial rather than two copies, because the rule it carries is money: only
    batches of the matching kind are offered, and a batch worth more than the thing it
    is ticked on has to say so. Two copies of that would eventually disagree.

    @param \Illuminate\Support\Collection $coupons   batches of the right kind, already filtered
    @param array $selected                           ids currently ticked
    @param float|null $price                          what this event or product charges, for the warning
    @param string $priceNoun                          'event' or 'product', for the wording
--}}

@php
    use App\Models\Coupon;
    use App\Support\PaymentFigures;

    $ticked = old('coupons', $selected);
    $ticked = is_array($ticked) ? array_map('intval', $ticked) : [];
@endphp

<x-admin.panel title="Coupons" icon="tag">
    @if ($coupons->isEmpty())
        <div class="px-5 py-4">
            <p class="text-sm text-gray-600">
                No coupons have been created for {{ $priceNoun === 'event' ? 'event registrations' : 'the shop' }} yet,
                or every one of them has expired.
                <a href="{{ route('admin.coupons.create') }}" class="font-semibold text-blue-600 hover:underline">Create one</a>
                and it will appear here to be ticked.
            </p>
        </div>
    @else
        <x-admin.field-row
            label="Valid Coupons"
            help="Tick every coupon that may be used here. Nothing is discounted until one is ticked."
            error="coupons">

            {{-- An empty tick list sends nothing, so a 0 is queued first and the
                 controller reads an absent array as "none ticked" rather than as
                 "leave whatever is stored". Without it, unticking the last coupon
                 would silently keep it. --}}
            <input type="hidden" name="coupons_present" value="1">

            <div class="space-y-2" data-coupon-picker>
                @foreach ($coupons as $batch)
                    @php
                        // Asked once per row. isExhausted() calls remaining() itself, so
                        // reading both would be two counts per batch on a form that is
                        // drawn on every create and every edit.
                        $remaining = $batch->remaining();
                        $exhausted = $remaining === 0;

                        /*
                         | The owner asked that a fixed amount could not exceed the price.
                         | A batch is created before it is attached to anything, so there is
                         | no price to compare at creation; this is where one exists. Said
                         | rather than blocked, because giving something away is a decision
                         | an organiser is allowed to make — CouponDiscount caps the figure
                         | at the charge either way, so the total still floors at zero.
                         */
                        $overPrice = $price !== null
                            && $price > 0
                            && ! $batch->isPercentage()
                            && (float) $batch->discount_value > $price;
                    @endphp

                    <label class="flex items-start gap-3 rounded-lg border border-gray-300 px-3.5 py-3 cursor-pointer transition hover:border-blue-300 has-checked:border-blue-600 has-checked:bg-blue-50">
                        <input type="checkbox" name="coupons[]" value="{{ $batch->id }}"
                               @checked(in_array($batch->id, $ticked, true))
                               class="mt-0.5 h-4 w-4 shrink-0 rounded border-gray-400 text-blue-600 focus:ring-2 focus:ring-blue-500/40">

                        <span class="min-w-0 text-sm text-gray-900">
                            <span class="block font-semibold">
                                <span class="font-mono tracking-wide">{{ $batch->name }}</span>
                                <span class="ml-1.5 font-normal text-gray-600">{{ $batch->discountLabel() }} off</span>
                            </span>

                            <span class="block text-xs text-gray-600 mt-0.5">
                                @if ($batch->isUnlimited())
                                    Unlimited uses
                                @else
                                    {{ number_format($remaining ?? 0) }} of {{ number_format($batch->quantity) }} codes left
                                @endif
                                &middot; expires {{ $batch->expiresLabel() }}
                            </span>

                            @if ($exhausted)
                                {{-- Still offered on purpose. A used-up batch left ticked is
                                     what makes the price fall back to normal instead of the
                                     discount vanishing from the record. --}}
                                <span class="block text-xs text-amber-700 font-semibold mt-1">
                                    Every code has been used. Tick it and the normal price applies until
                                    a new batch is created.
                                </span>
                            @endif

                            @if ($overPrice)
                                <span class="block text-xs text-amber-700 font-semibold mt-1">
                                    {{ $batch->discountLabel() }} is more than this {{ $priceNoun }} charges
                                    ({{ PaymentFigures::money($price) }}), so it would be free.
                                </span>
                            @endif
                        </span>
                    </label>
                @endforeach
            </div>

            <p class="text-xs text-gray-500 mt-2">
                @if ($priceNoun === 'event')
                    Only coupons created for Event Registration are listed. A registrant types the
                    code; several may be ticked so a batch that runs out can be left in place and a
                    fresh one added beside it.
                @else
                    Only coupons created for the Shop are listed. The buyer types the code at
                    checkout, and it comes off the goods rather than the postage.
                @endif
            </p>
        </x-admin.field-row>
    @endif
</x-admin.panel>
