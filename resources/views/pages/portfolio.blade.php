{{--
    Portfolio.

    A card grid, filtered by service. Each card is one delivered project: image,
    category, title, who it was for, when, a summary and its highlights.

    Cards with no uploaded image fall back to a lettered tile rather than a broken
    image or a grey box, so a grid can be populated before the photographs are ready
    without looking unfinished.
--}}
@extends('layouts.master')

@section('title', $pageTitle)

@section('content')
    @include('components.page-header', [
        'title' => $pageTitle,
        'subtitle' => $pageSubtitle,
    ])

    <section class="py-14 bg-white">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">

            @if ($totalPublished > 0)
                {{-- ---------------- Filter ---------------- --}}
                <div class="flex flex-wrap items-center gap-2 mb-10 pb-8 border-b border-gray-200">
                    <span class="text-sm font-semibold text-gray-500 mr-1">Show</span>

                    <a href="{{ route('portfolio') }}"
                       @class([
                           'px-4 py-2 rounded-full text-sm font-semibold transition',
                           'bg-blue-600 text-white shadow-sm' => $activeService === '',
                           'bg-gray-100 text-gray-700 hover:bg-gray-200' => $activeService !== '',
                       ])
                       @if ($activeService === '') aria-current="page" @endif>
                        Everything
                        <span class="opacity-70">({{ $totalPublished }})</span>
                    </a>

                    {{-- Only services that actually have published work are offered. A
                         filter leading to an empty grid reads as a broken page. --}}
                    @foreach ($services as $slug => $label)
                        @continue (($serviceCounts[$slug] ?? 0) === 0)

                        <a href="{{ route('portfolio', ['service' => $slug]) }}"
                           @class([
                               'px-4 py-2 rounded-full text-sm font-semibold transition',
                               'bg-blue-600 text-white shadow-sm' => $activeService === $slug,
                               'bg-gray-100 text-gray-700 hover:bg-gray-200' => $activeService !== $slug,
                           ])
                           @if ($activeService === $slug) aria-current="page" @endif>
                            {{ $label }}
                            <span class="opacity-70">({{ $serviceCounts[$slug] }})</span>
                        </a>
                    @endforeach
                </div>
            @endif

            {{-- ---------------- Grid ---------------- --}}
            @if ($projects->isNotEmpty())
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-7">
                    @foreach ($projects as $project)
                        @php
                            $image = $project->imageUrl();
                            $highlights = $project->highlightLines();
                        @endphp

                        <article class="group flex flex-col rounded-lg border border-gray-200 shadow-sm overflow-hidden hover:shadow-xl hover:border-blue-200 transition">

                            {{-- Image, or a lettered tile when there is none. --}}
                            <div class="relative aspect-[4/3] overflow-hidden bg-gray-900">
                                @if ($image)
                                    <img src="{{ $image }}"
                                         alt="{{ $project->title }}"
                                         loading="lazy"
                                         class="w-full h-full object-cover group-hover:scale-105 transition duration-500">
                                @else
                                    <div class="w-full h-full bg-gradient-to-br from-gray-900 via-gray-800 to-blue-900 flex items-center justify-center">
                                        <span class="text-5xl font-bold text-white/25" aria-hidden="true">
                                            {{ Str::upper(Str::substr($project->title, 0, 2)) }}
                                        </span>
                                    </div>
                                @endif

                                {{-- Category sits on the image so the card body starts with
                                     the title rather than with a label. --}}
                                <span class="absolute top-3 left-3 bg-white/95 backdrop-blur-sm text-gray-900 text-xs font-bold uppercase tracking-wide px-2.5 py-1.5 rounded">
                                    {{ $project->category }}
                                </span>

                                @if ($project->is_featured)
                                    <span class="absolute top-3 right-3 bg-amber-400 text-amber-900 text-xs font-bold uppercase tracking-wide px-2.5 py-1.5 rounded">
                                        Featured
                                    </span>
                                @endif
                            </div>

                            {{-- Body --}}
                            <div class="flex flex-col flex-1 p-5">
                                <h2 class="text-lg font-bold text-gray-900 leading-snug mb-2">
                                    {{ $project->title }}
                                </h2>

                                <p class="text-xs text-gray-500 mb-3">
                                    {{ $project->clientLabel() }}
                                    <span class="mx-1 text-gray-300" aria-hidden="true">&bull;</span>
                                    {{ $project->deliveredLabel() }}
                                    @if (filled($project->location))
                                        <span class="mx-1 text-gray-300" aria-hidden="true">&bull;</span>
                                        {{ $project->location }}
                                    @endif
                                </p>

                                <p class="text-sm text-gray-600 leading-relaxed mb-4">
                                    {{ $project->summary }}
                                </p>

                                @if ($highlights !== [])
                                    <ul class="space-y-1.5 mb-4">
                                        @foreach ($highlights as $line)
                                            <li class="flex gap-2 text-sm text-gray-700">
                                                <svg class="w-4 h-4 shrink-0 mt-0.5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                                </svg>
                                                <span>{{ $line }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif

                                {{-- Pushed to the bottom so every card in a row lines up
                                     regardless of how much summary it carries. --}}
                                <div class="mt-auto pt-3 border-t border-gray-100">
                                    <span class="text-xs font-semibold uppercase tracking-wide text-blue-600">
                                        {{ $project->serviceLabel() }}
                                    </span>
                                </div>
                            </div>

                        </article>
                    @endforeach
                </div>

                @if ($projects->hasPages())
                    <div class="mt-12">
                        {{ $projects->links() }}
                    </div>
                @endif

            @else
                {{-- Empty state. Two versions: nothing published at all, or a filter
                     that matched nothing. They are different problems. --}}
                <div class="max-w-xl mx-auto text-center py-16">
                    <svg class="w-16 h-16 mx-auto text-gray-300 mb-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>

                    @if ($activeService !== '')
                        <h2 class="text-xl font-bold text-gray-900 mb-3">
                            Nothing here under {{ $services[$activeService] }} yet
                        </h2>
                        <p class="text-base text-gray-600 mb-7">
                            There is other work to look at though.
                        </p>
                        <a href="{{ route('portfolio') }}"
                           class="inline-flex items-center gap-2 bg-blue-600 text-white px-6 py-3 rounded-lg font-semibold hover:bg-blue-700 transition">
                            Show everything
                        </a>
                    @else
                        <h2 class="text-xl font-bold text-gray-900 mb-3">
                            Our portfolio is being put together
                        </h2>
                        <p class="text-base text-gray-600 mb-7">
                            We would rather show you the work properly than post placeholders. In the
                            meantime, tell us what you are planning and we will talk you through
                            comparable events we have run.
                        </p>
                        <a href="{{ route('contact') }}"
                           class="inline-flex items-center gap-2 bg-blue-600 text-white px-6 py-3 rounded-lg font-semibold hover:bg-blue-700 transition">
                            Get in touch
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                            </svg>
                        </a>
                    @endif
                </div>
            @endif

        </div>
    </section>

    {{-- ---------------- Next step ---------------- --}}
    @if ($totalPublished > 0)
        <section class="py-16 bg-gradient-to-r from-gray-900 to-blue-900 text-white">
            <div class="container mx-auto px-4 sm:px-6 lg:px-8">
                <div class="max-w-3xl">
                    <h2 class="text-3xl md:text-4xl font-bold mb-4">Yours could be next on this page</h2>

                    <p class="text-base md:text-lg text-gray-300 mb-8">
                        Tell us the date and what the event is for. We will come back with whether it
                        is feasible, what it would take, and a rough cost.
                    </p>

                    <div class="flex flex-wrap gap-4">
                        <a href="{{ route('contact') }}"
                           class="inline-flex items-center gap-2 bg-blue-600 text-white px-7 py-3.5 rounded-lg font-semibold hover:bg-blue-700 transition shadow-md">
                            Start a conversation
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                            </svg>
                        </a>

                        <a href="{{ route('services') }}"
                           class="inline-flex items-center gap-2 border-2 border-white/60 text-white px-7 py-3.5 rounded-lg font-semibold hover:bg-white/10 transition">
                            See what we do
                        </a>
                    </div>
                </div>
            </div>
        </section>
    @endif
@endsection
