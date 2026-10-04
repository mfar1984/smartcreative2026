@extends('layouts.master')
@section('title', $pageTitle)

{{--
    What a size confirmation link answers once it has run out, or if it was edited.

    An ordinary page rather than an exception, which is the whole reason the signature
    is checked in the controller: the people holding these links are participants, and a
    stack trace or a bare 403 tells them nothing they can act on.

    Nothing about the registration is named here. At this point there is no evidence the
    person holding the link is entitled to it.
--}}

@section('content')
    @include('components.page-header', [
        'title' => $pageTitle,
        'subtitle' => $pageSubtitle,
    ])

    <section class="py-16 bg-gray-50">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="max-w-xl mx-auto">
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm px-6 py-10 text-center">
                    <svg class="w-10 h-10 mx-auto text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>

                    <h2 class="text-xl font-bold text-gray-900 mt-4">This link is no longer valid</h2>

                    <p class="text-base text-gray-600 mt-2">
                        Size confirmation links expire after a while, and a link that has been
                        edited or only partly copied will not open either.
                    </p>

                    <p class="text-sm text-gray-600 mt-4">
                        Reply to the email that brought you here and ask for a fresh link. Nothing
                        is owed and nothing has been charged.
                    </p>

                    <a href="{{ route('home') }}"
                       class="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2 mt-6 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                        Back to the home page
                    </a>
                </div>
            </div>
        </div>
    </section>
@endsection
