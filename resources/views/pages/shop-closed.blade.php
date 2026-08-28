{{--
    Shown while the shop is switched off in settings.

    A deliberate page rather than a 404, because the Shop link is in the header and
    the footer: a visitor who follows it should be told the shop is not open yet, not
    that the page does not exist.
--}}
@extends('layouts.master')

@section('title', $pageTitle)

@section('content')
    @include('components.page-header', [
        'title' => $pageTitle,
        'subtitle' => 'Not open yet.',
    ])

    <section class="py-20 bg-white">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="max-w-xl mx-auto text-center">
                <svg class="w-16 h-16 mx-auto text-gray-300 mb-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                </svg>

                <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-4">
                    The shop is not open yet
                </h2>

                <p class="text-base md:text-lg text-gray-600 mb-8">
                    We are still putting the catalogue together. If you need medals, trophies,
                    apparel or event merchandise in the meantime, tell us what you are after and
                    we will quote for it directly.
                </p>

                <div class="flex flex-wrap justify-center gap-4">
                    <a href="{{ route('contact') }}"
                       class="inline-flex items-center gap-2 bg-blue-600 text-white px-7 py-3.5 rounded-lg font-semibold hover:bg-blue-700 transition shadow-md">
                        Ask us for a quote
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                        </svg>
                    </a>

                    <a href="{{ route('portfolio') }}"
                       class="inline-flex items-center gap-2 border-2 border-gray-300 text-gray-700 px-7 py-3.5 rounded-lg font-semibold hover:bg-gray-50 transition">
                        See our work
                    </a>
                </div>
            </div>
        </div>
    </section>
@endsection
