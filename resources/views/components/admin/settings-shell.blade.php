{{--
    Outer shell for a settings screen: one card holding the page title, the
    description and the tab bar, with the active tab's content inside it.

    Tabs are links carrying ?tab=, so each one has its own URL.

    @param string $title
    @param string|null $description
    @param array  $tabs      slug => ['label' => string, 'icon' => string]
    @param string $activeTab
    @param string $route     route name the tabs link to
--}}
@props([
    'title',
    'description' => null,
    'tabs' => [],
    'activeTab' => null,
    'route' => null,

    /*
     | Extra route parameters the tab links need, for a screen whose route is not
     | reachable from a tab slug alone. A tournament detail page is the case that
     | needed it: its route carries the tournament, and without this the tab bar
     | cannot build a single link.
     */
    'routeParams' => [],
])

<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">

    {{-- Heading --}}
    <div class="px-6 pt-6">
        <h1 class="text-xl font-bold text-gray-900">{{ $title }}</h1>
        @if ($description)
            <p class="text-sm text-gray-500 mt-1">{{ $description }}</p>
        @endif
    </div>

    {{-- Tab bar --}}
    @if (count($tabs) > 0)
        <div class="px-6 mt-5 border-b border-gray-200">
            <nav class="-mb-px flex flex-wrap gap-x-1" aria-label="Sections">
                @foreach ($tabs as $slug => $tab)
                    @php
                        $tabLabel = is_array($tab) ? ($tab['label'] ?? $slug) : $tab;
                        $tabIcon = is_array($tab) ? ($tab['icon'] ?? null) : null;
                        $tabCount = is_array($tab) ? ($tab['count'] ?? null) : null;
                        $isActive = $activeTab === $slug;
                    @endphp

                    <a href="{{ route($route, array_merge($routeParams, ['tab' => $slug])) }}"
                       @class([
                           'inline-flex items-center gap-2 px-4 py-3 text-sm font-semibold border-b-2 transition whitespace-nowrap',
                           'border-blue-600 text-blue-700' => $isActive,
                           'border-transparent text-gray-500 hover:text-gray-800 hover:border-gray-300' => ! $isActive,
                       ])
                       @if ($isActive) aria-current="page" @endif>
                        @if ($tabIcon)
                            <x-admin.icon :name="$tabIcon" class="w-4 h-4 shrink-0" />
                        @endif
                        {{ $tabLabel }}

                        @if ($tabCount !== null)
                            <span @class([
                                'rounded-full px-2 py-0.5 text-xs font-bold',
                                'bg-blue-100 text-blue-700' => $isActive,
                                'bg-gray-100 text-gray-500' => ! $isActive,
                            ])>{{ $tabCount }}</span>
                        @endif
                    </a>
                @endforeach
            </nav>
        </div>
    @endif

    {{-- Tab content --}}
    <div class="p-6 bg-gray-50">
        {{ $slot }}
    </div>
</div>
