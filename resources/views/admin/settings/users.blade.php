@extends('layouts.admin')

@section('title', 'User Management')

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>Settings</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">User Management</span>
@endsection

@section('content')
    @php
        $label = 'block text-sm font-semibold text-gray-700 mb-1.5';
        $input = 'w-full rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition disabled:bg-gray-100 disabled:text-gray-500';
    @endphp

    <x-admin.page-card
        title="User Management"
        description="Accounts that can sign in to the admin area."
        :flush="true">

        @if ($canCreate)
            <x-slot:actions>
                <button type="button" data-open-dialog="user-create"
                        class="inline-flex items-center gap-2 bg-blue-600 text-white px-4 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    Add User
                </button>
            </x-slot:actions>
        @endif

        <x-admin.filter-bar
            :action="route('admin.settings.users')"
            :reset="$isFiltered ? route('admin.settings.users') : null">

            <div class="relative flex-1 min-w-56">
                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" aria-hidden="true">
                    <x-admin.icon name="search" class="w-4 h-4" />
                </span>
                <label for="q" class="sr-only">Search users</label>
                <input type="search" id="q" name="q" value="{{ $search }}"
                       placeholder="Search name, username or email..."
                       class="w-full rounded-lg border border-gray-300 pl-9 pr-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
            </div>

            <label for="role" class="sr-only">Role</label>
            <select id="role" name="role"
                    class="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 bg-white focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
                <option value="">All Roles</option>
                @foreach ($roles as $role)
                    <option value="{{ $role->id }}" @selected($roleId === $role->id)>{{ $role->name }}</option>
                @endforeach
            </select>

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
                        <th scope="col" class="px-6 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">User</th>
                        <th scope="col" class="px-6 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Username</th>
                        <th scope="col" class="px-6 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Role</th>
                        <th scope="col" class="px-6 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Status</th>
                        <th scope="col" class="px-6 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Last Sign In</th>
                        <th scope="col" class="px-6 py-3 text-xs font-bold uppercase tracking-wide text-gray-500 text-center">Actions</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-100">
                    @forelse ($users as $index => $row)
                        <tr class="hover:bg-blue-50/40">
                            <td class="px-6 py-3 text-gray-500">{{ $users->firstItem() + $index }}</td>

                            <td class="px-6 py-3">
                                <div class="flex items-center gap-3">
                                    <span class="w-8 h-8 rounded-full bg-blue-600 text-white flex items-center justify-center text-xs font-bold shrink-0" aria-hidden="true">
                                        {{ strtoupper(substr($row->name, 0, 1)) }}
                                    </span>
                                    <div class="min-w-0">
                                        <span class="block font-semibold text-gray-900 truncate">{{ $row->name }}</span>
                                        <span class="block text-xs text-gray-500 truncate">{{ $row->email }}</span>
                                    </div>
                                    @if ($row->is(auth()->user()))
                                        <x-admin.badge tone="blue" class="shrink-0">You</x-admin.badge>
                                    @endif
                                </div>
                            </td>

                            <td class="px-6 py-3"><code class="text-xs text-gray-600">{{ $row->username ?: '—' }}</code></td>

                            <td class="px-6 py-3 whitespace-nowrap">
                                @if ($row->role)
                                    <x-admin.badge :tone="$row->role->isSuperAdmin() ? 'purple' : 'gray'">{{ $row->role->name }}</x-admin.badge>
                                    @unless ($row->role->is_active)
                                        <span class="block text-xs text-red-600 mt-1">Role inactive</span>
                                    @endunless
                                @else
                                    <span class="text-xs text-gray-400">No role</span>
                                @endif
                            </td>

                            <td class="px-6 py-3 whitespace-nowrap">
                                @if ($row->is_active)
                                    <x-admin.badge tone="green" :dot="true">Active</x-admin.badge>
                                @else
                                    <x-admin.badge tone="gray" :dot="true">Inactive</x-admin.badge>
                                @endif
                            </td>

                            <td class="px-6 py-3 text-xs text-gray-500 whitespace-nowrap">
                                @if ($row->last_login_at)
                                    {{ $row->last_login_at->format('d/m/Y g:i a') }}
                                    @if ($row->last_login_ip)
                                        <span class="block text-gray-400">{{ $row->last_login_ip }}</span>
                                    @endif
                                @else
                                    <span class="text-gray-400">Never</span>
                                @endif
                            </td>

                            <td class="px-6 py-3 whitespace-nowrap">
                                <div class="flex items-center justify-center gap-1">
                                    @if ($canUpdate)
                                        <button type="button" data-open-dialog="user-edit-{{ $row->id }}"
                                                class="p-1.5 rounded-lg text-amber-600 hover:bg-amber-50 transition"
                                                title="Edit {{ $row->name }}" aria-label="Edit {{ $row->name }}">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                            </svg>
                                        </button>
                                    @endif

                                    @if ($canDelete && ! $row->is(auth()->user()))
                                        <form action="{{ route('admin.settings.users.destroy', $row) }}" method="POST"
                                              onsubmit="return confirm('Delete {{ addslashes($row->name) }}? This cannot be undone.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit"
                                                    class="p-1.5 rounded-lg text-red-600 hover:bg-red-50 transition"
                                                    title="Delete {{ $row->name }}" aria-label="Delete {{ $row->name }}">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                                </svg>
                                            </button>
                                        </form>
                                    @endif

                                    @if (! $canUpdate && ! $canDelete)
                                        <span class="text-xs text-gray-400">View only</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center text-sm text-gray-500">
                                @if ($isFiltered)
                                    No users match the current filters.
                                @else
                                    No users yet.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="px-6 py-3.5 border-t border-gray-200">
            @if ($users->hasPages())
                {{ $users->links() }}
            @else
                <p class="text-xs text-gray-500">
                    Showing {{ $users->total() }} {{ Str::plural('account', $users->total()) }}
                </p>
            @endif
        </div>
    </x-admin.page-card>

    {{-- ===================== Create dialog ===================== --}}
    @if ($canCreate)
        <div id="user-create" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true" aria-labelledby="user-create-title">
            <div class="fixed inset-0 bg-gray-900/50" data-close-dialog></div>

            <div class="relative min-h-full flex items-start justify-center p-4">
                <div class="relative w-full max-w-lg bg-white rounded-xl shadow-xl my-8">
                    <div class="flex items-center justify-between gap-4 px-6 py-4 border-b border-gray-200">
                        <h2 id="user-create-title" class="text-base font-bold text-gray-900">Add User</h2>
                        <button type="button" data-close-dialog class="p-1 rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition" aria-label="Close">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    <form action="{{ route('admin.settings.users.store') }}" method="POST" class="p-6 space-y-4">
                        @csrf

                        <div>
                            <label for="create-name" class="{{ $label }}">Full Name <span class="text-red-600" aria-hidden="true">*</span></label>
                            <input type="text" id="create-name" name="name" required maxlength="120" value="{{ old('name') }}" class="{{ $input }}">
                        </div>

                        <div>
                            <label for="create-username" class="{{ $label }}">Username <span class="text-red-600" aria-hidden="true">*</span></label>
                            <input type="text" id="create-username" name="username" required maxlength="120" value="{{ old('username') }}" autocomplete="off" class="{{ $input }}">
                        </div>

                        <div>
                            <label for="create-email" class="{{ $label }}">Email <span class="text-red-600" aria-hidden="true">*</span></label>
                            <input type="email" id="create-email" name="email" required maxlength="190" value="{{ old('email') }}" class="{{ $input }}">
                        </div>

                        <div>
                            <label for="create-role" class="{{ $label }}">Role <span class="text-red-600" aria-hidden="true">*</span></label>
                            <select id="create-role" name="role_id" required class="{{ $input }} bg-white">
                                <option value="">Select a role</option>
                                @foreach ($roles as $role)
                                    <option value="{{ $role->id }}" @selected((int) old('role_id') === $role->id)>{{ $role->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label for="create-password" class="{{ $label }}">Password <span class="text-red-600" aria-hidden="true">*</span></label>
                            <input type="password" id="create-password" name="password" required autocomplete="new-password" class="{{ $input }}">
                            <p class="text-xs text-gray-500 mt-1">At least 10 characters, with letters, numbers and a symbol.</p>
                        </div>

                        <div>
                            <label for="create-password-confirm" class="{{ $label }}">Confirm Password <span class="text-red-600" aria-hidden="true">*</span></label>
                            <input type="password" id="create-password-confirm" name="password_confirmation" required autocomplete="new-password" class="{{ $input }}">
                        </div>

                        <x-admin.toggle name="is_active" id="create-active" :checked="old('is_active', true)" label="Account is active" />

                        <div class="flex justify-end gap-3 pt-2 border-t border-gray-100">
                            <button type="button" data-close-dialog class="px-4 py-2.5 rounded-lg border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                                Cancel
                            </button>
                            <button type="submit" class="bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-sm">
                                Create User
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    {{-- ===================== Edit dialogs ===================== --}}
    @if ($canUpdate)
        @foreach ($users as $row)
            <div id="user-edit-{{ $row->id }}" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true" aria-labelledby="user-edit-title-{{ $row->id }}">
                <div class="fixed inset-0 bg-gray-900/50" data-close-dialog></div>

                <div class="relative min-h-full flex items-start justify-center p-4">
                    <div class="relative w-full max-w-lg bg-white rounded-xl shadow-xl my-8">
                        <div class="flex items-center justify-between gap-4 px-6 py-4 border-b border-gray-200">
                            <h2 id="user-edit-title-{{ $row->id }}" class="text-base font-bold text-gray-900">Edit {{ $row->name }}</h2>
                            <button type="button" data-close-dialog class="p-1 rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition" aria-label="Close">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>

                        <form action="{{ route('admin.settings.users.update', $row) }}" method="POST" class="p-6 space-y-4">
                            @csrf
                            @method('PUT')

                            <div>
                                <label for="edit-name-{{ $row->id }}" class="{{ $label }}">Full Name <span class="text-red-600" aria-hidden="true">*</span></label>
                                <input type="text" id="edit-name-{{ $row->id }}" name="name" required maxlength="120" value="{{ $row->name }}" class="{{ $input }}">
                            </div>

                            <div>
                                <label for="edit-username-{{ $row->id }}" class="{{ $label }}">Username <span class="text-red-600" aria-hidden="true">*</span></label>
                                <input type="text" id="edit-username-{{ $row->id }}" name="username" required maxlength="120" value="{{ $row->username }}" class="{{ $input }}">
                            </div>

                            <div>
                                <label for="edit-email-{{ $row->id }}" class="{{ $label }}">Email <span class="text-red-600" aria-hidden="true">*</span></label>
                                <input type="email" id="edit-email-{{ $row->id }}" name="email" required maxlength="190" value="{{ $row->email }}" class="{{ $input }}">
                            </div>

                            <div>
                                <label for="edit-role-{{ $row->id }}" class="{{ $label }}">Role <span class="text-red-600" aria-hidden="true">*</span></label>
                                <select id="edit-role-{{ $row->id }}" name="role_id" required
                                        @disabled($row->is(auth()->user()))
                                        class="{{ $input }} bg-white">
                                    @foreach ($roles as $role)
                                        <option value="{{ $role->id }}" @selected($row->role_id === $role->id)>{{ $role->name }}</option>
                                    @endforeach
                                </select>
                                @if ($row->is(auth()->user()))
                                    {{-- Disabled inputs are not submitted, so keep the value in the payload. --}}
                                    <input type="hidden" name="role_id" value="{{ $row->role_id }}">
                                    <p class="text-xs text-gray-500 mt-1">You cannot change your own role.</p>
                                @endif
                            </div>

                            <div>
                                <label for="edit-password-{{ $row->id }}" class="{{ $label }}">New Password</label>
                                <input type="password" id="edit-password-{{ $row->id }}" name="password" autocomplete="new-password" class="{{ $input }}">
                                <p class="text-xs text-gray-500 mt-1">Leave blank to keep the current password.</p>
                            </div>

                            <div>
                                <label for="edit-password-confirm-{{ $row->id }}" class="{{ $label }}">Confirm New Password</label>
                                <input type="password" id="edit-password-confirm-{{ $row->id }}" name="password_confirmation" autocomplete="new-password" class="{{ $input }}">
                            </div>

                            <x-admin.toggle
                                name="is_active"
                                id="edit-active-{{ $row->id }}"
                                :checked="$row->is_active"
                                label="Account is active"
                                :disabled="$row->is(auth()->user())" />

                            @if ($row->is(auth()->user()))
                                <input type="hidden" name="is_active" value="1">
                                <p class="text-xs text-gray-500">You cannot deactivate your own account.</p>
                            @endif

                            <div class="flex justify-end gap-3 pt-2 border-t border-gray-100">
                                <button type="button" data-close-dialog class="px-4 py-2.5 rounded-lg border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                                    Cancel
                                </button>
                                <button type="submit" class="bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-sm">
                                    Save Changes
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endforeach
    @endif
@endsection

@push('scripts')
<script>
    (function () {
        function closeAll() {
            document.querySelectorAll('[role="dialog"]').forEach(function (dialog) {
                dialog.classList.add('hidden');
            });
            document.body.classList.remove('overflow-hidden');
        }

        document.querySelectorAll('[data-open-dialog]').forEach(function (trigger) {
            trigger.addEventListener('click', function () {
                const dialog = document.getElementById(trigger.dataset.openDialog);
                if (!dialog) {
                    return;
                }

                closeAll();
                dialog.classList.remove('hidden');
                document.body.classList.add('overflow-hidden');
                dialog.querySelector('input:not([type="hidden"]):not(.sr-only), select')?.focus();
            });
        });

        document.querySelectorAll('[data-close-dialog]').forEach(function (trigger) {
            trigger.addEventListener('click', closeAll);
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeAll();
            }
        });

        // Reopen the create dialog when validation sent the user back with errors.
        @if ($errors->any() && old('username'))
            document.getElementById('user-create')?.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
        @endif
    })();
</script>
@endpush
