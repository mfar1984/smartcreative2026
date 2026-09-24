@extends('layouts.admin')

@section('title', 'Move ' . $registration->reference)

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>Event</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <a href="{{ route('admin.event.participants') }}" class="hover:text-gray-700 transition">Participants</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <a href="{{ route('admin.event.participants.show', $registration) }}" class="hover:text-gray-700 transition">{{ $registration->reference }}</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">Move</span>
@endsection

@section('content')
    @php
        use App\Support\ParticipantOptions;

        $input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 bg-white focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';
        $lbl = 'block text-xs font-semibold text-gray-700 mb-1';

        $fromFee = (float) ($from?->registrationAmount() ?? 0);
        $targetFee = (float) ($target?->registrationAmount() ?? 0);

        // The questions the target asks, split so the compulsory ones can be said to
        // be compulsory rather than left to be inferred from an asterisk.
        $questions = $target?->questions ?? collect();

        // Nothing can be submitted while either of these is true, so the confirm
        // button is withheld rather than offered and then refused.
        $unfixable = $blocked !== null;
    @endphp

    <x-admin.page-card
        title="Move this entry to another event"
        :description="$registration->reference . ' · ' . $registration->displayName()"
        :back="route('admin.event.participants.show', $registration)">

        @include('admin.partials.flash')

        @if ($blocked)
            <div role="alert" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3.5 mb-5">
                <p class="text-sm font-semibold text-red-900 mb-0.5">This entry cannot be moved</p>
                <p class="text-sm text-red-800">{{ $blocked }}</p>
            </div>
        @endif

        {{-- ---------------- Where it is now ---------------- --}}
        <x-admin.panel title="Where it is now" icon="clipboard">
            <div class="px-5 py-4 grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <p class="text-xs text-gray-500">Event</p>
                    <p class="text-sm font-semibold text-gray-900">{{ $from?->title ?? '—' }}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-500">On the entry</p>
                    <p class="text-sm font-semibold text-gray-900">
                        {{ $registration->participants->count() }}
                        {{ $registration->participants->count() === 1 ? 'person' : 'people' }},
                        {{ $playing }} playing
                    </p>
                    <p class="text-xs text-gray-400 mt-0.5">A manager who does not play holds no playing place.</p>
                </div>
                <div>
                    <p class="text-xs text-gray-500">Fee now</p>
                    <p class="text-sm font-semibold text-gray-900">
                        {{ $fromFee > 0 ? 'RM ' . number_format($fromFee, 2) : 'Free' }}
                    </p>
                </div>
            </div>
        </x-admin.panel>

        {{-- ---------------- Choose the event ---------------- --}}
        <x-admin.panel title="Move to" icon="identification">
            @if ($targets->isEmpty())
                <p class="px-5 py-6 text-sm text-gray-500">
                    There is no other open event that takes
                    {{ $registration->mode === \App\Models\Event::MODE_MANAGER ? 'squad' : 'individual' }}
                    entries, so there is nowhere for this one to go.
                </p>
            @else
                {{-- A plain GET, so choosing an event reloads this page with that
                     event's own limits, fee and questions on screen. The details
                     cannot be worked out before the event is known. --}}
                <form action="{{ route('admin.event.participants.transfer', $registration) }}" method="GET"
                      class="px-5 py-4 flex flex-wrap items-end gap-3">
                    <div class="grow min-w-64">
                        <label for="event" class="{{ $lbl }}">Event</label>
                        <select id="event" name="event" class="{{ $input }}">
                            <option value="">Choose an event</option>
                            @foreach ($targets as $option)
                                <option value="{{ $option->id }}" @selected($target?->id === $option->id)>
                                    {{ $option->title }}
                                    &mdash;
                                    @if ($option->max_players)
                                        {{ $option->min_players ?? 1 }}&ndash;{{ $option->max_players }} players
                                    @else
                                        from {{ $option->min_players ?? 1 }} players
                                    @endif
                                    &middot; {{ (float) $option->registrationAmount() > 0 ? 'RM ' . number_format((float) $option->registrationAmount(), 2) : 'Free' }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <button type="submit"
                            class="rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                        Show details
                    </button>
                </form>
            @endif
        </x-admin.panel>

        @if ($target)
            <form action="{{ route('admin.event.participants.transfer.save', $registration) }}" method="POST">
                @csrf
                <input type="hidden" name="event_id" value="{{ $target->id }}">

                @error('event_id')
                    <div role="alert" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3.5 mb-5">
                        <p class="text-sm text-red-800">{{ $message }}</p>
                    </div>
                @enderror

                {{-- ---------------- Money ---------------- --}}
                <x-admin.panel title="What it will cost" icon="credit-card">
                    <div class="px-5 py-4">
                        @if ($targetFee <= 0)
                            <p class="text-sm text-gray-700">
                                {{ $target->title }} is free of charge, so nothing will be owed after the move.
                            </p>
                        @else
                            <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                                <span class="text-sm text-gray-500">Fee on {{ $target->title }}</span>
                                <span class="text-base font-bold text-gray-900 tabular-nums">RM {{ number_format($targetFee, 2) }}</span>
                            </div>

                            <p class="text-sm text-amber-800 mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3.5 py-3">
                                @if ($fromFee <= 0)
                                    This entry arrived free of charge. After the move it will owe
                                    RM {{ number_format($targetFee, 2) }}, and a request to pay will be emailed to
                                    {{ $registration->displayName() }} as soon as this is saved.
                                @else
                                    The fee becomes {{ $target->title }}'s. After the move the entry will owe
                                    RM {{ number_format($targetFee, 2) }}, and a request to pay will be emailed to
                                    {{ $registration->displayName() }} as soon as this is saved.
                                @endif
                            </p>
                        @endif

                        <p class="text-xs text-gray-500 mt-3 leading-relaxed">
                            The entry fee is charged once per entry, not per person, so adding or leaving
                            behind people does not change the figure.
                        </p>
                    </div>
                </x-admin.panel>

                {{-- ---------------- Who goes ---------------- --}}
                <x-admin.panel title="Who goes" icon="users">
                    <div class="px-5 py-4 border-b border-gray-100">
                        <p class="text-sm text-gray-700">
                            {{ $target->title }} takes
                            @if ($maxPlayers)
                                at least <span class="font-semibold">{{ $minPlayers }}</span> players
                                and at most <span class="font-semibold">{{ $maxPlayers }}</span>.
                            @else
                                at least <span class="font-semibold">{{ $minPlayers }}</span> players, with no upper limit.
                            @endif
                            This entry has <span class="font-semibold">{{ $playing }}</span>.
                        </p>

                        @if ($mustDrop > 0)
                            <p class="text-sm text-amber-800 mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3.5 py-2.5">
                                {{ $mustDrop }} too many. Tick at least {{ $mustDrop }}
                                {{ $mustDrop === 1 ? 'person' : 'people' }} to leave behind.
                                @if ($minPlayers < $maxPlayers)
                                    You may leave behind up to {{ $playing - $minPlayers }} if you want a smaller squad.
                                @endif
                            </p>
                        @elseif ($mustAdd > 0)
                            <p class="text-sm text-amber-800 mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3.5 py-2.5">
                                {{ $mustAdd }} short of the minimum. Enter {{ $mustAdd }} more
                                {{ $mustAdd === 1 ? 'person' : 'people' }} below.
                                @if ($addSlots > $mustAdd)
                                    The remaining {{ $addSlots - $mustAdd }} {{ $addSlots - $mustAdd === 1 ? 'place is' : 'places are' }} optional.
                                @endif
                            </p>
                        @else
                            <p class="text-sm text-green-800 mt-2 rounded-lg border border-green-200 bg-green-50 px-3.5 py-2.5">
                                This entry already fits. Nobody has to be added or left behind.
                            </p>
                        @endif

                        @error('drop') <p class="text-sm text-red-600 mt-2">{{ $message }}</p> @enderror
                        @error('add') <p class="text-sm text-red-600 mt-2">{{ $message }}</p> @enderror
                    </div>

                    @foreach ($registration->participants as $person)
                        @php
                            $person->setRelation('registration', $registration);
                            $cannotDrop = $person->removalBlockedReason();
                            $isDropped = in_array((string) $person->id, array_map('strval', (array) old('drop', [])), true);
                        @endphp

                        <div class="px-5 py-4 {{ $loop->first ? '' : 'border-t border-gray-100' }}">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-gray-900">
                                        {{ $person->full_name }}
                                        <span class="text-xs font-normal text-gray-500">&middot; {{ $person->roleLabel() }}</span>
                                    </p>
                                    <p class="text-xs text-gray-500 mt-0.5 tabular-nums">{{ $person->ic_number }}</p>
                                </div>

                                @if ($cannotDrop === null)
                                    <label class="flex items-center gap-2 shrink-0 cursor-pointer">
                                        <input type="checkbox" name="drop[]" value="{{ $person->id }}"
                                               data-drop-box
                                               @checked($isDropped)
                                               class="rounded border-gray-300 text-red-600 focus:ring-red-500/40">
                                        <span class="text-xs font-semibold text-red-700">Leave behind</span>
                                    </label>
                                @else
                                    {{-- Said rather than hidden, so somebody looking for
                                         the box is told why it is not there. --}}
                                    <span class="text-xs text-gray-400 shrink-0 max-w-64 text-right">{{ $cannotDrop }}</span>
                                @endif
                            </div>

                            {{-- The target's questions, for this person. Only drawn for
                                 people who are going: an answer for somebody left behind
                                 would be recorded against a row about to be deleted. --}}
                            @if ($questions->isNotEmpty())
                                <div data-questions-for="{{ $person->id }}" class="mt-3 space-y-2 border-l-2 border-gray-200 pl-3">
                                    @foreach ($questions as $question)
                                        <label class="flex items-start gap-2 cursor-pointer">
                                            <input type="hidden" name="answers[{{ $person->id }}][{{ $question->id }}]" value="0">
                                            <input type="checkbox" name="answers[{{ $person->id }}][{{ $question->id }}]" value="1"
                                                   @checked(old('answers.' . $person->id . '.' . $question->id))
                                                   class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500/40">
                                            <span class="text-xs text-gray-700">
                                                {{ $question->title }}
                                                @if ($question->is_required)
                                                    <span class="text-red-600" aria-hidden="true">*</span>
                                                    <span class="text-gray-400">(compulsory)</span>
                                                @endif
                                                @if (filled($question->body))
                                                    <span class="block text-gray-500 mt-0.5 whitespace-pre-line leading-relaxed">{{ $question->body }}</span>
                                                @endif
                                            </span>
                                        </label>
                                        @error('answers.' . $person->id . '.' . $question->id)
                                            <p class="text-xs text-red-600">{{ $message }}</p>
                                        @enderror
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                </x-admin.panel>

                {{-- ---------------- People being brought in ---------------- --}}
                @if ($addSlots > 0)
                    <x-admin.panel title="People being added" icon="users">
                        <div class="px-5 py-4 border-b border-gray-100">
                            <p class="text-sm text-gray-700">
                                @if ($mustAdd > 0)
                                    The first {{ $mustAdd }} {{ $mustAdd === 1 ? 'place has' : 'places have' }} to be filled
                                    to reach {{ $target->title }}'s minimum. Leave the rest blank if they are not needed.
                                @else
                                    {{ $target->title }} has room for {{ $addSlots }} more
                                    {{ $addSlots === 1 ? 'player' : 'players' }}. Leave these blank if nobody is joining.
                                @endif
                            </p>
                            <p class="text-xs text-gray-500 mt-1.5">
                                Anybody added here joins as a player. Name, identity card, telephone and
                                email are needed; the rest can be filled in later on their own record.
                            </p>
                        </div>

                        @for ($i = 0; $i < $addSlots; $i++)
                            <div class="px-5 py-4 {{ $i === 0 ? '' : 'border-t border-gray-100' }}">
                                <p class="text-xs font-bold uppercase tracking-wide text-gray-500 mb-3">
                                    Player {{ $i + 1 }}
                                    @if ($i < $mustAdd)
                                        <span class="text-red-600" aria-hidden="true">*</span>
                                        <span class="font-normal normal-case tracking-normal text-gray-400">required</span>
                                    @else
                                        <span class="font-normal normal-case tracking-normal text-gray-400">optional</span>
                                    @endif
                                </p>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div class="sm:col-span-2">
                                        <label for="add_{{ $i }}_full_name" class="{{ $lbl }}">Full name</label>
                                        <input type="text" id="add_{{ $i }}_full_name" name="add[{{ $i }}][full_name]"
                                               value="{{ old('add.' . $i . '.full_name') }}" class="{{ $input }}">
                                        @error('add.' . $i . '.full_name') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                                    </div>

                                    <div>
                                        <label for="add_{{ $i }}_ic_number" class="{{ $lbl }}">Identity card</label>
                                        <input type="text" id="add_{{ $i }}_ic_number" name="add[{{ $i }}][ic_number]"
                                               value="{{ old('add.' . $i . '.ic_number') }}" class="{{ $input }} tabular-nums">
                                        @error('add.' . $i . '.ic_number') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                                    </div>

                                    <div>
                                        <label for="add_{{ $i }}_date_of_birth" class="{{ $lbl }}">Date of birth</label>
                                        <input type="date" id="add_{{ $i }}_date_of_birth" name="add[{{ $i }}][date_of_birth]"
                                               value="{{ old('add.' . $i . '.date_of_birth') }}" class="{{ $input }}">
                                        @error('add.' . $i . '.date_of_birth') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                                    </div>

                                    <div>
                                        <label for="add_{{ $i }}_phone" class="{{ $lbl }}">Telephone</label>
                                        <input type="text" id="add_{{ $i }}_phone" name="add[{{ $i }}][phone]"
                                               value="{{ old('add.' . $i . '.phone') }}" class="{{ $input }}">
                                        @error('add.' . $i . '.phone') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                                    </div>

                                    <div>
                                        <label for="add_{{ $i }}_email" class="{{ $lbl }}">Email</label>
                                        <input type="email" id="add_{{ $i }}_email" name="add[{{ $i }}][email]"
                                               value="{{ old('add.' . $i . '.email') }}" class="{{ $input }}">
                                        @error('add.' . $i . '.email') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                                    </div>

                                    {{-- Only the game account fields the target asks for. --}}
                                    @foreach ($target->ignFieldsAsked() as $field => $ignLabel)
                                        <div>
                                            <label for="add_{{ $i }}_{{ $field }}" class="{{ $lbl }}">
                                                {{ $ignLabel }}
                                                @if ($target->requiresIgnField($field))
                                                    <span class="text-red-600" aria-hidden="true">*</span>
                                                @endif
                                            </label>
                                            <input type="text" id="add_{{ $i }}_{{ $field }}" name="add[{{ $i }}][{{ $field }}]"
                                                   value="{{ old('add.' . $i . '.' . $field) }}" class="{{ $input }}">
                                            @error('add.' . $i . '.' . $field) <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                                        </div>
                                    @endforeach

                                    <div>
                                        <label for="add_{{ $i }}_gender" class="{{ $lbl }}">Gender</label>
                                        <select id="add_{{ $i }}_gender" name="add[{{ $i }}][gender]" class="{{ $input }}">
                                            <option value="">Not recorded</option>
                                            @foreach (ParticipantOptions::GENDERS as $key => $text)
                                                <option value="{{ $key }}" @selected(old('add.' . $i . '.gender') === $key)>{{ $text }}</option>
                                            @endforeach
                                        </select>
                                    </div>

                                    <div>
                                        <label for="add_{{ $i }}_race" class="{{ $lbl }}">Race</label>
                                        <select id="add_{{ $i }}_race" name="add[{{ $i }}][race]" class="{{ $input }}">
                                            <option value="">Not recorded</option>
                                            @foreach (ParticipantOptions::RACES as $key => $text)
                                                <option value="{{ $key }}" @selected(old('add.' . $i . '.race') === $key)>{{ $text }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>

                                @if ($questions->isNotEmpty())
                                    <div class="mt-3 space-y-2 border-l-2 border-gray-200 pl-3">
                                        @foreach ($questions as $question)
                                            <label class="flex items-start gap-2 cursor-pointer">
                                                <input type="hidden" name="add[{{ $i }}][answers][{{ $question->id }}]" value="0">
                                                <input type="checkbox" name="add[{{ $i }}][answers][{{ $question->id }}]" value="1"
                                                       @checked(old('add.' . $i . '.answers.' . $question->id))
                                                       class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500/40">
                                                <span class="text-xs text-gray-700">
                                                    {{ $question->title }}
                                                    @if ($question->is_required)
                                                        <span class="text-red-600" aria-hidden="true">*</span>
                                                        <span class="text-gray-400">(compulsory)</span>
                                                    @endif
                                                </span>
                                            </label>
                                            @error('add.' . $i . '.answers.' . $question->id)
                                                <p class="text-xs text-red-600">{{ $message }}</p>
                                            @enderror
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endfor
                    </x-admin.panel>
                @endif

                {{-- ---------------- What does not come with it ---------------- --}}
                <x-admin.panel title="What does not come with it" icon="archive">
                    <div class="px-5 py-4">
                        <ul class="text-sm text-gray-700 space-y-1 list-disc list-inside">
                            <li>
                                Extras chosen, including shirt sizes
                                @if ($registration->addonLines->isNotEmpty())
                                    ({{ $registration->addonLines->count() }}
                                    {{ $registration->addonLines->count() === 1 ? 'line' : 'lines' }})
                                @endif
                                . {{ $target->title }} sells its own.
                            </li>
                            <li>Answers to the old event's questions. They are replaced by the ones above.</li>
                            <li>Anybody left behind. Their record is deleted and a change row is written.</li>
                        </ul>

                        @if ($questions->isNotEmpty())
                            <p class="text-xs text-gray-500 mt-3 leading-relaxed border-t border-gray-100 pt-3">
                                Ticking a compulsory question above records that the person agreed to it.
                                You are doing that on their behalf, and the log will show who pressed Move.
                            </p>
                        @endif
                    </div>
                </x-admin.panel>

                <div class="flex items-center justify-end gap-3">
                    <a href="{{ route('admin.event.participants.show', $registration) }}"
                       class="rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                        Cancel
                    </a>

                    @unless ($unfixable)
                        <button type="submit"
                                class="rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition shadow-sm">
                            Move to {{ $target->title }}
                        </button>
                    @endunless
                </div>
            </form>
        @endif
    </x-admin.page-card>
@endsection

@push('scripts')
<script>
    /*
     | Dims the question block of anybody ticked to be left behind.
     |
     | Cosmetic only. Their answers are never written either way: the server writes
     | answers for the people who are going, and a row about to be deleted is not
     | one of them. This only stops an operator filling in boxes for somebody they
     | have just decided is not coming.
     */
    (function () {
        const boxes = Array.from(document.querySelectorAll('[data-drop-box]'));

        if (boxes.length === 0) {
            return;
        }

        boxes.forEach(function (box) {
            const questions = document.querySelector('[data-questions-for="' + box.value + '"]');

            if (!questions) {
                return;
            }

            function sync() {
                questions.classList.toggle('opacity-40', box.checked);

                questions.querySelectorAll('input[type=checkbox]').forEach(function (input) {
                    input.disabled = box.checked;
                });
            }

            box.addEventListener('change', sync);
            sync();
        });
    })();
</script>
@endpush
