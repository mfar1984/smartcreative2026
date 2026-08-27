{{--
    Event Management.

    Laid out as a delivery timeline, because the question people actually arrive with
    is "what happens after I hire you, and when". The other two service pages use
    different shapes on purpose so a visitor comparing all three can tell them apart.

    No head count or event count is claimed anywhere on this page. We do not have a
    verified figure, and an invented one on a live site is a lie that is easy to
    check.
--}}
@extends('layouts.master')

@section('title', $pageTitle)

@section('content')
    @include('components.page-header', [
        'title' => $pageTitle,
        'subtitle' => $pageSubtitle,
    ])

    {{-- ============================ What this is ============================ --}}
    <section class="py-16 bg-white">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-10 items-start">

                <div class="lg:col-span-2">
                    <p class="text-sm font-bold uppercase tracking-wider text-blue-600 mb-3">The short version</p>

                    <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-5">
                        You own the idea. We own the delivery.
                    </h2>

                    <div class="text-base text-gray-700 leading-relaxed space-y-4">
                        <p>
                            Most events do not fail on the day. They fail three weeks earlier, when
                            nobody can say how many people are coming, which suppliers are confirmed,
                            or who is approving the budget.
                        </p>
                        <p>
                            Our job is to remove that uncertainty. You get one point of contact, a
                            written plan with dates against every task, and a live count of
                            registrations you can check yourself at any hour.
                        </p>
                        <p>
                            We work on sports competitions, esports tournaments, corporate sessions,
                            youth programmes and training. The scale changes. The method does not.
                        </p>
                    </div>
                </div>

                {{-- Deliberately qualitative. Nothing here is a number we cannot stand behind. --}}
                <div class="bg-gray-50 border border-gray-200 rounded-lg p-6">
                    <h3 class="text-base font-bold text-gray-900 mb-4">What you can expect</h3>

                    <ul class="space-y-3.5">
                        @foreach ([
                            'One named contact, not a rotating team',
                            'A written plan with a date on every task',
                            'Live registration figures you can check yourself',
                            'Suppliers we have already worked with',
                            'A closing report with the numbers that matter',
                        ] as $promise)
                            <li class="flex gap-3 text-sm text-gray-700">
                                <svg class="w-5 h-5 shrink-0 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                <span>{{ $promise }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>

            </div>
        </div>
    </section>

    {{-- ============================ Delivery timeline ============================ --}}
    <section class="py-16 bg-gradient-to-b from-gray-50 to-white">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">

            <div class="max-w-3xl mb-14">
                <p class="text-sm font-bold uppercase tracking-wider text-blue-600 mb-3">How it runs</p>
                <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-4">Six stages, in this order</h2>
                <p class="text-base md:text-lg text-gray-600">
                    Every event we take on goes through the same six stages. You always know which
                    one you are in and what has to be true before the next one starts.
                </p>
            </div>

            @php
                /*
                 | The timeline. `gate` is the condition that must hold before the next
                 | stage begins, which is the part clients tell us they value most: it
                 | stops a project drifting forward with an unanswered question behind it.
                 */
                $stages = [
                    [
                        'name' => 'Brief and feasibility',
                        'when' => 'Week 1',
                        'body' => 'We sit down and establish what the event is for, who it is for, what it may cost and when it can realistically happen. If the date does not work, we say so here rather than three months in.',
                        'gate' => 'An agreed objective, budget range and target date',
                    ],
                    [
                        'name' => 'Plan and budget',
                        'when' => 'Weeks 2 to 3',
                        'body' => 'A written run of show, a task list with owners and dates, a venue shortlist and a costed budget. You approve it before anything is committed.',
                        'gate' => 'Signed plan and approved budget',
                    ],
                    [
                        'name' => 'Registration opens',
                        'when' => 'Depends on lead time',
                        'body' => 'We build the entry form, set the fee and add-ons, and open registration on our own platform. Payment is collected online. You watch the numbers climb from the same dashboard we do.',
                        'gate' => 'Form tested end to end, including a live payment',
                    ],
                    [
                        'name' => 'Build and confirm',
                        'when' => 'Up to two weeks before',
                        'body' => 'Suppliers confirmed in writing, venue walked, equipment listed, permits and insurance in place, staff briefed on their own written brief. Contingency agreed for weather or a supplier failing.',
                        'gate' => 'Every supplier confirmed in writing, nothing verbal',
                    ],
                    [
                        'name' => 'Event day',
                        'when' => 'The day itself',
                        'body' => 'We are on site early. Counter staff check people in against the registration list, officials run the competition, and one person holds the schedule. If something slips, it is dealt with without asking you first.',
                        'gate' => 'Doors open on time',
                    ],
                    [
                        'name' => 'Close and report',
                        'when' => 'Within two weeks after',
                        'body' => 'Final accounts, attendance against registration, results published, and a written review of what worked and what we would change. Sponsors get the figures they were promised.',
                        'gate' => 'Accounts closed and report delivered',
                    ],
                ];
            @endphp

            <div class="max-w-4xl">
                @foreach ($stages as $index => $stage)
                    <div class="relative flex gap-5 sm:gap-8 pb-10 last:pb-0">

                        {{-- The rail. Drawn on every item except the last so the line stops
                             at the final marker instead of trailing into white space. --}}
                        @unless ($loop->last)
                            <div class="absolute left-5 sm:left-6 top-12 bottom-0 w-0.5 bg-gradient-to-b from-blue-300 to-blue-100" aria-hidden="true"></div>
                        @endunless

                        <div class="relative z-10 shrink-0">
                            <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-full bg-blue-600 text-white flex items-center justify-center font-bold shadow-md">
                                {{ $index + 1 }}
                            </div>
                        </div>

                        <div class="flex-1 bg-white rounded-lg border border-gray-200 shadow-sm p-5 sm:p-6 hover:shadow-md transition">
                            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 mb-3">
                                <h3 class="text-xl font-bold text-gray-900">{{ $stage['name'] }}</h3>
                                <span class="text-xs font-semibold uppercase tracking-wide text-blue-600 bg-blue-50 px-2.5 py-1 rounded-full">
                                    {{ $stage['when'] }}
                                </span>
                            </div>

                            <p class="text-base text-gray-700 leading-relaxed mb-4">
                                {{ $stage['body'] }}
                            </p>

                            <div class="flex gap-2.5 pt-3 border-t border-gray-100">
                                <svg class="w-4 h-4 shrink-0 mt-0.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                </svg>
                                <p class="text-sm text-gray-500">
                                    <span class="font-semibold text-gray-700">Before we move on:</span>
                                    {{ $stage['gate'] }}
                                </p>
                            </div>
                        </div>

                    </div>
                @endforeach
            </div>

        </div>
    </section>

    {{-- ============================ What we handle ============================ --}}
    <section class="py-16 bg-gray-900 text-white">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">

            <div class="max-w-3xl mb-12">
                <h2 class="text-3xl md:text-4xl font-bold mb-4">What we actually handle</h2>
                <p class="text-base md:text-lg text-gray-300">
                    Take all of it or take the parts you are short of. Most clients start with
                    registration and hand over more once they have seen a day run.
                </p>
            </div>

            @php
                $areas = [
                    ['title' => 'Planning and budget', 'items' => ['Concept and format', 'Costed budget and cash flow', 'Task list with owners', 'Risk and contingency plan']],
                    ['title' => 'Venue and suppliers', 'items' => ['Venue sourcing and negotiation', 'Stage, sound, lighting, LED', 'Catering and hospitality', 'Supplier contracts and payment']],
                    ['title' => 'Registration and payment', 'items' => ['Online entry forms', 'Card and online banking payment', 'Confirmations and reminders', 'Refunds where they are due']],
                    ['title' => 'On the day', 'items' => ['Counter and check-in staff', 'Run of show and timekeeping', 'Officials and marshals', 'Crowd flow and safety']],
                    ['title' => 'Competition', 'items' => ['Draws and fixtures', 'Scoring and standings', 'Prize and podium', 'Results published the same day']],
                    ['title' => 'After the event', 'items' => ['Final accounts', 'Attendance against registration', 'Sponsor reporting', 'Written review']],
                ];
            @endphp

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach ($areas as $area)
                    <div class="bg-gray-800 rounded-lg p-6 border-t-4 border-blue-500">
                        <h3 class="text-lg font-bold mb-4">{{ $area['title'] }}</h3>
                        <ul class="space-y-2">
                            @foreach ($area['items'] as $item)
                                <li class="flex gap-2.5 text-sm text-gray-300">
                                    <span class="text-blue-400 font-bold shrink-0" aria-hidden="true">&bull;</span>
                                    <span>{{ $item }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>

        </div>
    </section>

    {{-- ============================ Event types ============================ --}}
    <section class="py-16 bg-white">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">

            <div class="max-w-3xl mb-12">
                <p class="text-sm font-bold uppercase tracking-wider text-blue-600 mb-3">Where we work</p>
                <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-4">The kinds of events we run</h2>
                <p class="text-base md:text-lg text-gray-600">
                    Each of these brings its own problem. Knowing which problem is coming is most
                    of the work.
                </p>
            </div>

            @php
                /*
                 | Categories match the ones our registration system actually uses, so this
                 | list and the live event list cannot describe two different businesses.
                 */
                $types = [
                    [
                        'name' => 'Sports events',
                        'accent' => 'blue',
                        'body' => 'Leagues, championships and one day tournaments. Draws, fixtures, officials and a results table that is right before people go home.',
                        'watch' => 'Squad eligibility and last minute player swaps',
                        'icon' => 'M13 10V3L4 14h7v7l9-11h-7z',
                    ],
                    [
                        'name' => 'Esports',
                        'accent' => 'purple',
                        'body' => 'Mobile and PC titles, group stage into playoffs. In game names verified, lobbies assigned, scores entered per match and standings recalculated on the spot.',
                        'watch' => 'Verifying that the player registered is the player competing',
                        'icon' => 'M14.5 9a3.5 3.5 0 11-7 0 3.5 3.5 0 017 0zM21 12a9 9 0 11-18 0 9 9 0 0118 0z',
                    ],
                    [
                        'name' => 'Corporate events',
                        'accent' => 'green',
                        'body' => 'Conferences, workshops, launches, annual dinners and team days. Delegate lists, name badges, seating, and a schedule that survives a speaker running long.',
                        'watch' => 'Approval chains and invoicing to a finance department',
                        'icon' => 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4',
                    ],
                    [
                        'name' => 'Youth programmes',
                        'accent' => 'amber',
                        'body' => 'Camps, leadership summits and school competitions. Guardian consent, age brackets and an emergency contact for every participant.',
                        'watch' => 'Duty of care, and paperwork that has to be right',
                        'icon' => 'M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z',
                    ],
                    [
                        'name' => 'Training',
                        'accent' => 'rose',
                        'body' => 'Courses and certification sessions, in a room or online. Seat limits, joining instructions, attendance records and certificates.',
                        'watch' => 'Attendance evidence, because certificates depend on it',
                        'icon' => 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253',
                    ],
                    [
                        'name' => 'Community and fundraising',
                        'accent' => 'teal',
                        'body' => 'Charity runs, open days and family events. High walk-in numbers, mixed age groups and a gate that has to keep moving.',
                        'watch' => 'Walk-ins arriving alongside pre-registered entries',
                        'icon' => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z',
                    ],
                ];

                // Written out in full so Tailwind finds them when it scans this file.
                $accents = [
                    'blue' => ['bar' => 'bg-blue-600', 'chip' => 'bg-blue-50 text-blue-700', 'icon' => 'text-blue-600'],
                    'purple' => ['bar' => 'bg-purple-600', 'chip' => 'bg-purple-50 text-purple-700', 'icon' => 'text-purple-600'],
                    'green' => ['bar' => 'bg-green-600', 'chip' => 'bg-green-50 text-green-700', 'icon' => 'text-green-600'],
                    'amber' => ['bar' => 'bg-amber-500', 'chip' => 'bg-amber-50 text-amber-700', 'icon' => 'text-amber-500'],
                    'rose' => ['bar' => 'bg-rose-600', 'chip' => 'bg-rose-50 text-rose-700', 'icon' => 'text-rose-600'],
                    'teal' => ['bar' => 'bg-teal-600', 'chip' => 'bg-teal-50 text-teal-700', 'icon' => 'text-teal-600'],
                ];
            @endphp

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach ($types as $type)
                    @php $accent = $accents[$type['accent']]; @endphp

                    <div class="rounded-lg border border-gray-200 shadow-sm overflow-hidden hover:shadow-lg transition flex flex-col">
                        <div class="h-1.5 {{ $accent['bar'] }}"></div>

                        <div class="p-6 flex flex-col flex-1">
                            <svg class="w-10 h-10 mb-4 {{ $accent['icon'] }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $type['icon'] }}"/>
                            </svg>

                            <h3 class="text-lg font-bold text-gray-900 mb-3">{{ $type['name'] }}</h3>

                            <p class="text-base text-gray-600 leading-relaxed mb-4 flex-1">
                                {{ $type['body'] }}
                            </p>

                            <div class="{{ $accent['chip'] }} rounded-lg px-3.5 py-2.5">
                                <p class="text-xs font-semibold uppercase tracking-wide mb-1">The thing to watch</p>
                                <p class="text-sm">{{ $type['watch'] }}</p>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

        </div>
    </section>

    {{-- ============================ Questions ============================ --}}
    <section class="py-16 bg-gray-50">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="max-w-3xl">

                <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-10">
                    Questions people ask first
                </h2>

                @php
                    $faqs = [
                        [
                            'q' => 'How much notice do you need?',
                            'a' => 'It depends on the size. A training session or a small workshop can be turned around in a few weeks. A championship with registration, sponsors and a venue needs a few months, mostly because the venue and the registration window set the pace, not us. Ask anyway; we will tell you honestly whether a date is possible.',
                        ],
                        [
                            'q' => 'What does it cost?',
                            'a' => 'There is no price list, because an event is not a product. Cost follows the format, the head count, the venue and how much of the work you keep. We quote after the first conversation, itemised, so you can see what each part costs and remove anything you do not want.',
                        ],
                        [
                            'q' => 'Can we just use the registration system?',
                            'a' => 'Yes. Plenty of clients run their own event and only want entries and payment handled properly. That is the Online Registration service, and it stands on its own.',
                        ],
                        [
                            'q' => 'Who holds the money from registration fees?',
                            'a' => 'Fees are collected through our payment gateway and reconciled to your event. You get a statement of what came in, what is still unpaid and what was refunded. Nothing is settled on a verbal figure.',
                        ],
                        [
                            'q' => 'What happens if it rains, or a supplier fails?',
                            'a' => 'Both are planned for before the day, in writing, during the build stage. A wet weather plan and a second option for anything critical are part of the plan you approve, not something we work out on the morning.',
                        ],
                        [
                            'q' => 'Do you work outside Kuala Lumpur?',
                            'a' => 'Yes. We have run events in East Malaysia as well as the Klang Valley. Travel and accommodation appear as their own line in the quote so you can see exactly what distance costs.',
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
    <section class="py-16 bg-gradient-to-r from-blue-700 to-blue-900 text-white">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="max-w-3xl">
                <h2 class="text-3xl md:text-4xl font-bold mb-4">Tell us the date and what it is for</h2>

                <p class="text-base md:text-lg text-blue-100 mb-8">
                    That is enough to start. We will come back with whether it is feasible, what it
                    would take, and a rough cost. No obligation on either side.
                </p>

                <div class="flex flex-wrap gap-4">
                    <a href="{{ route('contact') }}"
                       class="inline-flex items-center gap-2 bg-white text-blue-700 px-7 py-3.5 rounded-lg font-semibold hover:bg-blue-50 transition shadow-md">
                        Talk to us
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                        </svg>
                    </a>

                    <a href="{{ route('portfolio') }}"
                       class="inline-flex items-center gap-2 border-2 border-white/70 text-white px-7 py-3.5 rounded-lg font-semibold hover:bg-white/10 transition">
                        See our work
                    </a>
                </div>
            </div>
        </div>
    </section>
@endsection
