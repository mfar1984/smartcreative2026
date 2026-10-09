{{--
    Stamp: warm paper stock with a red rubber-stamp box around the code.

    The stamp is rotated about -7 degrees, which is the one thing here that can clip:
    a rotated box still occupies its unrotated space for layout but paints outside it.
    The wrapper therefore keeps padding around it rather than letting it sit flush
    against the edge, and the rotation is dropped on a phone where the panel is too
    narrow to spare the room.

    @param \App\Support\CouponTicket $ticket
    @param bool $compact
--}}

@props(['ticket', 'compact' => false])

<figure {{ $attributes->merge(['class' => 'overflow-hidden rounded-lg border border-[#e5ddc8] bg-[#fdfbf5] shadow-sm']) }}>
    <figcaption class="sr-only">{{ $ticket->summary() }}</figcaption>

    <div @class([
        'flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between',
        $compact ? 'px-4 py-3 gap-3' : 'px-5 py-5 sm:px-7 sm:py-7',
    ])>
        <div class="min-w-0">
            <p @class([
                'font-bold uppercase tracking-widest text-amber-700',
                $compact ? 'text-[9px]' : 'text-[11px]',
            ])>
                {{ $ticket->kindLabel() }} COUPON
            </p>

            <p @class([
                'mt-1.5 font-bold leading-none text-stone-900 break-words',
                $compact ? 'text-2xl' : $ticket->discountSizeClass(),
            ])>
                {{ $ticket->discountLabel() }}
            </p>

            <p @class(['mt-2 text-stone-600 break-words', $compact ? 'text-[11px]' : 'text-sm'])>
                {{ $ticket->subject }}
            </p>

            <p @class(['mt-1 text-stone-400', $compact ? 'text-[10px]' : 'text-xs'])>
                {{ $ticket->expiryNote() }}
            </p>
        </div>

        {{-- The stamp. Double rule, the inner one thinner, the way a real one inks. --}}
        <div @class([
            'shrink-0 self-start rounded-md border-[3px] border-red-700 p-1 sm:self-center sm:rotate-[-7deg]',
            $compact ? 'scale-90' : '',
        ])>
            <div @class([
                'rounded border border-red-700 text-center',
                $compact ? 'px-3 py-1.5' : 'px-5 py-3 sm:px-7',
            ])>
                <p @class([
                    'font-bold uppercase tracking-[0.2em] text-red-700',
                    $compact ? 'text-[8px]' : 'text-[9px]',
                ])>
                    Coupon Code
                </p>

                <p @class([
                    'mt-0.5 font-mono font-bold tracking-widest text-red-700 break-all',
                    $compact ? 'text-sm' : 'text-xl sm:text-2xl',
                ])>
                    {{ $ticket->code }}
                </p>
            </div>
        </div>
    </div>
</figure>
