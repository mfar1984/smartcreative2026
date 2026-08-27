{{--
    Card for a screen that has no tabs: a list page, or a create/edit form.

    Mirrors the header of settings-shell so tabbed and untabbed screens read
    the same, and takes an optional actions slot for the buttons that sit on
    the right of the title, plus an optional back link.

    @param string      $title
    @param string|null $description
    @param string|null $back      URL for the back arrow
    @param bool        $flush     drop the body padding, for a full width table
--}}
@props([
    'title',
    'description' => null,
    'back' => null,
    'flush' => false,
])

<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">

    <div class="flex flex-wrap items-start justify-between gap-4 px-6 py-5 @if (! $flush) border-b border-gray-200 @endif">
        <div class="flex items-start gap-3 min-w-0">
            @if ($back)
                <a href="{{ $back }}"
                   class="mt-0.5 p-1.5 -ml-1.5 rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-700 transition shrink-0"
                   aria-label="Back">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                    </svg>
                </a>
            @endif

            <div class="min-w-0">
                <h1 class="text-xl font-bold text-gray-900">{{ $title }}</h1>
                @if ($description)
                    <p class="text-sm text-gray-500 mt-1">{{ $description }}</p>
                @endif
            </div>
        </div>

        @isset($actions)
            <div class="flex flex-wrap items-center gap-2 shrink-0">
                {{ $actions }}
            </div>
        @endisset
    </div>

    <div @class(['p-6 bg-gray-50' => ! $flush])>
        {{ $slot }}
    </div>
</div>
