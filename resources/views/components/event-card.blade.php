{{--
    Event registration card.

    @param \App\Models\Event $event
--}}
@php
    use App\Models\Event;

    $statusMap = [
        Event::STATUS_OPEN => ['badge' => 'bg-green-100 text-green-800'],
        Event::STATUS_CLOSING_SOON => ['badge' => 'bg-amber-100 text-amber-800'],
        Event::STATUS_FULL => ['badge' => 'bg-red-100 text-red-800'],
        Event::STATUS_CLOSED => ['badge' => 'bg-gray-200 text-gray-700'],
        Event::STATUS_DRAFT => ['badge' => 'bg-gray-200 text-gray-700'],
        Event::STATUS_CANCELLED => ['badge' => 'bg-red-100 text-red-800'],
    ];

    // A finished or running event should not still advertise its registration
    // status, so the lifecycle wins once the event has started.
    $lifecycle = $event->lifecycle();

    [$badge, $badgeLabel] = match ($lifecycle) {
        'completed' => ['bg-gray-200 text-gray-700', 'Completed'],
        'ongoing' => ['bg-green-100 text-green-800', 'Happening Now'],
        default => [
            $statusMap[$event->status]['badge'] ?? 'bg-gray-200 text-gray-700',
            $event->statusLabel(),
        ],
    };

    $startsAt = $event->starts_at;
    $endsAt = $event->ends_at;

    if ($startsAt->isSameDay($endsAt)) {
        $dateRange = $startsAt->format('d M Y');
    } elseif ($startsAt->isSameMonth($endsAt) && $startsAt->isSameYear($endsAt)) {
        $dateRange = $startsAt->format('d') . ' - ' . $endsAt->format('d M Y');
    } else {
        $dateRange = $startsAt->format('d M') . ' - ' . $endsAt->format('d M Y');
    }

    $blocked = $event->registrationBlockedReason();
    $poster = $event->posterUrl();
@endphp

<article id="event-{{ $event->slug }}" class="flex flex-col bg-white rounded-lg border border-gray-200 shadow-sm hover:shadow-lg transition overflow-hidden scroll-mt-32">

    {{-- Poster, or a gradient when none is uploaded --}}
    <div class="relative h-40 @if (! $poster) bg-gradient-to-br from-gray-900 via-gray-800 to-blue-900 @endif">
        @if ($poster)
            <img src="{{ $poster }}" alt="Poster for {{ $event->title }}" class="w-full h-full object-cover">
        @endif

        <div class="absolute inset-0 flex items-start justify-between p-4">
            <span class="inline-block bg-white/90 text-gray-800 text-xs font-semibold px-3 py-1 rounded-full">
                {{ $event->category }}
            </span>
            <span class="inline-block {{ $badge }} text-xs font-semibold px-3 py-1 rounded-full">
                {{ $badgeLabel }}
            </span>
        </div>
    </div>

    <div class="flex flex-col flex-1 p-6">
        <h3 class="text-xl font-bold text-gray-900 mb-3">{{ $event->title }}</h3>

        <ul class="space-y-2 text-sm text-gray-600 mb-4">
            <li class="flex items-start gap-2">
                <svg class="w-4 h-4 mt-0.5 shrink-0 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
                <span><span class="sr-only">Date: </span>{{ $dateRange }}</span>
            </li>

            @if ($event->time)
                <li class="flex items-start gap-2">
                    <svg class="w-4 h-4 mt-0.5 shrink-0 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span><span class="sr-only">Time: </span>{{ $event->time }}</span>
                </li>
            @endif

            <li class="flex items-start gap-2">
                <svg class="w-4 h-4 mt-0.5 shrink-0 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a2 2 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                <span>
                    <span class="sr-only">Location: </span>{{ $event->location }}
                    @if ($event->address)
                        <span class="block text-xs text-gray-500">{{ $event->address }}</span>
                    @endif
                </span>
            </li>

            <li class="flex items-start gap-2">
                <svg class="w-4 h-4 mt-0.5 shrink-0 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                <span>
                    <span class="sr-only">Registration: </span>
                    {{ $event->isManagerMode() ? 'Team entry, registered by a manager' : 'Individual entry' }}
                </span>
            </li>
        </ul>

        @if ($event->description)
            <p class="text-sm text-gray-600 leading-relaxed mb-5">{{ $event->description }}</p>
        @endif

        {{-- Seat availability --}}
        @if ($event->seats_total > 0)
            <div class="mb-5 mt-auto">
                <div class="flex justify-between text-xs text-gray-500 mb-1">
                    <span>{{ $event->seatsLeft() }} of {{ $event->seats_total }} {{ $event->seatsLeft() === 1 ? $event->seatUnit() : $event->seatUnitPlural() }} left</span>
                    <span>{{ $event->filledPercent() }}% filled</span>
                </div>
                <div class="w-full h-1.5 bg-gray-200 rounded-full overflow-hidden" aria-hidden="true">
                    <div class="h-full bg-blue-600 rounded-full" style="width: {{ $event->filledPercent() }}%"></div>
                </div>
            </div>
        @endif

        {{-- Fee and call to action

             Stacked rather than sat side by side. Three cards to a row leaves each
             about 370px, and a fee beside two buttons squeezed the price onto two
             lines: "RM" above "200.00". Stacking gives both the full width and
             keeps three events to a row, which widening the cards would have cost.
        --}}
        <div class="pt-4 border-t border-gray-100 @if ($event->seats_total <= 0) mt-auto @endif">
            <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 mb-3">
                <span class="text-xs text-gray-500">Registration fee</span>
                <span class="text-base font-bold text-gray-900 whitespace-nowrap">{{ $event->feeLabel() }}</span>
                @unless ($event->isFree())
                    <span class="text-xs text-gray-500">{{ $event->feeBasisLabel() }}</span>
                @endunless
            </div>

            {{-- Posters sits beside Register rather than replacing the card picture,
                 which is cropped to a strip and unreadable for anything with words
                 on it. Offered whatever the registration state is: a closed or
                 finished event still has a fixture list worth reading.

                 Equal widths, so the pair reads as one deliberate row rather than
                 two buttons that happen to be next to each other. --}}
            <div class="flex items-stretch gap-2">
            @if ($event->hasPosters())
                <button type="button"
                        data-open-posters="{{ $event->slug }}"
                        class="flex-1 inline-flex items-center justify-center gap-1.5 border border-gray-300 text-gray-700 text-sm px-3 py-2.5 rounded-full font-semibold hover:bg-gray-50 transition whitespace-nowrap">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                    Posters ({{ $event->posterCount() }})
                    <span class="sr-only">for {{ $event->title }}</span>
                </button>
            @endif

            @if ($blocked === null)
                <button type="button"
                        data-open-registration="{{ $event->slug }}"
                        class="flex-1 inline-flex items-center justify-center gap-2 bg-blue-600 text-white text-sm px-3 py-2.5 rounded-full font-semibold hover:bg-blue-700 transition shadow-md whitespace-nowrap">
                    Register
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
                    </svg>
                    <span class="sr-only">for {{ $event->title }}</span>
                </button>
            @else
                <span class="flex-1 inline-flex items-center justify-center bg-gray-200 text-gray-500 text-sm px-3 py-2.5 rounded-full font-semibold cursor-not-allowed whitespace-nowrap"
                      aria-disabled="true"
                      title="{{ $blocked }}">
                    @if ($lifecycle === 'completed')
                        Ended
                    @elseif ($event->status === Event::STATUS_FULL)
                        Fully Booked
                    @else
                        Closed
                    @endif
                </span>
            @endif
            </div>
        </div>

        @if ($blocked !== null)
            <p class="text-xs text-gray-500 mt-2">{{ $blocked }}</p>
        @endif
    </div>
</article>
