@extends('layouts.master')

@section('title', 'Hall of Fame')

@section('content')
    @include('components.page-header', [
        'title' => 'Hall of Fame',
        'subtitle' => 'Champions of our tournaments, kept as they were announced.',
    ])

    <section class="py-14 md:py-20 bg-gray-50">
        <div class="max-w-5xl mx-auto px-4 sm:px-6">

            @forelse ($years as $year => $entries)
                <div class="mb-12 last:mb-0">
                    <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-6">{{ $year }}</h2>

                    <div class="space-y-5">
                        @foreach ($entries as $entry)
                            @php $tournament = $entry['tournament']; @endphp

                            <article class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                                <div class="px-6 py-4 border-b border-gray-100">
                                    <h3 class="text-base font-bold text-gray-900">
                                        {{ $tournament?->event?->title ?? 'Event' }}
                                    </h3>
                                    <p class="text-sm text-gray-500 mt-0.5">{{ $tournament?->name }}</p>
                                </div>

                                <ol class="divide-y divide-gray-100">
                                    @foreach ($entry['podium'] as $champion)
                                        @php
                                            // Written out so Tailwind finds the classes when scanning.
                                            $tone = match ($champion->rank) {
                                                1 => 'bg-amber-100 text-amber-800',
                                                2 => 'bg-gray-200 text-gray-700',
                                                default => 'bg-orange-100 text-orange-800',
                                            };
                                        @endphp

                                        <li class="flex items-center gap-4 px-6 py-4">
                                            {{-- Position given in words as well as by colour, so it does
                                                 not rely on telling gold from bronze. --}}
                                            <span class="shrink-0 w-9 h-9 rounded-full {{ $tone }} flex items-center justify-center text-sm font-bold">
                                                {{ $champion->rank }}
                                            </span>

                                            <div class="min-w-0 flex-1">
                                                <p class="text-base font-bold text-gray-900">{{ $champion->display_name }}</p>
                                                <p class="text-xs text-gray-500">{{ $champion->medalLabel() }}</p>
                                            </div>

                                            <span class="shrink-0 text-base font-bold text-gray-900 tabular-nums">
                                                {{ $champion->total_points + 0 }}
                                                <span class="text-xs font-normal text-gray-500">pts</span>
                                            </span>
                                        </li>
                                    @endforeach
                                </ol>

                                {{-- ===== Individual awards =====
                                     A separate ledger, published on its own, so this may
                                     be absent even where a podium is present. Only the
                                     name, in-game name and team are shown. --}}
                                @php $tournamentAwards = $awards[$tournament?->id] ?? collect(); @endphp

                                @if ($tournamentAwards->isNotEmpty())
                                    <div class="px-6 py-3 bg-gray-50 border-t border-gray-100">
                                        <p class="text-xs font-bold uppercase tracking-wide text-gray-600">Individual Awards</p>
                                        <p class="text-sm text-gray-500 mt-0.5">
                                            Counted separately from the team podium above.
                                        </p>
                                    </div>

                                    <ul class="divide-y divide-gray-100">
                                        @foreach ($tournamentAwards as $award)
                                            <li class="flex items-center gap-4 px-6 py-3">
                                                <span class="shrink-0 rounded bg-purple-100 px-2 py-1 text-xs font-bold text-purple-800">
                                                    {{ $award->award_label }}@if ($award->award_key === 'mvp' && $award->rank > 1) {{ $award->rank }}@endif
                                                </span>

                                                <div class="min-w-0 flex-1">
                                                    <p class="text-base font-semibold text-gray-900">{{ $award->display_name }}</p>
                                                    <p class="text-xs text-gray-500">
                                                        {{ $award->entrant_name }}@if ($award->ign) · {{ $award->ign }}@endif
                                                    </p>
                                                </div>

                                                <span class="shrink-0 text-base font-bold text-gray-900 tabular-nums">
                                                    {{ $award->total_points + 0 }}
                                                    <span class="text-xs font-normal text-gray-500">pts</span>
                                                </span>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </article>
                        @endforeach
                    </div>
                </div>
            @empty
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm px-6 py-16 text-center">
                    <svg class="w-12 h-12 mx-auto text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 21h8m-4-4v4m-5.5-17h11v5a5.5 5.5 0 01-11 0V4zm11 1h2.5a2 2 0 010 4H18m-11.5-4H4a2 2 0 000 4h2.5"/>
                    </svg>
                    <p class="text-base font-bold text-gray-900 mt-4">No champions yet</p>
                    <p class="text-base text-gray-500 mt-1">
                        Results appear here once a tournament is finished and published.
                    </p>
                </div>
            @endforelse

        </div>
    </section>
@endsection
