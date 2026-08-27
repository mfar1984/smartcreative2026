@extends('layouts.master')

@section('title', $pageTitle)

@section('content')
    @include('components.page-header', [
        'title' => $pageTitle,
        'subtitle' => $pageSubtitle,
    ])

    @php
        // Accent classes are written out in full so Tailwind can find them
        // when scanning this file.
        $accents = [
            'blue' => [
                'card' => 'from-blue-50 to-blue-100 border-blue-500',
                'icon' => 'bg-blue-500',
                'value' => 'text-blue-700 hover:text-blue-800',
            ],
            'purple' => [
                'card' => 'from-purple-50 to-purple-100 border-purple-500',
                'icon' => 'bg-purple-500',
                'value' => 'text-purple-700 hover:text-purple-800',
            ],
            'green' => [
                'card' => 'from-green-50 to-green-100 border-green-500',
                'icon' => 'bg-green-500',
                'value' => 'text-green-700 hover:text-green-800',
            ],
        ];
    @endphp

    {{-- Contact methods --}}
    <section class="py-20 bg-gradient-to-b from-white to-gray-50">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-12">
                <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-3">Get in Touch</h2>
                <p class="text-lg text-blue-600 font-semibold">We are here to help with your next project</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 max-w-6xl mx-auto">
                @foreach ($contactMethods as $method)
                    @php $accent = $accents[$method['accent']] ?? $accents['blue']; @endphp

                    <div class="bg-gradient-to-br {{ $accent['card'] }} rounded-lg shadow-md p-6 border-t-4 hover:shadow-xl transition">
                        <div class="{{ $accent['icon'] }} p-3 rounded-full w-12 h-12 flex items-center justify-center mb-4">
                            @switch($method['icon'])
                                @case('phone')
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                                    </svg>
                                    @break

                                @case('mail')
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                                    </svg>
                                    @break

                                @case('whatsapp')
                                    <svg class="w-6 h-6 text-white" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.174-.297-.019-.458.13-.606.134-.133.347-.347.52-.52.174-.174.232-.298.347-.497.116-.198.058-.371-.058-.52-.115-.148-.66-1.593-.905-2.181-.235-.567-.472-.49-.66-.5-.174-.008-.372-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.71.306 1.263.489 1.695.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/>
                                        <path d="M20.52 3.449C18.24 1.245 15.24 0 12.045 0 5.463 0 .104 5.334.101 11.892c0 2.096.549 4.14 1.595 5.945L0 24l6.335-1.652a11.882 11.882 0 005.71 1.447h.006c6.585 0 11.946-5.336 11.949-11.896 0-3.176-1.24-6.165-3.495-8.411M12.05 21.785h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.002-5.45 4.437-9.884 9.889-9.884a9.816 9.816 0 016.988 2.898 9.825 9.825 0 012.892 6.994c-.003 5.45-4.437 9.884-9.885 9.884"/>
                                    </svg>
                                    @break
                            @endswitch
                        </div>

                        <span class="block text-xs text-gray-600 uppercase tracking-wide mb-1">{{ $method['label'] }}</span>

                        <a href="{{ $method['url'] }}"
                           @if ($method['external']) target="_blank" rel="noopener noreferrer" @endif
                           class="block text-base font-bold {{ $accent['value'] }} transition break-words">
                            {{ $method['value'] }}
                        </a>

                        <p class="text-xs text-gray-600 mt-2">{{ $method['note'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Office address and business hours --}}
    <section class="py-20 bg-white">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-12">
                <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-3">Office Address</h2>
                <p class="text-lg text-blue-600 font-semibold">Visit us during business hours, or send us a message</p>
            </div>

            <div class="max-w-6xl mx-auto grid grid-cols-1 lg:grid-cols-2 gap-8">
                {{-- Office details and business hours --}}
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-8">
                    <div>
                        <span class="block text-xs text-gray-500 uppercase tracking-wide mb-3">{{ $office['heading'] }}</span>

                        <h3 class="text-lg font-bold text-gray-900 mb-1">{{ $office['name'] }}</h3>
                        <p class="text-sm text-gray-600 mb-4">({{ $office['registration'] }})</p>

                        <address class="not-italic text-base text-gray-700 leading-relaxed mb-6">
                            @foreach ($office['address'] as $line)
                                {{ $line }}@if (!$loop->last)<br>@endif
                            @endforeach
                        </address>

                        <a href="{{ $office['directions_url'] }}"
                           target="_blank"
                           rel="noopener noreferrer"
                           class="inline-flex items-center gap-2 bg-blue-600 text-white px-8 py-3 rounded-lg font-semibold hover:bg-blue-700 transition shadow-md">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/>
                            </svg>
                            Get Directions
                        </a>
                    </div>

                    {{-- Business hours --}}
                    <div class="mt-8 pt-8 border-t border-gray-100">
                        <span class="block text-xs text-gray-500 uppercase tracking-wide mb-3">Business Hours</span>

                        <dl class="divide-y divide-gray-100">
                            @foreach ($businessHours as $slot)
                                <div class="flex items-center justify-between gap-4 py-3">
                                    <dt class="text-sm text-gray-600">{{ $slot['days'] }}</dt>
                                    <dd class="text-sm font-bold {{ $slot['closed'] ? 'text-red-600' : 'text-gray-900' }}">
                                        {{ $slot['hours'] }}
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                </div>

                {{-- Send us a message --}}
                <div id="send-message" class="bg-white rounded-xl border border-gray-200 shadow-sm p-8 scroll-mt-32">
                    <h3 class="text-xl md:text-2xl font-bold text-gray-900 mb-1">Send Us a Message</h3>
                    <p class="text-sm text-gray-600 mb-6">Fields marked with an asterisk are required.</p>

                    @if (session('contact_status'))
                        <div role="alert" class="flex items-start gap-3 bg-green-50 border border-green-200 rounded-lg p-4 mb-6">
                            <svg class="w-5 h-5 shrink-0 text-green-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <p class="text-sm text-green-800">{{ session('contact_status') }}</p>
                        </div>
                    @endif

                    @if ($errors->any())
                        <div role="alert" class="flex items-start gap-3 bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
                            <svg class="w-5 h-5 shrink-0 text-red-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                            </svg>
                            <p class="text-sm text-red-800">Please correct the highlighted fields and try again.</p>
                        </div>
                    @endif

                    <form action="{{ route('contact.store') }}" method="POST" class="space-y-5">
                        @csrf

                        {{-- Name --}}
                        <div>
                            <label for="name" class="block text-sm font-semibold text-gray-700 mb-1.5">
                                Your Name <span class="text-red-600" aria-hidden="true">*</span>
                            </label>
                            <input type="text"
                                   id="name"
                                   name="name"
                                   value="{{ old('name') }}"
                                   required
                                   maxlength="120"
                                   autocomplete="name"
                                   @error('name') aria-invalid="true" aria-describedby="name-error" @enderror
                                   class="w-full rounded-lg border @error('name') border-red-500 @else border-gray-300 @enderror px-4 py-2.5 text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
                            @error('name')
                                <p id="name-error" class="text-xs text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Email --}}
                        <div>
                            <label for="email" class="block text-sm font-semibold text-gray-700 mb-1.5">
                                Email Address <span class="text-red-600" aria-hidden="true">*</span>
                            </label>
                            <input type="email"
                                   id="email"
                                   name="email"
                                   value="{{ old('email') }}"
                                   required
                                   maxlength="190"
                                   autocomplete="email"
                                   @error('email') aria-invalid="true" aria-describedby="email-error" @enderror
                                   class="w-full rounded-lg border @error('email') border-red-500 @else border-gray-300 @enderror px-4 py-2.5 text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
                            @error('email')
                                <p id="email-error" class="text-xs text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Phone --}}
                        <div>
                            <label for="phone" class="block text-sm font-semibold text-gray-700 mb-1.5">
                                Phone Number
                            </label>
                            <input type="tel"
                                   id="phone"
                                   name="phone"
                                   value="{{ old('phone') }}"
                                   maxlength="30"
                                   autocomplete="tel"
                                   @error('phone') aria-invalid="true" aria-describedby="phone-error" @enderror
                                   class="w-full rounded-lg border @error('phone') border-red-500 @else border-gray-300 @enderror px-4 py-2.5 text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
                            @error('phone')
                                <p id="phone-error" class="text-xs text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Service --}}
                        <div>
                            <label for="service" class="block text-sm font-semibold text-gray-700 mb-1.5">
                                Service Interested <span class="text-red-600" aria-hidden="true">*</span>
                            </label>
                            <select id="service"
                                    name="service"
                                    required
                                    @error('service') aria-invalid="true" aria-describedby="service-error" @enderror
                                    class="w-full rounded-lg border @error('service') border-red-500 @else border-gray-300 @enderror px-4 py-2.5 text-sm text-gray-900 bg-white focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
                                <option value="">Select a service</option>
                                @foreach ($services as $value => $label)
                                    <option value="{{ $value }}" @selected(old('service') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('service')
                                <p id="service-error" class="text-xs text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Message --}}
                        <div>
                            <label for="message" class="block text-sm font-semibold text-gray-700 mb-1.5">
                                Your Message <span class="text-red-600" aria-hidden="true">*</span>
                            </label>
                            <textarea id="message"
                                      name="message"
                                      rows="5"
                                      required
                                      minlength="10"
                                      maxlength="3000"
                                      @error('message') aria-invalid="true" aria-describedby="message-error" @enderror
                                      class="w-full rounded-lg border @error('message') border-red-500 @else border-gray-300 @enderror px-4 py-2.5 text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition resize-y">{{ old('message') }}</textarea>
                            @error('message')
                                <p id="message-error" class="text-xs text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <button type="submit"
                                class="w-full inline-flex items-center justify-center gap-2 bg-blue-600 text-white px-8 py-3 rounded-lg font-semibold hover:bg-blue-700 transition shadow-md">
                            Send Message
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
                            </svg>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </section>

    {{-- FAQ --}}
    <section class="py-20 bg-gradient-to-b from-gray-50 to-white">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-12">
                <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-3">Frequently Asked Questions</h2>
                <p class="text-lg text-blue-600 font-semibold">Quick answers to common questions</p>
            </div>

            <div class="max-w-4xl mx-auto space-y-4">
                @foreach ($faqs as $faq)
                    <div class="bg-white rounded-lg shadow-md p-6 hover:shadow-xl transition">
                        <div class="flex items-start gap-3 mb-3">
                            <span class="bg-blue-100 text-blue-600 rounded-full w-7 h-7 flex items-center justify-center shrink-0 text-sm font-bold" aria-hidden="true">?</span>
                            <h3 class="text-base font-bold text-gray-900 pt-0.5">{{ $faq['question'] }}</h3>
                        </div>
                        <p class="text-sm text-gray-600 leading-relaxed pl-10">
                            {{ $faq['answer'] }}
                        </p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
@endsection
