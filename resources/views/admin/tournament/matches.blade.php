@extends('layouts.admin')

@section('title', 'Matches')

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>Tournament</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">{{ $intro['label'] }}</span>
@endsection

@section('content')
    @php
        use App\Models\TournamentMatch;

        $head = 'px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500';
        $filterInput = 'rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 bg-white focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';
    @endphp

    <x-admin.settings-shell
        title="Matches"
        description="Fixtures to be played, and where results are entered."
        :tabs="$tabs"
        :active-tab="$activeTab"
        :route="$route"
        :route-params="$tournament ? ['tournament' => $tournament->id] : []">

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

            {{-- Which tournament is on screen, said at all times. Several run at once,
                 so a fixture list without a name on it could be any of them. --}}
            @if ($tournament)
                <div class="inline-flex items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-3.5 py-2.5 shrink-0">
                    <x-admin.icon name="trophy" class="w-4 h-4 text-blue-600" />
                    <span class="text-sm font-semibold text-blue-900">{{ $tournament->name }}</span>
                    <span class="text-xs text-blue-700">{{ $tournament->event?->title }}</span>
                </div>
            @endif
        </div>

        @if ($tournaments->isEmpty())
            <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                <div class="px-5 py-12 text-center">
                    <x-admin.icon name="clipboard" class="w-10 h-10 mx-auto text-gray-300" />
                    <p class="text-sm font-semibold text-gray-700 mt-3">No fixtures yet</p>
                    <p class="text-sm text-gray-500 mt-1 max-w-lg mx-auto">
                        Fixtures appear once a tournament's draw has been generated.
                        <a href="{{ route('admin.tournaments.index') }}" class="underline font-semibold">Open Tournaments</a>
                        to set one up.
                    </p>
                </div>
            </div>
        @else
            <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                <x-admin.filter-bar :action="route('admin.tournaments.matches')">
                    <input type="hidden" name="tab" value="{{ $activeTab }}">

                    <label for="tournament" class="sr-only">Tournament</label>
                    <select id="tournament" name="tournament" class="{{ $filterInput }} min-w-56">
                        @foreach ($tournaments as $option)
                            <option value="{{ $option->id }}" @selected($tournament?->id === $option->id)>
                                {{ $option->name }} — {{ $option->event?->title }}
                            </option>
                        @endforeach
                    </select>

                    @if ($stages->isNotEmpty())
                        <label for="stage" class="sr-only">Stage</label>
                        <select id="stage" name="stage" class="{{ $filterInput }}">
                            <option value="">All Stages</option>
                            @foreach ($stages as $stage)
                                <option value="{{ $stage->id }}" @selected($filters['stage'] === (string) $stage->id)>
                                    {{ $stage->name }}
                                </option>
                            @endforeach
                        </select>
                    @endif
                </x-admin.filter-bar>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-left">
                            <tr>
                                <th scope="col" class="{{ $head }}">Match</th>
                                <th scope="col" class="{{ $head }}">Stage</th>
                                <th scope="col" class="{{ $head }}">Who</th>
                                <th scope="col" class="{{ $head }}">Map</th>
                                <th scope="col" class="{{ $head }}">When</th>
                                <th scope="col" class="{{ $head }} text-center">Action</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-100">
                            @forelse ($matches ?? [] as $match)
                                <tr class="hover:bg-blue-50/40 align-top">
                                    <td class="px-5 py-3 whitespace-nowrap">
                                        <span class="font-semibold text-gray-900">{{ $match->label() }}</span>
                                        @if ($match->best_of > 1)
                                            <span class="block text-xs text-gray-500">Best of {{ $match->best_of }}</span>
                                        @endif
                                        @if ($match->resolution)
                                            <span class="block text-xs text-amber-700">{{ Str::headline($match->resolution) }}</span>
                                        @endif
                                    </td>

                                    <td class="px-5 py-3 text-gray-700">
                                        {{ $match->stage?->name }}
                                        @if ($match->group)
                                            <span class="block text-xs text-gray-500">{{ $match->group->name }}</span>
                                        @endif
                                    </td>

                                    <td class="px-5 py-3">
                                        @php $named = $match->entrants->filter(fn ($l) => $l->entrant !== null); @endphp

                                        @if ($named->count() > 4)
                                            <span class="text-gray-700">{{ $named->count() }} competitors</span>
                                        @elseif ($named->isEmpty())
                                            <span class="text-xs text-gray-400">Waiting on an earlier result</span>
                                        @else
                                            @foreach ($named as $line)
                                                <span @class([
                                                    'block text-xs',
                                                    'font-semibold text-green-800' => $match->winner_entrant_id === $line->tournament_entrant_id,
                                                    'text-gray-700' => $match->winner_entrant_id !== $line->tournament_entrant_id,
                                                ])>
                                                    {{ $line->entrant->displayName() }}
                                                    @if ($match->winner_entrant_id === $line->tournament_entrant_id)
                                                        (won)
                                                    @endif
                                                </span>
                                            @endforeach
                                        @endif
                                    </td>

                                    <td class="px-5 py-3 text-xs text-gray-600">
                                        {{ $match->map ?? '—' }}
                                        @if ($canEditFixture)
                                            <button type="button"
                                                    data-open-fixture="{{ $match->id }}"
                                                    class="ml-1 p-1 rounded text-gray-400 hover:bg-blue-50 hover:text-blue-600 transition align-middle"
                                                    title="Change the map or the time for {{ $match->label() }}"
                                                    aria-label="Change the map or the time for {{ $match->label() }}">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                                </svg>
                                            </button>
                                        @endif
                                    </td>

                                    <td class="px-5 py-3 whitespace-nowrap text-xs text-gray-500">
                                        {{ $match->scheduled_at?->format('d M, g:i a') ?? '—' }}
                                    </td>

                                    <td class="px-5 py-3 text-center whitespace-nowrap">
                                        @if ($canScore && $match->isReady())
                                            <div class="inline-flex items-center gap-1.5">
                                                <a href="{{ route('admin.tournaments.matches.score', $match) }}"
                                                   class="inline-flex items-center gap-1.5 rounded-lg border border-blue-300 bg-white px-3 py-1.5 text-xs font-semibold text-blue-700 hover:bg-blue-50 transition">
                                                    {{ $match->isSettled() ? 'Correct' : 'Enter Score' }}
                                                </a>

                                                {{-- Only for a fixture that holds one. Correcting a
                                                     result replaces it; this is for the figure that
                                                     should never have been entered, which used to
                                                     leave the whole tournament frozen: the draw
                                                     cannot be discarded once anything is scored. --}}
                                                @if ($match->isSettled())
                                                    <form action="{{ route('admin.tournaments.matches.score.clear', $match) }}" method="POST"
                                                          onsubmit="return confirm('Clear the result of {{ addslashes($match->label()) }}?\n\nThe fixture goes back to Scheduled and the standings are worked out again without it. This cannot be undone.');">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit"
                                                                class="inline-flex items-center gap-1.5 rounded-lg border border-red-300 bg-white px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50 transition"
                                                                title="Blank this result and put the fixture back to Scheduled">
                                                            Clear
                                                        </button>
                                                    </form>
                                                @endif
                                            </div>
                                        @elseif (! $match->isReady())
                                            <span class="text-xs text-gray-400">Not ready</span>
                                        @else
                                            <span class="text-xs text-gray-300">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-5 py-12 text-center">
                                        <x-admin.icon name="clipboard" class="w-10 h-10 mx-auto text-gray-300" />
                                        <p class="text-sm font-semibold text-gray-700 mt-3">
                                            Nothing {{ strtolower($intro['label']) }}
                                        </p>
                                        <p class="text-sm text-gray-500 mt-1">
                                            Fixtures appear once a tournament's draw has been generated.
                                        </p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="px-5 py-3.5 border-t border-gray-200">
                    @if ($matches && $matches->hasPages())
                        {{ $matches->links() }}
                    @else
                        <p class="text-xs text-gray-500">
                            {{ $matches?->total() ?? 0 }} {{ Str::plural('fixture', $matches?->total() ?? 0) }}
                        </p>
                    @endif
                </div>
            </div>
        @endif

        {{--
            One dialog per fixture, drawn outside the table.

            The table sits in an overflow-x-auto wrapper, and a fixed overlay nested
            inside one gets clipped by it, so these live out here and are matched to
            their button by id.
        --}}
        @if ($canEditFixture && $matches)
            @foreach ($matches as $match)
                <div id="fixture-modal-{{ $match->id }}"
                     data-fixture-modal="{{ $match->id }}"
                     class="fixed inset-0 z-50 overflow-y-auto hidden"
                     role="dialog"
                     aria-modal="true"
                     aria-labelledby="fixture-title-{{ $match->id }}">

                    <div class="fixed inset-0 bg-gray-900/60" data-close-fixture></div>

                    <div class="relative min-h-full flex items-center justify-center p-4">
                        <div class="relative w-full max-w-md bg-white rounded-xl shadow-2xl my-8">

                            <div class="flex items-start justify-between gap-4 px-6 py-4 border-b border-gray-200">
                                <div class="min-w-0">
                                    <h2 id="fixture-title-{{ $match->id }}" class="text-lg font-bold text-gray-900">
                                        {{ $match->label() }}
                                    </h2>
                                    <p class="text-xs text-gray-500 mt-0.5">
                                        {{ $match->stage?->name }}
                                        @if ($match->group)
                                            &middot; {{ $match->group->name }}
                                        @endif
                                    </p>
                                </div>

                                <button type="button" data-close-fixture
                                        class="p-1 rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition shrink-0"
                                        aria-label="Close">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                    </svg>
                                </button>
                            </div>

                            <form action="{{ route('admin.tournaments.matches.fixture.update', $match) }}" method="POST"
                                  class="px-6 py-5 space-y-4">
                                @csrf
                                @method('PUT')

                                <div>
                                    <label for="map-{{ $match->id }}" class="block text-xs font-semibold text-gray-700 mb-1">Map</label>

                                    {{-- A list with a free text box behind it, because the
                                         pool is a convenience and a map that is not on it
                                         still has to be typeable. --}}
                                    <input type="text" id="map-{{ $match->id }}" name="map" maxlength="60"
                                           value="{{ $match->map }}"
                                           list="map-pool"
                                           placeholder="Leave empty for no map"
                                           class="{{ $filterInput }} w-full">

                                    <p class="text-xs text-gray-400 mt-1">
                                        Pick from the pool or type any name. Empty shows a dash on the list.
                                    </p>
                                </div>

                                <div>
                                    <label for="when-{{ $match->id }}" class="block text-xs font-semibold text-gray-700 mb-1">Starts at</label>
                                    <input type="datetime-local" id="when-{{ $match->id }}" name="scheduled_at"
                                           value="{{ $match->scheduled_at?->format('Y-m-d\TH:i') }}"
                                           class="{{ $filterInput }} w-full">
                                    <p class="text-xs text-gray-400 mt-1">
                                        Set by the draw from the event date and the buffer between matches.
                                    </p>
                                </div>

                                @if ($match->isSettled())
                                    <p class="rounded-lg border border-gray-200 bg-gray-50 px-3.5 py-2.5 text-xs text-gray-600">
                                        This fixture has been played. Neither field is part of the result, so
                                        changing them here corrects the record without touching the standings.
                                    </p>
                                @endif

                                <div class="flex items-center justify-end gap-2 pt-1">
                                    <button type="button" data-close-fixture
                                            class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                                        Cancel
                                    </button>
                                    <button type="submit"
                                            class="rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition shadow-sm">
                                        Save
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            @endforeach

            {{-- One shared pool for every dialog on the page. --}}
            <datalist id="map-pool">
                @foreach ($mapPool as $map)
                    <option value="{{ $map }}"></option>
                @endforeach
            </datalist>
        @endif
    </x-admin.settings-shell>
@endsection

@push('scripts')
<script>
    /*
     | Opening and closing the fixture dialogs.
     |
     | One per row, each holding an ordinary PUT form. The script only shows and
     | hides them; nothing about the submission depends on JavaScript.
     */
    (function () {
        const dialogs = Array.from(document.querySelectorAll('[data-fixture-modal]'));

        if (dialogs.length === 0) {
            return;
        }

        function dialogFor(id) {
            return dialogs.find((node) => node.getAttribute('data-fixture-modal') === String(id)) || null;
        }

        function close(dialog) {
            dialog.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }

        function closeAll() {
            dialogs.forEach(close);
        }

        document.querySelectorAll('[data-open-fixture]').forEach(function (trigger) {
            trigger.addEventListener('click', function () {
                const dialog = dialogFor(trigger.getAttribute('data-open-fixture'));

                if (!dialog) {
                    return;
                }

                closeAll();
                dialog.classList.remove('hidden');

                // The scroll lock belongs to whichever dialog is open, and only one
                // can be, so it is set rather than counted.
                document.body.classList.add('overflow-hidden');

                dialog.querySelector('input:not([type=hidden]):not([disabled])')?.focus();
            });
        });

        dialogs.forEach(function (dialog) {
            dialog.querySelectorAll('[data-close-fixture]').forEach(function (trigger) {
                trigger.addEventListener('click', function () {
                    close(dialog);
                });
            });
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeAll();
            }
        });
    })();
</script>
@endpush
