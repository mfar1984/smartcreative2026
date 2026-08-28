{{--
    Portfolio.

    A card grid, filtered by service, twelve at a time with a Load More control
    underneath.

    Load More is a real link to the next page. With JavaScript it fetches that page,
    lifts the cards out and appends them; without it, it behaves as the pagination
    link it already is. Either way nobody is stranded on page one.

    Pressing a card that has photographs opens the lightbox included once at the
    bottom. Cards without photographs do not react, because a popup with nothing in
    it is worse than no popup.
--}}
@extends('layouts.master')

@section('title', $pageTitle)

@section('content')
    @include('components.page-header', [
        'title' => $pageTitle,
        'subtitle' => $pageSubtitle,
    ])

    <section class="py-14 bg-white">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">

            @if ($totalPublished > 0)
                {{-- ---------------- Filter ---------------- --}}
                <div class="flex flex-wrap items-center gap-2 mb-10 pb-8 border-b border-gray-200">
                    <span class="text-sm font-semibold text-gray-500 mr-1">Show</span>

                    <a href="{{ route('portfolio') }}"
                       @class([
                           'px-4 py-2 rounded-full text-sm font-semibold transition',
                           'bg-blue-600 text-white shadow-sm' => $activeService === '',
                           'bg-gray-100 text-gray-700 hover:bg-gray-200' => $activeService !== '',
                       ])
                       @if ($activeService === '') aria-current="page" @endif>
                        Everything
                        <span class="opacity-70">({{ $totalPublished }})</span>
                    </a>

                    {{-- Only services that actually have published work are offered. A
                         filter leading to an empty grid reads as a broken page. --}}
                    @foreach ($services as $slug => $label)
                        @continue (($serviceCounts[$slug] ?? 0) === 0)

                        <a href="{{ route('portfolio', ['service' => $slug]) }}"
                           @class([
                               'px-4 py-2 rounded-full text-sm font-semibold transition',
                               'bg-blue-600 text-white shadow-sm' => $activeService === $slug,
                               'bg-gray-100 text-gray-700 hover:bg-gray-200' => $activeService !== $slug,
                           ])
                           @if ($activeService === $slug) aria-current="page" @endif>
                            {{ $label }}
                            <span class="opacity-70">({{ $serviceCounts[$slug] }})</span>
                        </a>
                    @endforeach
                </div>
            @endif

            {{-- ---------------- Grid ---------------- --}}
            @if ($projects->isNotEmpty())
                <div id="portfolio-grid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-7">
                    @foreach ($projects as $project)
                        @include('components.portfolio-card', ['project' => $project])
                    @endforeach
                </div>

                {{-- ---------------- Load More ---------------- --}}
                {{-- Rendered whenever the set spans more than one page, not only when
                     there is a next page. Without the second condition somebody who
                     followed the link to the last page with JavaScript off would land
                     on a bare grid with no count and no way back. --}}
                @if ($projects->hasMorePages() || $projects->currentPage() > 1)
                    <div id="portfolio-more" class="mt-12 text-center" data-total="{{ $projects->total() }}">
                        @if ($projects->hasMorePages())
                            <a href="{{ $projects->nextPageUrl() }}"
                               id="portfolio-load-more"
                               class="inline-flex items-center gap-2 rounded-lg border-2 border-gray-300 px-8 py-3.5 text-sm font-semibold text-gray-700 hover:border-blue-400 hover:text-blue-700 transition">
                                <span data-load-more-label>Load More</span>

                                {{-- Only shown while a fetch is in flight, so a slow
                                     connection does not look like a dead button. --}}
                                <svg data-load-more-spinner class="hidden w-4 h-4 motion-safe:animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                </svg>
                            </a>
                        @endif

                        {{-- A range rather than a count. On page two "3 of 15" would
                             be read as three found, when it means the last three. --}}
                        <p @class(['text-xs text-gray-500', 'mt-3' => $projects->hasMorePages()])>
                            Showing {{ $projects->firstItem() }} to {{ $projects->lastItem() }}
                            of {{ $projects->total() }}
                        </p>

                        @if ($projects->currentPage() > 1)
                            <a href="{{ $projects->url(1) }}"
                               class="inline-block text-xs font-semibold text-blue-600 hover:underline mt-2">
                                Back to the start
                            </a>
                        @endif
                    </div>
                @endif

            @else
                {{-- Two versions, because a filter that matched nothing and a portfolio
                     with nothing in it are different problems. --}}
                <div class="max-w-xl mx-auto text-center py-16">
                    <svg class="w-16 h-16 mx-auto text-gray-300 mb-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>

                    @if ($activeService !== '')
                        <h2 class="text-xl font-bold text-gray-900 mb-3">
                            Nothing here under {{ $services[$activeService] }} yet
                        </h2>
                        <p class="text-base text-gray-600 mb-7">
                            There is other work to look at though.
                        </p>
                        <a href="{{ route('portfolio') }}"
                           class="inline-flex items-center gap-2 bg-blue-600 text-white px-6 py-3 rounded-lg font-semibold hover:bg-blue-700 transition">
                            Show everything
                        </a>
                    @else
                        <h2 class="text-xl font-bold text-gray-900 mb-3">
                            Our portfolio is being put together
                        </h2>
                        <p class="text-base text-gray-600 mb-7">
                            We would rather show you the work properly than post placeholders. In the
                            meantime, tell us what you are planning and we will talk you through
                            comparable events we have run.
                        </p>
                        <a href="{{ route('contact') }}"
                           class="inline-flex items-center gap-2 bg-blue-600 text-white px-6 py-3 rounded-lg font-semibold hover:bg-blue-700 transition">
                            Get in touch
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                            </svg>
                        </a>
                    @endif
                </div>
            @endif

        </div>
    </section>

    {{-- ---------------- Next step ---------------- --}}
    @if ($totalPublished > 0)
        <section class="py-16 bg-gradient-to-r from-gray-900 to-blue-900 text-white">
            <div class="container mx-auto px-4 sm:px-6 lg:px-8">
                <div class="max-w-3xl">
                    <h2 class="text-3xl md:text-4xl font-bold mb-4">Yours could be next on this page</h2>

                    <p class="text-base md:text-lg text-gray-300 mb-8">
                        Tell us the date and what the event is for. We will come back with whether it
                        is feasible, what it would take, and a rough cost.
                    </p>

                    <div class="flex flex-wrap gap-4">
                        <a href="{{ route('contact') }}"
                           class="inline-flex items-center gap-2 bg-blue-600 text-white px-7 py-3.5 rounded-lg font-semibold hover:bg-blue-700 transition shadow-md">
                            Start a conversation
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                            </svg>
                        </a>

                        <a href="{{ route('services') }}"
                           class="inline-flex items-center gap-2 border-2 border-white/60 text-white px-7 py-3.5 rounded-lg font-semibold hover:bg-white/10 transition">
                            See what we do
                        </a>
                    </div>
                </div>
            </div>
        </section>
    @endif

    @include('components.portfolio-lightbox')
@endsection

@push('scripts')
<script>
    /* ---------------------------------------------------------------------
     | Lightbox
     |
     | One popup for the page, filled from the data attribute on whichever card
     | was pressed. Listens on the grid rather than on each card, so cards
     | appended by Load More work without being wired up again.
     * ------------------------------------------------------------------ */
    (function () {
        const box = document.getElementById('portfolio-lightbox');
        const grid = document.getElementById('portfolio-grid');

        if (!box || !grid) {
            return;
        }

        const picture = document.getElementById('portfolio-lightbox-image');
        const title = document.getElementById('portfolio-lightbox-title');
        const counter = document.getElementById('portfolio-lightbox-counter');
        const caption = document.getElementById('portfolio-lightbox-caption');
        const thumbs = document.getElementById('portfolio-lightbox-thumbs');
        const prev = box.querySelector('[data-lightbox-prev]');
        const next = box.querySelector('[data-lightbox-next]');
        const closer = document.getElementById('portfolio-lightbox-close');

        let images = [];
        let index = 0;
        let opener = null;

        function show(i) {
            if (images.length === 0) {
                return;
            }

            // Wrap round, so the arrows never dead end on the first or last picture.
            index = (i + images.length) % images.length;

            const current = images[index];

            picture.src = current.src;
            picture.alt = current.caption || title.textContent;
            caption.textContent = current.caption || '';
            counter.textContent = (index + 1) + ' of ' + images.length;

            thumbs.querySelectorAll('[data-thumb]').forEach(function (thumb, i) {
                const on = i === index;
                thumb.classList.toggle('border-blue-500', on);
                thumb.classList.toggle('border-transparent', !on);
                thumb.classList.toggle('opacity-100', on);
                thumb.classList.toggle('opacity-50', !on);
                thumb.setAttribute('aria-current', on ? 'true' : 'false');
            });

            // A single picture needs no arrows.
            const many = images.length > 1;
            prev.classList.toggle('hidden', !many);
            next.classList.toggle('hidden', !many);
            thumbs.classList.toggle('hidden', !many);
        }

        function buildThumbs() {
            thumbs.innerHTML = '';

            if (images.length < 2) {
                return;
            }

            images.forEach(function (image, i) {
                const button = document.createElement('button');
                button.type = 'button';
                button.dataset.thumb = String(i);
                button.className = 'shrink-0 w-16 h-16 rounded overflow-hidden border-2 border-transparent opacity-50 hover:opacity-100 transition';
                button.setAttribute('aria-label', 'Show photograph ' + (i + 1));

                const img = document.createElement('img');
                img.src = image.src;
                img.alt = '';
                img.className = 'w-full h-full object-cover';

                button.appendChild(img);
                button.addEventListener('click', function () {
                    show(i);
                });

                thumbs.appendChild(button);
            });
        }

        function open(card, trigger) {
            let parsed;

            try {
                parsed = JSON.parse(card.dataset.gallery || '[]');
            } catch (error) {
                // A malformed payload should do nothing rather than open an empty
                // popup on top of the page.
                return;
            }

            images = parsed.filter(function (image) {
                return image && image.src;
            });

            if (images.length === 0) {
                return;
            }

            opener = trigger;
            title.textContent = card.dataset.galleryTitle || '';

            buildThumbs();
            show(0);

            box.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
            closer?.focus();
        }

        function close() {
            box.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');

            // Drop the source so a large picture is not held in memory, and so the
            // next open cannot flash the previous one.
            picture.src = '';
            images = [];

            // Back to the card that was pressed, otherwise focus falls to the top of
            // the document.
            opener?.focus();
            opener = null;
        }

        grid.addEventListener('click', function (event) {
            const trigger = event.target.closest('[data-portfolio-open]');

            if (!trigger) {
                return;
            }

            const card = trigger.closest('[data-portfolio-card]');

            if (card && card.dataset.gallery) {
                open(card, trigger);
            }
        });

        box.querySelectorAll('[data-lightbox-close]').forEach(function (trigger) {
            trigger.addEventListener('click', close);
        });

        prev.addEventListener('click', function () {
            show(index - 1);
        });

        next.addEventListener('click', function () {
            show(index + 1);
        });

        document.addEventListener('keydown', function (event) {
            if (box.classList.contains('hidden')) {
                return;
            }

            if (event.key === 'Escape') {
                close();
            } else if (event.key === 'ArrowLeft') {
                show(index - 1);
            } else if (event.key === 'ArrowRight') {
                show(index + 1);
            }
        });
    })();

    /* ---------------------------------------------------------------------
     | Load More
     |
     | The control is already a link to the next page, so this only upgrades it:
     | fetch that page, lift the cards out, append them. On any failure the
     | default navigation is allowed through instead.
     * ------------------------------------------------------------------ */
    (function () {
        const button = document.getElementById('portfolio-load-more');
        const grid = document.getElementById('portfolio-grid');

        if (!button || !grid) {
            return;
        }

        const wrap = document.getElementById('portfolio-more');
        const label = button.querySelector('[data-load-more-label]');
        const spinner = button.querySelector('[data-load-more-spinner]');

        let busy = false;

        button.addEventListener('click', async function (event) {
            // Let a modified click open the next page in a tab, as a link should.
            if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) {
                return;
            }

            event.preventDefault();

            if (busy) {
                return;
            }

            busy = true;
            spinner.classList.remove('hidden');
            label.textContent = 'Loading';

            try {
                const response = await fetch(button.href, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });

                if (!response.ok) {
                    throw new Error('Request failed');
                }

                const parsed = new DOMParser().parseFromString(await response.text(), 'text/html');
                const cards = parsed.querySelectorAll('#portfolio-grid > [data-portfolio-card]');

                if (cards.length === 0) {
                    throw new Error('Nothing to append');
                }

                cards.forEach(function (card) {
                    grid.appendChild(card);
                });

                // Move the button on to the page after this one, or retire it.
                const nextButton = parsed.getElementById('portfolio-load-more');

                if (nextButton) {
                    button.href = nextButton.getAttribute('href');

                    /*
                     | Counted from the grid, not read from the response. Page two
                     | describes its own slice, so copying its wording across would
                     | report the last twelve rather than everything now on screen.
                     */
                    const shown = grid.querySelectorAll('[data-portfolio-card]').length;
                    const total = wrap.dataset.total;
                    const line = wrap.querySelector('p');

                    if (line && total) {
                        line.textContent = 'Showing 1 to ' + shown + ' of ' + total;
                    }
                } else {
                    wrap.remove();
                    return;
                }
            } catch (error) {
                // Fall back to being a link. The next page still loads, just not in
                // place.
                window.location.href = button.href;

                return;
            } finally {
                busy = false;
                spinner.classList.add('hidden');
                label.textContent = 'Load More';
            }
        });
    })();
</script>
@endpush
