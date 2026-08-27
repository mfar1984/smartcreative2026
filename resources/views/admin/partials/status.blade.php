{{--
    The three footer counters, on one line.

    Each item is hidden unless the account can reach what it refers to, so a
    Referee is never shown a figure they cannot open. The failed count is plain
    text rather than a link: there is no failed jobs screen yet, and a link that
    goes nowhere useful is worse than no link at all.

    The icon pulses only while its number is above zero. A footer that animates for
    ever on every page turns into wallpaper, and the whole point of the movement is
    to pull the eye to something that wants doing. motion-safe: keeps it still for
    anyone who has asked their system to reduce motion.
--}}
@php
    $user = auth()->user();
    $counts = app(App\Support\AdminStatus::class)->counts();

    $items = [];

    if ($user?->hasPermission('payments.unpaid.view')) {
        $items[] = [
            'icon' => 'warning',
            'count' => $counts['unpaid'],
            'label' => 'unpaid',
            'tone' => $counts['unpaid'] > 0 ? 'text-amber-600' : 'text-gray-400',
            'href' => route('admin.payments.unpaid'),
            'hint' => $counts['unpaid'] . ' registration(s) not paid for yet.',
        ];
    }

    if ($user?->hasPermission('logs.activity.view')) {
        $items[] = [
            'icon' => 'bolt',
            'count' => $counts['failed'],
            'label' => 'failed',
            'tone' => $counts['failed'] > 0 ? 'text-red-600' : 'text-gray-400',
            'href' => null,
            'hint' => $counts['failed'] > 0
                ? $counts['failed'] . ' background job(s) failed and have not been retried.'
                : 'No background jobs have failed.',
        ];
    }

    if ($user?->hasPermission('tournaments.view')) {
        $items[] = [
            'icon' => 'dot',
            'count' => $counts['live'],
            'label' => 'live',
            'tone' => $counts['live'] > 0 ? 'text-green-600' : 'text-gray-400',
            'href' => route('admin.tournaments.matches'),
            'hint' => $counts['live'] > 0
                ? $counts['live'] . ' tournament(s) being played right now.'
                : 'No tournament is being played.',
        ];
    }
@endphp

@if ($items !== [])
    <div class="flex items-center gap-2 ml-auto shrink-0">
        @foreach ($items as $item)
            @if (! $loop->first)
                <span class="text-gray-200 select-none" aria-hidden="true">|</span>
            @endif

            @php
                $pulse = $item['count'] > 0 ? 'motion-safe:animate-pulse' : '';
                $classes = 'inline-flex items-center gap-1 text-xs whitespace-nowrap ' . $item['tone'];
            @endphp

            @if ($item['href'])
                <a href="{{ $item['href'] }}" class="{{ $classes }} hover:underline" title="{{ $item['hint'] }}">
            @else
                <span class="{{ $classes }}" title="{{ $item['hint'] }}">
            @endif

                @if ($item['icon'] === 'dot')
                    {{-- A filled dot rather than a glyph: it is the plainest way to
                         say "happening now", and it pulses cleanly at this size. --}}
                    <span class="w-2 h-2 rounded-full bg-current shrink-0 {{ $pulse }}" aria-hidden="true"></span>
                @else
                    <x-admin.icon :name="$item['icon']" class="w-3.5 h-3.5 shrink-0 {{ $pulse }}" />
                @endif

                <span class="font-bold tabular-nums">{{ $item['count'] }}</span>
                <span class="text-gray-500">{{ $item['label'] }}</span>

            @if ($item['href'])
                </a>
            @else
                </span>
            @endif
        @endforeach
    </div>
@endif
