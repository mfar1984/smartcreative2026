@extends('layouts.admin')

@php
    $isCreate = $mode === 'create';
    $heading = match ($mode) {
        'create' => 'Create Role',
        'edit' => 'Edit Role',
        default => 'View Role',
    };
    $action = $isCreate
        ? route('admin.settings.roles.store')
        : route('admin.settings.roles.update', $role);
    $input = 'w-full rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition disabled:bg-gray-100 disabled:text-gray-500';
@endphp

@section('title', $heading)

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>Settings</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <a href="{{ route('admin.settings.roles') }}" class="hover:text-gray-700 transition">Roles Management</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">{{ $heading }}</span>
@endsection

@section('content')
    <form action="{{ $action }}" method="POST" id="role-form">
        @csrf
        @unless ($isCreate)
            @method('PUT')
        @endunless

        <x-admin.page-card
            :title="$heading"
            :description="$isCreate ? 'Define a new role and set its permission matrix.' : 'Adjust this role and its permission matrix.'"
            :back="route('admin.settings.roles')">

            <x-slot:actions>
                @if (! $readonly)
                    <button type="button" data-matrix-clear
                            class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                        Clear All
                    </button>
                    <button type="button" data-matrix-select
                            class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                        Select All
                    </button>
                    <button type="submit"
                            class="inline-flex items-center gap-2 bg-blue-600 text-white px-5 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-sm">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1-4l-4 4m0 0L8 3m4 4V3"/>
                        </svg>
                        {{ $isCreate ? 'Save Role' : 'Save Changes' }}
                    </button>
                @else
                    @if ($mode !== 'show')
                        <x-admin.badge tone="purple">System role, locked</x-admin.badge>
                    @endif
                    @if ($mode === 'show' && auth()->user()->hasPermission('roles.update') && ! $role->isSuperAdmin())
                        <a href="{{ route('admin.settings.roles.edit', $role) }}"
                           class="inline-flex items-center gap-2 bg-blue-600 text-white px-5 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-sm">
                            Edit Role
                        </a>
                    @endif
                @endif
            </x-slot:actions>

            @if ($role->isSuperAdmin())
                <div role="note" class="flex items-start gap-3 bg-purple-50 border border-purple-200 rounded-lg p-4 mb-5">
                    <svg class="w-5 h-5 shrink-0 text-purple-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                    </svg>
                    <p class="text-sm text-purple-800">
                        Super Admin always holds every permission and stays active. This keeps a
                        guaranteed way back into the system, so its matrix cannot be edited here.
                    </p>
                </div>
            @endif

            {{-- Role details --}}
            <x-admin.panel title="Role Details" icon="shield">
                <x-admin.field-row label="Role Name" help="Shown wherever the role is listed." for="name" :required="true" error="name">
                    <input type="text" id="name" name="name" required maxlength="100"
                           value="{{ old('name', $role->name) }}"
                           placeholder="e.g. Event Manager, Editor, Viewer"
                           @disabled($readonly)
                           class="{{ $input }}">
                </x-admin.field-row>

                <x-admin.field-row label="Status" help="An inactive role blocks its users from signing in." for="is_active" error="is_active">
                    <div class="md:pt-1">
                        <x-admin.toggle
                            name="is_active"
                            :checked="old('is_active', $role->is_active ?? true)"
                            label="Role is active"
                            :disabled="$readonly" />
                        @if ($role->isSuperAdmin())
                            <p class="text-xs text-gray-500 mt-1.5">Super Admin cannot be deactivated.</p>
                        @endif
                    </div>
                </x-admin.field-row>

                <x-admin.field-row label="Description" help="Optional. A short note on what this role is for." for="description" error="description">
                    <input type="text" id="description" name="description" maxlength="255"
                           value="{{ old('description', $role->description) }}"
                           placeholder="Brief description of this role"
                           @disabled($readonly)
                           class="{{ $input }}">
                </x-admin.field-row>
            </x-admin.panel>

            {{-- Permission matrix --}}
            <x-admin.panel title="Permission Matrix" icon="clipboard" :flush="true">
                <div class="px-5 py-3 border-b border-gray-200 bg-blue-50/60">
                    <p class="text-xs text-blue-800">
                        Tick the actions this role may perform on each module. A dash means the
                        action does not exist for that module.
                        <span class="font-semibold" data-matrix-counter>0</span> of {{ $permissionTotal }} selected.
                    </p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm border-collapse">
                        <thead>
                            <tr class="bg-gray-50">
                                <th scope="col" class="sticky left-0 z-10 bg-gray-50 text-left px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500 border-b border-gray-200 min-w-56">
                                    Module
                                </th>
                                @foreach ($actionColumns as $actionKey => $actionLabel)
                                    <th scope="col" class="px-3 py-3 text-center text-xs font-bold uppercase tracking-wide text-gray-500 border-b border-l border-gray-200 w-24">
                                        {{ $actionLabel }}
                                    </th>
                                @endforeach
                                <th scope="col" class="px-3 py-3 text-left text-xs font-bold uppercase tracking-wide text-gray-500 border-b border-l border-gray-200 min-w-40">
                                    Other
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            @foreach ($matrix as $section => $modules)
                                @php
                                    /*
                                    | Keys are stripped before flattening. The columns map is
                                    | keyed by action, so two modules in the same section both
                                    | holding a "view" would collapse onto one another and the
                                    | band would undercount: Campaign read (0/8) when it holds
                                    | seventeen permissions.
                                    */
                                    $sectionIds = collect($modules)
                                        ->flatMap(fn (array $module) => array_merge(
                                            array_values($module['columns']),
                                            array_values($module['other']),
                                        ))
                                        ->map(fn ($permission) => $permission->id)
                                        ->all();
                                    $sectionKey = Str::slug($section);
                                @endphp

                                {{-- Section band --}}
                                <tr>
                                    <th scope="colgroup"
                                        colspan="{{ count($actionColumns) + 2 }}"
                                        class="sticky left-0 bg-blue-50 text-left px-5 py-2 border-y border-blue-100">
                                        <span class="text-xs font-bold uppercase tracking-wide text-blue-800">{{ $section }}</span>
                                        <span class="ml-2 text-xs text-blue-700" data-section-count="{{ $sectionKey }}">
                                            (0/{{ count($sectionIds) }})
                                        </span>
                                        @unless ($readonly)
                                            <button type="button"
                                                    data-section-toggle="{{ $sectionKey }}"
                                                    class="ml-2 text-xs font-bold uppercase tracking-wide text-blue-600 hover:text-blue-800 transition">
                                                Select Section
                                            </button>
                                        @endunless
                                    </th>
                                </tr>

                                @foreach ($modules as $module => $definition)
                                    <tr class="hover:bg-blue-50/30">
                                        <th scope="row" class="sticky left-0 z-10 bg-white text-left px-5 py-2.5 border-b border-gray-100 font-normal">
                                            <span class="text-sm text-gray-900">{{ $module }}</span>
                                        </th>

                                        @foreach ($actionColumns as $actionKey => $actionLabel)
                                            <td class="px-3 py-2.5 text-center border-b border-l border-gray-100">
                                                @isset($definition['columns'][$actionKey])
                                                    @php $permission = $definition['columns'][$actionKey]; @endphp
                                                    <input type="checkbox"
                                                           name="permissions[]"
                                                           value="{{ $permission->id }}"
                                                           data-matrix-box
                                                           data-section="{{ $sectionKey }}"
                                                           @checked(in_array($permission->id, old('permissions', $granted), true) || $role->isSuperAdmin())
                                                           @disabled($readonly)
                                                           class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-2 focus:ring-blue-500/40 disabled:opacity-50"
                                                           aria-label="{{ $actionLabel }} {{ $module }}">
                                                @else
                                                    <span class="text-gray-300" aria-hidden="true">&mdash;</span>
                                                    <span class="sr-only">Not applicable</span>
                                                @endisset
                                            </td>
                                        @endforeach

                                        {{-- Actions without a column of their own --}}
                                        <td class="px-3 py-2.5 border-b border-l border-gray-100">
                                            @if (count($definition['other']) === 0)
                                                <span class="text-gray-300" aria-hidden="true">&mdash;</span>
                                            @else
                                                <div class="flex flex-wrap gap-x-3 gap-y-1">
                                                    @foreach ($definition['other'] as $permission)
                                                        <label class="inline-flex items-center gap-1.5 text-xs text-gray-600">
                                                            <input type="checkbox"
                                                                   name="permissions[]"
                                                                   value="{{ $permission->id }}"
                                                                   data-matrix-box
                                                                   data-section="{{ $sectionKey }}"
                                                                   @checked(in_array($permission->id, old('permissions', $granted), true) || $role->isSuperAdmin())
                                                                   @disabled($readonly)
                                                                   class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-2 focus:ring-blue-500/40 disabled:opacity-50">
                                                            {{ Str::headline($permission->action) }}
                                                        </label>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-admin.panel>

            @unless ($readonly)
                <div class="flex items-center justify-between gap-4 bg-white rounded-lg border border-gray-200 px-5 py-4 mt-5">
                    <p class="text-xs text-gray-500">Users assigned to this role pick up the change on their next request.</p>
                    <button type="submit" class="bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-sm shrink-0">
                        {{ $isCreate ? 'Save Role' : 'Save Changes' }}
                    </button>
                </div>
            @endunless
        </x-admin.page-card>
    </form>
@endsection

@push('scripts')
<script>
    (function () {
        const form = document.getElementById('role-form');
        if (!form) {
            return;
        }

        const boxes = Array.from(form.querySelectorAll('[data-matrix-box]'));
        const counter = form.querySelector('[data-matrix-counter]');

        function refreshCounts() {
            if (counter) {
                counter.textContent = boxes.filter(function (box) { return box.checked; }).length;
            }

            form.querySelectorAll('[data-section-count]').forEach(function (label) {
                const section = label.dataset.sectionCount;
                const inSection = boxes.filter(function (box) { return box.dataset.section === section; });
                const checked = inSection.filter(function (box) { return box.checked; }).length;
                label.textContent = '(' + checked + '/' + inSection.length + ')';
            });
        }

        function setAll(value) {
            boxes.forEach(function (box) {
                if (!box.disabled) {
                    box.checked = value;
                }
            });
            refreshCounts();
        }

        form.querySelector('[data-matrix-select]')?.addEventListener('click', function () { setAll(true); });
        form.querySelector('[data-matrix-clear]')?.addEventListener('click', function () { setAll(false); });

        // Per section toggle: tick the whole section unless it is already full,
        // in which case clear it.
        form.querySelectorAll('[data-section-toggle]').forEach(function (button) {
            button.addEventListener('click', function () {
                const section = button.dataset.sectionToggle;
                const inSection = boxes.filter(function (box) {
                    return box.dataset.section === section && !box.disabled;
                });

                if (inSection.length === 0) {
                    return;
                }

                const shouldCheck = inSection.some(function (box) { return !box.checked; });
                inSection.forEach(function (box) { box.checked = shouldCheck; });
                refreshCounts();
            });
        });

        boxes.forEach(function (box) {
            box.addEventListener('change', refreshCounts);
        });

        refreshCounts();
    })();
</script>
@endpush
