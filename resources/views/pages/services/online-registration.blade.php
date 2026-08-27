{{--
    Online Registration Solutions.

    Laid out as a product page: a before-and-after comparison, the path a single
    entry takes, then the modules. Different shape from the Event Management page,
    which is a delivery timeline, because this is bought as a system rather than as a
    service engagement.

    Every capability listed here exists in the platform. Nothing on this page is a
    roadmap item dressed up as a feature.
--}}
@extends('layouts.master')

@section('title', $pageTitle)

@section('content')
    @include('components.page-header', [
        'title' => $pageTitle,
        'subtitle' => $pageSubtitle,
    ])

    {{-- ============================ The problem ============================ --}}
    <section class="py-16 bg-white">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">

            <div class="max-w-3xl mb-12">
                <p class="text-sm font-bold uppercase tracking-wider text-blue-600 mb-3">Why this exists</p>
                <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-5">
                    A spreadsheet cannot tell you who has paid
                </h2>
                <p class="text-base md:text-lg text-gray-600 leading-relaxed">
                    Most events start with a Google Form and a bank transfer. It works until about
                    forty entries, and then the questions start: who paid, who paid twice, which
                    squad is short a player, and which of the three copies of the list is current.
                </p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-base border border-gray-200 rounded-lg overflow-hidden">
                    <caption class="sr-only">Comparison of a manual registration process against this system</caption>
                    <thead>
                        <tr>
                            <th scope="col" class="w-1/3 text-left text-sm font-bold uppercase tracking-wide text-gray-500 px-5 py-4 bg-gray-50 border-b border-gray-200">
                                &nbsp;
                            </th>
                            <th scope="col" class="w-1/3 text-left text-sm font-bold uppercase tracking-wide text-gray-500 px-5 py-4 bg-gray-50 border-b border-gray-200">
                                Form and spreadsheet
                            </th>
                            <th scope="col" class="w-1/3 text-left text-sm font-bold uppercase tracking-wide text-blue-700 px-5 py-4 bg-blue-50 border-b border-blue-200">
                                This system
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach ([
                            ['Taking payment', 'A bank transfer, then someone checks statements by hand', 'Card or online banking at the point of entry, matched to the entry automatically'],
                            ['Knowing who paid', 'Cross referencing a statement against a list', 'A paid or unpaid status on every row, updated by the gateway'],
                            ['Confirmations', 'Typed and sent one at a time', 'Sent the moment payment clears, with the reference on it'],
                            ['Chasing unpaid entries', 'Someone remembers to do it', 'A reminder sent from the entry itself'],
                            ['Refunds', 'A manual transfer and a note somewhere', 'Sent back through the gateway to the original card, recorded against the entry'],
                            ['Squad entries', 'One row per player, and no way to see the team', 'A manager registers the squad, players sit under the team'],
                            ['Check-in on the day', 'A printed list and a pen', 'Searched and ticked off at the counter, live'],
                            ['Scores and standings', 'A whiteboard, then a spreadsheet', 'Entered per match, standings recalculated immediately'],
                            ['Who changed what', 'Nobody knows', 'Every admin action logged with the account that did it'],
                        ] as $row)
                            <tr class="hover:bg-gray-50/60">
                                <th scope="row" class="px-5 py-4 text-left font-semibold text-gray-900 align-top">
                                    {{ $row[0] }}
                                </th>
                                <td class="px-5 py-4 text-gray-500 align-top">
                                    <span class="flex gap-2.5">
                                        <svg class="w-5 h-5 shrink-0 mt-0.5 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                        </svg>
                                        <span>{{ $row[1] }}</span>
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-gray-800 align-top bg-blue-50/30">
                                    <span class="flex gap-2.5">
                                        <svg class="w-5 h-5 shrink-0 mt-0.5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                        </svg>
                                        <span>{{ $row[2] }}</span>
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

        </div>
    </section>

    {{-- ============================ The path one entry takes ============================ --}}
    <section class="py-16 bg-gray-900 text-white">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">

            <div class="max-w-3xl mb-14">
                <p class="text-sm font-bold uppercase tracking-wider text-blue-400 mb-3">End to end</p>
                <h2 class="text-3xl md:text-4xl font-bold mb-4">What happens to a single entry</h2>
                <p class="text-base md:text-lg text-gray-300">
                    From somebody pressing register to their name appearing in a results table.
                    Nobody retypes anything at any point.
                </p>
            </div>

            @php
                $flow = [
                    [
                        'title' => 'They fill in the form',
                        'body' => 'Fields follow the event: an individual entry, or a manager entering a squad. In game names, a team logo and paid extras appear only where the event asks for them.',
                    ],
                    [
                        'title' => 'They pay',
                        'body' => 'Card or online banking on the gateway\'s own page. We never touch card details. The place is held when the money clears, not when the form is submitted.',
                    ],
                    [
                        'title' => 'They are confirmed',
                        'body' => 'A confirmation email goes out with a reference. The entry lands in your list as paid, and your seat count moves.',
                    ],
                    [
                        'title' => 'They compete',
                        'body' => 'Checked in at the counter, drawn into fixtures, scored per match. Standings and the published results follow from the same record.',
                    ],
                ];
            @endphp

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                @foreach ($flow as $index => $step)
                    <div class="relative">

                        {{-- Connector, drawn only between cards and only where they sit
                             side by side, so it never points into empty space. --}}
                        @unless ($loop->last)
                            <div class="hidden lg:block absolute top-6 left-full w-6 h-0.5 bg-blue-500/40" aria-hidden="true"></div>
                        @endunless

                        <div class="bg-gray-800 rounded-lg p-6 h-full border border-gray-700">
                            <div class="w-12 h-12 rounded-lg bg-blue-600 flex items-center justify-center text-lg font-bold mb-4">
                                {{ $index + 1 }}
                            </div>
                            <h3 class="text-lg font-bold mb-3">{{ $step['title'] }}</h3>
                            <p class="text-sm text-gray-300 leading-relaxed">{{ $step['body'] }}</p>
                        </div>

                    </div>
                @endforeach
            </div>

        </div>
    </section>

    {{-- ============================ Modules ============================ --}}
    <section class="py-16 bg-white">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">

            <div class="max-w-3xl mb-12">
                <p class="text-sm font-bold uppercase tracking-wider text-blue-600 mb-3">What is in it</p>
                <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-4">Six parts, one record</h2>
                <p class="text-base md:text-lg text-gray-600">
                    They share a single participant record, which is the whole point. Check somebody
                    in and the tournament module already knows they are present.
                </p>
            </div>

            @php
                /*
                 | These map onto the modules that actually exist in the admin, in the
                 | order a user meets them. If a module is ever removed, this list is
                 | wrong and should be edited with it.
                 */
                $modules = [
                    [
                        'name' => 'Registration',
                        'lead' => 'Entry forms that fit the event rather than the other way round.',
                        'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
                        'items' => [
                            'Individual entries or a manager registering a squad',
                            'Seat limits, opening and closing dates',
                            'Paid add-ons with variants, such as jersey sizes',
                            'Team logo upload, in-game name and server ID',
                            'Rules shown beside the form before submission',
                        ],
                    ],
                    [
                        'name' => 'Payments',
                        'lead' => 'Money in, money back, and a figure you can defend.',
                        'icon' => 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
                        'items' => [
                            'Card and Malaysian online banking',
                            'Paid, unpaid, awaiting and failed on every entry',
                            'Refunds, full or partial, sent through the gateway',
                            'Transaction list with export',
                            'Card details never reach our servers',
                        ],
                    ],
                    [
                        'name' => 'Participants',
                        'lead' => 'The list, and the things you need to do to it.',
                        'icon' => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z',
                        'items' => [
                            'Search by name, team, reference or identity number',
                            'Filter by individual, team, paid or unpaid',
                            'Resend a confirmation that never arrived',
                            'Send a payment reminder from the entry',
                            'Export to CSV for a sponsor or an organiser',
                        ],
                    ],
                    [
                        'name' => 'Attendance',
                        'lead' => 'The counter on the day, not a printed list.',
                        'icon' => 'M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z',
                        'items' => [
                            'Check in against the registration record',
                            'Present and absent, live, as the queue moves',
                            'Swap a player who did not turn up',
                            'A check-in can be undone; a removal is recorded',
                            'Every action logged against the staff account',
                        ],
                    ],
                    [
                        'name' => 'Tournament',
                        'lead' => 'Draws, scores and a standings table that is correct.',
                        'icon' => 'M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z',
                        'items' => [
                            'Group stages, lobbies and playoff rounds',
                            'Point rules you define, per placement and per action',
                            'Team standings, and separate personal player standings',
                            'Correct a score and standings rebuild from it',
                            'Champions frozen into a Hall of Fame once final',
                        ],
                    ],
                    [
                        'name' => 'Messaging and reporting',
                        'lead' => 'Reaching people, and knowing what happened.',
                        'icon' => 'M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z',
                        'items' => [
                            'Email and SMS campaigns to a chosen audience',
                            'Opens and clicks, with one-press unsubscribe',
                            'Marketing consent recorded with time and origin',
                            'Registration, payment and attendance reporting',
                            'Activity and audit logs for every admin action',
                        ],
                    ],
                ];
            @endphp

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                @foreach ($modules as $module)
                    <div class="flex gap-5 rounded-lg border border-gray-200 shadow-sm p-6 hover:shadow-md hover:border-blue-200 transition">

                        <div class="shrink-0">
                            <div class="w-12 h-12 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $module['icon'] }}"/>
                                </svg>
                            </div>
                        </div>

                        <div class="min-w-0">
                            <h3 class="text-lg font-bold text-gray-900 mb-1.5">{{ $module['name'] }}</h3>
                            <p class="text-sm text-blue-600 font-semibold mb-4">{{ $module['lead'] }}</p>

                            <ul class="space-y-2">
                                @foreach ($module['items'] as $item)
                                    <li class="flex gap-2.5 text-sm text-gray-600">
                                        <svg class="w-4 h-4 shrink-0 mt-0.5 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                        </svg>
                                        <span>{{ $item }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>

                    </div>
                @endforeach
            </div>

        </div>
    </section>

    {{-- ============================ Two audiences ============================ --}}
    <section class="py-16 bg-gray-50">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">

            <div class="max-w-3xl mb-12">
                <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-4">
                    Two people use this, and they want different things
                </h2>
                <p class="text-base md:text-lg text-gray-600">
                    An entrant wants to be finished in two minutes. An organiser wants to know
                    where things stand. Both are served from the same record.
                </p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                <div class="bg-white rounded-lg border-t-4 border-green-500 shadow-sm p-7">
                    <div class="flex items-center gap-3 mb-5">
                        <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                        </svg>
                        <h3 class="text-xl font-bold text-gray-900">The person entering</h3>
                    </div>

                    <ul class="space-y-3">
                        @foreach ([
                            'One page, no account to create, no password to forget',
                            'Works on the phone they are holding',
                            'Sees the fee and every add-on before paying, itemised',
                            'Pays with the card or bank they already use',
                            'Gets a reference straight away, and a confirmation email',
                            'Reads the rules on the same page as the form',
                        ] as $item)
                            <li class="flex gap-3 text-base text-gray-700">
                                <svg class="w-5 h-5 shrink-0 mt-0.5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                </svg>
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <div class="bg-white rounded-lg border-t-4 border-blue-500 shadow-sm p-7">
                    <div class="flex items-center gap-3 mb-5">
                        <svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        <h3 class="text-xl font-bold text-gray-900">The person running it</h3>
                    </div>

                    <ul class="space-y-3">
                        @foreach ([
                            'Entries, seats left and money collected on one screen',
                            'Search any entrant by name, team or reference',
                            'Chase the unpaid without leaving the list',
                            'Refund from the entry, with the reason recorded',
                            'Staff accounts limited to what their job needs',
                            'A log of who changed what, and when',
                        ] as $item)
                            <li class="flex gap-3 text-base text-gray-700">
                                <svg class="w-5 h-5 shrink-0 mt-0.5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                </svg>
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>

            </div>
        </div>
    </section>

    {{-- ============================ Trust ============================ --}}
    <section class="py-16 bg-white">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="max-w-4xl">

                <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-10">
                    Handling other people's data and other people's money
                </h2>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    @foreach ([
                        [
                            'title' => 'Card details never reach us',
                            'body' => 'Payment happens on the gateway\'s own page. What comes back to the system is a reference and a result, so there is no card number here to lose.',
                            'icon' => 'M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z',
                        ],
                        [
                            'title' => 'Staff see only their part',
                            'body' => 'Roles decide what an account can reach. A referee entering scores cannot open the payment list, and a read-only account cannot move money.',
                            'icon' => 'M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z',
                        ],
                        [
                            'title' => 'Written under the PDPA',
                            'body' => 'Consent is recorded with the time and origin it was given. Identity numbers are treated as sensitive data, and our Privacy Policy says plainly what is collected and why.',
                            'icon' => 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
                        ],
                    ] as $card)
                        <div class="bg-gray-50 rounded-lg p-6 border border-gray-200">
                            <svg class="w-9 h-9 text-blue-600 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $card['icon'] }}"/>
                            </svg>
                            <h3 class="text-base font-bold text-gray-900 mb-2.5">{{ $card['title'] }}</h3>
                            <p class="text-sm text-gray-600 leading-relaxed">{{ $card['body'] }}</p>
                        </div>
                    @endforeach
                </div>

                <p class="text-sm text-gray-500 mt-6">
                    Read the
                    <a href="{{ route('legal.privacy') }}" class="text-blue-600 hover:text-blue-800 font-semibold">Privacy Policy</a>
                    and the
                    <a href="{{ route('legal.refund') }}" class="text-blue-600 hover:text-blue-800 font-semibold">Refund Policy</a>
                    for the detail.
                </p>

            </div>
        </div>
    </section>

    {{-- ============================ Questions ============================ --}}
    <section class="py-16 bg-gray-50">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="max-w-3xl">

                <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-10">Questions people ask first</h2>

                @php
                    $faqs = [
                        [
                            'q' => 'Do entrants need to create an account?',
                            'a' => 'No. They fill in a form and pay. There is no password, which removes the single most common reason somebody abandons a registration. Their reference is what identifies the entry afterwards.',
                        ],
                        [
                            'q' => 'Can we take entries for a free event?',
                            'a' => 'Yes. Set the fee to zero and the entry is confirmed on submission with no payment step. You still get the list, the check-in and the reporting.',
                        ],
                        [
                            'q' => 'How do squad entries work?',
                            'a' => 'The event is set to manager mode. One person registers the team, names each player, and pays once for the squad. Players sit under the team, so you can see the team and its players without holding two lists.',
                        ],
                        [
                            'q' => 'Can we charge for extras?',
                            'a' => 'Yes. Add-ons are defined per event and can have variants, such as jersey sizes. They appear as their own line before payment, and they are itemised in the takings so you know what was fee and what was merchandise.',
                        ],
                        [
                            'q' => 'What happens if somebody pays twice?',
                            'a' => 'Both payments appear against the entry with their own gateway reference, so the duplicate is visible rather than hidden in a bank statement. Refund it from the entry and the amount returned is recorded on the record.',
                        ],
                        [
                            'q' => 'Can we use our own branding?',
                            'a' => 'The public pages carry your event name, poster and rules. Logos in the administration area are configurable. A fully separate look on your own domain is a larger piece of work; ask and we will scope it.',
                        ],
                        [
                            'q' => 'Do we get the data out?',
                            'a' => 'Yes. Participants and transactions export to CSV, so a sponsor report or a hand-off to a funding body does not need us. The data is yours.',
                        ],
                        [
                            'q' => 'What if we only want registration, not the tournament part?',
                            'a' => 'Then use only registration. The modules share one record but you are not obliged to touch the ones you do not need.',
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
    <section class="py-16 bg-gradient-to-r from-gray-900 to-blue-900 text-white">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="max-w-3xl">
                <h2 class="text-3xl md:text-4xl font-bold mb-4">See it with your own event in it</h2>

                <p class="text-base md:text-lg text-gray-300 mb-8">
                    Send us the format, the fee and the fields you need. We will set up the entry
                    form and show you the whole path, including a real payment, before you commit
                    to anything.
                </p>

                <div class="flex flex-wrap gap-4">
                    <a href="{{ route('contact') }}"
                       class="inline-flex items-center gap-2 bg-blue-600 text-white px-7 py-3.5 rounded-lg font-semibold hover:bg-blue-700 transition shadow-md">
                        Request a walkthrough
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                        </svg>
                    </a>

                    <a href="{{ route('registration') }}"
                       class="inline-flex items-center gap-2 border-2 border-white/60 text-white px-7 py-3.5 rounded-lg font-semibold hover:bg-white/10 transition">
                        Look at a live entry form
                    </a>
                </div>
            </div>
        </div>
    </section>
@endsection
