@extends('layouts.admin')

@section('title', 'Preview: ' . $definition['label'])

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>Event</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <a href="{{ route('admin.event.settings', ['tab' => $template->channel]) }}" class="hover:text-gray-700 transition">Settings</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">Preview</span>
@endsection

@section('content')
    <x-admin.page-card
        :title="$definition['label']"
        :description="$channelLabel . ' · rendered with sample data, nothing was sent'"
        :back="route('admin.event.settings', ['tab' => $template->channel])">

        <div role="note" class="flex items-start gap-3 bg-blue-50 border border-blue-200 rounded-lg p-4 mb-5">
            <svg class="w-5 h-5 shrink-0 text-blue-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <p class="text-sm text-blue-800">
                Every value below is invented, using a made up PUBG squad. This shows the last
                <strong>saved</strong> version of the template, so save a change before previewing it.
            </p>
        </div>

        @if ($template->isEmail())
            <x-admin.panel title="Subject" icon="mail">
                <p class="px-5 py-4 text-sm font-semibold text-gray-900">{{ $subject }}</p>
            </x-admin.panel>
        @endif

        <x-admin.panel title="Message" icon="clipboard">
            <div class="px-5 py-4">
                {{--
                    whitespace-pre-line keeps the line breaks the author typed while
                    still escaping the content, which is the same treatment the real
                    email layout gives it. Anything resembling markup shows as text.
                --}}
                <div class="rounded-lg border border-gray-200 bg-white p-5 text-sm leading-relaxed text-gray-800 whitespace-pre-line">{{ $body }}</div>

                @unless ($template->isEmail())
                    @php $length = mb_strlen($body); @endphp

                    <p class="text-xs text-gray-500 mt-3">
                        {{ number_format($length) }} characters after the placeholders were filled in
                        &middot; {{ $length === 0 ? 0 : (int) ceil($length / 160) }} SMS segment(s)
                        @if ($length > 160)
                            <span class="font-semibold text-amber-700">
                                &middot; this would be billed as more than one message
                            </span>
                        @endif
                    </p>
                @endunless
            </div>
        </x-admin.panel>

        @unless ($template->is_active)
            <div role="alert" class="flex items-start gap-3 bg-gray-100 border border-gray-300 rounded-lg p-4 mt-5">
                <x-admin.icon name="power" class="w-5 h-5 shrink-0 text-gray-500 mt-0.5" />
                <p class="text-sm text-gray-700">
                    This template is switched off, so it would not be sent even once sending is
                    wired up.
                </p>
            </div>
        @endunless
    </x-admin.page-card>
@endsection
