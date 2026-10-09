{{--
    Minimal: white, a thin blue rule across the top, light type, generous space.

    The code is right-aligned with wide letter spacing on a wide screen and falls back
    to the left under the discount on a phone, where right-aligning a tracked-out
    monospace string is what starts clipping.

    @param \App\Support\CouponTicket $ticket
    @param bool $compact
--}}

@props(['ticket', 'compact' => false])

<figure {{ $attributes->merge(['class' => 'overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm']) }}>
    <figcaption class="sr-only">{{ $ticket->summary() }}</figcaption>

    <span class="block h-1 w-full bg-blue-600" aria-hidden="true"></span>

    <div @class([$compact ? 'px-4 py-3' : 'px-6 py-6 sm:px-9 sm:py-8'])>
        {{-- Two real columns on a wide screen: the left one flexes into whatever the
             code column does not take, so a long event title wraps inside its own
             column instead of running under the code. --}}
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0 flex-1">
                <p @class([
                    'font-bold uppercase tracking-[0.2em] text-slate-400',
                    $compact ? 'text-[9px]' : 'text-[10px]',
                ])>
                    Coupon
                </p>

                <p @class([
                    'mt-3 font-light leading-none text-slate-900 break-words',
                    $compact ? 'text-xl' : $ticket->discountSizeClass(),
                ])>
                    {{ $ticket->discountLabel() }}
                </p>

                <span @class(['mt-4 block h-px w-full max-w-xs bg-gray-200', $compact ? 'mt-2' : ''])
                      aria-hidden="true"></span>

                {{-- Two lines, then an ellipsis. The whole title is still in the
                     markup for a screen reader; only what is drawn is clamped. --}}
                <p @class([
                    'mt-3 text-slate-600 break-words line-clamp-2',
                    $compact ? 'text-[11px]' : 'text-sm',
                ])>
                    {{ $ticket->subject }}
                </p>
            </div>

            <div class="min-w-0 shrink-0 sm:text-right">
                <p @class([
                    'font-bold uppercase tracking-[0.2em] text-slate-400',
                    $compact ? 'text-[9px]' : 'text-[10px]',
                ])>
                    Code
                </p>

                <p @class([
                    'mt-1.5 font-mono font-normal text-slate-900 break-all',
                    $compact ? 'text-sm tracking-widest' : 'text-xl tracking-[0.2em] sm:text-2xl',
                ])>
                    {{ $ticket->code }}
                </p>

                <p @class(['mt-3 text-slate-400', $compact ? 'text-[10px]' : 'text-xs'])>
                    {{ $ticket->expiryNote() }}
                </p>
            </div>
        </div>
    </div>
</figure>
