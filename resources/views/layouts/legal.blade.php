{{--
    Shell shared by the five policy pages.

    Sits between layouts.master and each policy view, so the five content views carry
    nothing but their own wording and cannot drift apart in layout. It holds the page
    header, the effective date, the reading column and the closing contact block.

    A nested layout rather than a component, because the project renders public pages
    through @extends and a component cannot take part in that inheritance.

    Company details come from Settings > General Config, the same source the footer
    reads, so the registration number and the address are changed in one place.

    Expects from the controller: $pageTitle, $pageSubtitle, $effectiveFrom
    Content views fill: @section('document')
--}}
@extends('layouts.master')

@section('title', $pageTitle)

@section('content')
    @include('components.page-header', [
        'title' => $pageTitle,
        'subtitle' => $pageSubtitle,
    ])

    <section class="py-16 bg-white">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="max-w-3xl">

                <p class="text-sm text-gray-500 mb-10 pb-6 border-b border-gray-200">
                    In effect from {{ $effectiveFrom }}
                </p>

                <div class="text-base text-gray-700 leading-relaxed">
                    @yield('document')
                </div>

                {{-- Every policy has to say who to contact and name the legal entity,
                     otherwise it is not clear who the agreement is with. --}}
                <div class="mt-12 pt-8 border-t border-gray-200">
                    <h2 class="text-xl font-bold text-gray-900 mb-4">Contact us about this policy</h2>

                    <div class="text-base text-gray-700 leading-relaxed">
                        <p class="mb-4">
                            Write to us if anything here is unclear, or if you want to exercise a
                            right described above.
                        </p>

                        <p class="font-semibold text-gray-900">{{ App\Support\GeneralSettings::siteName() }}</p>
                        <p class="text-sm text-gray-500 mb-3">Registration: {{ App\Support\GeneralSettings::registrationNo() }}</p>

                        <p class="mb-3">
                            @foreach (App\Support\GeneralSettings::addressLines() as $line)
                                {{ $line }}@if (! $loop->last)<br>@endif
                            @endforeach
                        </p>

                        <p>
                            Email:
                            <a href="mailto:{{ App\Support\GeneralSettings::contactEmail() }}" class="text-blue-600 hover:text-blue-800 font-semibold">
                                {{ App\Support\GeneralSettings::contactEmail() }}
                            </a>
                            <br>
                            Phone:
                            <a href="{{ App\Support\GeneralSettings::contactPhoneLink() }}" class="text-blue-600 hover:text-blue-800 font-semibold">
                                {{ App\Support\GeneralSettings::contactPhone() }}
                            </a>
                        </p>
                    </div>
                </div>

            </div>
        </div>
    </section>
@endsection
