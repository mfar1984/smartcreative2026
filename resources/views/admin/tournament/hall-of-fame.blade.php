@extends('layouts.admin')

@section('title', 'Hall of Fame')

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>Tournament</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">{{ $intro['label'] }}</span>
@endsection

@section('content')
    @php
        $head = 'px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500';
        $medals = [1 => 'Champion', 2 => 'Runner-up', 3 => 'Third'];
    @endphp

    <x-admin.settings-shell
        title="Hall of Fame"
        description="Champions on the public site. A published podium is frozen as it was published."
        :tabs="$tabs"
        :active-tab="$activeTab"
        :route="$route">

        @if (session('status'))
            <div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-5">
                <p class="text-sm text-green-800">{{ session('status') }}</p>
            </div>
        @endif

        @if ($errors->any())
            <div role="alert" class="bg-red-50 border border-red-200 rounded-lg p-4 mb-5">
                <ul class="text-sm text-red-800 space-y-0.5">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="flex flex-wrap items-start justify-between gap-4 mb-5">
            <x-admin.section-intro
                :title="$intro['title']"
                :description="$intro['description']"
                :icon="$intro['icon']"
                :accent="$intro['accent']"
                class="mb-0" />

            @if ($activeTab === 'published')
                <a href="{{ url('/hall-of-fame') }}" target="_blank" rel="noopener"
                   class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition shrink-0">
                    <x-admin.icon name="globe" class="w-4 h-4" />
                    View Public Page
                </a>
            @endif
        </div>

        <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-left">
                        <tr>
                            <th scope="col" class="{{ $head }}">Tournament</th>
                            <th scope="col" class="{{ $head }}">Event</th>
                            <th scope="col" class="{{ $head }}">Podium</th>
                            <th scope="col" class="{{ $head }}">{{ $activeTab === 'published' ? 'Published' : 'Finished' }}</th>
                            @if ($canPublish)
                                <th scope="col" class="{{ $head }} text-center">Action</th>
                            @endif
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-gray-100">
                        @forelse ($tournaments as $tournament)
                            <tr class="hover:bg-blue-50/40 align-top">
                                <td class="px-5 py-3">
                                    <a href="{{ route('admin.tournaments.show', $tournament) }}"
                                       class="font-semibold text-blue-600 hover:underline">{{ $tournament->name }}</a>
                                    <span class="block text-xs text-gray-400 mt-0.5">{{ $tournament->formatLabel() }}</span>
                                </td>

                                <td class="px-5 py-3 text-gray-700">{{ $tournament->event?->title ?? '—' }}</td>

                                <td class="px-5 py-3">
                                    @php
                                        $rows = $activeTab === 'published'
                                            ? $tournament->champions
                                            : ($previews[$tournament->id] ?? collect());
                                    @endphp

                                    @forelse ($rows as $row)
                                        <span class="block text-xs">
                                            <span class="font-semibold text-gray-500">{{ $medals[$row->rank] ?? $row->rank }}</span>
                                            <span class="text-gray-900 font-semibold ml-1">
                                                {{ $activeTab === 'published' ? $row->display_name : ($row->entrant?->displayName() ?? '—') }}
                                            </span>
                                            <span class="text-gray-500 ml-1 tabular-nums">{{ $row->total_points + 0 }}</span>
                                        </span>
                                    @empty
                                        <span class="text-xs text-gray-400">No standings to take a podium from</span>
                                    @endforelse
                                </td>

                                <td class="px-5 py-3 whitespace-nowrap text-xs text-gray-500">
                                    @if ($activeTab === 'published')
                                        {{ $tournament->published_at?->format('d M Y, g:i a') }}
                                        @if ($tournament->champions->first()?->publisher)
                                            <span class="block text-gray-400">
                                                by {{ $tournament->champions->first()->publisher->name }}
                                            </span>
                                        @endif
                                    @else
                                        {{ $tournament->completed_at?->format('d M Y, g:i a') ?? '—' }}
                                    @endif
                                </td>

                                @if ($canPublish)
                                    <td class="px-5 py-3 text-center whitespace-nowrap">
                                        @if ($activeTab === 'published')
                                            <form action="{{ route('admin.tournaments.hall-of-fame.withdraw', $tournament) }}" method="POST"
                                                  onsubmit="return confirm('Take the podium for {{ addslashes($tournament->name) }} off the public site?\n\nThe frozen podium is deleted and scores can be corrected again.');">
                                                @csrf
                                                <button type="submit"
                                                        class="rounded-lg border border-red-300 bg-white px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50 transition">
                                                    Withdraw
                                                </button>
                                            </form>
                                        @else
                                            @php $preview = $previews[$tournament->id] ?? collect(); @endphp

                                            @if ($preview->isNotEmpty())
                                                <form action="{{ route('admin.tournaments.hall-of-fame.publish', $tournament) }}" method="POST"
                                                      onsubmit="return confirm('Publish this podium?\n\n{{ $preview->map(fn ($r) => ($medals[$r->rank] ?? $r->rank) . ': ' . addslashes($r->entrant?->displayName() ?? '') . ' — ' . ($r->total_points + 0))->implode('\n') }}\n\nIt will be copied and frozen. Correcting a score afterwards will NOT change what is published.');">
                                                    @csrf
                                                    <button type="submit"
                                                            class="inline-flex items-center gap-1.5 rounded-lg border border-blue-600 bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-700 transition">
                                                        <x-admin.icon name="trophy" class="w-3.5 h-3.5" />
                                                        Publish
                                                    </button>
                                                </form>
                                            @else
                                                <span class="text-xs text-gray-400">Nothing to publish</span>
                                            @endif
                                        @endif
                                    </td>
                                @endif
                            </tr>

                            {{-- ===== Individual awards =====
                                 A separate row because they are a separate ledger and
                                 publish on their own. A tournament can have its champions
                                 frozen while its MVP is still waiting, and the other way
                                 round. --}}
                            @if ($tournament->tracksPlayers())
                                @php
                                    $frozen = $tournament->playerAwards;
                                    $awardPreview = $awardPreviews[$tournament->id] ?? [];
                                @endphp

                                <tr class="bg-gray-50/70">
                                    <td colspan="{{ $canPublish ? 4 : 3 }}" class="px-5 py-2.5">
                                        <div class="flex items-start gap-2">
                                            <x-admin.icon name="users" class="w-3.5 h-3.5 mt-0.5 shrink-0 text-gray-400" />
                                            <div class="min-w-0">
                                                <span class="text-xs font-bold uppercase tracking-wide text-gray-500">Player Awards</span>

                                                @if ($frozen->isNotEmpty())
                                                    <span class="ml-2 rounded bg-purple-100 px-1.5 py-0.5 text-xs font-semibold text-purple-800">Published</span>
                                                    <span class="block text-xs text-gray-600 mt-1">
                                                        @foreach ($frozen as $award)
                                                            <span class="mr-3">
                                                                {{ $award->award_label }}@if ($award->award_key === 'mvp') {{ $award->rank }}@endif:
                                                                <span class="font-semibold text-gray-900">{{ $award->display_name }}</span>
                                                            </span>
                                                        @endforeach
                                                    </span>
                                                @elseif ($awardPreview !== [])
                                                    <span class="block text-xs text-gray-600 mt-1">
                                                        @foreach ($awardPreview as $award)
                                                            <span class="mr-3">
                                                                {{ $award['award_label'] }}@if ($award['award_key'] === 'mvp') {{ $award['rank'] }}@endif:
                                                                <span class="font-semibold text-gray-900">{{ $award['display_name'] }}</span>
                                                            </span>
                                                        @endforeach
                                                    </span>
                                                @else
                                                    <span class="block text-xs text-gray-400 mt-1">
                                                        No personal scores recorded. Player scoring is optional, so this
                                                        does not hold the podium back.
                                                    </span>
                                                @endif
                                            </div>
                                        </div>
                                    </td>

                                    @if ($canPublish)
                                        <td class="px-5 py-2.5 text-center whitespace-nowrap">
                                            @if ($frozen->isNotEmpty())
                                                <form action="{{ route('admin.tournaments.hall-of-fame.awards.withdraw', $tournament) }}" method="POST"
                                                      onsubmit="return confirm('Take the player awards for {{ addslashes($tournament->name) }} off the public site?');">
                                                    @csrf
                                                    <button type="submit"
                                                            class="rounded-lg border border-red-300 bg-white px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50 transition">
                                                        Withdraw Awards
                                                    </button>
                                                </form>
                                            @elseif ($awardPreview !== [])
                                                <form action="{{ route('admin.tournaments.hall-of-fame.awards.publish', $tournament) }}" method="POST"
                                                      onsubmit="return confirm('Publish these player awards?\n\n{{ collect($awardPreview)->map(fn ($a) => $a['award_label'] . ' ' . $a['rank'] . ': ' . addslashes($a['display_name']))->implode('\n') }}\n\nThey will be copied and frozen. Correcting a score afterwards will NOT change them.');">
                                                    @csrf
                                                    <button type="submit"
                                                            class="inline-flex items-center gap-1.5 rounded-lg border border-purple-600 bg-purple-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-purple-700 transition">
                                                        <x-admin.icon name="users" class="w-3.5 h-3.5" />
                                                        Publish Awards
                                                    </button>
                                                </form>
                                            @else
                                                <span class="text-xs text-gray-400">Nothing to publish</span>
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @endif
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-12 text-center">
                                    <x-admin.icon name="trophy" class="w-10 h-10 mx-auto text-gray-300" />
                                    <p class="text-sm font-semibold text-gray-700 mt-3">Nothing here yet</p>
                                    <p class="text-sm text-gray-500 mt-1 max-w-lg mx-auto">
                                        @if ($activeTab === 'published')
                                            Nothing has been published to the public site.
                                        @else
                                            A tournament reaches this screen once every match is done.
                                        @endif
                                    </p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="px-5 py-3.5 border-t border-gray-200">
                @if ($tournaments->hasPages())
                    {{ $tournaments->links() }}
                @else
                    <p class="text-xs text-gray-500">
                        {{ $tournaments->total() }} {{ Str::plural('tournament', $tournaments->total()) }}
                    </p>
                @endif
            </div>
        </div>
    </x-admin.settings-shell>
@endsection
