@props([
    'award',
    'number' => 1,
])

{{--
    One Star of the Match award, drawn from the row it was saved as.

    Everything on the card except the fixture's own details is copied onto that row
    when the result is saved: the award's name, which figure leads, the figures, the
    player's public name and the squad they played for. So the card reads the same
    after the point rule is edited or the tournament moves on. It deliberately carries
    no overall rank or running total, which would be out of date by the next result.

    The colour comes from the award's place in the rule's list when it was given,
    which is also copied, so Going All Out stays gold.
--}}

@php
    use App\Models\TournamentMatchAward;

    $match = $award->match;
    $event = $award->tournament?->event;

    /*
     | Full class names only. Tailwind reads the source for the names it keeps, so a
     | colour assembled from pieces would never reach the stylesheet.
     */
    $tones = [
        [
            'bar' => 'from-amber-200 to-amber-600',
            'text' => 'text-amber-300',
            'badge' => 'border-amber-300/30 bg-amber-400/10 text-amber-300',
            'ring' => 'border-amber-300/30',
            'glow' => 'bg-amber-400/15',
            'dot' => 'bg-amber-300',
            'cell' => 'bg-[#1d1a14]',
        ],
        [
            'bar' => 'from-teal-200 to-teal-600',
            'text' => 'text-teal-300',
            'badge' => 'border-teal-300/30 bg-teal-400/10 text-teal-300',
            'ring' => 'border-teal-300/30',
            'glow' => 'bg-teal-400/15',
            'dot' => 'bg-teal-300',
            'cell' => 'bg-[#10201f]',
        ],
        [
            'bar' => 'from-violet-200 to-violet-600',
            'text' => 'text-violet-300',
            'badge' => 'border-violet-300/30 bg-violet-400/10 text-violet-300',
            'ring' => 'border-violet-300/30',
            'glow' => 'bg-violet-400/15',
            'dot' => 'bg-violet-300',
            'cell' => 'bg-[#191629]',
        ],
        [
            'bar' => 'from-rose-200 to-rose-600',
            'text' => 'text-rose-300',
            'badge' => 'border-rose-300/30 bg-rose-400/10 text-rose-300',
            'ring' => 'border-rose-300/30',
            'glow' => 'bg-rose-400/15',
            'dot' => 'bg-rose-300',
            'cell' => 'bg-[#21141a]',
        ],
    ];

    $tone = $tones[((int) $award->award_position) % count($tones)];

    $fields = array_values($award->fields ?? []);

    // As many columns as there are figures, up to four across. Six falls to three
    // across two rows rather than six squeezed into one.
    $columns = match (count($fields)) {
        1 => '@sm:grid-cols-1',
        2 => '@sm:grid-cols-2',
        3 => '@sm:grid-cols-3',
        5 => '@sm:grid-cols-5',
        6 => '@sm:grid-cols-3',
        default => '@sm:grid-cols-4',
    };

    // Two letters for the portrait square. Words where there are words, otherwise the
    // first two characters of a handle such as JLYBlzz.
    $words = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', (string) $award->display_name) ?: []));
    $initials = count($words) >= 2
        ? mb_substr($words[0], 0, 1) . mb_substr($words[1], 0, 1)
        : mb_substr($words[0] ?? '?', 0, 2);
    $initials = mb_strtoupper($initials);

    $playedAt = $match?->scheduled_at ?? $match?->scored_at;
@endphp

<article class="@container relative overflow-hidden rounded-2xl border border-white/10 bg-gradient-to-br from-[#171e2d] via-[#0e1421] to-[#080c13] text-slate-50 shadow-[0_24px_60px_rgba(6,11,19,0.22)]">
    <span class="absolute inset-y-0 left-0 w-1 bg-gradient-to-b {{ $tone['bar'] }}" aria-hidden="true"></span>
    <span class="pointer-events-none absolute -top-28 -right-24 h-80 w-80 rounded-full {{ $tone['glow'] }} blur-3xl" aria-hidden="true"></span>

    {{-- The fixture. --}}
    <header class="relative flex items-center justify-between gap-3 border-b border-white/[0.075] py-3.5 pl-6 pr-5">
        <div class="flex min-w-0 items-center gap-2.5">
            <span class="inline-flex h-7 min-w-9 shrink-0 items-center justify-center rounded-lg border px-2 text-xs font-black {{ $tone['badge'] }}">
                {{ $match?->label() ?? '—' }}
            </span>

            <span class="min-w-0">
                <span class="block truncate text-[11px] font-extrabold uppercase text-slate-200">
                    {{ $event?->title ?? $award->tournament?->name }}
                </span>
                <span class="block truncate text-[10px] text-slate-500">
                    {{ collect([$match?->map, $playedAt?->format('d M Y')])->filter()->implode(' · ') }}
                </span>
            </span>
        </div>

        <span class="inline-flex shrink-0 items-center gap-1 text-[9px] font-extrabold uppercase tracking-widest text-slate-400">
            <svg class="h-3.5 w-3.5 {{ $tone['text'] }}" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.6-2a9 9 0 11-17.2 0 9 9 0 0117.2 0z"/>
            </svg>
            Verified
        </span>
    </header>

    {{-- Who, and what for. --}}
    <div class="relative grid grid-cols-[4rem_minmax(0,1fr)] items-center gap-4 px-6 py-5 @sm:grid-cols-[4.5rem_minmax(0,1fr)_auto]">
        <div class="relative grid h-16 w-16 place-items-center overflow-hidden rounded-2xl border {{ $tone['ring'] }} bg-gradient-to-br from-slate-700 to-slate-900 text-lg font-black text-slate-100 shadow-lg @sm:h-[4.5rem] @sm:w-[4.5rem] @sm:text-xl" aria-hidden="true">
            {{ $initials }}
            <span class="absolute inset-x-0 bottom-0 h-[3px] {{ $tone['dot'] }}"></span>
        </div>

        <div class="min-w-0">
            <p class="text-[9px] font-black uppercase tracking-[0.14em] {{ $tone['text'] }}">
                Star of the Match &middot; {{ $award->award_label }}
            </p>

            <h3 class="mt-1.5 truncate text-xl font-extrabold leading-none text-white">{{ $award->display_name }}</h3>

            <p class="mt-2 flex min-w-0 items-center gap-2 text-[11px] font-semibold text-slate-400">
                <x-team-crest :registration="$award->entrant?->registration" :name="$award->entrant_name" size="xs" />
                <span class="truncate">
                    {{ $award->entrant_name }}
                    @if ($award->ign)
                        &middot; ID {{ $award->ign }}
                    @endif
                </span>
            </p>
        </div>

        <div class="col-span-2 flex items-baseline justify-end gap-2 border-t border-white/[0.07] pt-3 @sm:col-span-1 @sm:block @sm:border-l @sm:border-t-0 @sm:pl-4 @sm:pt-0 @sm:text-right">
            <strong class="block text-4xl font-black leading-none tabular-nums {{ $tone['text'] }}">
                {{ TournamentMatchAward::format($award->headlineValue()) }}
            </strong>
            <span class="block text-[9px] font-black uppercase tracking-widest text-slate-400 @sm:mt-2">
                {{ $award->headlineLabel() }}
            </span>
        </div>
    </div>

    {{-- Every figure the card carries. A dash where none was supplied, which is not
         the same as a zero somebody read off the screen. --}}
    <p class="relative mx-6 mb-2.5 flex items-center gap-2 text-[9px] font-black uppercase tracking-[0.11em] text-slate-500">
        Match snapshot
        <span class="h-px flex-1 bg-white/[0.075]"></span>
    </p>

    <dl class="relative mx-6 mb-5 grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-white/[0.075] bg-white/[0.075] {{ $columns }}">
        @foreach ($fields as $field)
            @php
                $key = $field['key'] ?? '';
                $isHeadline = $key === $award->headline_key;
                $value = $award->figure($key);
            @endphp

            <div @class(['px-2.5 py-3 text-center', $tone['cell'] => $isHeadline, 'bg-[#0f1520]' => ! $isHeadline])>
                <dt class="text-[8px] font-black uppercase leading-tight tracking-wider text-slate-500">{{ $field['label'] ?? $key }}</dt>
                <dd @class([
                    'mt-1.5 text-lg font-extrabold leading-none tabular-nums',
                    $tone['text'] => $isHeadline,
                    'text-slate-100' => ! $isHeadline && $value !== null,
                    'text-slate-600' => $value === null,
                ])>{{ TournamentMatchAward::format($value) }}</dd>
            </div>
        @endforeach
    </dl>

    <footer class="relative flex items-center justify-between gap-4 border-t border-white/[0.065] py-3 pl-6 pr-5 text-[10px] text-slate-500">
        <span class="truncate">
            {{ collect([$award->tournament?->name, $match?->stage?->name])->filter()->implode(' · ') }}
        </span>

        <span class="inline-flex shrink-0 items-center gap-1.5 font-black uppercase tracking-wider text-slate-400">
            <span class="h-1.5 w-1.5 rounded-full {{ $tone['dot'] }}" aria-hidden="true"></span>
            Award #{{ sprintf('%02d', $number) }}
        </span>
    </footer>
</article>
