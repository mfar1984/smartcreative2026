@props(['stream', 'heading' => 'Live Now'])

{{--
    The broadcast, above the table it belongs to.

    Above rather than below, because somebody arriving mid-match wants the picture
    first and the placings second, and a video found by scrolling past a table has
    already lost the moment it was there for.

    The aspect ratio is held by padding rather than by a height, so the frame is
    exactly 16:9 at every width and the page never reflows once Facebook's player
    loads inside it. A fixed height would letterbox on a phone.
--}}
<div {{ $attributes->merge(['class' => 'bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden mb-8']) }}>

    <div class="flex flex-wrap items-center justify-between gap-3 px-5 md:px-7 py-4 bg-gradient-to-r from-slate-900 to-slate-800">
        <div class="flex items-center gap-3 min-w-0">
            <span class="inline-flex items-center gap-2 rounded-full bg-red-500/15 border border-red-500/40 px-3 py-1 shrink-0">
                <span class="relative flex w-2 h-2">
                    <span class="absolute inline-flex w-full h-full rounded-full bg-red-400 opacity-75 animate-ping"></span>
                    <span class="relative inline-flex w-2 h-2 rounded-full bg-red-500"></span>
                </span>
                <span class="text-xs font-bold uppercase tracking-widest text-red-300">{{ $heading }}</span>
            </span>

            @if (filled($stream['title'] ?? null))
                <p class="text-sm font-semibold text-white truncate">{{ $stream['title'] }}</p>
            @endif
        </div>

        {{-- A way out to Facebook itself, for the comments and for anybody whose
             browser blocks the embed. Not the main route, just the escape hatch. --}}
        <a href="{{ $stream['permalink'] }}"
           target="_blank"
           rel="noopener noreferrer"
           class="inline-flex items-center gap-1.5 text-xs font-semibold uppercase tracking-widest text-gray-400 hover:text-white transition shrink-0">
            Watch on Facebook
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
            </svg>
        </a>
    </div>

    <div class="relative bg-black" style="padding-top: 56.25%;">
        <iframe src="{{ $stream['embed'] }}"
                title="{{ filled($stream['title'] ?? null) ? $stream['title'] : 'Live stream' }}"
                class="absolute inset-0 w-full h-full"
                style="border: none; overflow: hidden;"
                scrolling="no"
                frameborder="0"
                loading="lazy"
                allow="autoplay; clipboard-write; encrypted-media; picture-in-picture; web-share"
                allowfullscreen></iframe>
    </div>

    <p class="px-5 md:px-7 py-3 text-xs text-gray-500 border-t border-gray-100">
        Streamed from Facebook. It starts muted, so turn the sound on in the player.
    </p>
</div>
