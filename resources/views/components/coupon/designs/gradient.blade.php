{{--
    Gradient: blue through indigo to violet, white type, soft circles behind it, and
    the code in a frosted chip.

    The circles are decorative and absolutely positioned, so the panel clips its own
    overflow and they can never push the layout wider than the column it sits in.

    Two real columns on a wide screen: the left one flexes into whatever the code chip
    does not take, so a long event title wraps inside its own column instead of running
    under the chip. On a phone they stack and the chip has the full width.

    @param \App\Support\CouponTicket $ticket
    @param bool $compact
--}}

@props(['ticket', 'compact' => false])

<figure {{ $attributes->merge(['class' => 'relative overflow-hidden rounded-lg bg-gradient-to-br from-blue-600 via-indigo-700 to-violet-700 shadow-sm']) }}>
    <figcaption class="sr-only">{{ $ticket->summary() }}</figcaption>

    <span class="pointer-events-none absolute -right-16 -top-24 h-64 w-64 rounded-full bg-white/10" aria-hidden="true"></span>
    <span class="pointer-events-none absolute -bottom-20 right-10 h-44 w-44 rounded-full bg-white/[0.07]" aria-hidden="true"></span>

    <div @class([
        'relative flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between',
        $compact ? 'px-4 py-3' : 'px-5 py-5 sm:px-7 sm:py-7',
    ])>
        <div class="min-w-0 flex-1">
            <p @class([
                'font-bold uppercase tracking-[0.2em] text-indigo-200',
                $compact ? 'text-[9px]' : 'text-[10px]',
            ])>
                {{ $ticket->kindLabel() }} COUPON
            </p>

            <p @class([
                'mt-1.5 font-bold leading-none text-white break-words',
                $compact ? 'text-2xl' : $ticket->discountSizeClass(),
            ])>
                {{ $ticket->discountLabel() }}
            </p>

            {{-- Two lines, then an ellipsis. The whole title is still in the markup
                 for a screen reader; what is clamped is only what is drawn. --}}
            <p @class([
                'mt-2 text-blue-100 break-words line-clamp-2',
                $compact ? 'text-[11px]' : 'text-sm',
            ])>
                {{ $ticket->subject }}
            </p>

            <p @class(['mt-1 text-indigo-200', $compact ? 'text-[10px]' : 'text-xs'])>
                {{ $ticket->expiryNote() }}
            </p>
        </div>

        {{-- The glass chip. --}}
        <div @class([
            'shrink-0 rounded-xl border border-white/45 bg-white/15 text-center backdrop-blur-sm',
            $compact ? 'px-3 py-1.5' : 'px-5 py-3 sm:px-7',
        ])>
            <p @class([
                'font-bold uppercase tracking-[0.2em] text-indigo-100',
                $compact ? 'text-[8px]' : 'text-[9px]',
            ])>
                Code
            </p>

            <p @class([
                'mt-0.5 font-mono font-bold tracking-widest text-white break-all',
                $compact ? 'text-sm' : 'text-xl sm:text-2xl',
            ])>
                {{ $ticket->code }}
            </p>
        </div>
    </div>
</figure>
