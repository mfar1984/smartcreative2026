<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') &middot; Smart Digital Creative Admin</title>

    @include('partials.favicon')

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body class="bg-gray-100">
    <div class="min-h-screen lg:flex">

        {{-- Sidebar --}}
        @include('admin.partials.sidebar')

        {{-- Main column --}}
        <div class="flex-1 min-w-0 flex flex-col">

            {{-- Top bar --}}
            {{-- h-16 and border-b sit on the SAME element here, exactly as they do on
                 the sidebar's brand block, so the two bottom borders land on the same
                 pixel.

                 Putting the height on an inner div instead does not work: Tailwind
                 sets box-sizing: border-box, so a height on the element carrying the
                 border includes that border, while a height on a child excludes the
                 parent's. That is a one pixel difference, and one pixel is enough to
                 read as a step where the two meet. --}}
            <header class="h-16 shrink-0 bg-white border-b border-gray-200 sticky top-0 z-20">
                <div class="h-full flex items-center justify-between gap-4 px-4 sm:px-6">
                    <div class="flex items-center gap-3 min-w-0">
                        <button type="button"
                                id="sidebar-toggle"
                                class="lg:hidden shrink-0 p-2 -ml-2 rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-700 transition"
                                aria-label="Toggle navigation"
                                aria-controls="admin-sidebar"
                                aria-expanded="false">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                            </svg>
                        </button>

                        <div class="min-w-0">
                            {{-- Pages that carry their own title card show a breadcrumb here
                                 instead, so the same heading is not printed twice. --}}
                            @hasSection('breadcrumb')
                                <nav aria-label="Breadcrumb" class="flex items-center text-xs text-gray-500 truncate">
                                    @yield('breadcrumb')
                                </nav>
                            @else
                                <h1 class="text-base font-bold text-gray-900 truncate">@yield('heading', View::getSection('title'))</h1>
                                @hasSection('subheading')
                                    <p class="text-xs text-gray-500 truncate">@yield('subheading')</p>
                                @endif
                            @endif
                        </div>
                    </div>

                    {{-- Signed in user --}}
                    <div class="flex items-center gap-3 shrink-0">
                        <div class="hidden sm:block text-right">
                            <span class="block text-sm font-semibold text-gray-900 leading-tight">{{ auth()->user()->name }}</span>
                            <span class="block text-xs text-gray-500 leading-tight">
                                {{ auth()->user()->role?->name ?? 'No role' }}
                            </span>
                        </div>

                        <span class="w-9 h-9 rounded-full bg-blue-600 text-white flex items-center justify-center text-sm font-bold shrink-0" aria-hidden="true">
                            {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                        </span>

                        <form action="{{ route('admin.logout') }}" method="POST">
                            @csrf
                            <button type="submit"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 hover:text-gray-900 transition">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                                </svg>
                                <span class="hidden sm:inline">Sign out</span>
                            </button>
                        </form>
                    </div>
                </div>
            </header>

            {{-- Page content --}}
            <main class="flex-1 p-4 sm:p-6">
                @include('admin.partials.flash')

                @yield('content')
            </main>

            {{-- Fixed height, matched by the sidebar footer, so the two top borders
                 meet as one line across the screen. Padding alone left them 20px
                 apart, because one holds a line of text and the other an icon.

                 Stuck to the bottom of the viewport, the same way the top bar above
                 is stuck to the top. The sidebar is h-screen, so its own footer was
                 always on screen while this one scrolled away with the content: the
                 two borders only lined up on pages short enough not to scroll. This
                 keeps them joined at every scroll position.

                 The cost is 36px at the bottom of the viewport now covered while
                 scrolling, the same trade the sticky header already makes at the top.
                 Nothing is permanently hidden, because at the end of the scroll the
                 footer settles into its real place below the content. --}}
            <footer class="h-9 shrink-0 sticky bottom-0 z-10 flex items-center px-4 sm:px-6 border-t border-gray-200 bg-white">
                <p class="text-xs text-gray-500 truncate">
                    Smart Digital Creative Management &amp; Resources &copy; {{ date('Y') }}
                </p>

                {{-- Counters on the right of the same line, so the footer keeps its
                     h-9 and the border still meets the sidebar's. --}}
                @include('admin.partials.status')
            </footer>
        </div>
    </div>

    @stack('scripts')

    <script>
        // Sidebar slide over on small screens.
        (function () {
            const toggle = document.getElementById('sidebar-toggle');
            const sidebar = document.getElementById('admin-sidebar');
            const backdrop = document.getElementById('sidebar-backdrop');

            if (!toggle || !sidebar) {
                return;
            }

            function setOpen(open) {
                sidebar.classList.toggle('-translate-x-full', !open);
                backdrop?.classList.toggle('hidden', !open);
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            }

            toggle.addEventListener('click', function () {
                setOpen(sidebar.classList.contains('-translate-x-full'));
            });

            backdrop?.addEventListener('click', function () {
                setOpen(false);
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    setOpen(false);
                }
            });
        })();
    </script>
</body>
</html>
