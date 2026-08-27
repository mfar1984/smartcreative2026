<section class="hero-section relative bg-gradient-to-r from-gray-900 via-gray-800 to-black text-white min-h-screen flex items-center overflow-hidden">
    <div class="absolute inset-0 bg-cover bg-center opacity-30" style="background-image: url('{{ asset('images/home.png') }}');"></div>
    
    <div class="container mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
            <!-- Left Side: Content -->
            <div class="space-y-6">
                <h1 class="text-4xl md:text-5xl lg:text-6xl font-bold leading-tight uppercase">
                    Innovate, Create & Manage
                </h1>
                
                <!-- Separator Line -->
                <div class="w-full max-w-2xl h-0.5 bg-white"></div>
                
                <p class="text-base md:text-lg text-gray-300 leading-relaxed max-w-2xl" style="text-align: justify;">
                    "Your Vision, Our Expertise." We are dedicated to transforming your ideas into impactful realities through exceptional event management, seamless online registration solutions, and captivating digital creative services. Partner with us to achieve your goals with precision and flair.
                </p>
                
                <!-- Pill Buttons -->
                <div class="flex flex-wrap gap-4 pt-4">
                    <a href="#services" class="inline-flex items-center gap-2 bg-cyan-400 text-gray-900 px-8 py-3 rounded-full font-semibold hover:bg-cyan-500 transition shadow-lg">
                        Our Services
                    </a>
                    <a href="#about" class="inline-flex items-center gap-2 border-2 border-white text-white px-6 py-3 rounded-full font-semibold hover:bg-white hover:text-gray-900 transition">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
                        </svg>
                        About Us
                    </a>
                </div>
            </div>
            
            <!-- Right Side: Floating Image -->
            <div class="hidden lg:block relative">
                <div class="relative animate-float max-w-sm mx-auto">
                    <img src="{{ asset('images/home-hero.png') }}" alt="Digital Creative Illustration" class="w-full h-auto drop-shadow-2xl">
                    
                    <!-- Floating Elements -->
                    <div class="absolute top-10 -left-10 w-20 h-20 bg-blue-500 rounded-full opacity-20 animate-pulse"></div>
                    <div class="absolute bottom-20 -right-10 w-32 h-32 bg-purple-500 rounded-full opacity-20 animate-pulse delay-1000"></div>
                    <div class="absolute top-1/2 left-10 w-16 h-16 bg-cyan-500 rounded-full opacity-20 animate-bounce"></div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Scroll Down Indicator -->
    <div class="absolute bottom-8 left-1/2 transform -translate-x-1/2 animate-bounce">
        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/>
        </svg>
    </div>
</section>

<style>
@keyframes float {
    0%, 100% {
        transform: translateY(0px);
    }
    50% {
        transform: translateY(-20px);
    }
}

.animate-float {
    animation: float 3s ease-in-out infinite;
}

.delay-1000 {
    animation-delay: 1s;
}
</style>
