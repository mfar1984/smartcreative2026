{{--
    One person in the entry open at the counter.

    The name and card number are set large and in a monospaced face, because the
    only job here is comparing them against the card in the operator's hand.

    @param \App\Models\EventParticipant $participant
    @param \App\Models\EventRegistration $registration
    @param bool $canUpdate
    @param array $genders
    @param array $races
--}}
@php
    $attendance = $participant->attendance;
    $isIn = $attendance !== null;
    $swapReason = $participant->swapBlockedReason();
    $removalReason = $participant->removalBlockedReason();
    $wasSwapped = $participant->changes->isNotEmpty();

    $swapInput = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';
@endphp

<li @class(['px-5 py-4', 'bg-green-50/40' => $isIn])>
    <div class="flex flex-wrap items-start justify-between gap-4">

        {{-- Identity, sized for reading off a card --}}
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2 mb-1">
                <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide text-gray-600">
                    {{ $participant->roleLabel() }}
                </span>

                @if ($wasSwapped)
                    @php $latestChange = $participant->changes->first(); @endphp
                    <span @class([
                              'inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold',
                              'bg-amber-100 text-amber-800' => $latestChange->isTransfer(),
                              'bg-purple-100 text-purple-800' => ! $latestChange->isTransfer(),
                          ])
                          title="{{ $latestChange->isTransfer()
                              ? 'Moved here from ' . $latestChange->fromLabel() . ' at the counter'
                              : 'This place was handed to a different person at the counter' }}">
                        {{ $latestChange->typeLabel() }}
                    </span>
                @endif

                @if ($isIn && ! $attendance->ic_verified)
                    <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-800">
                        No card produced
                    </span>
                @endif
            </div>

            <p class="text-base font-bold text-gray-900 leading-snug">{{ $participant->full_name }}</p>

            <p class="mt-0.5 font-mono text-lg font-bold tracking-wider text-blue-800 tabular-nums">
                {{ $participant->ic_number }}
            </p>

            {{-- The counter checks this against the account on the player's
                 handset, so it sits with the card number rather than in a
                 details panel. --}}
            @if ($registration->event?->requiresIgn() || $participant->hasIgn())
                <p class="mt-1 text-sm">
                    <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">In-Game</span>
                    @if ($participant->hasIgn())
                        <span class="font-mono font-bold text-gray-900">{{ $participant->ign_player_id ?: '—' }}</span>
                        <span class="text-gray-400 mx-1">on</span>
                        <span class="font-semibold text-gray-900">{{ $participant->ign_server_id ?: '—' }}</span>
                    @else
                        <span class="font-semibold text-amber-700">Not recorded</span>
                    @endif
                </p>
            @endif

            <p class="text-xs text-gray-500 mt-1">
                {{ $participant->phone ?: 'No phone' }}
                @if (filled($participant->email))
                    <span class="text-gray-300 mx-1">&middot;</span>{{ $participant->email }}
                @endif
                @if ($participant->date_of_birth)
                    <span class="text-gray-300 mx-1">&middot;</span>{{ $participant->genderLabel() }}, {{ $participant->age() }}
                @endif
            </p>
        </div>

        {{-- State and actions --}}
        <div class="shrink-0 text-right">
            @if ($isIn)
                <p class="inline-flex items-center gap-1.5 text-sm font-bold text-green-700">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    Checked in
                </p>
                <p class="text-xs text-gray-500 mt-0.5">
                    {{ $attendance->checked_in_at?->format('g:i a, d M') }}
                    <span class="block">by {{ $attendance->recordedByName() }}</span>
                </p>

                @if (filled($attendance->notes))
                    <p class="text-xs text-gray-600 mt-1 max-w-56 text-right italic">"{{ $attendance->notes }}"</p>
                @endif

                @if ($canUpdate)
                    <form action="{{ route('admin.event.attendance.undo-check-in', $participant) }}" method="POST" class="mt-2">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                                onclick="return confirm('Undo the check-in for {{ addslashes($participant->full_name) }}?')"
                                class="text-xs font-semibold text-red-600 hover:underline">
                            Undo check-in
                        </button>
                    </form>
                @endif
            @elseif ($canUpdate)
                {{-- Two submits on one form, so "card checked" and "no card
                     produced" are both one press and neither is a default the
                     operator did not choose. --}}
                <form action="{{ route('admin.event.attendance.check-in', $participant) }}" method="POST">
                    @csrf

                    <button type="submit" name="ic_verified" value="1"
                            class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition shadow-sm">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                        Card Checked, Check In
                    </button>

                    <div class="mt-1.5">
                        <button type="submit" name="ic_verified" value="0"
                                onclick="return confirm('Check {{ addslashes($participant->full_name) }} in without seeing their identity card? This is recorded.')"
                                class="text-xs font-semibold text-amber-700 hover:underline">
                            Check in without a card
                        </button>
                    </div>

                    {{-- Kept folded so the quick path stays one press. --}}
                    <details class="mt-2 text-left">
                        <summary class="cursor-pointer text-xs font-semibold text-gray-500 hover:text-gray-800">
                            Add a note
                        </summary>
                        <input type="text" name="notes" maxlength="255"
                               value="{{ old('notes') }}"
                               placeholder="e.g. arrived late, card expired"
                               class="{{ $swapInput }} mt-1.5 w-56">
                    </details>
                </form>
            @else
                <p class="text-sm font-semibold text-gray-400">Not arrived</p>
            @endif
        </div>
    </div>

    {{-- ---------------- Substitution ---------------- --}}
    @if ($canUpdate)
        <div class="mt-3 pt-3 border-t border-gray-100">
            {{-- The two things a manager tells a counter about a player who is not
                 in front of them: somebody else is taking the place, or nobody is.
                 Kept side by side because they are the same conversation. --}}
            <div class="flex flex-wrap items-start justify-between gap-3">
                @if ($swapReason !== null)
                    <p class="text-xs text-gray-400 max-w-80">{{ $swapReason }}</p>
                @endif

                @if ($canRemove && $removalReason === null)
                    <form action="{{ route('admin.event.attendance.remove-player', $participant) }}" method="POST"
                          class="ml-auto"
                          onsubmit="return confirm('Remove {{ addslashes($participant->full_name) }} from {{ addslashes($registration->displayName()) }}?\n\nNobody takes their place, so the team plays a player short. This cannot be undone, though the removal is recorded under Player Change.');">
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="reason" value="Not attending, reported at the counter">
                        <button type="submit"
                                class="inline-flex items-center gap-1.5 text-xs font-semibold text-red-600 hover:text-red-800 transition">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                            </svg>
                            Not playing, remove
                        </button>
                    </form>
                @elseif ($canRemove)
                    <p class="ml-auto text-xs text-gray-400 max-w-72 text-right">{{ $removalReason }}</p>
                @endif
            </div>

            @if ($swapReason === null)
                <details class="mt-1" @if ($errors->any() && old('_swap_for') == $participant->id) open @endif>
                    <summary class="cursor-pointer inline-flex items-center gap-1.5 text-xs font-semibold text-purple-700 hover:text-purple-900">
                        <x-admin.icon name="users" class="w-3.5 h-3.5" />
                        Someone else is taking this place
                    </summary>

                    <form action="{{ route('admin.event.attendance.swap', $participant) }}" method="POST"
                          class="mt-3 rounded-lg border border-purple-200 bg-purple-50/50 p-4">
                        @csrf
                        @method('PUT')

                        {{-- Lets the form reopen on the right person after a
                             validation failure. --}}
                        <input type="hidden" name="_swap_for" value="{{ $participant->id }}">

                        <p class="text-xs text-gray-600 mb-3">
                            Replacing <strong>{{ $participant->full_name }}</strong>. Only the name, card
                            number and a contact number are needed; the rest can be filled in later.
                            The change is recorded under Player Change.
                        </p>

                        {{-- Shown when the card just typed turns out to belong to
                             another team in this event. Moving them empties the
                             place they hold there, and that team is not standing at
                             the desk to be asked, so it needs saying out loud and
                             ticking. --}}
                        @if ($errors->has('confirm_transfer') && old('_swap_for') == $participant->id)
                            <div role="alert" class="mb-3 rounded-lg border border-amber-300 bg-amber-50 p-3">
                                <div class="flex items-start gap-2">
                                    <x-admin.icon name="users" class="w-4 h-4 mt-0.5 shrink-0 text-amber-700" />
                                    <div>
                                        <p class="text-xs font-bold text-amber-900 mb-1">This is a transfer, not a substitution</p>
                                        <p class="text-xs text-amber-800">{{ $errors->first('confirm_transfer') }}</p>

                                        <label class="mt-2 flex items-start gap-2 cursor-pointer">
                                            <input type="checkbox" name="confirm_transfer" value="1"
                                                   class="mt-0.5 rounded border-amber-400 text-amber-600 focus:ring-amber-500">
                                            <span class="text-xs font-semibold text-amber-900">
                                                Yes, move them here and leave their old team a player short.
                                            </span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        @endif

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label for="swap-name-{{ $participant->id }}" class="block text-xs font-semibold text-gray-700 mb-1">
                                    Name on Card <span class="text-red-600" aria-hidden="true">*</span>
                                </label>
                                <input type="text" id="swap-name-{{ $participant->id }}" name="full_name" maxlength="180" required
                                       value="{{ old('_swap_for') == $participant->id ? old('full_name') : '' }}"
                                       class="{{ $swapInput }}">
                            </div>

                            <div>
                                <label for="swap-ic-{{ $participant->id }}" class="block text-xs font-semibold text-gray-700 mb-1">
                                    Identity Card <span class="text-red-600" aria-hidden="true">*</span>
                                </label>
                                <input type="text" id="swap-ic-{{ $participant->id }}" name="ic_number" maxlength="32" required
                                       inputmode="numeric"
                                       value="{{ old('_swap_for') == $participant->id ? old('ic_number') : '' }}"
                                       class="{{ $swapInput }} font-mono">
                            </div>

                            <div>
                                <label for="swap-phone-{{ $participant->id }}" class="block text-xs font-semibold text-gray-700 mb-1">
                                    Telephone <span class="text-red-600" aria-hidden="true">*</span>
                                </label>
                                <input type="tel" id="swap-phone-{{ $participant->id }}" name="phone" maxlength="30" required
                                       value="{{ old('_swap_for') == $participant->id ? old('phone') : '' }}"
                                       class="{{ $swapInput }}">
                            </div>

                            <div>
                                <label for="swap-reason-{{ $participant->id }}" class="block text-xs font-semibold text-gray-700 mb-1">
                                    Reason
                                </label>
                                <input type="text" id="swap-reason-{{ $participant->id }}" name="reason" maxlength="255"
                                       value="{{ old('_swap_for') == $participant->id ? old('reason') : '' }}"
                                       placeholder="e.g. original player injured"
                                       class="{{ $swapInput }}">
                            </div>

                            {{-- The account that was in this place belonged to the
                                 player leaving, so their replacement has to give
                                 their own. Asked here rather than folded away,
                                 because a tournament cannot run without it. --}}
                            @if ($registration->event?->requiresIgn())
                                <div>
                                    <label for="swap-ign-player-{{ $participant->id }}" class="block text-xs font-semibold text-gray-700 mb-1">
                                        Player ID <span class="text-red-600" aria-hidden="true">*</span>
                                    </label>
                                    <input type="text" id="swap-ign-player-{{ $participant->id }}" name="ign_player_id"
                                           maxlength="60" required
                                           value="{{ old('_swap_for') == $participant->id ? old('ign_player_id') : '' }}"
                                           class="{{ $swapInput }} font-mono">
                                </div>

                                <div>
                                    <label for="swap-ign-server-{{ $participant->id }}" class="block text-xs font-semibold text-gray-700 mb-1">
                                        Server ID <span class="text-red-600" aria-hidden="true">*</span>
                                    </label>
                                    <input type="text" id="swap-ign-server-{{ $participant->id }}" name="ign_server_id"
                                           maxlength="60" required
                                           value="{{ old('_swap_for') == $participant->id ? old('ign_server_id') : '' }}"
                                           class="{{ $swapInput }}">
                                </div>
                            @endif
                        </div>

                        <details class="mt-3">
                            <summary class="cursor-pointer text-xs font-semibold text-gray-500 hover:text-gray-800">
                                More details, if there is time
                            </summary>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-2">
                                <div>
                                    <label for="swap-email-{{ $participant->id }}" class="block text-xs font-semibold text-gray-700 mb-1">Email</label>
                                    <input type="email" id="swap-email-{{ $participant->id }}" name="email" maxlength="190"
                                           value="{{ old('_swap_for') == $participant->id ? old('email') : '' }}"
                                           class="{{ $swapInput }}">
                                </div>

                                <div>
                                    <label for="swap-dob-{{ $participant->id }}" class="block text-xs font-semibold text-gray-700 mb-1">Date of Birth</label>
                                    <input type="date" id="swap-dob-{{ $participant->id }}" name="date_of_birth"
                                           value="{{ old('_swap_for') == $participant->id ? old('date_of_birth') : '' }}"
                                           class="{{ $swapInput }}">
                                </div>

                                <div>
                                    <label for="swap-gender-{{ $participant->id }}" class="block text-xs font-semibold text-gray-700 mb-1">Gender</label>
                                    <select id="swap-gender-{{ $participant->id }}" name="gender" class="{{ $swapInput }} bg-white">
                                        <option value="">Not recorded</option>
                                        @foreach ($genders as $key => $label)
                                            <option value="{{ $key }}" @selected(old('_swap_for') == $participant->id && old('gender') === $key)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div>
                                    <label for="swap-race-{{ $participant->id }}" class="block text-xs font-semibold text-gray-700 mb-1">Race</label>
                                    <select id="swap-race-{{ $participant->id }}" name="race" class="{{ $swapInput }} bg-white">
                                        <option value="">Not recorded</option>
                                        @foreach ($races as $key => $label)
                                            <option value="{{ $key }}" @selected(old('_swap_for') == $participant->id && old('race') === $key)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </details>

                        <div class="flex items-center gap-2 mt-4">
                            <button type="submit"
                                    class="rounded-lg bg-purple-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-purple-700 transition shadow-sm">
                                Hand Over This Place
                            </button>
                            <p class="text-xs text-gray-500">
                                The previous name and card number are kept in the audit.
                            </p>
                        </div>
                    </form>
                </details>
            @endif

            {{-- What has already happened to this place, so the counter can see
                 the history without leaving the desk. --}}
            @if ($wasSwapped)
                <ul class="mt-2 space-y-0.5">
                    @foreach ($participant->changes as $change)
                        <li class="text-xs text-gray-500">
                            {{ $change->previous_name }}
                            <span class="text-gray-400">({{ $change->previous_ic }})</span>
                            &rarr;
                            {{ $change->new_name ?? 'nobody' }}
                            @if (filled($change->new_ic))
                                <span class="text-gray-400">({{ $change->new_ic }})</span>
                            @endif

                            @if ($change->isTransfer())
                                <span class="text-amber-700 font-semibold">transferred from {{ $change->fromLabel() }}</span>
                            @endif

                            <span class="text-gray-300 mx-1">&middot;</span>
                            {{ $change->created_at?->format('g:i a, d M') }}
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif
</li>
