{{--
    Search and filter strip that sits above a table.

    Wraps its slot in a GET form so every filter lands in the query string,
    which keeps the current view shareable and reloadable.

    @param string $action  route URL the form submits to
    @param string|null $reset  URL for the reset link, omitted when nothing is filtered
--}}
@props([
    'action',
    'reset' => null,
])

<form action="{{ $action }}" method="GET" class="flex flex-wrap items-center gap-2 px-6 py-3.5 border-b border-gray-200 bg-white">
    {{ $slot }}

    {{--
        border-transparent is not decoration. The inputs in the slot carry
        border-gray-300, and a border counts toward height, so a button with the
        same py-2 and no border sat two pixels shorter than everything beside it on
        every filter bar in the admin. A transparent border restores the two pixels
        without drawing anything.
    --}}
    <button type="submit" class="rounded-lg border border-transparent bg-gray-100 px-3.5 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-200 transition">
        Apply
    </button>

    {{-- Anything that belongs beside Apply rather than among the filters: an
         export, a secondary action. Optional, so the twenty existing filter bars
         are unaffected. --}}
    @isset($actions)
        {{ $actions }}
    @endisset

    @if ($reset)
        <a href="{{ $reset }}" class="inline-flex items-center gap-1.5 rounded-lg border border-transparent px-3 py-2 text-sm font-semibold text-gray-500 hover:text-gray-800 transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            Reset
        </a>
    @endif
</form>
