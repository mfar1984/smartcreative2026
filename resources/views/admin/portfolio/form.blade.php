@extends('layouts.admin')

@php
    use App\Models\PortfolioProject;

    $isCreate = $mode === 'create';
    $heading = $isCreate ? 'Add Portfolio Project' : 'Edit Portfolio Project';
    $action = $isCreate
        ? route('admin.portfolio.store')
        : route('admin.portfolio.update', $project);

    $input = 'w-full rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';
@endphp

@section('title', $heading)

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>Portfolio</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <a href="{{ route('admin.portfolio.index') }}" class="hover:text-gray-700 transition">Projects</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">{{ $isCreate ? 'Add' : 'Edit' }}</span>
@endsection

@section('content')
    <form action="{{ $action }}" method="POST" enctype="multipart/form-data">
        @csrf
        @unless ($isCreate)
            @method('PUT')
        @endunless

        <x-admin.page-card
            :title="$heading"
            description="One piece of work delivered. It appears on the public Portfolio page once the status is Published."
            :back="route('admin.portfolio.index')">

            <x-slot:actions>
                <a href="{{ route('admin.portfolio.index') }}"
                   class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                    Cancel
                </a>
                <button type="submit"
                        class="inline-flex items-center gap-2 bg-blue-600 text-white px-5 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1-4l-4 4m0 0L8 3m4 4V3"/>
                    </svg>
                    {{ $isCreate ? 'Save Project' : 'Save Changes' }}
                </button>
            </x-slot:actions>

            <x-admin.section-intro
                title="Project Details"
                description="What the work was, who it was for, and when it was delivered."
                icon="photo" />

            {{-- ---------------- Image ---------------- --}}
            <x-admin.panel title="Cover Image" icon="photo">
                <x-admin.field-row
                    label="Cover Image"
                    help="JPG, PNG or WebP up to 4 MB. Shown as the card on the public page. Landscape works best; the card crops to a 4:3 shape."
                    for="image"
                    error="image">

                    <div class="flex flex-wrap items-start gap-4">
                        <div class="w-40 h-30 shrink-0 rounded-lg border border-gray-200 bg-gray-50 overflow-hidden flex items-center justify-center">
                            <img id="image-preview"
                                 src="{{ $project->imageUrl() ?? '' }}"
                                 alt="Cover image preview"
                                 @class(['w-full h-full object-cover', 'hidden' => ! $project->imageUrl()])>
                            <span id="image-empty"
                                  @class(['text-xs text-gray-400 px-3 text-center', 'hidden' => (bool) $project->imageUrl()])>
                                No image uploaded
                            </span>
                        </div>

                        <div class="flex-1 min-w-48 space-y-2">
                            <input type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp"
                                   class="block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-blue-600 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-blue-700 file:cursor-pointer">

                            @if ($project->imageUrl())
                                <x-admin.toggle name="remove_image" :checked="old('remove_image')" label="Remove the current image" />
                            @endif

                            <p class="text-xs text-gray-500">
                                A project without an image still shows, using a plain lettered tile
                                instead. It is worth uploading one: the image is what people look at.
                            </p>
                        </div>
                    </div>
                </x-admin.field-row>
            </x-admin.panel>

            {{-- ---------------- Identity ---------------- --}}
            <x-admin.panel title="What It Was" icon="identification">
                <x-admin.field-row label="Project Title" help="Shown as the card heading." for="title" :required="true" error="title">
                    <input type="text" id="title" name="title" required maxlength="180"
                           value="{{ old('title', $project->title) }}"
                           placeholder="e.g. PUBG Mobile Sibu Esport Championship 2026"
                           class="{{ $input }}">
                </x-admin.field-row>

                <x-admin.field-row label="URL Slug" help="Leave blank to build one from the title." for="slug" error="slug">
                    <input type="text" id="slug" name="slug" maxlength="180"
                           value="{{ old('slug', $project->slug) }}"
                           placeholder="pubg-mobile-sibu-esport-championship-2026"
                           class="{{ $input }}">
                </x-admin.field-row>

                <x-admin.field-row
                    label="Client"
                    help="Leave blank where the work was our own, or where the client would rather not be named. The card then reads Smart Digital Creative."
                    for="client"
                    error="client">
                    <input type="text" id="client" name="client" maxlength="180"
                           value="{{ old('client', $project->client) }}"
                           placeholder="e.g. Sibu Esports Association"
                           class="{{ $input }}">
                </x-admin.field-row>

                <x-admin.field-row
                    label="Service"
                    help="Which service this work belongs to. Visitors filter the portfolio by this."
                    for="service"
                    :required="true"
                    error="service">
                    <select id="service" name="service" required class="{{ $input }}">
                        @foreach ($services as $slug => $label)
                            <option value="{{ $slug }}" @selected(old('service', $project->service) === $slug)>{{ $label }}</option>
                        @endforeach
                    </select>
                </x-admin.field-row>

                <x-admin.field-row
                    label="Category"
                    help="Free text, shown on the card. Reuse the same wording as the event categories so the site reads consistently."
                    for="category"
                    :required="true"
                    error="category">
                    <input type="text" id="category" name="category" required maxlength="100" list="category-options"
                           value="{{ old('category', $project->category) }}"
                           placeholder="e.g. E-Sport"
                           class="{{ $input }}">
                    <datalist id="category-options">
                        @foreach ($categories as $option)
                            <option value="{{ $option }}"></option>
                        @endforeach
                    </datalist>
                </x-admin.field-row>

                <x-admin.field-row label="Location" help="Where it happened. Left off the card when blank." for="location" error="location">
                    <input type="text" id="location" name="location" maxlength="180"
                           value="{{ old('location', $project->location) }}"
                           placeholder="e.g. RH Hotel Sibu, Sarawak"
                           class="{{ $input }}">
                </x-admin.field-row>

                <x-admin.field-row
                    label="Delivered On"
                    help="Only the month and year are shown. Cannot be a future date: this records work already done."
                    for="delivered_on"
                    :required="true"
                    error="delivered_on">
                    <input type="date" id="delivered_on" name="delivered_on" required
                           max="{{ now()->toDateString() }}"
                           value="{{ old('delivered_on', $project->delivered_on?->toDateString()) }}"
                           class="{{ $input }}">
                </x-admin.field-row>
            </x-admin.panel>

            {{-- ---------------- Words ---------------- --}}
            <x-admin.panel title="What To Say About It" icon="clipboard">
                <x-admin.field-row
                    label="Summary"
                    help="The one or two sentences on the card. 280 characters, because it has to stay scannable."
                    for="summary"
                    :required="true"
                    error="summary">
                    <textarea id="summary" name="summary" rows="3" required maxlength="280"
                              placeholder="e.g. Registration, payment and live scoring for a 32 team mobile esports championship, run over one weekend."
                              class="{{ $input }} resize-y">{{ old('summary', $project->summary) }}</textarea>
                </x-admin.field-row>

                <x-admin.field-row
                    label="Highlights"
                    help="One per line. Each line becomes a bullet on the card. Numbers work well here. Leave blank and no bullets appear."
                    for="highlights"
                    error="highlights">
                    <textarea id="highlights" name="highlights" rows="5" maxlength="2000"
                              placeholder="One per line, for example:&#10;32 teams, 161 players registered online&#10;All entry fees collected by card and online banking&#10;Standings published the same evening"
                              class="{{ $input }} resize-y">{{ old('highlights', $project->highlights) }}</textarea>
                </x-admin.field-row>

                <x-admin.field-row
                    label="Full Description"
                    help="Optional. Held for a future detail page; it is not shown on the grid today."
                    for="description"
                    error="description">
                    <textarea id="description" name="description" rows="6" maxlength="5000"
                              class="{{ $input }} resize-y">{{ old('description', $project->description) }}</textarea>
                </x-admin.field-row>
            </x-admin.panel>

            {{-- ---------------- Publishing ---------------- --}}
            <x-admin.panel title="Publishing" icon="globe">
                <x-admin.field-row
                    label="Status"
                    help="Draft keeps it out of sight. Published puts it on the public Portfolio page immediately."
                    for="status"
                    :required="true"
                    error="status">
                    <select id="status" name="status" required class="{{ $input }}">
                        @foreach ($statuses as $slug => $label)
                            <option value="{{ $slug }}" @selected(old('status', $project->status) === $slug)>{{ $label }}</option>
                        @endforeach
                    </select>
                </x-admin.field-row>

                <x-admin.field-row
                    label="Featured"
                    help="Featured projects lead the grid, ahead of everything else. Use it for the two or three you would show a new client first."
                    error="is_featured">
                    <x-admin.toggle
                        name="is_featured"
                        :checked="old('is_featured', $project->is_featured)"
                        label="Show this project first" />
                </x-admin.field-row>

                <x-admin.field-row
                    label="Sort Order"
                    help="Lower numbers come first, within the featured and unfeatured groups. Leave at 0 to order by delivery date instead."
                    for="sort_order"
                    error="sort_order">
                    <input type="number" id="sort_order" name="sort_order" min="0" max="9999"
                           value="{{ old('sort_order', $project->sort_order ?? 0) }}"
                           class="{{ $input }} max-w-32">
                </x-admin.field-row>
            </x-admin.panel>

        </x-admin.page-card>
    </form>
@endsection

@push('scripts')
    <script>
        /*
         | Local preview of a chosen file. Reads it in the browser, so nothing is
         | uploaded until the form is submitted.
         */
        (function () {
            const field = document.getElementById('image');
            const preview = document.getElementById('image-preview');
            const empty = document.getElementById('image-empty');

            if (!field || !preview) {
                return;
            }

            field.addEventListener('change', function () {
                const file = this.files && this.files[0];

                if (!file) {
                    return;
                }

                const reader = new FileReader();

                reader.addEventListener('load', function () {
                    preview.src = reader.result;
                    preview.classList.remove('hidden');

                    if (empty) {
                        empty.classList.add('hidden');
                    }
                });

                reader.readAsDataURL(file);
            });
        })();
    </script>
@endpush
