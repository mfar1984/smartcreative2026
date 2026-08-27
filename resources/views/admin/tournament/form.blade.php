@extends('layouts.admin')

@section('title', $mode === 'create' ? 'New Tournament' : 'Edit ' . $tournament->name)

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <a href="{{ route('admin.tournaments.index') }}" class="hover:text-gray-700 transition">Tournaments</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">{{ $mode === 'create' ? 'New' : Str::limit($tournament->name, 40) }}</span>
@endsection

@section('content')
    @php
        $input = 'w-full rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';
        $format = old('format', $tournament->format);
    @endphp

    <x-admin.page-card
        :title="$mode === 'create' ? 'New Tournament' : 'Edit ' . $tournament->name"
        description="One competition on one event. Several can run at the same time, so this does not affect any other."
        :back="$tournament->exists ? route('admin.tournaments.show', $tournament) : route('admin.tournaments.index')">

        @if ($errors->any())
            <div role="alert" class="bg-red-50 border border-red-200 rounded-lg p-4 mb-5">
                <p class="text-sm font-bold text-red-900 mb-1">Nothing was saved</p>
                <ul class="text-sm text-red-800 space-y-0.5">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($locked)
            <div role="note" class="flex items-start gap-3 bg-amber-50 border border-amber-200 rounded-lg p-4 mb-5">
                <x-admin.icon name="lock" class="w-5 h-5 mt-0.5 shrink-0 text-amber-600" />
                <p class="text-sm text-amber-800">
                    A draw already exists, so the event, format and scoring are fixed. Changing
                    them would leave fixtures and results meaning something different from what
                    was recorded. Discard the draw first if they have to change.
                </p>
            </div>
        @endif

        <form action="{{ $mode === 'create' ? route('admin.tournaments.store') : route('admin.tournaments.update', $tournament) }}"
              method="POST">
            @csrf
            @if ($mode === 'edit') @method('PUT') @endif

            <x-admin.panel title="What And Where" icon="clipboard">
                <x-admin.field-row label="Event" help="Whose entries compete. Only events with registrations are listed." for="event_id" :required="true" error="event_id">
                    @if ($locked)
                        <input type="hidden" name="event_id" value="{{ $tournament->event_id }}">
                        <p class="text-sm text-gray-900 md:pt-2">{{ $tournament->event?->title }}</p>
                    @else
                        <select id="event_id" name="event_id" required class="{{ $input }} bg-white">
                            <option value="">Choose an event</option>
                            @foreach ($events as $id => $title)
                                <option value="{{ $id }}" @selected((string) old('event_id', $tournament->event_id) === (string) $id)>{{ $title }}</option>
                            @endforeach
                        </select>
                    @endif
                </x-admin.field-row>

                <x-admin.field-row label="Name" help="How you tell it apart from the others on the same event, such as Main Event or Ladies Division." for="name" :required="true" error="name">
                    <input type="text" id="name" name="name" required maxlength="190"
                           value="{{ old('name', $tournament->name) }}"
                           placeholder="e.g. Main Event"
                           class="{{ $input }}">
                </x-admin.field-row>
            </x-admin.panel>

            <x-admin.panel title="How It Is Played" icon="grid">
                <x-admin.field-row label="Format" help="Decides what the draw looks like and how a result is recorded." for="format" :required="true" error="format">
                    @if ($locked)
                        <input type="hidden" name="format" value="{{ $tournament->format }}">
                        <p class="text-sm text-gray-900 md:pt-2">{{ $tournament->formatLabel() }}</p>
                        <p class="text-xs text-gray-500 mt-0.5">{{ $tournament->formatNote() }}</p>
                    @else
                        <select id="format" name="format" required class="{{ $input }} bg-white" data-format>
                            @foreach ($formats as $value => $label)
                                <option value="{{ $value }}" @selected($format === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="text-xs text-gray-500 mt-1.5" data-format-note>
                            {{ $formatNotes[$format] ?? '' }}
                        </p>
                    @endif
                </x-admin.field-row>

                <x-admin.field-row label="Scoring" help="A reusable point rule. It has to match the format." for="point_rule_id" :required="true" error="point_rule_id">
                    @if ($locked)
                        <input type="hidden" name="point_rule_id" value="{{ $tournament->point_rule_id }}">
                        <p class="text-sm text-gray-900 md:pt-2">{{ $tournament->pointRule?->name }}</p>
                    @else
                        <select id="point_rule_id" name="point_rule_id" required class="{{ $input }} bg-white" data-rule>
                            @foreach ($rulesByKind as $kind => $rules)
                                @foreach ($rules as $id => $name)
                                    <option value="{{ $id }}" data-kind="{{ $kind }}"
                                        @selected((string) old('point_rule_id', $tournament->point_rule_id) === (string) $id)>
                                        {{ $name }}
                                    </option>
                                @endforeach
                            @endforeach
                        </select>
                        <p class="text-xs text-gray-500 mt-1.5" data-rule-note></p>
                        <p class="text-xs text-gray-500 mt-1">
                            Nothing suitable listed?
                            <a href="{{ route('admin.tournaments.rules') }}" class="underline font-semibold">Create a point rule</a>
                            first.
                        </p>
                    @endif
                </x-admin.field-row>

                <x-admin.field-row label="Seeding" help="How the draw order is decided. Can be changed until the draw is generated." for="seeding_method" :required="true" error="seeding_method">
                    <select id="seeding_method" name="seeding_method" required class="{{ $input }} bg-white">
                        @foreach ($seedingMethods as $value => $label)
                            <option value="{{ $value }}" @selected(old('seeding_method', $tournament->seeding_method) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </x-admin.field-row>
            </x-admin.panel>

            <div class="flex flex-wrap items-center justify-between gap-4 bg-white rounded-lg border border-gray-200 px-5 py-4 mt-5">
                <p class="text-xs text-gray-500 max-w-md">
                    Saving creates it in Setup. Nothing is drawn and nobody is entered until you
                    do it on the next screen.
                </p>
                <button type="submit"
                        class="rounded-lg border border-blue-600 bg-blue-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition shadow-sm shrink-0">
                    {{ $mode === 'create' ? 'Create Tournament' : 'Save Changes' }}
                </button>
            </div>
        </form>
    </x-admin.page-card>
@endsection

@push('scripts')
<script>
    (function () {
        const format = document.querySelector('[data-format]');
        const rule = document.querySelector('[data-rule]');
        const formatNote = document.querySelector('[data-format-note]');
        const ruleNote = document.querySelector('[data-rule-note]');

        if (!format || !rule) {
            return;
        }

        const notes = @json($formatNotes);
        const kindForFormat = @json($kindForFormat);
        const kindLabels = @json(\App\Models\PointRule::KINDS);

        /*
         | Narrow the scoring list to the family the chosen format needs, rather than
         | letting somebody pick one that will be refused on save. The refusal on the
         | server stays in place regardless: this is a courtesy, not the guard.
         */
        function syncRules() {
            const needed = kindForFormat[format.value];
            let firstMatch = null;
            let matches = 0;

            Array.from(rule.options).forEach(function (option) {
                const fits = option.dataset.kind === needed;
                option.hidden = !fits;
                option.disabled = !fits;

                if (fits) {
                    matches++;
                    firstMatch = firstMatch || option;
                }
            });

            if (rule.selectedOptions[0]?.disabled) {
                rule.value = firstMatch ? firstMatch.value : '';
            }

            if (ruleNote) {
                ruleNote.textContent = matches === 0
                    ? 'No ' + (kindLabels[needed] || needed) + ' point rule exists yet. Create one before saving.'
                    : 'Showing ' + (kindLabels[needed] || needed) + ' rules, which is what this format needs.';
                ruleNote.className = matches === 0
                    ? 'text-xs text-amber-700 font-semibold mt-1.5'
                    : 'text-xs text-gray-500 mt-1.5';
            }
        }

        function syncNote() {
            if (formatNote) {
                formatNote.textContent = notes[format.value] || '';
            }
        }

        format.addEventListener('change', function () {
            syncNote();
            syncRules();
        });

        syncNote();
        syncRules();
    })();
</script>
@endpush
