{{--
    One lightbox for the whole page.

    Filled from the data attribute on whichever card was pressed, so there is a
    single popup rather than one per project. That is what lets Load More append
    cards without also appending their popups.

    Starts hidden and empty. Everything inside is written by the script in
    pages/portfolio.blade.php.
--}}
<div id="portfolio-lightbox"
     class="hidden fixed inset-0 z-50"
     role="dialog"
     aria-modal="true"
     aria-labelledby="portfolio-lightbox-title">

    <div class="absolute inset-0 bg-gray-950/90" data-lightbox-close></div>

    <div class="relative h-full flex flex-col">

        {{-- ---------------- Bar ---------------- --}}
        <div class="shrink-0 flex items-center justify-between gap-4 px-4 sm:px-6 py-4">
            <div class="min-w-0">
                <h2 id="portfolio-lightbox-title" class="text-base sm:text-lg font-bold text-white truncate"></h2>
                <p id="portfolio-lightbox-counter" class="text-xs text-gray-400 tabular-nums mt-0.5"></p>
            </div>

            <button type="button" data-lightbox-close id="portfolio-lightbox-close"
                    class="shrink-0 p-2 rounded-lg text-gray-300 hover:bg-white/10 hover:text-white transition"
                    aria-label="Close">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        {{-- ---------------- Stage ---------------- --}}
        <div class="relative flex-1 min-h-0 flex items-center justify-center px-4 sm:px-16">
            {{-- Arrows sit outside the picture on a wide screen and over it on a
                 narrow one, so they never cover the middle of the image. --}}
            <button type="button" data-lightbox-prev
                    class="absolute left-1 sm:left-4 z-10 p-2.5 rounded-full bg-white/10 text-white hover:bg-white/25 transition"
                    aria-label="Previous photograph">
                <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                </svg>
            </button>

            <img id="portfolio-lightbox-image"
                 src=""
                 alt=""
                 class="max-h-full max-w-full object-contain rounded-lg shadow-2xl">

            <button type="button" data-lightbox-next
                    class="absolute right-1 sm:right-4 z-10 p-2.5 rounded-full bg-white/10 text-white hover:bg-white/25 transition"
                    aria-label="Next photograph">
                <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
            </button>
        </div>

        {{-- ---------------- Caption and thumbnails ---------------- --}}
        <div class="shrink-0 px-4 sm:px-6 pt-3 pb-4 space-y-3">
            <p id="portfolio-lightbox-caption" class="text-sm text-gray-300 text-center min-h-5"></p>

            <div id="portfolio-lightbox-thumbs"
                 class="flex gap-2 overflow-x-auto justify-start sm:justify-center pb-1"></div>
        </div>

    </div>
</div>
