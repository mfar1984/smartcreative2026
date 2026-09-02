@extends('layouts.master')

@section('title', $pageTitle)

@section('content')
    @include('components.page-header', [
        'title' => $pageTitle,
        'subtitle' => $pageSubtitle,
    ])

    {{-- State options are shared by every participant block on the page. --}}
    <datalist id="state-options">
        @foreach ($states as $state)
            <option value="{{ $state }}"></option>
        @endforeach
    </datalist>

    <section class="py-16 bg-gray-50">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="max-w-6xl mx-auto">

                {{-- Confirmation after a successful submission --}}
                @if (session('registration_status'))
                    <div role="alert" class="flex items-start gap-3 bg-green-50 border border-green-200 rounded-lg p-5 mb-10">
                        <svg class="w-6 h-6 shrink-0 text-green-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <div>
                            <p class="text-base font-bold text-green-900 mb-1">Registration received</p>
                            <p class="text-sm text-green-800">{{ session('registration_status') }}</p>
                        </div>
                    </div>
                @endif

                {{-- Errors from a failed submission. The modal reopens below. --}}
                @if ($errors->any())
                    <div role="alert" class="bg-red-50 border border-red-200 rounded-lg p-5 mb-10">
                        <div class="flex items-start gap-3">
                            <svg class="w-6 h-6 shrink-0 text-red-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                            </svg>
                            <div>
                                <p class="text-base font-bold text-red-900 mb-1">Your registration was not submitted</p>
                                <ul class="list-disc list-inside space-y-0.5">
                                    @foreach ($errors->all() as $error)
                                        <li class="text-sm text-red-800">{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    </div>
                @endif

                @php
                    $headings = [
                        'open' => [
                            'title' => 'Open for Registration',
                            'blurb' => 'Select an event below to view the details and complete your registration.',
                            'emptyTitle' => 'No events open right now',
                            'emptyBlurb' => 'There are no events accepting registrations at the moment. Please check back soon.',
                        ],
                        'ongoing' => [
                            'title' => 'Happening Now',
                            'blurb' => 'These events are running today. Registration may still be open for some of them.',
                            'emptyTitle' => 'Nothing running today',
                            'emptyBlurb' => 'No events are in progress at the moment. Check the Open Registration tab for what is coming up.',
                        ],
                        'past' => [
                            'title' => 'Past Events',
                            'blurb' => 'A record of events we have already delivered, most recent first.',
                            'emptyTitle' => 'No past events yet',
                            'emptyBlurb' => 'Once an event finishes it will be listed here.',
                        ],
                    ];

                    $heading = $headings[$activeTab] ?? $headings['open'];
                @endphp

                {{-- Tabs. Plain links carrying ?tab=, so each list has its own
                     URL and works without JavaScript. --}}
                <div class="flex justify-center mb-10">
                    <nav class="inline-flex flex-wrap justify-center gap-1 rounded-full bg-white border border-gray-200 shadow-sm p-1.5"
                         aria-label="Event lists">
                        @foreach ($tabs as $slug => $tab)
                            @php $isActive = $activeTab === $slug; @endphp

                            <a href="{{ route('registration', ['tab' => $slug]) }}"
                               @class([
                                   'inline-flex items-center gap-2 rounded-full px-5 py-2.5 text-sm font-semibold transition whitespace-nowrap',
                                   'bg-blue-600 text-white shadow' => $isActive,
                                   'text-gray-600 hover:bg-gray-100 hover:text-gray-900' => ! $isActive,
                               ])
                               @if ($isActive) aria-current="page" @endif>
                                {{ $tab['label'] }}
                                <span @class([
                                    'rounded-full px-2 py-0.5 text-xs font-bold',
                                    'bg-white/20 text-white' => $isActive,
                                    'bg-gray-100 text-gray-500' => ! $isActive,
                                ])>{{ $tab['count'] }}</span>
                            </a>
                        @endforeach
                    </nav>
                </div>

                <div class="text-center mb-12">
                    <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-3">{{ $heading['title'] }}</h2>
                    <p class="text-base text-gray-600 max-w-2xl mx-auto">{{ $heading['blurb'] }}</p>
                </div>

                @if ($events->isNotEmpty())
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                        @foreach ($events as $event)
                            @include('components.event-card', ['event' => $event])
                        @endforeach
                    </div>
                @else
                    <div class="bg-white rounded-lg border border-gray-200 shadow-sm p-12 text-center">
                        <svg class="w-16 h-16 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                        <h3 class="text-xl font-bold text-gray-900 mb-2">{{ $heading['emptyTitle'] }}</h3>
                        <p class="text-base text-gray-600 mb-6">{{ $heading['emptyBlurb'] }}</p>

                        @if ($activeTab === 'open')
                            <a href="{{ route('contact') }}" class="inline-block bg-blue-600 text-white px-8 py-3 rounded-lg font-semibold hover:bg-blue-700 transition shadow-md">
                                Contact Us
                            </a>
                        @else
                            <a href="{{ route('registration') }}" class="inline-block bg-blue-600 text-white px-8 py-3 rounded-lg font-semibold hover:bg-blue-700 transition shadow-md">
                                See Open Registration
                            </a>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </section>

    {{-- ==================== Registration modals ==================== --}}
    @foreach ($events as $event)
        @continue($event->registrationBlockedReason() !== null)

        @php
            $isOpenModal = $openSlug === $event->slug;

            // On a failed submit the submitted rows are replayed so nothing the
            // visitor typed is lost. Otherwise start with the minimum shape the
            // event's mode requires.
            $submitted = $isOpenModal ? old('participants') : null;

            [$minPlayers, $maxPlayers] = $event->playerBounds();

            if (is_array($submitted) && $submitted !== []) {
                $initialRows = $submitted;
            } elseif ($event->isManagerMode()) {
                // One manager, then the minimum number of players.
                $initialRows = array_merge([[]], array_fill(0, $minPlayers, []));
            } else {
                $initialRows = [[]];
            }
        @endphp

        <div id="registration-modal-{{ $event->slug }}"
             @class(['fixed inset-0 z-50 overflow-y-auto', 'hidden' => ! $isOpenModal])
             role="dialog" aria-modal="true"
             aria-labelledby="registration-title-{{ $event->slug }}"
             data-registration-modal="{{ $event->slug }}">

            <div class="fixed inset-0 bg-gray-900/60" data-close-registration></div>

            <div class="relative min-h-full flex items-start justify-center p-4">
                {{-- Wider when there are rules to show, so the side column does
                     not squeeze the form. Without rules the form is the only
                     thing here and does not need the extra room. --}}
                <div @class([
                    'relative w-full bg-white rounded-xl shadow-2xl my-8',
                    'max-w-6xl' => $event->hasRules(),
                    'max-w-4xl' => ! $event->hasRules(),
                ])>

                    {{-- Modal header --}}
                    <div class="sticky top-0 z-10 flex items-start justify-between gap-4 px-6 py-4 border-b border-gray-200 bg-white rounded-t-xl">
                        <div class="min-w-0">
                            <h2 id="registration-title-{{ $event->slug }}" class="text-lg font-bold text-gray-900">
                                Register for {{ $event->title }}
                            </h2>
                            <p class="text-xs text-gray-500 mt-0.5">
                                {{ $event->starts_at->format('d M Y') }}
                                @unless ($event->starts_at->isSameDay($event->ends_at))
                                    &ndash; {{ $event->ends_at->format('d M Y') }}
                                @endunless
                                &middot; {{ $event->location }}
                                &middot; {{ $event->feeLabel() }}@unless ($event->isFree()) {{ $event->feeBasisLabel() }} @endunless
                            </p>
                        </div>

                        <button type="button" data-close-registration
                                class="p-1 rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition shrink-0"
                                aria-label="Close">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    {{-- Form and rules side by side on a wide screen. The rules
                         come first in the markup so that on a phone, where the
                         two stack, they are read before the form rather than
                         buried under it. Order is flipped back on desktop. --}}
                    <div class="flex flex-col lg:flex-row lg:items-start">

                        @if ($event->hasRules())
                            <aside class="lg:order-2 lg:w-80 shrink-0 border-b lg:border-b-0 lg:border-l border-gray-200 bg-gray-50 lg:rounded-br-xl"
                                   aria-labelledby="rules-title-{{ $event->slug }}">

                                {{-- Sticky under the modal header on desktop, with
                                     its own scroll, so a long rule set never
                                     stretches the dialog. --}}
                                <div class="p-5 lg:sticky lg:top-20 max-h-72 lg:max-h-[60vh] overflow-y-auto">
                                    <h3 id="rules-title-{{ $event->slug }}" class="flex items-center gap-2 text-sm font-bold text-gray-900 mb-3">
                                        <svg class="w-4 h-4 shrink-0 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                        </svg>
                                        Rules
                                    </h3>

                                    <ul class="space-y-2">
                                        @foreach ($event->ruleLines() as $rule)
                                            <li class="flex items-start gap-2 text-xs text-gray-700 leading-relaxed">
                                                <span class="mt-1.5 w-1.5 h-1.5 rounded-full bg-blue-500 shrink-0" aria-hidden="true"></span>
                                                <span>{{ $rule }}</span>
                                            </li>
                                        @endforeach
                                    </ul>

                                    <p class="text-[11px] text-gray-500 mt-4 pt-3 border-t border-gray-200">
                                        Submitting a registration means agreeing to these rules.
                                    </p>
                                </div>
                            </aside>
                        @endif

                    {{-- multipart because an event may ask for a logo. Without it
                         the file silently never arrives. --}}
                    <form action="{{ route('registration.store', $event) }}" method="POST" class="p-6 lg:order-1 min-w-0 flex-1"
                          enctype="multipart/form-data"
                          data-registration-form
                          data-mode="{{ $event->registration_mode }}"
                          data-min-players="{{ $minPlayers }}"
                          data-max-players="{{ $maxPlayers ?? '' }}">
                        @csrf
                        <input type="hidden" name="event_slug" value="{{ $event->slug }}">

                        {{-- How this event works --}}
                        <div role="note" class="flex items-start gap-3 bg-blue-50 border border-blue-200 rounded-lg p-4 mb-5">
                            <svg class="w-5 h-5 shrink-0 text-blue-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <p class="text-sm text-blue-800">
                                @if ($event->isManagerMode())
                                    One manager registers the whole squad. Enter the manager first, then
                                    {{ $minPlayers }}@if ($maxPlayers) to {{ $maxPlayers }}@else or more @endif players.
                                    Every person needs their own details.
                                @else
                                    One person per registration. Anyone else taking part should submit
                                    their own form.
                                @endif
                            </p>
                        </div>

                        {{-- Team name, squad registrations only --}}
                        @if ($event->isManagerMode())
                            <div class="mb-5">
                                <label for="team_name_{{ $event->slug }}" class="block text-sm font-semibold text-gray-700 mb-1.5">
                                    Team / Organisation Name <span class="text-red-600" aria-hidden="true">*</span>
                                </label>
                                <input type="text" id="team_name_{{ $event->slug }}" name="team_name" required maxlength="150"
                                       value="{{ $isOpenModal ? old('team_name') : '' }}"
                                       class="w-full rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
                                @error('team_name')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                            </div>
                        @endif

                        {{-- Logo, one per entry rather than one per person --}}
                        @if ($event->asksLogo())
                            <div class="mb-5">
                                <label for="logo_{{ $event->slug }}" class="block text-sm font-semibold text-gray-700 mb-1.5">
                                    {{ $event->logoLabel() }}
                                    @if ($event->requiresLogo())
                                        <span class="text-red-600" aria-hidden="true">*</span>
                                    @else
                                        <span class="text-xs font-normal text-gray-500">(optional)</span>
                                    @endif
                                </label>

                                <div class="flex flex-wrap items-start gap-4">
                                    <div class="w-24 h-24 shrink-0 rounded-lg border border-gray-200 bg-gray-50 overflow-hidden flex items-center justify-center">
                                        <img data-logo-preview="{{ $event->slug }}" src="" alt="" class="hidden w-full h-full object-contain">
                                        <span data-logo-empty="{{ $event->slug }}" class="text-xs text-gray-400 px-2 text-center">
                                            No image
                                        </span>
                                    </div>

                                    <div class="flex-1 min-w-48">
                                        <input type="file" id="logo_{{ $event->slug }}" name="logo"
                                               @required($event->requiresLogo())
                                               accept="image/jpeg,image/png,image/webp,image/svg+xml"
                                               data-logo-input="{{ $event->slug }}"
                                               class="block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-blue-600 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-blue-700 file:cursor-pointer">

                                        <p class="text-xs text-gray-500 mt-1.5">
                                            JPG, PNG, WebP or SVG up to 2 MB.
                                            @if ($event->isManagerMode())
                                                One image for the whole squad.
                                            @endif
                                        </p>

                                        {{-- Browsers clear file inputs on a failed
                                             submit, so this is said plainly rather
                                             than leaving the visitor to wonder. --}}
                                        @if ($isOpenModal && $errors->any())
                                            <p class="text-xs text-amber-700 mt-1.5 font-semibold">
                                                Please choose the image again. Browsers do not keep a selected
                                                file when a form comes back with errors.
                                            </p>
                                        @endif
                                    </div>
                                </div>

                                @error('logo')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                            </div>
                        @endif

                        {{-- One switch for the whole squad, because a manager filling
                             in six people should not have to answer the same
                             question six times. It only sets the individual boxes,
                             which remain the thing that is submitted: that way one
                             person can still be left out. --}}
                        @if ($event->isManagerMode())
                            <div class="flex items-start gap-2.5 rounded-lg border border-gray-200 bg-gray-50 p-3.5 mb-4">
                                <input type="checkbox" id="consent-all-{{ $event->id }}" data-consent-all
                                       class="mt-0.5 h-4 w-4 shrink-0 rounded border-gray-400 text-blue-600 focus:ring-2 focus:ring-blue-500/40">
                                <label for="consent-all-{{ $event->id }}" class="text-xs text-gray-700 cursor-pointer">
                                    <span class="font-semibold">Tick for everyone below</span>
                                    <span class="block text-gray-500 mt-0.5">
                                        Sets the "happy to hear from us" box on every person. You can
                                        untick anyone individually afterwards.
                                    </span>
                                </label>
                            </div>
                        @endif

                        {{-- Participants --}}
                        <div class="space-y-4" data-participant-list>
                            @foreach ($initialRows as $index => $row)
                                @php
                                    /*
                                     | In squad mode the first block is the person
                                     | registering, and they choose their own
                                     | position. Everyone after them is a player.
                                     */
                                    $isFirstRow = $event->isManagerMode() && $index === 0;

                                    // Replayed from what came back rather than reset
                                    // to the default, so a failed submit does not
                                    // quietly demote a manager who was also playing.
                                    $rowPosition = $isFirstRow
                                        ? App\Support\ParticipantOptions::positionKeyFor(
                                            $row['role'] ?? 'manager',
                                            filter_var($row['also_plays'] ?? false, FILTER_VALIDATE_BOOLEAN),
                                        )
                                        : null;

                                    [$rowRole, $rowPlays] = $isFirstRow
                                        ? App\Support\ParticipantOptions::POSITIONS[$rowPosition]
                                        : [$event->isManagerMode() ? 'player' : 'participant', false];

                                    $rowTitle = $event->isManagerMode()
                                        ? ($isFirstRow ? 'You' : 'Player')
                                        : 'Your Details';
                                @endphp

                                <x-registration-participant
                                    :index="$index"
                                    :values="$row"
                                    :genders="$genders"
                                    :races="$races"
                                    :states="$states"
                                    :countries="$countries"
                                    :role="$rowRole"
                                    :also-plays="$rowPlays"
                                    :positions="$isFirstRow ? App\Support\ParticipantOptions::positionLabels() : []"
                                    :position="$rowPosition ?? 'manager_only'"
                                    :removable="$event->isManagerMode() && ! $isFirstRow && $index > $minPlayers"
                                    :title="$rowTitle"
                                    :ign-fields="$event->ignFormFields()" />
                            @endforeach
                        </div>

                        {{-- Template cloned by the Add button. Squad mode only, so
                             an added person is always a player. --}}
                        @if ($event->isManagerMode())
                            <template data-participant-template>
                                <x-registration-participant
                                    index="__INDEX__"
                                    :genders="$genders"
                                    :races="$races"
                                    :states="$states"
                                    :countries="$countries"
                                    role="player"
                                    :removable="true"
                                    title="Player"
                                    :ign-fields="$event->ignFormFields()" />
                            </template>
                        @endif

                        @if ($event->isManagerMode())
                            <div class="flex flex-wrap items-center justify-between gap-3 mt-4">
                                <button type="button" data-add-participant
                                        class="inline-flex items-center gap-2 rounded-lg border border-blue-300 bg-blue-50 px-4 py-2.5 text-sm font-semibold text-blue-700 hover:bg-blue-100 transition">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                                    </svg>
                                    Add Player
                                </button>

                                <p class="text-xs text-gray-500" data-participant-summary></p>
                            </div>
                        @endif

                        {{-- Notes --}}
                        <div class="mt-5">
                            <label for="notes_{{ $event->slug }}" class="block text-sm font-semibold text-gray-700 mb-1.5">
                                Notes for the organiser
                            </label>
                            <textarea id="notes_{{ $event->slug }}" name="notes" rows="3" maxlength="1000"
                                      class="w-full rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition resize-y">{{ $isOpenModal ? old('notes') : '' }}</textarea>
                            @error('notes')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        </div>

                        {{-- Paid extras --}}
                        @include('components.registration-addons', [
                            'event' => $event,
                            'isOpenModal' => $isOpenModal,
                        ])

                        {{-- Amount due. Rebuilt by JS as extras are chosen; the
                             figures the server charges come from the database. --}}
                        @if (! $event->isFree() || $event->hasAddons())
                            <div class="bg-gray-50 border border-gray-200 rounded-lg px-4 py-3 mt-5"
                                 data-fee-summary
                                 data-registration-fee="{{ number_format($event->registrationAmount(), 2, '.', '') }}">

                                <table class="w-full text-sm">
                                    <caption class="sr-only">Amount due for this registration</caption>
                                    <tbody data-fee-lines>
                                        @unless ($event->isFree())
                                            <tr>
                                                <td class="py-1 text-gray-700">
                                                    Event registration
                                                    <span class="text-xs text-gray-500">({{ $event->feeBasisLabel() }})</span>
                                                </td>
                                                <td class="py-1 text-right font-semibold text-gray-900 tabular-nums whitespace-nowrap w-28">{{ $event->feeLabel() }}</td>
                                            </tr>
                                        @endunless
                                    </tbody>
                                    <tfoot>
                                        <tr class="border-t border-gray-300">
                                            <td class="pt-2 text-base font-bold text-gray-900">Amount due</td>
                                            <td class="pt-2 text-right text-base font-bold text-blue-700 tabular-nums whitespace-nowrap w-28" data-fee-total>
                                                RM {{ number_format($event->registrationAmount(), 2) }}
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>

                                <p class="text-xs text-gray-500 mt-2">
                                    @if ($event->isManagerMode() && ! $event->isFree())
                                        One registration charge for the whole team, whatever its size.
                                    @endif
                                    You will be taken to the payment page after submitting.
                                </p>
                            </div>
                        @endif

                        <div class="flex justify-end gap-3 pt-5 mt-5 border-t border-gray-200">
                            <button type="button" data-close-registration
                                    class="px-4 py-2.5 rounded-lg border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                                Cancel
                            </button>
                            {{-- Says where the button leads. An event with a fee or
                                 required extras always leads to payment; one that
                                 might cost nothing is decided by JS from the
                                 running total. --}}
                            <button type="submit" data-submit-registration
                                    class="inline-flex items-center gap-2 bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-md">
                                <span data-submit-label>
                                    {{ $event->isFree() ? 'Submit Registration' : 'Continue to Payment' }}
                                </span>
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
                                </svg>
                            </button>
                        </div>
                    </form>
                    </div>
                </div>
            </div>
        </div>
    @endforeach
@endsection

@push('scripts')
<script>
    (function () {
        function closeAll() {
            document.querySelectorAll('[data-registration-modal]').forEach(function (modal) {
                modal.classList.add('hidden');
            });
            document.body.classList.remove('overflow-hidden');
        }

        function openModal(slug) {
            const modal = document.querySelector('[data-registration-modal="' + slug + '"]');

            if (!modal) {
                return;
            }

            closeAll();
            modal.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
            modal.querySelector('input:not([type="hidden"]), select')?.focus();
        }

        document.querySelectorAll('[data-open-registration]').forEach(function (trigger) {
            trigger.addEventListener('click', function () {
                openModal(trigger.dataset.openRegistration);
            });
        });

        document.querySelectorAll('[data-close-registration]').forEach(function (trigger) {
            trigger.addEventListener('click', closeAll);
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeAll();
            }
        });

        // ---- Participant rows -------------------------------------------------

        document.querySelectorAll('[data-registration-form]').forEach(function (form) {
            const list = form.querySelector('[data-participant-list]');
            const template = form.querySelector('[data-participant-template]');
            const addButton = form.querySelector('[data-add-participant]');
            const summary = form.querySelector('[data-participant-summary]');
            const consentAll = form.querySelector('[data-consent-all]');

            const isManagerMode = form.dataset.mode === 'manager';
            const minPlayers = parseInt(form.dataset.minPlayers || '1', 10);
            const maxPlayers = form.dataset.maxPlayers === '' ? null : parseInt(form.dataset.maxPlayers, 10);

            // Indices only need to be unique; the server re-keys the array.
            let nextIndex = list.querySelectorAll('[data-participant]').length;

            function blocks() {
                return Array.from(list.querySelectorAll('[data-participant]'));
            }

            /*
             | The position the first person chose, as the pair the server wants.
             |
             | Mirrors ParticipantOptions::POSITIONS. Kept here rather than fetched
             | so the form still works with the page it was served, and written to
             | the two hidden inputs so the server keeps receiving a plain role.
             */
            const positions = {
                manager_player: { role: 'manager', plays: true },
                manager_only: { role: 'manager', plays: false },
                player_only: { role: 'player', plays: false },
            };

            const positionSelect = form.querySelector('[data-position-select]');

            function chosenPosition() {
                return positions[positionSelect?.value] ?? positions.manager_only;
            }

            /** Whether the first block occupies a playing place. */
            function firstBlockPlays() {
                if (!isManagerMode) {
                    return true;
                }

                const chosen = chosenPosition();

                return chosen.role === 'player' || chosen.plays;
            }

            function syncPositionInputs() {
                if (!positionSelect) {
                    return;
                }

                const chosen = chosenPosition();
                const block = blocks()[0];

                if (!block) {
                    return;
                }

                const roleInput = block.querySelector('[data-position-role]');
                const playsInput = block.querySelector('[data-position-plays]');

                if (roleInput) {
                    roleInput.value = chosen.role;
                }

                if (playsInput) {
                    playsInput.value = chosen.plays ? '1' : '0';
                }
            }

            function refresh() {
                const all = blocks();
                const firstPlays = firstBlockPlays();

                all.forEach(function (block, position) {
                    const badge = block.querySelector('[data-participant-number]');

                    if (!badge) {
                        return;
                    }

                    if (!isManagerMode) {
                        badge.textContent = '1';

                        return;
                    }

                    /*
                     | The first block is marked M while it is a manager, whether or
                     | not they also play, because that is what distinguishes it from
                     | the numbered players below. When the first person is a player
                     | only there is no manager, so numbering simply starts at one.
                     */
                    if (position === 0) {
                        badge.textContent = chosenPosition().role === 'manager' ? 'M' : '1';

                        return;
                    }

                    badge.textContent = String(
                        chosenPosition().role === 'manager' ? position : position + 1
                    );
                });

                // Counted the way the server counts: everyone holding a playing
                // place, which includes the manager when they said they play.
                const players = isManagerMode
                    ? (all.length - 1) + (firstPlays ? 1 : 0)
                    : all.length;

                if (summary) {
                    let text = players + (players === 1 ? ' player' : ' players') + ' entered';

                    text += firstPlays && isManagerMode && chosenPosition().role === 'manager'
                        ? ', counting you.'
                        : '.';

                    if (players < minPlayers) {
                        text += ' At least ' + minPlayers + ' required.';
                    } else if (maxPlayers !== null) {
                        text += ' Up to ' + maxPlayers + ' allowed.';
                    }

                    summary.textContent = text;
                }

                if (addButton && maxPlayers !== null) {
                    addButton.disabled = players >= maxPlayers;
                    addButton.classList.toggle('opacity-50', addButton.disabled);
                    addButton.classList.toggle('cursor-not-allowed', addButton.disabled);
                }

                // The amount due is fixed per registration, so adding or
                // removing a player deliberately does not change it.
            }

            /*
             | Switching to a position that puts the manager on the roster can push
             | the squad one over its limit. Rather than leaving the visitor to work
             | out which block to delete, the last still-empty player block is
             | dropped. Only empty ones: removing a block somebody had typed into
             | would throw their work away to fix a count.
             */
            function trimOverflow() {
                if (!isManagerMode || maxPlayers === null) {
                    return;
                }

                let all = blocks();
                let players = (all.length - 1) + (firstBlockPlays() ? 1 : 0);

                while (players > maxPlayers) {
                    const spare = all.slice(1).reverse().find(function (block) {
                        return Array.from(block.querySelectorAll('input:not([type="hidden"]), select'))
                            .every(function (input) {
                                return input.type === 'checkbox' ? !input.checked : input.value === '';
                            });
                    });

                    if (!spare) {
                        return;
                    }

                    spare.remove();
                    all = blocks();
                    players = (all.length - 1) + (firstBlockPlays() ? 1 : 0);
                }
            }

            positionSelect?.addEventListener('change', function () {
                syncPositionInputs();
                trimOverflow();
                refresh();
            });

            function wireRemove(block) {
                block.querySelector('[data-remove-participant]')?.addEventListener('click', function () {
                    block.remove();
                    refresh();
                });
            }

            blocks().forEach(wireRemove);

            addButton?.addEventListener('click', function () {
                if (!template) {
                    return;
                }

                const markup = template.innerHTML.replace(/__INDEX__/g, String(nextIndex));
                nextIndex += 1;

                const holder = document.createElement('div');
                holder.innerHTML = markup.trim();

                const block = holder.firstElementChild;
                list.appendChild(block);
                wireRemove(block);
                refresh();

                // A player added after the master switch was ticked inherits it,
                // which is what somebody who ticked "everyone" meant.
                if (consentAll?.checked) {
                    const box = block.querySelector('[data-consent-box]');

                    if (box) {
                        box.checked = true;
                    }
                }

                block.querySelector('input:not([type="hidden"]), select')?.focus();
            });

            refresh();
        });

        // ---- Consent for everyone --------------------------------------------

        // The master switch only sets the individual boxes; those are what get
        // submitted. Anyone can still be unticked afterwards, and unticking one
        // clears the master so it never claims more than is true.
        document.querySelectorAll('[data-registration-form]').forEach(function (form) {
            const all = form.querySelector('[data-consent-all]');

            if (!all) {
                return;
            }

            function boxes() {
                return Array.from(form.querySelectorAll('[data-consent-box]'));
            }

            all.addEventListener('change', function () {
                boxes().forEach(function (box) {
                    box.checked = all.checked;
                });
            });

            // Delegated, so boxes on players added later are covered too.
            form.addEventListener('change', function (event) {
                if (!event.target.matches('[data-consent-box]')) {
                    return;
                }

                const current = boxes();
                all.checked = current.length > 0 && current.every(function (box) {
                    return box.checked;
                });
            });
        });

        // ---- Logo preview ----------------------------------------------------

        // Local preview only, so the visitor can see they picked the right image
        // before submitting. Nothing is uploaded until the form is sent.
        document.querySelectorAll('[data-logo-input]').forEach(function (input) {
            const slug = input.dataset.logoInput;
            const preview = document.querySelector('[data-logo-preview="' + slug + '"]');
            const empty = document.querySelector('[data-logo-empty="' + slug + '"]');

            if (!preview) {
                return;
            }

            input.addEventListener('change', function () {
                const file = input.files && input.files[0];

                if (!file) {
                    preview.classList.add('hidden');
                    empty?.classList.remove('hidden');

                    return;
                }

                preview.src = URL.createObjectURL(file);
                preview.alt = file.name;
                preview.classList.remove('hidden');
                empty?.classList.add('hidden');
            });
        });

        // ---- Extras and the running total ------------------------------------

        document.querySelectorAll('[data-registration-form]').forEach(function (form) {
            const summary = form.querySelector('[data-fee-summary]');

            if (!summary) {
                return;
            }

            const lines = summary.querySelector('[data-fee-lines]');
            const totalCell = summary.querySelector('[data-fee-total]');
            const submitLabel = form.querySelector('[data-submit-label]');
            const inputs = Array.from(form.querySelectorAll('[data-addon-qty]'));

            // Money is added up in cents. Summing floats would drift, and this
            // figure sits next to the one the server charges.
            const feeCents = Math.round(parseFloat(summary.dataset.registrationFee || '0') * 100);

            function money(cents) {
                return 'RM ' + (cents / 100).toFixed(2);
            }

            function quantityOf(input) {
                const value = parseInt(input.value, 10);

                return Number.isFinite(value) && value > 0 ? value : 0;
            }

            // The per registration cap counts every option of one add-on
            // together, so it cannot be expressed with a max attribute on each
            // input and is checked here as well as on the server.
            function refreshCaps() {
                form.querySelectorAll('[data-addon-cap]').forEach(function (card) {
                    const cap = parseInt(card.dataset.addonCap, 10);
                    const warning = card.querySelector('[data-addon-cap-warning]');

                    if (!Number.isFinite(cap) || !warning) {
                        return;
                    }

                    let chosen = 0;

                    card.querySelectorAll('[data-addon-qty]').forEach(function (input) {
                        chosen += quantityOf(input);
                    });

                    const over = chosen > cap;

                    warning.textContent = over
                        ? 'That is ' + chosen + ' units. Only ' + cap + ' are allowed per registration.'
                        : '';
                    warning.classList.toggle('hidden', !over);
                });
            }

            function refreshTotal() {
                // Clear only the rows this function owns, leaving the server
                // rendered registration fee row in place.
                lines.querySelectorAll('[data-addon-line]').forEach(function (row) {
                    row.remove();
                });

                let addonCents = 0;

                /*
                 | The add-on's own price is charged once, however many units are
                 | taken from it, so it is added per card rather than per box. The
                 | boxes carry only the surcharge for their size.
                 */
                form.querySelectorAll('[data-addon-once]').forEach(function (card) {
                    const once = Math.round(parseFloat(card.dataset.addonOnce || '0') * 100);

                    if (once === 0) {
                        return;
                    }

                    const taken = Array.from(card.querySelectorAll('[data-addon-qty]'))
                        .some(function (box) { return quantityOf(box) > 0; });

                    if (!taken) {
                        return;
                    }

                    addonCents += once;

                    const row = document.createElement('tr');
                    row.setAttribute('data-addon-line', '');

                    const label = document.createElement('td');
                    label.className = 'py-1 text-gray-700';
                    label.textContent = card.dataset.addonName || 'Extra';

                    const amount = document.createElement('td');
                    amount.className = 'py-1 text-right font-semibold text-gray-900 tabular-nums whitespace-nowrap w-28';
                    amount.textContent = money(once);

                    row.appendChild(label);
                    row.appendChild(amount);
                    lines.appendChild(row);
                });

                inputs.forEach(function (input) {
                    const quantity = quantityOf(input);

                    if (quantity === 0) {
                        return;
                    }

                    const unit = Math.round(parseFloat(input.dataset.price || '0') * 100);
                    const lineTotal = unit * quantity;

                    // A size that adds nothing still records a choice, but it has no
                    // place on a list of money.
                    if (lineTotal === 0) {
                        return;
                    }

                    addonCents += lineTotal;

                    const row = document.createElement('tr');
                    row.setAttribute('data-addon-line', '');

                    const label = document.createElement('td');
                    label.className = 'py-1 text-gray-700';
                    label.textContent = input.dataset.label + ' \u00d7 ' + quantity;

                    const amount = document.createElement('td');
                    // Matches the server rendered fee row above, so the column
                    // stays aligned as rows come and go.
                    amount.className = 'py-1 text-right font-semibold text-gray-900 tabular-nums whitespace-nowrap w-28';
                    amount.textContent = money(lineTotal);

                    row.appendChild(label);
                    row.appendChild(amount);
                    lines.appendChild(row);
                });

                const total = feeCents + addonCents;

                if (totalCell) {
                    totalCell.textContent = money(total);
                }

                // A registration that costs nothing does not go to a payment page,
                // so the button must not promise one.
                if (submitLabel) {
                    submitLabel.textContent = total > 0 ? 'Continue to Payment' : 'Submit Registration';
                }

                refreshCaps();
            }

            inputs.forEach(function (input) {
                // change catches the spinner and paste; input catches typing.
                input.addEventListener('input', refreshTotal);
                input.addEventListener('change', refreshTotal);
            });

            /*
             | Add-ons offered already ticked.
             |
             | The tick box submits nothing itself. Clearing it zeroes the quantities
             | underneath, which are what the server reads, so the two can never
             | disagree about whether the extra was taken. Ticking it back restores
             | one unit, on the first option still in stock when there are options.
             |
             | The reminder appears on the untick rather than in a dialog: declining
             | is a deliberate choice, and a modal would interrupt somebody who
             | meant it while telling them nothing they cannot read in place.
             */
            form.querySelectorAll('[data-addon-toggle]').forEach(function (toggle) {
                const card = toggle.closest('[data-addon]');

                if (!card) {
                    return;
                }

                const reminder = card.querySelector('[data-addon-reminder]');
                const boxes = Array.from(card.querySelectorAll('[data-addon-qty]'));

                toggle.addEventListener('change', function () {
                    if (toggle.checked) {
                        const already = boxes.some(function (box) {
                            return quantityOf(box) > 0;
                        });

                        if (!already) {
                            const first = boxes.find(function (box) {
                                return !box.disabled;
                            });

                            if (first) {
                                first.value = '1';
                            }
                        }
                    } else {
                        boxes.forEach(function (box) {
                            box.value = '0';
                        });
                    }

                    reminder?.classList.toggle('hidden', toggle.checked);
                    refreshTotal();
                });

                /*
                 | Typing a quantity back in clears the reminder, and taking every
                 | quantity down to zero by hand raises it. Without this the notice
                 | would contradict the boxes as soon as somebody edited them
                 | directly rather than using the tick.
                 */
                boxes.forEach(function (box) {
                    box.addEventListener('input', function () {
                        const any = boxes.some(function (other) {
                            return quantityOf(other) > 0;
                        });

                        toggle.checked = any;
                        reminder?.classList.toggle('hidden', any);
                    });
                });
            });

            refreshTotal();
        });
    })();
</script>
@endpush
