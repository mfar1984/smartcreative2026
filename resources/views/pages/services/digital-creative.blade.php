{{--
    Digital Creative Solutions.

    Editorial layout: large index numbers and alternating full width rows, so it reads
    like a folio rather than a feature grid. Deliberately different from the Event
    Management timeline and the Online Registration product page.

    The deliverables are stated as concrete file formats and counts. That is the part
    clients get caught by, when "a logo" turns out to be one JPEG that cannot be
    printed.
--}}
@extends('layouts.master')

@section('title', $pageTitle)

@section('content')
    @include('components.page-header', [
        'title' => $pageTitle,
        'subtitle' => $pageSubtitle,
    ])

    {{-- ============================ Statement ============================ --}}
    <section class="py-16 md:py-20 bg-white">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="max-w-4xl">
                <p class="text-sm font-bold uppercase tracking-wider text-purple-600 mb-5">Why it matters</p>

                <p class="text-2xl md:text-3xl font-bold text-gray-900 leading-snug mb-8">
                    People decide whether an event is worth their Saturday from a single image on
                    their phone, in about two seconds, before they read a word of it.
                </p>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-8 text-base text-gray-700 leading-relaxed">
                    <p>
                        That image is doing the selling. If it looks like it was made in a hurry, the
                        event looks like it was organised in a hurry, and the entry fee looks
                        expensive. None of that is fair, and all of it is true.
                    </p>
                    <p>
                        We design the material that carries an event: the poster it is announced
                        with, the pages people register on, the graphics that go out while it is
                        running, and the recap that makes next year easier to sell.
                    </p>
                </div>
            </div>
        </div>
    </section>

    {{-- ============================ Disciplines ============================ --}}
    <section class="bg-gray-50">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8 py-16">

            <div class="max-w-3xl mb-14">
                <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-4">What we make</h2>
                <p class="text-base md:text-lg text-gray-600">
                    Five areas. Most projects use two or three of them, and the event ones almost
                    always start with identity.
                </p>
            </div>

            @php
                /*
                 | `gives` is what lands in the client's hands. Stating it as files and
                 | counts rather than as adjectives is the whole reason this section is
                 | laid out in rows instead of small cards.
                 */
                $disciplines = [
                    [
                        'name' => 'Event identity',
                        'tag' => 'Where an event gets its face',
                        'body' => 'A name treatment, a colour palette and a type pairing that hold up on a banner, on a jersey and in a 40 pixel tall profile picture. Built once, then everything else is assembled from it, which is why the eighth poster takes an afternoon instead of a week.',
                        'gives' => ['Logo lockups in SVG, PNG and PDF', 'Colour palette with print and screen values', 'Type pairing with fallbacks', 'A one page usage sheet', 'Editable master files'],
                        'accent' => 'purple',
                    ],
                    [
                        'name' => 'Print and large format',
                        'tag' => 'The things people stand in front of',
                        'body' => 'Posters, backdrops, stage banners, bunting, standees, certificates and numbered bibs. Set up properly for the printer, at the right resolution, with bleed and safe margins, in the colour space the press actually uses.',
                        'gives' => ['Print ready PDF with bleed and crop marks', 'CMYK, at scale', 'A screen proof for approval', 'Editable source files', 'Printer liaison if you want it'],
                        'accent' => 'blue',
                    ],
                    [
                        'name' => 'Social and digital',
                        'tag' => 'The announcement, and everything after it',
                        'body' => 'Announcement graphics, countdowns, fixture and result cards, sponsor acknowledgements and the recap set. Sized per platform rather than one square cropped five ways, because a feed post and a story are not the same shape.',
                        'gives' => ['Feed, story and banner sizes', 'A caption for each piece', 'Templates you can reuse yourself', 'Short looping animation where it helps', 'A posting order for the run up'],
                        'accent' => 'pink',
                    ],
                    [
                        'name' => 'Web and landing pages',
                        'tag' => 'Where the interest turns into an entry',
                        'body' => 'Event pages, microsites and registration pages that work on a phone on mobile data. Fast, readable, and honest about the fee, because a page that hides the price gets abandoned at the payment step.',
                        'gives' => ['Responsive pages, phone first', 'Registration wired to the entry system', 'Accessible contrast and keyboard use', 'Basic search metadata and preview cards', 'Handover so you can edit copy'],
                        'accent' => 'teal',
                    ],
                    [
                        'name' => 'Photo and video',
                        'tag' => 'Proof it happened, and it was good',
                        'body' => 'Coverage on the day, then an edit you can actually use: a short recap, vertical cuts for social, and a stills set that is sorted and named rather than a folder of two thousand frames.',
                        'gives' => ['Edited stills, colour corrected', 'A short recap edit', 'Vertical cuts for social', 'Sponsor visible selects, tagged', 'Full resolution originals on handover'],
                        'accent' => 'amber',
                    ],
                ];

                // Written out in full so Tailwind finds them when it scans this file.
                $accents = [
                    'purple' => ['text' => 'text-purple-600', 'bg' => 'bg-purple-600', 'soft' => 'bg-purple-50', 'ring' => 'text-purple-200'],
                    'blue' => ['text' => 'text-blue-600', 'bg' => 'bg-blue-600', 'soft' => 'bg-blue-50', 'ring' => 'text-blue-200'],
                    'pink' => ['text' => 'text-pink-600', 'bg' => 'bg-pink-600', 'soft' => 'bg-pink-50', 'ring' => 'text-pink-200'],
                    'teal' => ['text' => 'text-teal-600', 'bg' => 'bg-teal-600', 'soft' => 'bg-teal-50', 'ring' => 'text-teal-200'],
                    'amber' => ['text' => 'text-amber-600', 'bg' => 'bg-amber-500', 'soft' => 'bg-amber-50', 'ring' => 'text-amber-200'],
                ];
            @endphp

            <div class="space-y-6">
                @foreach ($disciplines as $index => $item)
                    @php $accent = $accents[$item['accent']]; @endphp

                    <article class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden hover:shadow-md transition">
                        <div class="grid grid-cols-1 lg:grid-cols-12 gap-0">

                            {{-- Index rail --}}
                            <div class="lg:col-span-3 {{ $accent['soft'] }} p-7 flex flex-col justify-between">
                                <div>
                                    <span class="block text-5xl md:text-6xl font-bold {{ $accent['ring'] }} leading-none mb-4" aria-hidden="true">
                                        {{ str_pad($index + 1, 2, '0', STR_PAD_LEFT) }}
                                    </span>

                                    <h3 class="text-xl font-bold text-gray-900 mb-2">{{ $item['name'] }}</h3>

                                    <p class="text-sm font-semibold {{ $accent['text'] }}">{{ $item['tag'] }}</p>
                                </div>

                                <div class="h-1 w-14 {{ $accent['bg'] }} rounded-full mt-6" aria-hidden="true"></div>
                            </div>

                            {{-- Body --}}
                            <div class="lg:col-span-5 p-7">
                                <p class="text-base text-gray-700 leading-relaxed">{{ $item['body'] }}</p>
                            </div>

                            {{-- Deliverables --}}
                            <div class="lg:col-span-4 p-7 bg-gray-50 border-t lg:border-t-0 lg:border-l border-gray-200">
                                <p class="text-xs font-bold uppercase tracking-wider text-gray-500 mb-4">
                                    What you receive
                                </p>

                                <ul class="space-y-2">
                                    @foreach ($item['gives'] as $give)
                                        <li class="flex gap-2.5 text-sm text-gray-700">
                                            <svg class="w-4 h-4 shrink-0 mt-0.5 {{ $accent['text'] }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                            </svg>
                                            <span>{{ $give }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>

                        </div>
                    </article>
                @endforeach
            </div>

        </div>
    </section>

    {{-- ============================ Process ============================ --}}
    <section class="py-16 bg-gray-900 text-white">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">

            <div class="max-w-3xl mb-14">
                <p class="text-sm font-bold uppercase tracking-wider text-purple-400 mb-3">How we work</p>
                <h2 class="text-3xl md:text-4xl font-bold mb-4">Two rounds of changes, then we stop</h2>
                <p class="text-base md:text-lg text-gray-300">
                    Endless revisions are how a design gets worse and a deadline gets missed. The
                    number is agreed in writing at the start, and it is generous enough that we
                    have rarely needed a third.
                </p>
            </div>

            @php
                $steps = [
                    ['n' => 'Brief', 'body' => 'What it is for, who it speaks to, where it will appear, and what it must not look like. That last question saves the most time.'],
                    ['n' => 'Direction', 'body' => 'Two or three routes, rough but real. You pick one, or pick pieces from two. We do not proceed on a maybe.'],
                    ['n' => 'Build', 'body' => 'The chosen route made properly, at the sizes it actually has to work at, including the awkward small one.'],
                    ['n' => 'Review', 'body' => 'Two rounds of changes, collected in one list each time rather than arriving one message at a time.'],
                    ['n' => 'Handover', 'body' => 'Final files in every format you need, plus the editable sources. They are yours, and you do not have to come back to us to use them.'],
                ];
            @endphp

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-5">
                @foreach ($steps as $index => $step)
                    <div class="relative bg-gray-800 rounded-lg p-6 border-t-2 border-purple-500">
                        <span class="block text-xs font-bold uppercase tracking-wider text-purple-400 mb-2">
                            Step {{ $index + 1 }}
                        </span>
                        <h3 class="text-lg font-bold mb-3">{{ $step['n'] }}</h3>
                        <p class="text-sm text-gray-300 leading-relaxed">{{ $step['body'] }}</p>
                    </div>
                @endforeach
            </div>

        </div>
    </section>

    {{-- ============================ Turnaround ============================ --}}
    <section class="py-16 bg-white">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="max-w-4xl">

                <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-4">How long things take</h2>

                <p class="text-base md:text-lg text-gray-600 mb-8">
                    Working days, from an approved brief, assuming feedback comes back within two
                    days. Rush work is possible and is quoted as rush work rather than quietly
                    pushing somebody else's job back.
                </p>

                <div class="overflow-x-auto">
                    <table class="w-full text-base border border-gray-200">
                        <thead>
                            <tr class="bg-gray-50">
                                <th scope="col" class="text-left text-sm font-bold uppercase tracking-wide text-gray-500 px-5 py-4 border-b border-gray-200">Piece of work</th>
                                <th scope="col" class="text-left text-sm font-bold uppercase tracking-wide text-gray-500 px-5 py-4 border-b border-gray-200">Typical turnaround</th>
                                <th scope="col" class="text-left text-sm font-bold uppercase tracking-wide text-gray-500 px-5 py-4 border-b border-gray-200">What slows it down</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @foreach ([
                                ['Single poster or social graphic', '2 to 3 days', 'Waiting on final wording or a sponsor logo'],
                                ['Event identity package', '2 to 3 weeks', 'Deciding between directions'],
                                ['Full print set for an event', '1 to 2 weeks', 'Sponsor logos arriving late or in the wrong format'],
                                ['Social campaign set', '1 to 2 weeks', 'Fixtures not confirmed yet'],
                                ['Landing page or microsite', '2 to 4 weeks', 'Copy, and who signs it off'],
                                ['Event coverage edit', '1 to 2 weeks after the event', 'How much footage there is to sort'],
                            ] as $row)
                                <tr class="hover:bg-gray-50/60">
                                    <th scope="row" class="px-5 py-4 text-left font-semibold text-gray-900 align-top">{{ $row[0] }}</th>
                                    <td class="px-5 py-4 align-top">
                                        <span class="inline-block bg-purple-50 text-purple-700 text-sm font-semibold px-3 py-1 rounded-full whitespace-nowrap">
                                            {{ $row[1] }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-4 text-gray-600 align-top">{{ $row[2] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <p class="text-sm text-gray-500 mt-5">
                    The most common cause of a late delivery is not design time. It is a sponsor
                    logo that arrives as a screenshot two days before print.
                </p>

            </div>
        </div>
    </section>

    {{-- ============================ Straight answers ============================ --}}
    <section class="py-16 bg-gray-50">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="max-w-3xl">

                <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-10">Straight answers</h2>

                @php
                    $faqs = [
                        [
                            'q' => 'Who owns the artwork when it is finished?',
                            'a' => 'You do, on final payment, including the editable source files. We do not hold your logo hostage, and we do not charge you again for a file you already paid to have made. We ask only to show the work in our own portfolio, and we will leave it out if you would rather we did not.',
                        ],
                        [
                            'q' => 'How many revisions do we get?',
                            'a' => 'Two rounds, agreed in writing. A round means one collected list of changes, not messages arriving over a week. Beyond two we quote for the extra time, which is fairer than pretending revisions are free and padding the original price for everybody.',
                        ],
                        [
                            'q' => 'Can you work with our existing brand?',
                            'a' => 'Yes, and it is usually faster. Send whatever you have, including the guidelines if they exist. If all you have is a logo in a Word document we will rebuild it as a proper vector first, and we will tell you before doing it.',
                        ],
                        [
                            'q' => 'Do you use stock images or AI generated ones?',
                            'a' => 'Stock is used where it is sensible and licensed, and we tell you which pieces contain it so you know what you can and cannot reuse. Where we generate anything, we say so. What we will not do is pass off a generated crowd as a photograph of your event.',
                        ],
                        [
                            'q' => 'Can you just do the printing too?',
                            'a' => 'We will deal with the printer and check the proof, which is worth having because most print problems are caught at proof stage or not at all. Printing itself is billed at cost, shown separately from design.',
                        ],
                        [
                            'q' => 'What if we do not like any of the directions?',
                            'a' => 'Then the brief was wrong, and that is usually on us for not asking enough at the start. We go back to the brief and present again once, at no extra cost. It has happened, and it is better than building the wrong thing carefully.',
                        ],
                    ];
                @endphp

                <div class="space-y-3">
                    @foreach ($faqs as $faq)
                        <details class="group bg-white rounded-lg border border-gray-200 shadow-sm">
                            <summary class="flex items-start justify-between gap-4 cursor-pointer px-5 py-4 list-none [&::-webkit-details-marker]:hidden">
                                <span class="text-base font-semibold text-gray-900">{{ $faq['q'] }}</span>
                                <svg class="w-5 h-5 shrink-0 mt-0.5 text-gray-400 transition-transform group-open:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </summary>
                            <div class="px-5 pb-5 -mt-1">
                                <p class="text-base text-gray-600 leading-relaxed">{{ $faq['a'] }}</p>
                            </div>
                        </details>
                    @endforeach
                </div>

            </div>
        </div>
    </section>

    {{-- ============================ Next step ============================ --}}
    <section class="py-16 bg-gradient-to-r from-purple-700 via-purple-800 to-gray-900 text-white">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="max-w-3xl">
                <h2 class="text-3xl md:text-4xl font-bold mb-4">Send us the brief, or the mess</h2>

                <p class="text-base md:text-lg text-purple-100 mb-8">
                    A finished brief is welcome. So is a half formed idea and a folder of files from
                    three different designers. We will tell you what is usable and what needs
                    rebuilding before quoting.
                </p>

                <div class="flex flex-wrap gap-4">
                    <a href="{{ route('contact') }}"
                       class="inline-flex items-center gap-2 bg-white text-purple-800 px-7 py-3.5 rounded-lg font-semibold hover:bg-purple-50 transition shadow-md">
                        Start a project
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                        </svg>
                    </a>

                    <a href="{{ route('portfolio') }}"
                       class="inline-flex items-center gap-2 border-2 border-white/60 text-white px-7 py-3.5 rounded-lg font-semibold hover:bg-white/10 transition">
                        See our work
                    </a>
                </div>
            </div>
        </div>
    </section>
@endsection
