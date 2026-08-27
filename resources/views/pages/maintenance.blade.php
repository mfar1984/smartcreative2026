@extends('layouts.master')

@section('title', $pageName)

@section('content')
    @include('components.hero', [
        'title' => $pageName,
    ])
    
    <section class="py-20 bg-white">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="max-w-2xl mx-auto text-center">
                <div class="mb-8">
                    <svg class="w-32 h-32 mx-auto text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                </div>
                
                <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-4">Under Development</h2>
                <p class="text-lg text-gray-600 mb-8">
                    {{ $message }}
                </p>
                
                <div class="space-y-4">
                    <p class="text-gray-500">
                        We're working hard to bring you this section. In the meantime, feel free to explore other parts of our website.
                    </p>
                    <a href="{{ route('home') }}" class="inline-block bg-blue-600 text-white px-8 py-3 rounded-lg font-semibold hover:bg-blue-700 transition shadow-md">
                        Return to Home
                    </a>
                </div>
            </div>
        </div>
    </section>
@endsection
