@extends('layouts.admin')

@section('title', $mode === 'create' ? 'New Template' : 'Edit Template')

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <a href="{{ route('admin.campaigns.templates') }}" class="hover:text-gray-700 transition">Templates</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">{{ $mode === 'create' ? 'New' : $template->name }}</span>
@endsection

@section('content')
    @php
        use App\Support\EventTemplates;

        $input = 'w-full rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';
        $channel = old('channel', $template->channel ?: EventTemplates::CHANNEL_EMAIL);
    @endphp

    <x-admin.page-card
        :title="$mode === 'create' ? 'New Campaign Template' : 'Edit ' . $template->name"
        description="Reusable wording. A campaign copies it, so changes here never alter a message already sent."
        :back="route('admin.campaigns.templates', ['tab' => $channel])">

        @if ($errors->any())
            <div role="alert" class="bg-red-50 border border-red-200 rounded-lg p-4 mb-5">
                <p class="text-sm font-bold text-red-900 mb-1">Nothing was saved</p>
                <ul class="text-sm text-red-800 space-y-0.5">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form action="{{ $mode === 'create' ? route('admin.campaigns.templates.store') : route('admin.campaigns.templates.update', $template) }}"
              method="POST">
            @csrf
            @if ($mode !== 'create') @method('PUT') @endif

            <x-admin.panel title="Template" icon="mail">
                <x-admin.field-row label="Name" help="For finding it again. Recipients never see this." for="name" :required="true" error="name">
                    <input type="text" id="name" name="name" required maxlength="190"
                           value="{{ old('name', $template->name) }}"
                           class="{{ $input }}">
                </x-admin.field-row>

                <x-admin.field-row label="Channel" for="channel" :required="true" error="channel">
                    <select id="channel" name="channel" class="{{ $input }} bg-white" data-channel>
                        @foreach ($channels as $value => $label)
                            <option value="{{ $value }}" @selected($channel === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </x-admin.field-row>

                <x-admin.field-row label="Available" help="Switch off to keep the wording without offering it to new campaigns." error="is_active">
                    <input type="hidden" name="is_active" value="0">
                    <x-admin.toggle name="is_active" id="is_active"
                                    :checked="(bool) old('is_active', $template->is_active ?? true)"
                                    label="Offered when creating a campaign" />
                </x-admin.field-row>

                <div data-subject-row @class(['hidden' => $channel !== EventTemplates::CHANNEL_EMAIL])>
                    <x-admin.field-row label="Subject" for="subject" error="subject">
                        <input type="text" id="subject" name="subject" maxlength="200"
                               value="{{ old('subject', $template->subject) }}"
                               class="{{ $input }}">
                    </x-admin.field-row>
                </div>

                <x-admin.field-row label="Message" for="body" :required="true" error="body">
                    <textarea id="body" name="body" rows="12" required
                              class="{{ $input }} resize-y font-mono text-xs"
                              data-body>{{ old('body', $template->body) }}</textarea>

                    <p class="text-xs text-gray-500 mt-1.5" data-length>
                        <span data-count>0</span> characters<span data-segments></span>
                    </p>
                </x-admin.field-row>

                <x-admin.field-row label="Placeholders">
                    <div class="md:pt-1 space-y-1">
                        @foreach ($placeholders as $key => $description)
                            <p class="text-xs text-gray-600">
                                <code class="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-gray-800">{{ \App\Services\Campaign\CampaignRenderer::token($key) }}</code>
                                <span class="ml-1.5">{{ $description }}</span>
                            </p>
                        @endforeach
                    </div>
                </x-admin.field-row>
            </x-admin.panel>

            <div class="flex items-center justify-between gap-4 bg-white rounded-lg border border-gray-200 px-5 py-4 mt-5">
                <p class="text-xs text-gray-500">Templates send nothing on their own.</p>
                <button type="submit" class="bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-sm shrink-0">
                    {{ $mode === 'create' ? 'Create Template' : 'Save Changes' }}
                </button>
            </div>
        </form>
    </x-admin.page-card>
@endsection

@push('scripts')
<script>
    (function () {
        const channel = document.querySelector('[data-channel]');
        const subjectRow = document.querySelector('[data-subject-row]');
        const body = document.querySelector('[data-body]');
        const count = document.querySelector('[data-count]');
        const segments = document.querySelector('[data-segments]');

        function sync() {
            const isEmail = channel.value === 'email';
            subjectRow?.classList.toggle('hidden', !isEmail);

            const length = body.value.length;
            count.textContent = length;

            if (isEmail) {
                segments.textContent = '';
                segments.className = '';

                return;
            }

            // Billed per segment, so a body three characters over 160 doubles the
            // cost of every campaign that uses this template.
            const parts = length <= 160 ? 1 : Math.ceil(length / 153);
            segments.textContent = ' · ' + parts + (parts === 1 ? ' SMS segment' : ' SMS segments, billed per segment');
            segments.className = parts > 1 ? 'text-amber-700 font-semibold' : '';
        }

        channel?.addEventListener('change', sync);
        body?.addEventListener('input', sync);
        sync();
    })();
</script>
@endpush
