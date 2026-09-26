@extends('layouts.master')

@section('title', $profile['label'] . ' — Player Profile')

@section('content')
    @php
        $totals = $profile['totals'];

        $ordinal = function (?int $n): string {
            if ($n === null) {
                return '–';
            }

            $suffix = match (true) {
                $n % 100 >= 11 && $n % 100 <= 13 => 'th',
                $n % 10 === 1 => 'st',
                $n % 10 === 2 => 'nd',
                $n % 10 === 3 => 'rd',
                default => 'th',
            };

            return $n . $suffix;
        };

        // The heading over the identity panel: what they compete in, and in which
        // games. Built from their own appearances rather than written in, so a
        // competitor who only ever played one game is not announced as playing two.
        $discipline = trim(
            implode(' ', array_map('strtoupper', $profile['categories']))
            . ($profile['games'] !== [] ? ' (' . implode(' · ', $profile['games']) . ')' : '')
        );
    @endphp

    {{--
        Player hero. Darker and tighter than the event pages: this is one person, and
        the name is the loudest thing on it. The hero-section class is kept because the
        main header measures its height to decide when to switch to its scrolled state.
    --}}
    <section class="hero-section relative bg-gradient-to-br from-gray-900 via-slate-900 to-black text-white pt-28 pb-12 md:pt-32 md:pb-14 overflow-hidden">
        <div class="absolute inset-0 bg-cover bg-center opacity-15" style="background-image: url('{{ asset('images/home.png') }}');"></div>
        <div class="absolute -top-28 -left-16 w-[26rem] h-[26rem] rounded-full bg-blue-600/20 blur-3xl"></div>

        <div class="container mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
            @if ($profile['appearances'] !== [])
                @php $first = $profile['appearances'][0]; @endphp

                <a href="{{ route('events.ranking', $first['event']->slug) }}"
                   class="inline-flex items-center gap-1.5 text-xs font-semibold uppercase tracking-widest text-gray-400 hover:text-white transition mb-6">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                    </svg>
                    All standings
                </a>
            @endif

            @if ($discipline !== '')
                <p class="text-xs font-bold uppercase tracking-widest text-blue-300 mb-2">{{ $discipline }}</p>
            @endif

            <h1 class="text-3xl md:text-5xl font-bold leading-tight break-words">{{ $profile['label'] }}</h1>

            <div class="w-20 h-1 bg-blue-500 mt-5 rounded-full"></div>

            <div class="flex flex-wrap gap-x-8 gap-y-4 mt-7">
                <div>
                    <span class="block text-xl md:text-2xl font-bold tabular-nums">{{ $totals['events'] }}</span>
                    <span class="text-xs font-semibold uppercase tracking-widest text-gray-400">
                        {{ Str::plural('Event', $totals['events']) }}
                    </span>
                </div>

                <div>
                    <span class="block text-xl md:text-2xl font-bold tabular-nums">{{ $totals['teams'] }}</span>
                    <span class="text-xs font-semibold uppercase tracking-widest text-gray-400">
                        {{ Str::plural('Team', $totals['teams']) }}
                    </span>
                </div>

                <div>
                    <span class="block text-xl md:text-2xl font-bold tabular-nums">{{ $totals['matches'] }}</span>
                    <span class="text-xs font-semibold uppercase tracking-widest text-gray-400">
                        {{ Str::plural('Match', $totals['matches']) }}
                    </span>
                </div>

                <div>
                    {{-- Their own points when a game kept them, otherwise the squad's,
                         and said which. --}}
                    <span class="block text-xl md:text-2xl font-bold tabular-nums">
                        {{ ($totals['has_own'] ? $totals['own_points'] : $totals['points']) + 0 }}
                    </span>
                    <span class="text-xs font-semibold uppercase tracking-widest text-gray-400">
                        {{ $totals['has_own'] ? 'Own points' : 'Team points' }}
                    </span>
                </div>

                {{-- Withheld until something has been played. Before the first result
                     every entrant is level on nil and the ranking puts them all first,
                     which is true and worthless. --}}
                <div>
                    <span @class([
                        'block text-xl md:text-2xl font-bold tabular-nums',
                        'text-amber-400' => $totals['best'] !== null && $totals['best'] <= 3,
                        'text-gray-500' => $totals['best'] === null,
                    ])>{{ $ordinal($totals['best']) }}</span>
                    <span class="text-xs font-semibold uppercase tracking-widest text-gray-400">Best finish</span>
                </div>
            </div>
        </div>
    </section>

    <section class="py-10 md:py-14 bg-gray-50">
        <div class="max-w-4xl mx-auto px-4 sm:px-6">

            @if (session('player_message_status'))
                <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm text-emerald-800">
                    {{ session('player_message_status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-800">
                    Your message could not be sent. Please check the highlighted fields and try again.
                </div>
            @endif

            {{-- ===== Identity =====
                 Laid out as a card because that is what it is. Every value here is
                 shown through App\Support\PublicIdentity, which is one-way: enough for
                 the person it belongs to to recognise themselves, and not enough for a
                 stranger to use. The name on the identity card is never shown at all. --}}
            <article class="relative rounded-2xl bg-gradient-to-br from-slate-800 via-slate-900 to-black text-white shadow-lg overflow-hidden mb-6">
                <div class="absolute -top-16 -right-10 w-64 h-64 rounded-full bg-blue-500/10 blur-3xl"></div>

                <div class="relative px-5 md:px-7 py-5 border-b border-white/10 flex flex-wrap items-center justify-between gap-3">
                    <p class="text-xs font-bold uppercase tracking-widest text-blue-300">
                        {{ $discipline !== '' ? $discipline : 'Competitor' }}
                    </p>

                    {{-- A chip, the way a real card has one. Decorative only. --}}
                    <span class="w-9 h-6 rounded bg-gradient-to-br from-amber-200 to-amber-500 ring-1 ring-amber-300/40 shrink-0" aria-hidden="true"></span>
                </div>

                <dl class="relative px-5 md:px-7 py-5 divide-y divide-white/5">
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-2.5 first:pt-0">
                        <dt class="text-xs font-semibold uppercase tracking-widest text-gray-400 w-full sm:w-56 shrink-0">Identity Card</dt>
                        <dd class="text-base font-bold tracking-widest tabular-nums">{{ $profile['card'] }}</dd>
                    </div>

                    @foreach ($profile['accounts'] as $account)
                        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-2.5">
                            <dt class="text-xs font-semibold uppercase tracking-widest text-gray-400 w-full sm:w-56 shrink-0">
                                {{ $account['label'] }}
                                <span class="block normal-case tracking-normal text-gray-500">{{ $account['game'] }}</span>
                            </dt>
                            <dd class="text-base font-bold break-all">{{ $account['value'] }}</dd>
                        </div>
                    @endforeach

                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-2.5">
                        <dt class="text-xs font-semibold uppercase tracking-widest text-gray-400 w-full sm:w-56 shrink-0">Gender</dt>
                        <dd class="text-base text-gray-200">{{ $profile['gender'] }}</dd>
                    </div>

                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-2.5">
                        <dt class="text-xs font-semibold uppercase tracking-widest text-gray-400 w-full sm:w-56 shrink-0">Race</dt>
                        <dd class="text-base text-gray-200">{{ $profile['race'] }}</dd>
                    </div>

                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-2.5">
                        <dt class="text-xs font-semibold uppercase tracking-widest text-gray-400 w-full sm:w-56 shrink-0">Telephone</dt>
                        <dd class="text-base text-gray-200 tracking-widest tabular-nums">{{ $profile['phone'] }}</dd>
                    </div>

                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-2.5 last:pb-0">
                        <dt class="text-xs font-semibold uppercase tracking-widest text-gray-400 w-full sm:w-56 shrink-0">Email</dt>
                        <dd class="text-base text-gray-200 break-all">
                            {{-- The masked address is the button. Nothing behind it reveals
                                 the real one: the message goes to the office, who hold the
                                 address and decide whether to pass anything on. --}}
                            @if ($profile['can_message'])
                                <button type="button"
                                        data-player-message-open
                                        class="inline-flex items-center gap-1.5 text-blue-300 hover:text-blue-200 underline decoration-dotted underline-offset-4 transition">
                                    {{ $profile['email'] }}
                                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                                    </svg>
                                </button>
                            @else
                                {{ $profile['email'] }}
                            @endif
                        </dd>
                    </div>
                </dl>

                <p class="relative px-5 md:px-7 pb-5 text-xs text-gray-500">
                    Shown in part only. Full details are held by the organiser and are never
                    published.
                </p>
            </article>

            {{-- ===== Star of the Match =====
                 One card for every award this player has been given, newest first. Each
                 is a record of one match, so a second award adds a card and never
                 changes the first. --}}
            @if ($profile['match_awards']->isNotEmpty())
                <section class="mb-6" aria-labelledby="match-awards-title">
                    <div class="flex flex-wrap items-baseline justify-between gap-3 mb-3">
                        <h2 id="match-awards-title" class="text-xs font-bold uppercase tracking-widest text-gray-500">
                            Star of the Match
                        </h2>
                        <p class="text-sm text-gray-400">
                            {{ $profile['match_awards']->count() }}
                            {{ Str::plural('award', $profile['match_awards']->count()) }}
                        </p>
                    </div>

                    <div @class([
                        'grid grid-cols-1 gap-4',
                        'lg:grid-cols-2' => $profile['match_awards']->count() > 1,
                        'max-w-2xl' => $profile['match_awards']->count() === 1,
                    ])>
                        @foreach ($profile['match_awards'] as $entry)
                            <x-match-award-card :award="$entry['award']" :number="$entry['number']" />
                        @endforeach
                    </div>
                </section>
            @endif

            {{-- ===== Honours =====
                 Announced results only. A team leading a half-played table has not won
                 anything yet, and this is the section read as a record. --}}
            @if ($profile['podiums']->isNotEmpty() || $profile['awards']->isNotEmpty())
                <article class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden mb-6">
                    <div class="flex flex-wrap items-baseline justify-between gap-3 px-5 md:px-7 py-4 border-b border-gray-100">
                        <p class="text-xs font-bold uppercase tracking-widest text-gray-500">Honours</p>
                        <p class="text-sm text-gray-400">Announced results only</p>
                    </div>

                    <div class="px-5 md:px-7 py-5 grid grid-cols-1 sm:grid-cols-2 gap-3">
                        @foreach ($profile['podiums'] as $podium)
                            @php
                                $medal = match ((int) $podium->rank) {
                                    1 => ['border-amber-300', 'from-amber-50', 'text-amber-700', 'Champion'],
                                    2 => ['border-slate-300', 'from-slate-50', 'text-slate-600', 'Runner Up'],
                                    default => ['border-orange-200', 'from-orange-50', 'text-orange-700', 'Third'],
                                };
                            @endphp

                            <div class="rounded-xl border {{ $medal[0] }} bg-gradient-to-br {{ $medal[1] }} to-white px-4 py-3.5">
                                <p class="text-xs font-bold uppercase tracking-widest {{ $medal[2] }}">
                                    <span aria-hidden="true">&#127942;</span> {{ $medal[3] }}
                                </p>
                                <p class="text-sm font-bold text-gray-900 mt-1.5">{{ $podium->display_name }}</p>
                                <p class="text-xs text-gray-500 mt-0.5">
                                    {{ $podium->tournament?->name }}
                                    @if ($podium->tournament?->event?->starts_at)
                                        &middot; {{ $podium->tournament->event->starts_at->format('Y') }}
                                    @endif
                                </p>
                            </div>
                        @endforeach

                        @foreach ($profile['awards'] as $award)
                            <div class="rounded-xl border border-indigo-200 bg-gradient-to-br from-indigo-50 to-white px-4 py-3.5">
                                <p class="text-xs font-bold uppercase tracking-widest text-indigo-700">
                                    <span aria-hidden="true">&#11088;</span> {{ $award->award_label }}
                                </p>
                                <p class="text-sm font-bold text-gray-900 mt-1.5">{{ $award->display_name }}</p>
                                <p class="text-xs text-gray-500 mt-0.5">
                                    {{ $award->entrant_name }}
                                    @if ($award->tournament?->name)
                                        &middot; {{ $award->tournament->name }}
                                    @endif
                                </p>
                            </div>
                        @endforeach
                    </div>
                </article>
            @endif

            {{-- ===== Career =====
                 Every event they have entered, newest first, with the team they played
                 for. Somebody who played for two different squads in two different
                 years reads as exactly that. --}}
            <article class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="flex flex-wrap items-baseline justify-between gap-3 px-5 md:px-7 py-4 border-b border-gray-100">
                    <p class="text-xs font-bold uppercase tracking-widest text-gray-500">Career</p>
                    <p class="text-sm text-gray-400">
                        {{ count($profile['appearances']) }}
                        {{ Str::plural('entry', count($profile['appearances'])) }}
                    </p>
                </div>

                <div class="divide-y divide-gray-100">
                    @foreach ($profile['appearances'] as $appearance)
                        @php
                            $standing = $appearance['standing'];
                            $personal = $appearance['personal'];
                            $event = $appearance['event'];
                            $ranked = $standing && (int) $standing->played > 0;
                        @endphp

                        <div class="px-5 md:px-7 py-5">
                            <div class="flex items-start gap-3.5">
                                <x-team-crest :registration="$appearance['registration']"
                                              :name="$appearance['team']"
                                              size="md" />

                                <div class="min-w-0 grow">
                                    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                                        @if ($appearance['registration'] && $event)
                                            <a href="{{ route('events.team', [$event->slug, $appearance['registration']->id]) }}"
                                               class="text-base font-bold text-gray-900 hover:text-blue-600 hover:underline transition">
                                                {{ $appearance['team'] }}
                                            </a>
                                        @else
                                            <span class="text-base font-bold text-gray-900">{{ $appearance['team'] }}</span>
                                        @endif

                                        @if ($appearance['person']->isManager())
                                            <span class="rounded bg-indigo-100 px-1.5 py-0.5 text-xs font-bold text-indigo-800">
                                                {{ $appearance['person']->also_plays ? 'Manager & Player' : 'Manager' }}
                                            </span>
                                        @endif
                                    </div>

                                    <p class="text-sm text-gray-500 mt-0.5">
                                        {{ $appearance['tournament']->name }}
                                        @if ($event)
                                            &middot; {{ $event->title }}
                                            @if ($event->starts_at)
                                                &middot; {{ $event->starts_at->format('M Y') }}
                                            @endif
                                        @endif
                                    </p>

                                    <div class="flex flex-wrap gap-x-6 gap-y-2 mt-3">
                                        <div>
                                            <span @class([
                                                'block text-sm font-bold tabular-nums',
                                                'text-amber-600' => $ranked && (int) $standing->rank <= 3,
                                                'text-gray-400' => ! $ranked,
                                            ])>{{ $ranked ? $ordinal((int) $standing->rank) : '–' }}</span>
                                            <span class="text-xs text-gray-400">
                                                {{ $standing?->stage?->name ?? 'Placing' }}
                                            </span>
                                        </div>

                                        <div>
                                            <span class="block text-sm font-bold tabular-nums text-gray-800">
                                                {{ ($standing?->total_points ?? 0) + 0 }}
                                            </span>
                                            <span class="text-xs text-gray-400">Team points</span>
                                        </div>

                                        <div>
                                            <span class="block text-sm font-bold tabular-nums text-gray-800">
                                                {{ (int) ($standing?->played ?? 0) }}
                                            </span>
                                            <span class="text-xs text-gray-400">Matches</span>
                                        </div>

                                        {{-- Only where the competition kept individual figures.
                                             A zero here on a game that never recorded any would
                                             read as a player who scored nothing. --}}
                                        @if ($personal)
                                            <div>
                                                <span class="block text-sm font-bold tabular-nums text-blue-700">
                                                    {{ $personal->total_points + 0 }}
                                                </span>
                                                <span class="text-xs text-gray-400">Own points</span>
                                            </div>

                                            @if ($personal->rank)
                                                <div>
                                                    <span class="block text-sm font-bold tabular-nums text-blue-700">
                                                        {{ $ordinal((int) $personal->rank) }}
                                                    </span>
                                                    <span class="text-xs text-gray-400">Player rank</span>
                                                </div>
                                            @endif
                                        @endif
                                    </div>

                                    {{-- The figures behind that score, under the competition's
                                         own labels. A stat left at nothing is dropped rather
                                         than printed as a zero, because a game that never asked
                                         for damage did not record none of it. --}}
                                    @if ($personal && $appearance['player_columns'] !== [])
                                        @php
                                            $figures = collect($appearance['player_columns'])
                                                ->mapWithKeys(fn (array $column) => [
                                                    $column['label'] => $personal->componentCount($column['key']) + 0,
                                                ])
                                                ->filter(fn ($value) => $value > 0);
                                        @endphp

                                        @if ($figures->isNotEmpty())
                                            <div class="flex flex-wrap gap-2 mt-3">
                                                @foreach ($figures as $label => $value)
                                                    <span class="inline-flex items-baseline gap-1.5 rounded-lg bg-slate-100 px-2.5 py-1.5">
                                                        <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $label }}</span>
                                                        <span class="text-sm font-bold tabular-nums text-slate-800">{{ $value }}</span>
                                                    </span>
                                                @endforeach
                                            </div>
                                        @endif
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </article>

        </div>
    </section>

    {{-- ===== Message dialog =====
         Centred, following the rest of the site. The masked address opens this; the
         real one is never in the page, so nothing here can leak it. --}}
    @if ($profile['can_message'])
        <div id="player-message"
             class="fixed inset-0 z-50 hidden items-center justify-center bg-black/60 p-4"
             role="dialog"
             aria-modal="true"
             aria-labelledby="player-message-title">

            @php
                /*
                 | The field styling, written out once.
                 |
                 | Spelled out in full rather than leaning on a forms plugin, because this
                 | project does not load @tailwindcss/forms and Tailwind's preflight sets
                 | border-width to 0 on every element. A class list carrying only
                 | border-gray-300 therefore paints a colour onto a border that is not
                 | there, and the field renders as blank space with no height — which is
                 | exactly how these boxes were behaving. `border` and the padding are what
                 | make a box; the colour only decides what shade it is.
                 |
                 | Held in variables so the four fields cannot drift apart from each other
                 | the way they would if the same long string were pasted four times.
                 */
                $box = 'w-full rounded-lg border bg-white px-3.5 py-2.5 text-sm text-gray-900 placeholder-gray-400 shadow-sm focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';

                // Label beside the field on a comfortable screen, above it on a narrow
                // one. Two columns on a phone would leave the input too narrow to read
                // what has been typed into it.
                $row = 'sm:flex sm:items-start sm:gap-4';
                $lab = 'block text-sm font-semibold text-gray-700 mb-1.5 sm:mb-0 sm:w-32 sm:shrink-0 sm:pt-2.5';

                // Matches the label column plus the gap, so the buttons line up with the
                // fields above rather than floating against the panel edge.
                $act = 'sm:pl-36';
            @endphp

            <div class="w-full max-w-xl rounded-2xl bg-white shadow-xl overflow-hidden max-h-full overflow-y-auto">
                <div class="flex items-start justify-between gap-4 px-5 md:px-6 py-4 border-b border-gray-100">
                    <div>
                        <h2 id="player-message-title" class="text-base font-bold text-gray-900">
                            Message {{ $profile['label'] }}
                        </h2>
                        <p class="text-sm text-gray-500 mt-0.5">
                            Sent to the organiser, who will pass it on. You will not be given this
                            competitor's contact details.
                        </p>
                    </div>

                    <button type="button" data-player-message-close
                            class="shrink-0 rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition"
                            aria-label="Close">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <form action="{{ route('player.message', $participant) }}" method="POST" class="px-5 md:px-6 py-5 space-y-4">
                    @csrf

                    <div class="{{ $row }}">
                        <label for="pm-name" class="{{ $lab }}">Your name</label>
                        <div class="sm:flex-1 sm:min-w-0">
                            <input type="text" id="pm-name" name="name" value="{{ old('name') }}" required maxlength="120"
                                   placeholder="Who the organiser should say this is from"
                                   @error('name') aria-describedby="pm-name-error" aria-invalid="true" @enderror
                                   class="{{ $box }} @error('name') border-red-500 @else border-gray-300 @enderror">
                            @error('name')
                                <p id="pm-name-error" class="text-xs text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="{{ $row }}">
                        <label for="pm-email" class="{{ $lab }}">Your email</label>
                        <div class="sm:flex-1 sm:min-w-0">
                            <input type="email" id="pm-email" name="email" value="{{ old('email') }}" required maxlength="190"
                                   placeholder="name@example.com"
                                   @error('email') aria-describedby="pm-email-error" aria-invalid="true" @enderror
                                   class="{{ $box }} @error('email') border-red-500 @else border-gray-300 @enderror">
                            @error('email')
                                <p id="pm-email-error" class="text-xs text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="{{ $row }}">
                        <label for="pm-phone" class="{{ $lab }}">
                            Your telephone
                            <span class="block font-normal text-xs text-gray-400 sm:mt-0.5">optional</span>
                        </label>
                        <div class="sm:flex-1 sm:min-w-0">
                            <input type="text" id="pm-phone" name="phone" value="{{ old('phone') }}" maxlength="30"
                                   placeholder="Only if you would rather be called"
                                   @error('phone') aria-describedby="pm-phone-error" aria-invalid="true" @enderror
                                   class="{{ $box }} @error('phone') border-red-500 @else border-gray-300 @enderror">
                            @error('phone')
                                <p id="pm-phone-error" class="text-xs text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="{{ $row }}">
                        <label for="pm-message" class="{{ $lab }}">Message</label>
                        <div class="sm:flex-1 sm:min-w-0">
                            <textarea id="pm-message" name="message" rows="5" required minlength="10" maxlength="3000"
                                      placeholder="What you would like passed on."
                                      @error('message') aria-describedby="pm-message-error" aria-invalid="true" @enderror
                                      class="{{ $box }} resize-y @error('message') border-red-500 @else border-gray-300 @enderror">{{ old('message') }}</textarea>
                            @error('message')
                                <p id="pm-message-error" class="text-xs text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="{{ $act }}">
                        <div class="flex flex-wrap justify-end gap-2.5 pt-1">
                            <button type="button" data-player-message-close
                                    class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                                Cancel
                            </button>
                            <button type="submit"
                                    class="rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition">
                                Send message
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <script>
            (function () {
                const dialog = document.getElementById('player-message');

                if (!dialog) {
                    return;
                }

                function open() {
                    dialog.classList.remove('hidden');
                    dialog.classList.add('flex');
                    document.body.classList.add('overflow-hidden');
                    dialog.querySelector('input, textarea')?.focus();
                }

                function close() {
                    dialog.classList.add('hidden');
                    dialog.classList.remove('flex');
                    document.body.classList.remove('overflow-hidden');
                }

                document.querySelectorAll('[data-player-message-open]').forEach(function (trigger) {
                    trigger.addEventListener('click', open);
                });

                document.querySelectorAll('[data-player-message-close]').forEach(function (trigger) {
                    trigger.addEventListener('click', close);
                });

                // Clicking the backdrop closes, clicking the panel does not.
                dialog.addEventListener('click', function (event) {
                    if (event.target === dialog) {
                        close();
                    }
                });

                document.addEventListener('keydown', function (event) {
                    if (event.key === 'Escape' && !dialog.classList.contains('hidden')) {
                        close();
                    }
                });

                // A rejected submission comes back with the fields filled in, so the
                // dialog is reopened rather than leaving somebody looking at a page
                // that appears to have swallowed what they typed.
                @if ($errors->any())
                    open();
                @endif
            })();
        </script>
    @endif
@endsection
