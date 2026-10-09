{{--
    Classic: a perforated ticket with the code on a torn-off stub.

    The tear line is a dashed border with a notch punched out of each end, which is
    what makes it read as a ticket rather than a box with a divider. On a phone the
    stub drops under the body and the tear turns horizontal, so nothing is clipped and
    the code never ends up in a column too narrow for it.

    @param \App\Support\CouponTicket $ticket
    @param bool $compact
--}}

@props(['ticket', 'compact' => false])

<figure {{ $attributes->merge(['class' => 'relative flex flex-col sm:flex-row overflow-hidden rounded-lg border border-gray-300 bg-white shadow-sm']) }}>
    <figcaption class="sr-only">{{ $ticket->summary() }}</figcaption>

    {{-- The spine. A full-height bar on a wide screen, a top rule on a phone. --}}
    <span class="h-1.5 w-full shrink-0 bg-blue-600 sm:h-auto sm:w-2" aria-hidden="true"></span>

    {{-- The body. Flexes into whatever the stub does not take, so a long event title
         wraps inside this column rather than running under the code. --}}
    <div @class(['min-w-0 flex-1', $compact ? 'px-4 py-3' : 'px-5 py-5 sm:px-7 sm:py-6'])>
        <p @class([
            'font-bold uppercase tracking-widest text-blue-600',
            $compact ? 'text-[9px]' : 'text-[11px]',
        ])>
            {{ $ticket->kindLabel() }} COUPON
        </p>

        <p @class([
            'mt-1 font-bold leading-none text-slate-900 break-words',
            $compact ? 'text-2xl' : $ticket->discountSizeClass(),
        ])>
            {{ $ticket->discountLabel() }}
        </p>

        {{-- Two lines, then an ellipsis. The whole title is still in the markup for a
             screen reader; what is clamped is only what is drawn. --}}
        <p @class([
            'mt-2 text-slate-600 break-words line-clamp-2',
            $compact ? 'text-[11px]' : 'text-sm',
        ])>
            {{ $ticket->subject }}
        </p>

        <p @class(['mt-1 text-slate-400', $compact ? 'text-[10px]' : 'text-xs'])>
            {{ $ticket->expiryNote() }}
        </p>
    </div>

    {{-- The stub. --}}
    <div @class([
        'relative shrink-0 border-t border-dashed border-gray-300 sm:border-t-0 sm:border-l',
        'flex flex-col items-center justify-center',
        $compact ? 'px-4 py-2.5' : 'px-6 py-4 sm:px-8',
    ])>
        {{-- The notches. Filled with the page colour rather than cut out, which is
             the same trick the reference artwork uses: a real cut-out would need a
             mask and would show the wrong colour on any other background. --}}
        <span class="pointer-events-none absolute -left-2.5 -top-2.5 hidden h-5 w-5 rounded-full bg-gray-100 sm:block" aria-hidden="true"></span>
        <span class="pointer-events-none absolute -left-2.5 -bottom-2.5 hidden h-5 w-5 rounded-full bg-gray-100 sm:block" aria-hidden="true"></span>

        <p @class([
            'font-bold uppercase tracking-widest text-slate-400',
            $compact ? 'text-[9px]' : 'text-[10px]',
        ])>
            Code
        </p>

        <p @class([
            'mt-0.5 font-mono font-bold tracking-widest text-slate-900 break-all text-center',
            $compact ? 'text-sm' : 'text-xl sm:text-2xl',
        ])>
            {{ $ticket->code }}
        </p>
    </div>
</figure>
