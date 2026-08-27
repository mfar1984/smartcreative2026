{{--
    Pagination for every admin table.

    Replaces Laravel's built in Tailwind view, which carries `dark:` variants. Under
    Tailwind v4 those follow the operating system's colour preference, so on a
    machine set to dark mode the pager turned into a dark segmented block while the
    rest of the admin stayed light. Nothing else here has a dark theme, so the pager
    was the only thing responding to it.

    The window of page numbers is worked out here rather than using the $elements
    Laravel passes in. That is deliberate: it keeps the shape identical on all 21
    screens without editing a single ->links() call, and Laravel's default window of
    three either side puts eight numbers on screen where three or four read better.

    Shape: « ‹ 1 … 7 [8] 9 … 24 › »
--}}
@if ($paginator->hasPages())
    @php
        $current = $paginator->currentPage();
        $last = $paginator->lastPage();

        /*
         | One page either side of the current one, plus the first and last, with a
         | gap marker where numbers were skipped. Sorted and deduplicated so the
         | windows overlapping near either end cannot produce a repeat.
         */
        $window = collect([1, $current - 1, $current, $current + 1, $last])
            ->filter(fn (int $page) => $page >= 1 && $page <= $last)
            ->unique()
            ->sort()
            ->values();

        $pages = [];
        $previous = 0;

        foreach ($window as $page) {
            if ($previous !== 0 && $page - $previous > 1) {
                $pages[] = 'gap';
            }

            $pages[] = $page;
            $previous = $page;
        }

        $arrow = 'inline-flex items-center justify-center w-8 h-8 rounded-lg transition';
        $arrowOn = 'text-gray-400 hover:text-gray-700 hover:bg-gray-100';
        $arrowOff = 'text-gray-200 cursor-not-allowed';
    @endphp

    <nav role="navigation" aria-label="Pagination" class="flex items-center justify-end gap-1">

        {{-- First. Hidden on the first page rather than shown disabled, because two
             greyed arrows side by side is just noise. --}}
        @if ($paginator->onFirstPage())
            <span class="{{ $arrow }} {{ $arrowOff }}" aria-hidden="true">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"/>
                </svg>
            </span>
        @else
            <a href="{{ $paginator->url(1) }}" class="{{ $arrow }} {{ $arrowOn }}" aria-label="First page">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"/>
                </svg>
            </a>
        @endif

        {{-- Previous --}}
        @if ($paginator->onFirstPage())
            <span class="{{ $arrow }} {{ $arrowOff }}" aria-hidden="true">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                </svg>
            </span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev"
               class="{{ $arrow }} {{ $arrowOn }}" aria-label="Previous page">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                </svg>
            </a>
        @endif

        {{-- Page numbers --}}
        @foreach ($pages as $page)
            @if ($page === 'gap')
                <span class="inline-flex items-center justify-center w-8 h-8 text-sm text-gray-300" aria-hidden="true">&hellip;</span>
            @elseif ($page === $current)
                <span aria-current="page"
                      class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-blue-600 text-sm font-semibold text-white tabular-nums">
                    {{ $page }}
                </span>
            @else
                <a href="{{ $paginator->url($page) }}"
                   class="inline-flex items-center justify-center w-8 h-8 rounded-full text-sm text-gray-500 tabular-nums hover:bg-gray-100 hover:text-gray-900 transition"
                   aria-label="Page {{ $page }}">
                    {{ $page }}
                </a>
            @endif
        @endforeach

        {{-- Next --}}
        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next"
               class="{{ $arrow }} {{ $arrowOn }}" aria-label="Next page">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
            </a>
        @else
            <span class="{{ $arrow }} {{ $arrowOff }}" aria-hidden="true">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
            </span>
        @endif

        {{-- Last --}}
        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->url($last) }}" class="{{ $arrow }} {{ $arrowOn }}" aria-label="Last page">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"/>
                </svg>
            </a>
        @else
            <span class="{{ $arrow }} {{ $arrowOff }}" aria-hidden="true">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"/>
                </svg>
            </span>
        @endif

        {{-- Read out to a screen reader only, since the numbers above already say it
             to everybody else. --}}
        <span class="sr-only">
            Page {{ $current }} of {{ $last }}, {{ $paginator->total() }} results in total
        </span>
    </nav>
@endif
