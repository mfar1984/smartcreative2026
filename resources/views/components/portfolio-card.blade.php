{{--
    One project on the portfolio grid.

    Its own file because Load More fetches the next page and lifts these elements out
    of the response, so the markup has to be identical whether it arrived with the
    first page or was appended later.

    A project with photographs renders as a button that opens the lightbox. One
    without stays a plain block: a popup with nothing in it is worse than a card that
    does not react, so the affordance only appears when there is something behind it.

    The gallery travels in a data attribute rather than a hidden block of markup.
    That keeps one lightbox in the page for every card, and means an appended card
    works without also copying its own popup across.

    @param \App\Models\PortfolioProject $project
--}}
@php
    $cover = $project->imageUrl();
    $gallery = $project->galleryImages();
    $highlights = $project->highlightLines();

    $payload = $gallery
        ->map(fn ($image) => ['src' => $image->url(), 'caption' => (string) $image->caption])
        ->all();
@endphp

<article data-portfolio-card
         @if ($gallery->isNotEmpty())
             data-gallery-title="{{ $project->title }}"
             data-gallery="{{ json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}"
         @endif
         class="group flex flex-col rounded-lg border border-gray-200 shadow-sm overflow-hidden hover:shadow-xl hover:border-blue-200 transition">

    {{-- ---------------- Picture ---------------- --}}
    @if ($gallery->isNotEmpty())
        <button type="button" data-portfolio-open
                class="relative block w-full aspect-[4/3] overflow-hidden bg-gray-900 text-left cursor-zoom-in"
                aria-label="Open {{ $gallery->count() }} {{ Str::plural('photograph', $gallery->count()) }} from {{ $project->title }}">
    @else
        <div class="relative aspect-[4/3] overflow-hidden bg-gray-900">
    @endif

        @if ($cover)
            <img src="{{ $cover }}"
                 alt="{{ $project->title }}"
                 loading="lazy"
                 class="w-full h-full object-cover group-hover:scale-105 transition duration-500">
        @else
            <div class="w-full h-full bg-gradient-to-br from-gray-900 via-gray-800 to-blue-900 flex items-center justify-center">
                <span class="text-5xl font-bold text-white/25" aria-hidden="true">
                    {{ Str::upper(Str::substr($project->title, 0, 2)) }}
                </span>
            </div>
        @endif

        <span class="absolute top-3 left-3 bg-white/95 backdrop-blur-sm text-gray-900 text-xs font-bold uppercase tracking-wide px-2.5 py-1.5 rounded">
            {{ $project->category }}
        </span>

        @if ($project->is_featured)
            <span class="absolute top-3 right-3 bg-amber-400 text-amber-900 text-xs font-bold uppercase tracking-wide px-2.5 py-1.5 rounded">
                Featured
            </span>
        @endif

        @if ($gallery->isNotEmpty())
            {{-- Says how many there are and that pressing does something. Without it
                 nothing on the card suggests it opens. --}}
            <span class="absolute bottom-3 right-3 inline-flex items-center gap-1.5 rounded bg-gray-900/75 px-2.5 py-1.5 text-xs font-semibold text-white backdrop-blur-sm group-hover:bg-blue-600 transition">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
                {{ $gallery->count() }} {{ Str::plural('photo', $gallery->count()) }}
            </span>
        @endif

    @if ($gallery->isNotEmpty())
        </button>
    @else
        </div>
    @endif

    {{-- ---------------- Words ---------------- --}}
    <div class="flex flex-col flex-1 p-5">
        <h2 class="text-lg font-bold text-gray-900 leading-snug mb-2">
            {{ $project->title }}
        </h2>

        <p class="text-xs text-gray-500 mb-3">
            {{ $project->clientLabel() }}
            <span class="mx-1 text-gray-300" aria-hidden="true">&bull;</span>
            {{ $project->deliveredLabel() }}
            @if (filled($project->location))
                <span class="mx-1 text-gray-300" aria-hidden="true">&bull;</span>
                {{ $project->location }}
            @endif
        </p>

        <p class="text-sm text-gray-600 leading-relaxed mb-4">
            {{ $project->summary }}
        </p>

        @if ($highlights !== [])
            <ul class="space-y-1.5 mb-4">
                @foreach ($highlights as $line)
                    <li class="flex gap-2 text-sm text-gray-700">
                        <svg class="w-4 h-4 shrink-0 mt-0.5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                        <span>{{ $line }}</span>
                    </li>
                @endforeach
            </ul>
        @endif

        {{-- Pushed to the bottom so every card in a row lines up regardless of how
             much summary it carries. --}}
        <div class="mt-auto pt-3 border-t border-gray-100 flex flex-wrap items-center justify-between gap-2">
            <span class="text-xs font-semibold uppercase tracking-wide text-blue-600">
                {{ $project->serviceLabel() }}
            </span>

            @if ($gallery->isNotEmpty())
                <button type="button" data-portfolio-open
                        class="text-xs font-semibold text-gray-500 hover:text-blue-700 transition">
                    View photographs
                </button>
            @endif
        </div>
    </div>

</article>
