@extends('layouts.master')

@section('title', 'Home')

@section('content')
    @include('components.hero', [
        'title' => $heroTitle,
        'subtitle' => $heroSubtitle,
    ])
    
    @include('components.about-us')
    
    <!-- Our Services Section -->
    <section class="py-16 bg-gray-50">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="max-w-6xl mx-auto">
                <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-12 text-center">Our Services</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                    <div class="bg-white p-8 rounded-lg shadow-md hover:shadow-xl transition">
                        <div class="text-blue-600 mb-4">
                            <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 mb-3">Event Management</h3>
                        <p class="text-gray-600 mb-4">
                            Professional event planning and execution services. From corporate events to large-scale conferences, 
                            we handle every detail to ensure your event is a success.
                        </p>
                        <a href="{{ route('services.event-management') }}" class="text-blue-600 hover:text-blue-700 font-semibold">
                            Learn More →
                        </a>
                    </div>
                    
                    <div class="bg-white p-8 rounded-lg shadow-md hover:shadow-xl transition">
                        <div class="text-blue-600 mb-4">
                            <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 mb-3">Online Registration Solutions</h3>
                        <p class="text-gray-600 mb-4">
                            Streamlined digital registration systems that make it easy for your attendees to sign up and for you to manage participants efficiently.
                        </p>
                        <a href="{{ route('services.online-registration') }}" class="text-blue-600 hover:text-blue-700 font-semibold">
                            Learn More →
                        </a>
                    </div>
                    
                    <div class="bg-white p-8 rounded-lg shadow-md hover:shadow-xl transition">
                        <div class="text-blue-600 mb-4">
                            <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 mb-3">Digital Creative Solutions</h3>
                        <p class="text-gray-600 mb-4">
                            Innovative digital content and design services that bring your brand to life. From web design to digital marketing, we've got you covered.
                        </p>
                        <a href="{{ route('services.digital-creative') }}" class="text-blue-600 hover:text-blue-700 font-semibold">
                            Learn More →
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
