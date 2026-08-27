{{--
    Compact page header for inner pages.

    Keeps the `hero-section` class because the main header and top header read
    its height to decide when to switch to their scrolled state.

    @param string      $title
    @param string|null $subtitle
--}}
<section class="hero-section relative bg-gradient-to-r from-gray-900 via-gray-800 to-black text-white pt-28 pb-14 md:pt-32 md:pb-16 overflow-hidden">
    <div class="absolute inset-0 bg-cover bg-center opacity-20" style="background-image: url('{{ asset('images/home.png') }}');"></div>

    <div class="container mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
        <h1 class="text-3xl md:text-4xl lg:text-5xl font-bold leading-tight uppercase">
            {{ $title }}
        </h1>

        <div class="w-full max-w-2xl h-0.5 bg-white mt-5"></div>

        @if (!empty($subtitle))
            <p class="text-base md:text-lg text-gray-300 mt-5 max-w-2xl">
                {{ $subtitle }}
            </p>
        @endif
    </div>
</section>
