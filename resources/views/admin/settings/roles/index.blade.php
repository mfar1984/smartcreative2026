@extends('layouts.admin')

@section('title', 'Roles Management')

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>Settings</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">Roles Management</span>
@endsection

@section('content')
    <x-admin.page-card
        title="Roles Management"
        description="Manage user roles and their access permissions."
        :flush="true">

        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('admin.settings.roles.create') }}"
                   class="inline-flex items-center gap-2 bg-blue-600 text-white px-4 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    Add Role
                </a>
            </x-slot:actions>
        @endif

        {{-- Search and status filter --}}
        <x-admin.filter-bar
            :action="route('admin.settings.roles')"
            :reset="$isFiltered ? route('admin.settings.roles') : null">

            <div class="relative flex-1 min-w-56">
                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" aria-hidden="true">
                    <x-admin.icon name="search" class="w-4 h-4" />
                </span>
                <label for="q" class="sr-only">Search roles</label>
                <input type="search" id="q" name="q" value="{{ $search }}"
                       placeholder="Search role name or description..."
                       class="w-full rounded-lg border border-gray-300 pl-9 pr-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
            </div>

            <label for="status" class="sr-only">Status</label>
            <select id="status" name="status"
                    class="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 bg-white focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
                <option value="">All Status</option>
                <option value="active" @selected($status === 'active')>Active</option>
                <option value="inactive" @selected($status === 'inactive')>Inactive</option>
            </select>
        </x-admin.filter-bar>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-xs font-bold uppercase tracking-wide text-gray-500 w-12">#</th>
                        <th scope="col" class="px-6 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Role Name</th>
                        <th scope="col" class="px-6 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Description</th>
                        <th scope="col" class="px-6 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Users</th>
                        <th scope="col" class="px-6 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Permissions</th>
                        <th scope="col" class="px-6 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Status</th>
                        <th scope="col" class="px-6 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Created</th>
                        <th scope="col" class="px-6 py-3 text-xs font-bold uppercase tracking-wide text-gray-500 text-center">Actions</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-100">
                    @forelse ($roles as $index => $role)
                        <tr class="hover:bg-blue-50/40">
                            <td class="px-6 py-3 text-gray-500">{{ $index + 1 }}</td>

                            <td class="px-6 py-3 whitespace-nowrap">
                                <div class="flex items-center gap-2">
                                    <span class="font-semibold text-gray-900">{{ $role->name }}</span>
                                    @if ($role->is_protected)
                                        <x-admin.badge tone="purple">system</x-admin.badge>
                                    @endif
                                </div>
                                <code class="text-xs text-gray-400">{{ $role->slug }}</code>
                            </td>

                            <td class="px-6 py-3 text-gray-600">{{ $role->description ?: '—' }}</td>

                            <td class="px-6 py-3 whitespace-nowrap">
                                <span class="inline-flex items-center gap-1.5 text-gray-700">
                                    <x-admin.icon name="users" class="w-4 h-4 text-gray-400" />
                                    {{ $role->users_count }}
                                </span>
                            </td>

                            <td class="px-6 py-3 whitespace-nowrap text-gray-700">
                                @if ($role->isSuperAdmin())
                                    {{ $permissionTotal }} / {{ $permissionTotal }}
                                @else
                                    {{ $role->permissions_count }} / {{ $permissionTotal }}
                                @endif
                            </td>

                            <td class="px-6 py-3 whitespace-nowrap">
                                @if ($role->is_active)
                                    <x-admin.badge tone="green" :dot="true">Active</x-admin.badge>
                                @else
                                    <x-admin.badge tone="gray" :dot="true">Inactive</x-admin.badge>
                                @endif
                            </td>

                            <td class="px-6 py-3 text-xs text-gray-500 whitespace-nowrap">
                                {{ $role->created_at?->format('d/m/Y') ?? '—' }}
                            </td>

                            <td class="px-6 py-3 whitespace-nowrap">
                                <div class="flex items-center justify-center gap-1">
                                    <a href="{{ route('admin.settings.roles.show', $role) }}"
                                       class="p-1.5 rounded-lg text-blue-600 hover:bg-blue-50 transition"
                                       title="View {{ $role->name }}" aria-label="View {{ $role->name }}">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                        </svg>
                                    </a>

                                    @if ($canUpdate)
                                        <a href="{{ route('admin.settings.roles.edit', $role) }}"
                                           class="p-1.5 rounded-lg text-amber-600 hover:bg-amber-50 transition"
                                           title="Edit {{ $role->name }}" aria-label="Edit {{ $role->name }}">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                            </svg>
                                        </a>
                                    @endif

                                    @if ($canDelete && ! $role->is_protected)
                                        <form action="{{ route('admin.settings.roles.destroy', $role) }}" method="POST"
                                              onsubmit="return confirm('Delete the {{ addslashes($role->name) }} role? This cannot be undone.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit"
                                                    class="p-1.5 rounded-lg text-red-600 hover:bg-red-50 transition"
                                                    title="Delete {{ $role->name }}" aria-label="Delete {{ $role->name }}">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                                </svg>
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-6 py-12 text-center text-sm text-gray-500">
                                @if ($isFiltered)
                                    No roles match the current filters.
                                @else
                                    No roles yet.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="px-6 py-3.5 border-t border-gray-200">
            <p class="text-xs text-gray-500">
                Showing {{ $roles->count() }} {{ Str::plural('role', $roles->count()) }}
            </p>
        </div>
    </x-admin.page-card>
@endsection
