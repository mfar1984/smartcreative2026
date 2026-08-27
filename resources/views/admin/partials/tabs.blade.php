{{--
    Tab navigation driven by a query string, so every tab has its own URL and
    can be bookmarked, shared and reloaded after a form submission.

    @param array<string, string> $tabs      slug => label
    @param string                $activeTab currently selected slug
    @param string                $route     route name the tabs link to
--}}
<div class="border-b border-gray-200 mb-6">
    <nav class="-mb-px flex flex-wrap gap-x-1 gap-y-1" aria-label="Sections">
        @foreach ($tabs as $slug => $label)
            @php $isActive = $activeTab === $slug; @endphp

            <a href="{{ route($route, ['tab' => $slug]) }}"
               @class([
                   'px-4 py-2.5 text-sm font-semibold border-b-2 transition whitespace-nowrap',
                   'border-blue-600 text-blue-700' => $isActive,
                   'border-transparent text-gray-500 hover:text-gray-800 hover:border-gray-300' => ! $isActive,
               ])
               @if ($isActive) aria-current="page" @endif>
                {{ $label }}
            </a>
        @endforeach
    </nav>
</div>
