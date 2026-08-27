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

    <button type="submit" class="rounded-lg bg-gray-100 px-3.5 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-200 transition">
        Apply
    </button>

    @if ($reset)
        <a href="{{ $reset }}" class="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-semibold text-gray-500 hover:text-gray-800 transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            Reset
        </a>
    @endif
</form>
