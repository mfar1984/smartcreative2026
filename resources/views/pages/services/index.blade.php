{{--
    Services landing page.

    A directory, not a fourth pitch. Every claim on it is lifted from the service
    page it links to, so the two cannot end up describing different businesses after
    one of them is edited. The title and the one line summary come from
    ServiceController, which is where both this page and the service page read them
    from; the supporting points below are the headings those pages already use.

    Promotional Merchandise appears in the contact form's subject list and in the
    Contact FAQ but has no service page, because it is the Shop. It is presented here
    as its own section pointing at the Shop and at an enquiry, which is the honest
    answer: a fourth card linking to a page that does not exist would be worse, and
    leaving it off entirely would contradict the contact form.

    Laid out as one repeated card shape on purpose. The three service pages each use
    a different shape so they can be told apart; a directory is the one page where a
    visitor is comparing, so the comparison has to be like for like.
--}}
@extends('layouts.master')

@section('title', $pageTitle)

@section('content')
    @include('components.page-header', [
        'title' => $pageTitle,
        'subtitle' => $pageSubtitle,
    ])

    {{-- ============================ What we do ============================ --}}
    <section class="py-16 bg-white">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="max-w-3xl">
                <p class="text-sm font-bold uppercase tracking-wider text-blue-600 mb-3">The short version</p>

                <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-5">
                    Take all of it, or take the part you are short of
                </h2>

                <div class="text-base md:text-lg text-gray-700 leading-relaxed space-y-4">
                    <p>
                        We run events, we take the entries and the money, and we design the material
                        that carries both. The three are sold separately because they are bought
                        separately: plenty of clients run their own event and only want registration
                        handled properly.
                    </p>
                    <p>
                        Each service has its own page with the process, the deliverables and the
                        questions people ask first. Start with whichever describes the problem you
                        actually have.
                    </p>
                </div>
            </div>
        </div>
    </section>

    {{-- ============================ The three services ============================ --}}
    <section class="py-16 bg-gray-50">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">

            <h2 class="sr-only">Our three services</h2>

            @php
                /*
                 | `points` are the section and module headings from each service's own
                 | page, so this card cannot promise something that page does not. `link`
                 | names its destination rather than saying "read more" three times,
                 | which is what a screen reader user hears when tabbing the list.
                 |
                 | Keyed on the same slug as ServiceController::SERVICES, which supplies
                 | the title and the summary.
                 */
                $cards = [
                    'event-management' => [
                        'route' => 'services.event-management',
                        'accent' => 'blue',
                        'icon' => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
                        'points' => [
                            'Six delivery stages, in a fixed order',
                            'A written plan with a date on every task',
                            'Venue, suppliers, staff and the run of show',
                            'A closing report with the numbers that matter',
                        ],
                        'link' => 'How we run an event',
                    ],
                    'online-registration' => [
                        'route' => 'services.online-registration',
                        'accent' => 'teal',
                        'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
                        'points' => [
                            'Entry forms that fit the event, individual or squad',
                            'Card and Malaysian online banking, with refunds',
                            'Check-in at the counter against the entry record',
                            'Draws, scores and a standings table that is correct',
                        ],
                        'link' => 'What the registration system does',
                    ],
                    'digital-creative' => [
                        'route' => 'services.digital-creative',
                        'accent' => 'purple',
                        'icon' => 'M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01',
                        'points' => [
                            'Event identity, built once and reused',
                            'Print and large format, set up for the printer',
                            'Social sets, sized per platform',
                            'Landing pages, and photo and video coverage',
                        ],
                        'link' => 'What we design and make',
                    ],
                ];

                // Written out in full so Tailwind finds them when it scans this file.
                $accents = [
                    'blue' => ['bar' => 'bg-blue-600', 'icon' => 'text-blue-600', 'soft' => 'bg-blue-50', 'link' => 'text-blue-700 hover:text-blue-900'],
                    'teal' => ['bar' => 'bg-teal-600', 'icon' => 'text-teal-600', 'soft' => 'bg-teal-50', 'link' => 'text-teal-700 hover:text-teal-900'],
                    'purple' => ['bar' => 'bg-purple-600', 'icon' => 'text-purple-600', 'soft' => 'bg-purple-50', 'link' => 'text-purple-700 hover:text-purple-900'],
                ];
            @endphp

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach ($cards as $slug => $card)
                    @php $accent = $accents[$card['accent']]; @endphp

                    <article class="bg-white rounded-lg border border-gray-200 shadow-sm overflow-hidden hover:shadow-lg transition flex flex-col">
                        <div class="h-1.5 {{ $accent['bar'] }}" aria-hidden="true"></div>

                        <div class="p-6 sm:p-7 flex flex-col flex-1">
                            <div class="w-12 h-12 rounded-lg {{ $accent['soft'] }} {{ $accent['icon'] }} flex items-center justify-center mb-5">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $card['icon'] }}"/>
                                </svg>
                            </div>

                            <h3 class="text-xl font-bold text-gray-900 mb-3">
                                {{ $services[$slug]['title'] }}
                            </h3>

                            <p class="text-base text-gray-700 leading-relaxed mb-5">
                                {{ $services[$slug]['summary'] }}
                            </p>

                            <ul class="space-y-2.5 mb-6 flex-1">
                                @foreach ($card['points'] as $point)
                                    <li class="flex gap-2.5 text-sm text-gray-600">
                                        <svg class="w-4 h-4 shrink-0 mt-0.5 {{ $accent['icon'] }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                        </svg>
                                        <span>{{ $point }}</span>
                                    </li>
                                @endforeach
                            </ul>

                            <a href="{{ route($card['route']) }}"
                               class="inline-flex items-center gap-2 font-semibold {{ $accent['link'] }} transition">
                                {{ $card['link'] }}
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                                </svg>
                            </a>
                        </div>
                    </article>
                @endforeach
            </div>

        </div>
    </section>

    {{-- ============================ Promotional merchandise ============================ --}}
    <section class="py-16 bg-white">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="max-w-4xl">

                <div class="rounded-lg border border-gray-200 shadow-sm overflow-hidden">
                    <div class="grid grid-cols-1 lg:grid-cols-12">

                        <div class="lg:col-span-4 bg-gray-50 p-7 flex flex-col justify-center">
                            <svg class="w-10 h-10 text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                            </svg>

                            <h2 class="text-2xl md:text-3xl font-bold text-gray-900 mb-2">
                                Promotional Merchandise
                            </h2>

                            <p class="text-sm font-semibold text-gray-500">
                                Sold through the Shop, not quoted as a project
                            </p>
                        </div>

                        <div class="lg:col-span-8 p-7">
                            <p class="text-base text-gray-700 leading-relaxed mb-4">
                                Medals, trophies, apparel and event merchandise. It has no page of its
                                own here because it is not a service we scope and schedule: it is a
                                catalogue, so it lives in the Shop.
                            </p>

                            <p class="text-base text-gray-700 leading-relaxed mb-6">
                                Bulk orders, custom medals and anything branded for a specific event are
                                quoted directly. Tell us the item, the quantity and the date you need it
                                by.
                            </p>

                            <div class="flex flex-wrap gap-4">
                                <a href="{{ route('shop') }}"
                                   class="inline-flex items-center gap-2 bg-blue-600 text-white px-6 py-3 rounded-lg font-semibold hover:bg-blue-700 transition shadow-sm">
                                    Browse the Shop
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                                    </svg>
                                </a>

                                <a href="{{ route('contact') }}"
                                   class="inline-flex items-center gap-2 border-2 border-gray-300 text-gray-700 px-6 py-3 rounded-lg font-semibold hover:bg-gray-50 transition">
                                    Ask for a merchandise quote
                                </a>
                            </div>
                        </div>

                    </div>
                </div>

            </div>
        </div>
    </section>

    {{-- ============================ Which one ============================ --}}
    <section class="py-16 bg-gray-50">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="max-w-3xl">

                <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-10">
                    Not sure which one you need
                </h2>

                @php
                    /*
                     | Answers taken from the FAQ sections of the pages they point at, so
                     | this page is not a second, slightly different account of the same
                     | thing.
                     */
                    $routes = [
                        [
                            'q' => 'We have an event and nobody to run it',
                            'a' => 'That is Event Management. You get one named contact, a written plan with dates against every task, and a live count of registrations you can check yourself at any hour.',
                            'route' => 'services.event-management',
                            'link' => 'See the six delivery stages',
                        ],
                        [
                            'q' => 'We run our own event but the entries are a mess',
                            'a' => 'Then use only the registration system. Plenty of clients run their own event and want entries and payment handled properly, and that service stands on its own.',
                            'route' => 'services.online-registration',
                            'link' => 'See what one entry goes through',
                        ],
                        [
                            'q' => 'The event is sorted but it looks like it was thrown together',
                            'a' => 'That is Digital Creative Solutions. People decide whether an event is worth their Saturday from a single image on their phone, and that image is doing the selling.',
                            'route' => 'services.digital-creative',
                            'link' => 'See what you receive, in file formats',
                        ],
                    ];
                @endphp

                <div class="space-y-4">
                    @foreach ($routes as $item)
                        <div class="bg-white rounded-lg border border-gray-200 shadow-sm p-6">
                            <h3 class="text-lg font-bold text-gray-900 mb-2.5">{{ $item['q'] }}</h3>

                            <p class="text-base text-gray-600 leading-relaxed mb-4">{{ $item['a'] }}</p>

                            <a href="{{ route($item['route']) }}"
                               class="inline-flex items-center gap-2 text-sm font-semibold text-blue-700 hover:text-blue-900 transition">
                                {{ $item['link'] }}
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                                </svg>
                            </a>
                        </div>
                    @endforeach
                </div>

            </div>
        </div>
    </section>

    {{-- ============================ Next step ============================ --}}
    <section class="py-16 bg-gradient-to-r from-gray-900 to-blue-900 text-white">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="max-w-3xl">
                <h2 class="text-3xl md:text-4xl font-bold mb-4">Tell us the date and what it is for</h2>

                <p class="text-base md:text-lg text-gray-300 mb-8">
                    That is enough to start, and you do not have to know which service you want. We
                    will come back with whether it is feasible, what it would take, and a rough
                    cost.
                </p>

                <div class="flex flex-wrap gap-4">
                    <a href="{{ route('contact') }}"
                       class="inline-flex items-center gap-2 bg-blue-600 text-white px-7 py-3.5 rounded-lg font-semibold hover:bg-blue-700 transition shadow-md">
                        Talk to us
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
