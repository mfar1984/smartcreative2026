{{--
    Bold: a dark block on the left carrying the discount as the hero.

    The hero block is sized for one short number, so the figure is stepped down by
    CouponTicket::heroSizeClass() as it gets longer — "50" fills the block, "1,250.00"
    shrinks to fit rather than spilling out of it. The unit sits under the number
    instead of beside it for the same reason.

    @param \App\Support\CouponTicket $ticket
    @param bool $compact
--}}

@props(['ticket', 'compact' => false])

<figure {{ $attributes->merge(['class' => 'flex flex-col overflow-hidden rounded-lg border border-gray-300 bg-white shadow-sm sm:flex-row']) }}>
    <figcaption class="sr-only">{{ $ticket->summary() }}</figcaption>

    {{-- The hero. A fixed-ish column on a wide screen, a full-width band on a phone,
         so a long figure has the whole width rather than a third of it. --}}
    <div @class([
        'flex shrink-0 flex-col items-center justify-center bg-slate-900 text-center',
        'sm:w-48',
        $compact ? 'px-4 py-3' : 'px-6 py-5',
    ])>
        <p @class([
            'font-bold leading-none text-white break-all',
            $compact ? 'text-2xl' : $ticket->heroSizeClass(),
        ])>
            {{ $ticket->heroValue() }}
        </p>

        <p @class([
            'mt-1 font-bold tracking-wide text-blue-400',
            $compact ? 'text-[10px]' : 'text-base sm:text-lg',
        ])>
            {{ $ticket->heroUnit() }}
        </p>

        <p @class([
            'mt-0.5 font-semibold uppercase tracking-widest text-slate-500',
            $compact ? 'text-[8px]' : 'text-[10px]',
        ])>
            Discount
        </p>
    </div>

    <div @class(['min-w-0 flex-1', $compact ? 'px-4 py-3' : 'px-5 py-5 sm:px-6'])>
        <p @class([
            'font-bold uppercase tracking-widest text-blue-600',
            $compact ? 'text-[9px]' : 'text-[11px]',
        ])>
            {{ $ticket->kindLabel() }} COUPON
        </p>

        <p @class([
            'mt-1 font-semibold leading-snug text-slate-900 break-words',
            $compact ? 'text-xs' : 'text-lg sm:text-xl',
        ])>
            {{ $ticket->subject }}
        </p>

        <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2">
            <p @class([
                'rounded-md border border-blue-200 bg-blue-50 font-mono font-bold tracking-widest text-blue-700 break-all',
                $compact ? 'px-2 py-1 text-xs' : 'px-4 py-2 text-lg sm:text-xl',
            ])>
                {{ $ticket->code }}
            </p>

            <p @class(['text-slate-400', $compact ? 'text-[10px]' : 'text-xs'])>
                {{ $ticket->expiryNote() }}
            </p>
        </div>
    </div>
</figure>
