<header id="main-header" class="absolute top-0 left-0 right-0 z-40 transition-all duration-300">
    <div class="container mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center py-2">
            <!-- Logo Section -->
            <div class="logo">
                <a href="{{ route('home') }}" class="flex items-center">
                    <img id="header-logo" src="{{ asset('images/header-logo.png') }}" alt="Smart Digital Creative Logo" style="width: 150px !important; height: auto !important;">
                </a>
            </div>
            
            <!-- Desktop Navigation Menu -->
            <nav class="hidden md:flex items-center space-x-8 text-sm">
                <a href="{{ route('home') }}" data-nav-link class="text-white hover:text-blue-300 font-medium transition {{ request()->routeIs('home') ? 'text-blue-300' : '' }}">
                    Home
                </a>
                
                <div class="relative group">
                    <a href="{{ route('services') }}" data-nav-link class="text-white hover:text-blue-300 font-medium transition flex items-center {{ request()->routeIs('services*') ? 'text-blue-300' : '' }}">
                        Services
                        <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </a>
                    <div class="absolute left-0 mt-2 w-64 bg-white rounded-md shadow-lg opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200">
                        <div class="py-2">
                            <a href="{{ route('services.event-management') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-blue-50 hover:text-blue-600 transition">
                                Event Management
                            </a>
                            <a href="{{ route('services.online-registration') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-blue-50 hover:text-blue-600 transition">
                                Online Registration Solutions
                            </a>
                            <a href="{{ route('services.digital-creative') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-blue-50 hover:text-blue-600 transition">
                                Digital Creative Solutions
                            </a>
                        </div>
                    </div>
                </div>
                
                <a href="{{ route('registration') }}" data-nav-link class="text-white hover:text-blue-300 font-medium transition {{ request()->routeIs('registration*') ? 'text-blue-300' : '' }}">
                    Registration
                </a>
                
                <a href="{{ route('portfolio') }}" data-nav-link class="text-white hover:text-blue-300 font-medium transition {{ request()->routeIs('portfolio') ? 'text-blue-300' : '' }}">
                    Portfolio
                </a>
                
                <a href="{{ route('shop') }}" data-nav-link class="text-white hover:text-blue-300 font-medium transition {{ request()->routeIs('shop') ? 'text-blue-300' : '' }}">
                    Shop
                </a>
                
                <a href="{{ route('contact') }}" data-nav-link class="text-white hover:text-blue-300 font-medium transition {{ request()->routeIs('contact') ? 'text-blue-300' : '' }}">
                    Contact
                </a>
            </nav>
            
            <!-- Mobile Menu Toggle -->
            <button id="mobile-menu-toggle" class="md:hidden flex flex-col justify-center items-center w-10 h-10 space-y-1.5" aria-label="Toggle menu">
                <span class="block w-6 h-0.5 bg-white transition-all duration-300"></span>
                <span class="block w-6 h-0.5 bg-white transition-all duration-300"></span>
                <span class="block w-6 h-0.5 bg-white transition-all duration-300"></span>
            </button>
        </div>
        
        <!-- Mobile Navigation Menu -->
        <div id="mobile-menu" class="hidden md:hidden pb-4">
            <div class="flex flex-col space-y-3">
                <a href="{{ route('home') }}" data-nav-link class="text-white hover:text-blue-300 font-medium transition {{ request()->routeIs('home') ? 'text-blue-300' : '' }}">
                    Home
                </a>
                
                <div>
                    <button id="mobile-services-toggle" data-nav-link class="w-full text-left text-white hover:text-blue-300 font-medium transition flex items-center justify-between {{ request()->routeIs('services*') ? 'text-blue-300' : '' }}">
                        Services
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>
                    <div id="mobile-services-submenu" class="hidden pl-4 mt-2 space-y-2">
                        <a href="{{ route('services.event-management') }}" data-nav-link class="block text-gray-200 hover:text-blue-300 transition">
                            Event Management
                        </a>
                        <a href="{{ route('services.online-registration') }}" data-nav-link class="block text-gray-200 hover:text-blue-300 transition">
                            Online Registration Solutions
                        </a>
                        <a href="{{ route('services.digital-creative') }}" data-nav-link class="block text-gray-200 hover:text-blue-300 transition">
                            Digital Creative Solutions
                        </a>
                    </div>
                </div>
                
                <a href="{{ route('registration') }}" data-nav-link class="text-white hover:text-blue-300 font-medium transition {{ request()->routeIs('registration*') ? 'text-blue-300' : '' }}">
                    Registration
                </a>
                
                <a href="{{ route('portfolio') }}" data-nav-link class="text-white hover:text-blue-300 font-medium transition {{ request()->routeIs('portfolio') ? 'text-blue-300' : '' }}">
                    Portfolio
                </a>
                
                <a href="{{ route('shop') }}" data-nav-link class="text-white hover:text-blue-300 font-medium transition {{ request()->routeIs('shop') ? 'text-blue-300' : '' }}">
                    Shop
                </a>
                
                <a href="{{ route('contact') }}" data-nav-link class="text-white hover:text-blue-300 font-medium transition {{ request()->routeIs('contact') ? 'text-blue-300' : '' }}">
                    Contact
                </a>
            </div>
        </div>
    </div>
</header>

@push('scripts')
<script>
// Mobile menu toggle
document.getElementById('mobile-menu-toggle').addEventListener('click', function() {
    const menu = document.getElementById('mobile-menu');
    menu.classList.toggle('hidden');
});

// Mobile services submenu toggle
document.getElementById('mobile-services-toggle').addEventListener('click', function() {
    const submenu = document.getElementById('mobile-services-submenu');
    submenu.classList.toggle('hidden');
});

// Change header background on scroll
(function() {
    const mainHeader = document.getElementById('main-header');
    const topHeaderElement = document.getElementById('top-header');
    const heroSection = document.querySelector('.hero-section');
    const headerLogo = document.getElementById('header-logo');

    // Hanya link menu utama yang bertukar warna.
    // Link dalam dropdown Services TIDAK termasuk kerana panelnya berlatar putih,
    // jadi teksnya mesti kekal gelap.
    const navLinks = mainHeader.querySelectorAll('[data-nav-link]');
    const hamburgerSpans = mainHeader.querySelectorAll('#mobile-menu-toggle span');

    const lightLogo = '{{ asset('images/header-logo.png') }}';
    const darkLogo = '{{ asset('images/logo.png') }}';

    // Simpan class asal setiap link supaya warna boleh dipulihkan dengan tepat
    navLinks.forEach(link => {
        link.dataset.baseClass = link.className;
    });

    let isSolid = null;

    function applySolid() {
        // Header fixed, diletakkan tepat di bawah top header
        mainHeader.classList.remove('absolute', 'bg-transparent');
        mainHeader.classList.add('fixed', 'shadow-md');
        mainHeader.style.backgroundColor = 'rgba(255, 255, 255, 0.3)';
        mainHeader.style.backdropFilter = 'blur(10px)';
        mainHeader.style.webkitBackdropFilter = 'blur(10px)';
        mainHeader.style.left = '0';
        mainHeader.style.right = '0';
        mainHeader.style.zIndex = '40';

        headerLogo.src = darkLogo;

        navLinks.forEach(link => {
            link.classList.remove('text-white', 'text-gray-200', 'hover:text-blue-300');
            link.classList.add('text-gray-700', 'hover:text-blue-600');
        });

        hamburgerSpans.forEach(span => {
            span.classList.remove('bg-white');
            span.classList.add('bg-gray-700');
        });
    }

    function applyTransparent() {
        mainHeader.classList.remove('fixed', 'shadow-md');
        mainHeader.classList.add('absolute', 'bg-transparent');
        mainHeader.style.backgroundColor = '';
        mainHeader.style.backdropFilter = '';
        mainHeader.style.webkitBackdropFilter = '';
        mainHeader.style.top = '0';

        headerLogo.src = lightLogo;

        navLinks.forEach(link => {
            link.className = link.dataset.baseClass;
        });

        hamburgerSpans.forEach(span => {
            span.classList.remove('bg-gray-700');
            span.classList.add('bg-white');
        });
    }

    function syncHeader() {
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        const heroHeight = heroSection ? heroSection.offsetHeight : 300;
        const shouldBeSolid = scrollTop > heroHeight - 100;

        if (shouldBeSolid !== isSolid) {
            shouldBeSolid ? applySolid() : applyTransparent();
            isSolid = shouldBeSolid;
        }

        // Ikut ketinggian sebenar top header supaya tiada celah di antaranya
        if (shouldBeSolid) {
            const offset = topHeaderElement ? topHeaderElement.offsetHeight : 0;
            mainHeader.style.top = offset + 'px';
        }
    }

    window.addEventListener('scroll', syncHeader, { passive: true });
    window.addEventListener('resize', syncHeader);
    syncHeader();
})();
</script>
@endpush
