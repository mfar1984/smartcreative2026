@php
    use App\Support\AdminNavigation;

    $navigation = AdminNavigation::for(auth()->user());

    $itemBase = 'flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition';
    $itemIdle = 'text-gray-600 hover:bg-gray-100 hover:text-gray-900';
    $itemActive = 'bg-blue-50 text-blue-700 font-semibold';
@endphp

{{-- Backdrop for the mobile slide over --}}
<div id="sidebar-backdrop" class="hidden lg:hidden fixed inset-0 z-30 bg-gray-900/50" aria-hidden="true"></div>

<aside id="admin-sidebar"
       class="fixed lg:sticky top-0 left-0 z-40 h-screen w-64 shrink-0 bg-white border-r border-gray-200 flex flex-col -translate-x-full lg:translate-x-0 transition-transform duration-200">

    {{-- Brand.
         h-16 and border-b are on this one element, and the top bar in
         layouts/admin.blade.php is built the same way, so both bottom borders land
         on the same pixel. Change one and change the other. --}}
    <div class="h-16 shrink-0 flex items-center justify-center px-4 border-b border-gray-200">
        <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-2 min-w-0">
            {{-- Uploaded under Settings, General Config, Branding. Falls back to the
                 file shipped with the project when nothing has been uploaded, and
                 BrandingSettings holds that fallback so it is not repeated here. --}}
            {{-- h-11 in a 64px block leaves 10px above and below. The block itself
                 cannot grow: it is pinned to h-16 to keep its border level with the
                 top bar, so the logo grows into the padding instead.

                 max-w-full and object-contain matter now that any logo can be
                 uploaded. The shipped file is 1000x357, which is 123px wide at this
                 height and fits easily, but a wide banner would otherwise push
                 straight out of a 224px sidebar. --}}
            <img src="{{ App\Support\BrandingSettings::sidebarLogo() }}"
                 alt="{{ config('app.name') }}" class="h-11 w-auto max-w-full object-contain">
        </a>
    </div>

    {{-- Navigation --}}
    <nav class="flex-1 overflow-y-auto px-3 py-4" aria-label="Admin navigation">
        @foreach ($navigation as $node)

            {{-- ---------- Top level link ---------- --}}
            @if ($node['kind'] === 'item')
                <a href="{{ route($node['route']) }}"
                   @class([$itemBase, request()->routeIs(...(array) $node['active']) ? $itemActive : $itemIdle])
                   @if (request()->routeIs(...(array) $node['active'])) aria-current="page" @endif>
                    <x-admin.icon :name="$node['icon']" class="w-5 h-5 shrink-0" />
                    {{ $node['label'] }}
                </a>
            @endif

            {{-- ---------- Labelled section ---------- --}}
            @if ($node['kind'] === 'section')
                {{-- No rule above the very first block, only between groups. --}}
                @unless ($loop->first)
                    <hr class="my-4 border-gray-200">
                @endunless

                <p @class([
                    'px-3 mb-2 text-xs font-bold uppercase tracking-wider text-gray-400',
                    'mt-1' => $loop->first,
                ])>
                    {{ $node['label'] }}
                </p>

                @foreach ($node['items'] as $child)

                    {{-- Plain link inside a section --}}
                    @if ($child['kind'] === 'item')
                        <a href="{{ route($child['route']) }}"
                           @class([$itemBase, request()->routeIs(...(array) $child['active']) ? $itemActive : $itemIdle])
                           @if (request()->routeIs(...(array) $child['active'])) aria-current="page" @endif>
                            <x-admin.icon :name="$child['icon']" class="w-5 h-5 shrink-0" />
                            {{ $child['label'] }}
                        </a>
                    @endif

                    {{-- Collapsible group. Uses <details> so expanding works
                         without JavaScript and stays keyboard accessible. --}}
                    @if ($child['kind'] === 'group')
                        @php
                            /*
                             | `active` may be one pattern or several, so it is spread
                             | either way. Several are needed where one item covers
                             | routes that have no common prefix, such as Portfolio
                             | Projects covering index, create and edit while its
                             | sibling owns everything under gallery.
                             */
                            $groupActive = collect($child['children'])
                                ->contains(fn (array $link) => request()->routeIs(...(array) $link['active']));
                        @endphp

                        <details class="group" data-nav-group="{{ $child['key'] }}" @if ($groupActive) open @endif>
                            <summary class="{{ $itemBase }} {{ $itemIdle }} cursor-pointer list-none select-none [&::-webkit-details-marker]:hidden">
                                <x-admin.icon :name="$child['icon']" class="w-5 h-5 shrink-0" />
                                <span class="flex-1">{{ $child['label'] }}</span>
                                <x-admin.icon name="chevron-down"
                                              class="w-4 h-4 shrink-0 text-gray-400 transition-transform duration-200 group-open:-rotate-180" />
                            </summary>

                            {{-- Child links, joined by a tree line --}}
                            <div class="relative mt-1 ml-6 space-y-0.5">
                                <span class="absolute left-0 top-0 bottom-0 w-px bg-gray-200" aria-hidden="true"></span>

                                @foreach ($child['children'] as $link)
                                    @php $isActive = request()->routeIs(...(array) $link['active']); @endphp

                                    <div class="relative">
                                        {{-- Connector from the tree line to the row --}}
                                        <span class="absolute left-0 top-1/2 -translate-y-1/2 w-3 h-px bg-gray-200" aria-hidden="true"></span>

                                        <a href="{{ route($link['route']) }}"
                                           @class([
                                               'ml-3 flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm transition',
                                               $itemActive => $isActive,
                                               $itemIdle => ! $isActive,
                                           ])
                                           @if ($isActive) aria-current="page" @endif>
                                            <span @class([
                                                'w-2 h-2 rounded-full shrink-0',
                                                'bg-blue-600' => $isActive,
                                                'bg-gray-300' => ! $isActive,
                                            ]) aria-hidden="true"></span>
                                            {{ $link['label'] }}
                                        </a>
                                    </div>
                                @endforeach
                            </div>
                        </details>
                    @endif
                @endforeach
            @endif
        @endforeach
    </nav>

    {{-- Footer: the CHIP account balance, on one line.
         Height fixed at h-9 to match the page footer in layouts/admin.blade.php,
         so both top borders read as one continuous line. Change one and change
         the other.

         The button is p-1.5 rather than p-2: at p-2 it measures 36px and fills a
         36px bar edge to edge with no breathing room.

         The figure comes from cache. ChipBalance asks CHIP at most once every few
         minutes, because this partial is drawn on every admin page and an outage at
         CHIP must not slow all of them down.

         Hidden without payments.view. A balance on every screen would otherwise
         show the takings to anybody who can reach the admin, a Referee included. --}}
    @php
        $balance = auth()->user()?->hasPermission('payments.view')
            ? app(App\Support\ChipBalance::class)->current()
            : null;
    @endphp

    <div class="h-9 shrink-0 flex items-center gap-2 px-3 border-t border-gray-200">
        <a href="{{ route('admin.payments.overview') }}"
           class="p-1.5 rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-900 transition shrink-0"
           title="Payments"
           aria-label="Payments">
            <x-admin.icon name="cash" class="w-5 h-5 shrink-0" />
        </a>

        @if ($balance)
            @php
                // Negative is possible: CHIP's own documented examples include an
                // account in the red, so it is shown rather than assumed away.
                $isNegative = $balance['amount'] < 0;

                $tone = match (true) {
                    $isNegative => 'text-red-600',
                    $balance['stale'] => 'text-gray-400',
                    default => 'text-gray-700',
                };

                $formatted = ($isNegative ? '-' : '') . 'RM ' . number_format(abs($balance['amount']), 2);

                // One line has no room for "checked 2 minutes ago", so the age lives
                // in the tooltip, and a stale figure says so in words as well as by
                // the grey dot.
                $hint = $balance['stale']
                    ? 'CHIP balance as of ' . $balance['fetched_at']->diffForHumans() . '. CHIP could not be reached since.'
                    : 'CHIP available balance, checked ' . $balance['fetched_at']->diffForHumans() . '.';
            @endphp

            {{-- Sits right beside the icon rather than pushed to the far edge.
                 ml-auto put the whole sidebar's width between the two. --}}
            <span class="flex items-center gap-1.5 min-w-0" title="{{ $hint }}">
                <span @class([
                    'w-1.5 h-1.5 rounded-full shrink-0',
                    'bg-gray-300' => $balance['stale'],
                    'bg-green-500' => ! $balance['stale'] && ! $isNegative,
                    'bg-red-500' => $isNegative,
                ]) aria-hidden="true"></span>

                <span class="text-xs font-semibold text-gray-400 shrink-0">{{ $balance['currency'] }}</span>

                <span class="text-xs font-bold tabular-nums truncate {{ $tone }}">{{ $formatted }}</span>

                <span class="sr-only">
                    CHIP available balance {{ $formatted }}{{ $balance['stale'] ? ', out of date' : '' }}
                </span>
            </span>
        @endif
    </div>
</aside>

@push('scripts')
<script>
    // Remember which groups the operator left open. A group holding the current
    // page is always opened regardless of what was stored.
    (function () {
        const storageKey = 'admin.sidebar.groups';
        let stored = {};

        try {
            stored = JSON.parse(localStorage.getItem(storageKey) || '{}');
        } catch (error) {
            stored = {};
        }

        document.querySelectorAll('[data-nav-group]').forEach(function (group) {
            const key = group.dataset.navGroup;

            // `open` is already set server side when a child route is active.
            if (!group.open && stored[key] === true) {
                group.open = true;
            }

            group.addEventListener('toggle', function () {
                stored[key] = group.open;

                try {
                    localStorage.setItem(storageKey, JSON.stringify(stored));
                } catch (error) {
                    // Storage unavailable, for example private browsing. The
                    // menu still works, it just will not remember state.
                }
            });
        });
    })();
</script>
@endpush
