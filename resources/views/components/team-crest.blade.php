@props([
    'registration' => null,
    'name' => '',
    'size' => 'sm',
    'tone' => 'light',
])

{{--
    A team's mark, beside its name.

    One component rather than markup repeated per table, because the crest now appears
    on the standings, the podium, the team page and a player's history, and four copies
    of the same fallback would drift apart.

    An uploaded logo is drawn as-is on white with object-contain: these are supplied by
    whoever registered, in whatever shape they had to hand, and cropping a squad's badge
    to fill a square is a good way to cut half of it off.

    Where there is no logo the initials stand in, so every entry has a mark of its own
    without anybody having to upload one.
--}}

@php
    $url = $registration?->logoUrl();

    $box = match ($size) {
        'lg' => 'w-16 h-16 rounded-2xl',
        'md' => 'w-11 h-11 rounded-xl',
        // Inline with a line of text, such as the team line on an award card.
        'xs' => 'w-5 h-5 rounded-md',
        default => 'w-8 h-8 rounded-lg',
    };

    $type = match ($size) {
        'lg' => 'text-xl',
        'md' => 'text-sm',
        'xs' => 'text-[8px]',
        default => 'text-xs',
    };

    $fallback = $tone === 'dark'
        ? 'bg-gradient-to-br from-slate-700 to-slate-900 ring-white/10 text-blue-300'
        : 'bg-gradient-to-br from-slate-100 to-slate-200 ring-gray-200 text-slate-500';

    $initials = collect(preg_split('/\s+/', trim((string) $name)))
        ->filter()
        ->take(2)
        ->map(fn (string $word) => mb_strtoupper(mb_substr($word, 0, 1)))
        ->implode('');
@endphp

@if ($url)
    <img src="{{ $url }}"
         alt=""
         loading="lazy"
         {{ $attributes->class([$box, 'object-contain bg-white ring-1 ring-gray-200 shrink-0 p-0.5']) }}>
@else
    <span aria-hidden="true"
          {{ $attributes->class([$box, $type, $fallback, 'inline-flex items-center justify-center font-bold ring-1 shrink-0']) }}>
        {{ $initials !== '' ? $initials : '—' }}
    </span>
@endif
