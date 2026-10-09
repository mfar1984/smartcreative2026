{{--
    Custom: the operator's own artwork, with the four facts printed under it.

    The facts are not drawn over the picture. Nobody knows what is in an uploaded
    image, so white text on it could land on white and a code nobody can read is a
    coupon nobody can use. The artwork is the decoration; the strip below it is the
    coupon.

    With no file uploaded there is nothing custom to draw, so the classic design is
    used instead. That happens when a batch is saved as custom and the picture is
    removed afterwards.

    @param \App\Support\CouponTicket $ticket
    @param bool $compact
--}}

@props(['ticket', 'compact' => false])

@if ($ticket->artworkUrl() === null)
    <x-coupon.designs.classic :ticket="$ticket" :compact="$compact" {{ $attributes }} />
@else
    <figure {{ $attributes->merge(['class' => 'overflow-hidden rounded-lg border border-gray-300 bg-white shadow-sm']) }}>
        <figcaption class="sr-only">{{ $ticket->summary() }}</figcaption>

        <img src="{{ $ticket->artworkUrl() }}"
             alt="Coupon artwork for {{ $ticket->subject }}"
             loading="lazy"
             @class(['w-full object-cover', $compact ? 'h-20' : 'max-h-64'])>

        <div @class([
            'flex flex-col gap-3 border-t border-gray-200 sm:flex-row sm:items-center sm:justify-between',
            $compact ? 'px-4 py-2.5' : 'px-5 py-4',
        ])>
            <div class="min-w-0">
                <p @class([
                    'font-bold leading-none text-slate-900 break-words',
                    $compact ? 'text-base' : 'text-2xl',
                ])>
                    {{ $ticket->discountLabel() }}
                </p>

                <p @class(['mt-1 text-slate-600 break-words', $compact ? 'text-[11px]' : 'text-sm'])>
                    {{ $ticket->subject }}
                </p>

                <p @class(['mt-0.5 text-slate-400', $compact ? 'text-[10px]' : 'text-xs'])>
                    {{ $ticket->expiryNote() }}
                </p>
            </div>

            <p @class([
                'shrink-0 rounded-md border border-gray-200 bg-gray-50 font-mono font-bold tracking-widest text-slate-900 break-all',
                $compact ? 'px-2 py-1 text-xs' : 'px-4 py-2 text-lg',
            ])>
                {{ $ticket->code }}
            </p>
        </div>
    </figure>
@endif
