@extends('layouts.admin')

@section('title', 'User Management')

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>Settings</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">User Management</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>{{ $tabs[$activeTab]['label'] ?? '' }}</span>
@endsection

@section('content')
    @php
        $label = 'block text-sm font-semibold text-gray-700 mb-1.5';
        $input = 'w-full rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition disabled:bg-gray-100 disabled:text-gray-500';
        $head = 'px-6 py-3 text-xs font-bold uppercase tracking-wide text-gray-500';
        $addButton = 'inline-flex items-center gap-2 bg-blue-600 text-white px-4 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-sm shrink-0';
    @endphp

    <x-admin.settings-shell
        title="User Management"
        description="Accounts that can sign in to the admin area."
        :tabs="$tabs"
        :active-tab="$activeTab"
        route="admin.settings.users">

        {{-- ==================== Users ==================== --}}
        @if ($activeTab === 'users')
            <div class="flex flex-wrap items-start justify-between gap-4 mb-5">
                <x-admin.section-intro
                    title="Users"
                    description="Administrator accounts. Handlers have their own tab, so nobody is listed twice."
                    icon="users"
                    class="mb-0" />

                @if ($canCreate)
                    <button type="button" data-open-dialog="user-create" class="{{ $addButton }}">
                        <x-admin.icon name="plus" class="w-4 h-4" />
                        Add User
                    </button>
                @endif
            </div>

            <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                <x-admin.filter-bar
                    :action="route('admin.settings.users')"
                    :reset="$isFiltered ? route('admin.settings.users', ['tab' => 'users']) : null">

                    <input type="hidden" name="tab" value="users">

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
                                <th scope="col" class="{{ $head }} w-12">#</th>
                                <th scope="col" class="{{ $head }}">User</th>
                                <th scope="col" class="{{ $head }}">Username</th>
                                <th scope="col" class="{{ $head }}">Role</th>
                                <th scope="col" class="{{ $head }}">Status</th>
                                <th scope="col" class="{{ $head }}">Last Sign In</th>
                                <th scope="col" class="{{ $head }} text-center">Actions</th>
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
                                            {{ \App\Support\LocalTime::format($row->last_login_at) }}
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
            </div>
        @endif

        {{-- ==================== Handler ==================== --}}
        @if ($activeTab === 'handler')
            <div class="flex flex-wrap items-start justify-between gap-4 mb-5">
                <x-admin.section-intro
                    title="Handler"
                    description="Accounts that run a tournament on the day. They sign in at the same /admin/login."
                    icon="trophy"
                    class="mb-0" />

                @if ($canCreateHandler)
                    <button type="button" data-open-dialog="handler-create" class="{{ $addButton }}">
                        <x-admin.icon name="plus" class="w-4 h-4" />
                        Add Handler
                    </button>
                @endif
            </div>

            {{-- Said here because the tournaments column below is read only, and an
                 unassigned handler can see nothing until somebody ticks a box. --}}
            <p class="text-xs text-gray-500 mb-4">
                A handler is assigned to a tournament on that tournament's own form, under Who Runs It.
                The role is set automatically, so there is nothing to pick here.
            </p>

            <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                <x-admin.filter-bar
                    :action="route('admin.settings.users')"
                    :reset="$isFiltered ? route('admin.settings.users', ['tab' => 'handler']) : null">

                    <input type="hidden" name="tab" value="handler">

                    <div class="relative flex-1 min-w-56">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" aria-hidden="true">
                            <x-admin.icon name="search" class="w-4 h-4" />
                        </span>
                        <label for="q" class="sr-only">Search handlers</label>
                        <input type="search" id="q" name="q" value="{{ $search }}"
                               placeholder="Search name, username or email..."
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
                                <th scope="col" class="{{ $head }} w-12">#</th>
                                <th scope="col" class="{{ $head }}">Handler</th>
                                <th scope="col" class="{{ $head }}">Email</th>
                                <th scope="col" class="{{ $head }}">Status</th>
                                <th scope="col" class="{{ $head }}">Tournaments</th>
                                <th scope="col" class="{{ $head }} text-center">Actions</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-100">
                            @forelse ($handlers as $index => $row)
                                <tr class="hover:bg-blue-50/40 align-top">
                                    <td class="px-6 py-3 text-gray-500">{{ $handlers->firstItem() + $index }}</td>

                                    <td class="px-6 py-3">
                                        <div class="flex items-center gap-3">
                                            <span class="w-8 h-8 rounded-full bg-purple-600 text-white flex items-center justify-center text-xs font-bold shrink-0" aria-hidden="true">
                                                {{ strtoupper(substr($row->name, 0, 1)) }}
                                            </span>
                                            <div class="min-w-0">
                                                <span class="block font-semibold text-gray-900 truncate">{{ $row->name }}</span>
                                                <code class="block text-xs text-gray-500 truncate">{{ $row->username }}</code>
                                            </div>
                                        </div>
                                    </td>

                                    <td class="px-6 py-3 text-gray-600">{{ $row->email }}</td>

                                    <td class="px-6 py-3 whitespace-nowrap">
                                        @if ($row->is_active)
                                            <x-admin.badge tone="green" :dot="true">Active</x-admin.badge>
                                        @else
                                            <x-admin.badge tone="gray" :dot="true">Inactive</x-admin.badge>
                                        @endif
                                    </td>

                                    <td class="px-6 py-3">
                                        @forelse ($row->handledTournaments as $tournament)
                                            <span class="block text-xs text-gray-700">{{ $tournament->name }}</span>
                                        @empty
                                            <span class="text-xs text-gray-400">— Not assigned yet</span>
                                        @endforelse
                                    </td>

                                    <td class="px-6 py-3 whitespace-nowrap">
                                        <div class="flex items-center justify-center gap-1">
                                            @if ($canUpdateHandler)
                                                <button type="button" data-open-dialog="handler-edit-{{ $row->id }}"
                                                        class="p-1.5 rounded-lg text-amber-600 hover:bg-amber-50 transition"
                                                        title="Edit {{ $row->name }}" aria-label="Edit {{ $row->name }}">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                                    </svg>
                                                </button>
                                            @endif

                                            @if ($canDeleteHandler)
                                                @php
                                                    $assigned = $row->handledTournaments->count();
                                                    $confirm = $assigned === 0
                                                        ? sprintf('Delete %s? This cannot be undone.', $row->name)
                                                        : sprintf(
                                                            'Delete %s? They are assigned to %d tournament(s), which will lose this handler. This cannot be undone.',
                                                            $row->name,
                                                            $assigned,
                                                        );
                                                @endphp

                                                <form action="{{ route('admin.settings.users.handlers.destroy', $row) }}" method="POST"
                                                      onsubmit="return confirm('{{ addslashes($confirm) }}');">
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

                                            @if (! $canUpdateHandler && ! $canDeleteHandler)
                                                <span class="text-xs text-gray-400">View only</span>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-6 py-12 text-center text-sm text-gray-500">
                                        @if ($isFiltered)
                                            No handlers match the current filters.
                                        @else
                                            No handler accounts yet.
                                        @endif
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="px-6 py-3.5 border-t border-gray-200">
                    @if ($handlers->hasPages())
                        {{ $handlers->links() }}
                    @else
                        <p class="text-xs text-gray-500">
                            Showing {{ $handlers->total() }} {{ Str::plural('handler', $handlers->total()) }}
                        </p>
                    @endif
                </div>
            </div>
        @endif

        {{-- ==================== Sponsorship ==================== --}}
        @if ($activeTab === 'sponsorship')
            <div class="flex flex-wrap items-start justify-between gap-4 mb-5">
                <x-admin.section-intro
                    title="Sponsorship"
                    description="Accounts that monitor the coupons they funded. They sign in at the same /admin/login and can only look."
                    icon="cash"
                    class="mb-0" />

                @if ($canCreateSponsor)
                    <button type="button" data-open-dialog="sponsor-create" class="{{ $addButton }}">
                        <x-admin.icon name="plus" class="w-4 h-4" />
                        Add Sponsorship
                    </button>
                @endif
            </div>

            {{-- Said here because the Funded column below is read only, and a
                 sponsorship that has been tagged to nothing sees an empty screen. --}}
            <p class="text-xs text-gray-500 mb-4">
                A sponsorship is tied to the coupon blocks it paid for on Coupon &rarr; Report,
                under Blocks Issued. The role is set automatically, so there is nothing to pick here.
            </p>

            <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                <x-admin.filter-bar
                    :action="route('admin.settings.users')"
                    :reset="$isFiltered ? route('admin.settings.users', ['tab' => 'sponsorship']) : null">

                    <input type="hidden" name="tab" value="sponsorship">

                    <div class="relative flex-1 min-w-56">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" aria-hidden="true">
                            <x-admin.icon name="search" class="w-4 h-4" />
                        </span>
                        <label for="q" class="sr-only">Search sponsorship accounts</label>
                        <input type="search" id="q" name="q" value="{{ $search }}"
                               placeholder="Search name, username or email..."
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
                                <th scope="col" class="{{ $head }} w-12">#</th>
                                <th scope="col" class="{{ $head }}">Sponsorship</th>
                                <th scope="col" class="{{ $head }}">Email</th>
                                <th scope="col" class="{{ $head }}">Status</th>
                                <th scope="col" class="{{ $head }}">Funded</th>
                                <th scope="col" class="{{ $head }} text-right">Committed</th>
                                <th scope="col" class="{{ $head }} text-right">Used</th>
                                <th scope="col" class="{{ $head }} text-center">Actions</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-100">
                            @forelse ($sponsors as $index => $row)
                                @php
                                    // CouponSponsorship's own figures, which resolve a
                                    // batch-level sponsorship as well as a block-level
                                    // one, so this column and the sponsor's own screen
                                    // cannot disagree about what they funded.
                                    $figures = $sponsorFigures[$row->id] ?? null;
                                    $batches = $sponsorBatches[$row->id] ?? [];
                                    $units = ($figures['blocks'] ?? 0) + ($figures['shared_batches'] ?? 0);

                                    // Assembled in one string rather than across
                                    // several lines of markup, so the sentence a
                                    // reader sees is the sentence in the source.
                                    $fundedParts = [];

                                    if (($figures['blocks'] ?? 0) > 0) {
                                        $fundedParts[] = number_format($figures['blocks'])
                                            . ' ' . Str::plural('block', $figures['blocks']);
                                    }

                                    if (($figures['shared_batches'] ?? 0) > 0) {
                                        $fundedParts[] = number_format($figures['shared_batches'])
                                            . ' shared ' . Str::plural('code', $figures['shared_batches']);
                                    }

                                    $fundedParts[] = sprintf(
                                        '%s of %s %s used',
                                        number_format($figures['used'] ?? 0),
                                        number_format($figures['codes'] ?? 0),
                                        Str::plural('code', $figures['codes'] ?? 0),
                                    );

                                    $fundedLine = implode(' · ', $fundedParts);
                                @endphp

                                <tr class="hover:bg-blue-50/40 align-top">
                                    <td class="px-6 py-3 text-gray-500">{{ $sponsors->firstItem() + $index }}</td>

                                    <td class="px-6 py-3">
                                        <div class="flex items-center gap-3">
                                            <span class="w-8 h-8 rounded-full bg-emerald-600 text-white flex items-center justify-center text-xs font-bold shrink-0" aria-hidden="true">
                                                {{ strtoupper(substr($row->name, 0, 1)) }}
                                            </span>
                                            <div class="min-w-0">
                                                <span class="block font-semibold text-gray-900 truncate">{{ $row->name }}</span>
                                                <code class="block text-xs text-gray-500 truncate">{{ $row->username }}</code>
                                            </div>
                                        </div>
                                    </td>

                                    <td class="px-6 py-3 text-gray-600">{{ $row->email }}</td>

                                    <td class="px-6 py-3 whitespace-nowrap">
                                        @if ($row->is_active)
                                            <x-admin.badge tone="green" :dot="true">Active</x-admin.badge>
                                        @else
                                            <x-admin.badge tone="gray" :dot="true">Inactive</x-admin.badge>
                                        @endif
                                    </td>

                                    {{-- What the account is tied to, and the state of it. This
                                         column is the whole reason somebody opens this tab. --}}
                                    <td class="px-6 py-3">
                                        @if ($batches === [])
                                            <span class="text-xs text-gray-400">— Not tagged to any coupon yet</span>
                                        @else
                                            @foreach ($batches as $batchName)
                                                <span class="block text-xs text-gray-700">{{ $batchName }}</span>
                                            @endforeach
                                            <span class="block text-xs text-gray-500 mt-0.5">{{ $fundedLine }}</span>
                                        @endif
                                    </td>

                                    <td class="px-6 py-3 text-right tabular-nums whitespace-nowrap">
                                        @if ($row->sponsor_committed_amount === null)
                                            <span class="text-xs text-gray-400">—</span>
                                        @else
                                            {{ \App\Support\PaymentFigures::money((float) $row->sponsor_committed_amount) }}
                                        @endif
                                    </td>

                                    <td class="px-6 py-3 text-right font-semibold text-gray-900 tabular-nums whitespace-nowrap">
                                        {{ \App\Support\PaymentFigures::money($figures['actual'] ?? 0) }}
                                    </td>

                                    <td class="px-6 py-3 whitespace-nowrap">
                                        <div class="flex items-center justify-center gap-1">
                                            @if ($canUpdateSponsor)
                                                <button type="button" data-open-dialog="sponsor-edit-{{ $row->id }}"
                                                        class="p-1.5 rounded-lg text-amber-600 hover:bg-amber-50 transition"
                                                        title="Edit {{ $row->name }}" aria-label="Edit {{ $row->name }}">
                                                    <x-admin.icon name="pencil" class="w-4 h-4" />
                                                </button>
                                            @endif

                                            @if ($canDeleteSponsor)
                                                @php
                                                    $confirm = $units === 0
                                                        ? sprintf('Delete %s? This cannot be undone.', $row->name)
                                                        : sprintf(
                                                            'Delete %s? %d coupon block(s) or batch(es) will be released, keeping every code and every use. This cannot be undone.',
                                                            $row->name,
                                                            $units,
                                                        );
                                                @endphp

                                                <form action="{{ route('admin.settings.users.sponsors.destroy', $row) }}" method="POST"
                                                      onsubmit="return confirm('{{ addslashes($confirm) }}');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit"
                                                            class="p-1.5 rounded-lg text-red-600 hover:bg-red-50 transition"
                                                            title="Delete {{ $row->name }}" aria-label="Delete {{ $row->name }}">
                                                        <x-admin.icon name="trash" class="w-4 h-4" />
                                                    </button>
                                                </form>
                                            @endif

                                            @if (! $canUpdateSponsor && ! $canDeleteSponsor)
                                                <span class="text-xs text-gray-400">View only</span>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-6 py-12 text-center text-sm text-gray-500">
                                        @if ($isFiltered)
                                            No sponsorship accounts match the current filters.
                                        @else
                                            No sponsorship accounts yet.
                                        @endif
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="px-6 py-3.5 border-t border-gray-200">
                    @if ($sponsors->hasPages())
                        {{ $sponsors->links() }}
                    @else
                        <p class="text-xs text-gray-500">
                            Showing {{ $sponsors->total() }} {{ Str::plural('account', $sponsors->total()) }}
                        </p>
                    @endif
                </div>
            </div>
        @endif

        {{-- ==================== Monitoring ==================== --}}
        @if ($activeTab === 'monitoring')
            <div class="flex flex-wrap items-start justify-between gap-4 mb-5">
                <x-admin.section-intro
                    title="Monitoring"
                    description="Outside organisers who watch the events assigned to them. They sign in at the same /admin/login and can only look."
                    icon="clipboard"
                    class="mb-0" />

                @if ($canCreateMonitor)
                    <button type="button" data-open-dialog="monitor-create" class="{{ $addButton }}">
                        <x-admin.icon name="plus" class="w-4 h-4" />
                        Add Monitoring
                    </button>
                @endif
            </div>

            {{-- Said here because the Events column is what the account can see, and
                 one assigned to nothing sees nothing at all. --}}
            <p class="text-xs text-gray-500 mb-4">
                Which events an account may watch is ticked on its own form below. It reads those events'
                participants, attendance, collection, reporting and coupons &mdash; and nothing else in the
                system. The role is set automatically and is view only, apart from exporting those events'
                own lists.
            </p>

            <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                <x-admin.filter-bar
                    :action="route('admin.settings.users')"
                    :reset="$isFiltered ? route('admin.settings.users', ['tab' => 'monitoring']) : null">

                    <input type="hidden" name="tab" value="monitoring">

                    <div class="relative flex-1 min-w-56">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" aria-hidden="true">
                            <x-admin.icon name="search" class="w-4 h-4" />
                        </span>
                        <label for="q" class="sr-only">Search monitoring accounts</label>
                        <input type="search" id="q" name="q" value="{{ $search }}"
                               placeholder="Search name, username or email..."
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
                                <th scope="col" class="{{ $head }} w-12">#</th>
                                <th scope="col" class="{{ $head }}">Monitoring</th>
                                <th scope="col" class="{{ $head }}">Email</th>
                                <th scope="col" class="{{ $head }}">Status</th>
                                <th scope="col" class="{{ $head }}">Events Watched</th>
                                <th scope="col" class="{{ $head }} text-center">Actions</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-100">
                            @forelse ($monitors as $index => $row)
                                <tr class="hover:bg-blue-50/40 align-top">
                                    <td class="px-6 py-3 text-gray-500">{{ $monitors->firstItem() + $index }}</td>

                                    <td class="px-6 py-3">
                                        <div class="flex items-center gap-3">
                                            <span class="w-8 h-8 rounded-full bg-sky-600 text-white flex items-center justify-center text-xs font-bold shrink-0" aria-hidden="true">
                                                {{ strtoupper(substr($row->name, 0, 1)) }}
                                            </span>
                                            <div class="min-w-0">
                                                <span class="block font-semibold text-gray-900 truncate">{{ $row->name }}</span>
                                                <code class="block text-xs text-gray-500 truncate">{{ $row->username }}</code>
                                            </div>
                                        </div>
                                    </td>

                                    <td class="px-6 py-3 text-gray-600">{{ $row->email }}</td>

                                    <td class="px-6 py-3 whitespace-nowrap">
                                        @if ($row->is_active)
                                            <x-admin.badge tone="green" :dot="true">Active</x-admin.badge>
                                        @else
                                            <x-admin.badge tone="gray" :dot="true">Inactive</x-admin.badge>
                                        @endif
                                    </td>

                                    {{-- The account's whole field of view. This column is the
                                         reason somebody opens this tab. --}}
                                    <td class="px-6 py-3">
                                        @forelse ($row->monitoredEvents as $event)
                                            <span class="block text-xs text-gray-700">{{ $event->title }}</span>
                                        @empty
                                            <span class="text-xs text-gray-400">&mdash; Not assigned yet, so it sees nothing</span>
                                        @endforelse
                                    </td>

                                    <td class="px-6 py-3 whitespace-nowrap">
                                        <div class="flex items-center justify-center gap-1">
                                            @if ($canUpdateMonitor)
                                                <button type="button" data-open-dialog="monitor-edit-{{ $row->id }}"
                                                        class="p-1.5 rounded-lg text-amber-600 hover:bg-amber-50 transition"
                                                        title="Edit {{ $row->name }}" aria-label="Edit {{ $row->name }}">
                                                    <x-admin.icon name="pencil" class="w-4 h-4" />
                                                </button>
                                            @endif

                                            @if ($canDeleteMonitor)
                                                @php
                                                    $watched = $row->monitoredEvents->count();
                                                    $confirm = $watched === 0
                                                        ? sprintf('Delete %s? This cannot be undone.', $row->name)
                                                        : sprintf(
                                                            'Delete %s? They are watching %d event(s), which are otherwise untouched. This cannot be undone.',
                                                            $row->name,
                                                            $watched,
                                                        );
                                                @endphp

                                                <form action="{{ route('admin.settings.users.monitors.destroy', $row) }}" method="POST"
                                                      onsubmit="return confirm('{{ addslashes($confirm) }}');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit"
                                                            class="p-1.5 rounded-lg text-red-600 hover:bg-red-50 transition"
                                                            title="Delete {{ $row->name }}" aria-label="Delete {{ $row->name }}">
                                                        <x-admin.icon name="trash" class="w-4 h-4" />
                                                    </button>
                                                </form>
                                            @endif

                                            @if (! $canUpdateMonitor && ! $canDeleteMonitor)
                                                <span class="text-xs text-gray-400">View only</span>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-6 py-12 text-center text-sm text-gray-500">
                                        @if ($isFiltered)
                                            No monitoring accounts match the current filters.
                                        @else
                                            No monitoring accounts yet.
                                        @endif
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="px-6 py-3.5 border-t border-gray-200">
                    @if ($monitors->hasPages())
                        {{ $monitors->links() }}
                    @else
                        <p class="text-xs text-gray-500">
                            Showing {{ $monitors->total() }} {{ Str::plural('account', $monitors->total()) }}
                        </p>
                    @endif
                </div>
            </div>
        @endif
    </x-admin.settings-shell>

    {{-- ===================== Create dialog: user ===================== --}}
    @if ($activeTab === 'users' && $canCreate)
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

    {{-- ===================== Edit dialogs: user ===================== --}}
    @if ($activeTab === 'users' && $canUpdate)
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

    {{-- ===================== Create dialog: handler ===================== --}}
    @if ($activeTab === 'handler' && $canCreateHandler)
        <div id="handler-create" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true" aria-labelledby="handler-create-title">
            <div class="fixed inset-0 bg-gray-900/50" data-close-dialog></div>

            <div class="relative min-h-full flex items-start justify-center p-4">
                <div class="relative w-full max-w-lg bg-white rounded-xl shadow-xl my-8">
                    <div class="flex items-center justify-between gap-4 px-6 py-4 border-b border-gray-200">
                        <h2 id="handler-create-title" class="text-base font-bold text-gray-900">Add Handler</h2>
                        <button type="button" data-close-dialog class="p-1 rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition" aria-label="Close">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    <form action="{{ route('admin.settings.users.handlers.store') }}" method="POST" class="p-6 space-y-4">
                        @csrf

                        <div>
                            <label for="handler-create-name" class="{{ $label }}">Full Name <span class="text-red-600" aria-hidden="true">*</span></label>
                            <input type="text" id="handler-create-name" name="name" required maxlength="120" value="{{ old('name') }}" class="{{ $input }}">
                        </div>

                        <div>
                            <label for="handler-create-username" class="{{ $label }}">Username <span class="text-red-600" aria-hidden="true">*</span></label>
                            <input type="text" id="handler-create-username" name="username" required maxlength="120" value="{{ old('username') }}" autocomplete="off" class="{{ $input }}">
                            <p class="text-xs text-gray-500 mt-1">What they sign in with at /admin/login.</p>
                        </div>

                        <div>
                            <label for="handler-create-email" class="{{ $label }}">Email <span class="text-red-600" aria-hidden="true">*</span></label>
                            <input type="email" id="handler-create-email" name="email" required maxlength="190" value="{{ old('email') }}" class="{{ $input }}">
                        </div>

                        <div>
                            <label for="handler-create-password" class="{{ $label }}">Password <span class="text-red-600" aria-hidden="true">*</span></label>
                            <input type="password" id="handler-create-password" name="password" required autocomplete="new-password" class="{{ $input }}">
                            <p class="text-xs text-gray-500 mt-1">At least 10 characters, with letters, numbers and a symbol.</p>
                        </div>

                        <div>
                            <label for="handler-create-password-confirm" class="{{ $label }}">Confirm Password <span class="text-red-600" aria-hidden="true">*</span></label>
                            <input type="password" id="handler-create-password-confirm" name="password_confirmation" required autocomplete="new-password" class="{{ $input }}">
                        </div>

                        <x-admin.toggle name="is_active" id="handler-create-active" :checked="old('is_active', true)" label="Account is active" />

                        <p class="text-xs text-gray-500">
                            The handler role is assigned automatically. Tick which tournaments they run on the
                            tournament's own form afterwards.
                        </p>

                        <div class="flex justify-end gap-3 pt-2 border-t border-gray-100">
                            <button type="button" data-close-dialog class="px-4 py-2.5 rounded-lg border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                                Cancel
                            </button>
                            <button type="submit" class="bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-sm">
                                Create Handler
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    {{-- ===================== Edit dialogs: handler ===================== --}}
    @if ($activeTab === 'handler' && $canUpdateHandler)
        @foreach ($handlers as $row)
            <div id="handler-edit-{{ $row->id }}" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true" aria-labelledby="handler-edit-title-{{ $row->id }}">
                <div class="fixed inset-0 bg-gray-900/50" data-close-dialog></div>

                <div class="relative min-h-full flex items-start justify-center p-4">
                    <div class="relative w-full max-w-lg bg-white rounded-xl shadow-xl my-8">
                        <div class="flex items-center justify-between gap-4 px-6 py-4 border-b border-gray-200">
                            <h2 id="handler-edit-title-{{ $row->id }}" class="text-base font-bold text-gray-900">Edit {{ $row->name }}</h2>
                            <button type="button" data-close-dialog class="p-1 rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition" aria-label="Close">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>

                        <form action="{{ route('admin.settings.users.handlers.update', $row) }}" method="POST" class="p-6 space-y-4">
                            @csrf
                            @method('PUT')

                            <div>
                                <label for="handler-edit-name-{{ $row->id }}" class="{{ $label }}">Full Name <span class="text-red-600" aria-hidden="true">*</span></label>
                                <input type="text" id="handler-edit-name-{{ $row->id }}" name="name" required maxlength="120" value="{{ $row->name }}" class="{{ $input }}">
                            </div>

                            <div>
                                <label for="handler-edit-username-{{ $row->id }}" class="{{ $label }}">Username <span class="text-red-600" aria-hidden="true">*</span></label>
                                <input type="text" id="handler-edit-username-{{ $row->id }}" name="username" required maxlength="120" value="{{ $row->username }}" class="{{ $input }}">
                            </div>

                            <div>
                                <label for="handler-edit-email-{{ $row->id }}" class="{{ $label }}">Email <span class="text-red-600" aria-hidden="true">*</span></label>
                                <input type="email" id="handler-edit-email-{{ $row->id }}" name="email" required maxlength="190" value="{{ $row->email }}" class="{{ $input }}">
                            </div>

                            <div>
                                <label for="handler-edit-password-{{ $row->id }}" class="{{ $label }}">New Password</label>
                                <input type="password" id="handler-edit-password-{{ $row->id }}" name="password" autocomplete="new-password" class="{{ $input }}">
                                <p class="text-xs text-gray-500 mt-1">Leave blank to keep the current password.</p>
                            </div>

                            <div>
                                <label for="handler-edit-password-confirm-{{ $row->id }}" class="{{ $label }}">Confirm New Password</label>
                                <input type="password" id="handler-edit-password-confirm-{{ $row->id }}" name="password_confirmation" autocomplete="new-password" class="{{ $input }}">
                            </div>

                            <x-admin.toggle
                                name="is_active"
                                id="handler-edit-active-{{ $row->id }}"
                                :checked="$row->is_active"
                                label="Account is active" />

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

    {{-- ===================== Create dialog: sponsorship ===================== --}}
    @if ($activeTab === 'sponsorship' && $canCreateSponsor)
        <div id="sponsor-create" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true" aria-labelledby="sponsor-create-title">
            <div class="fixed inset-0 bg-gray-900/50" data-close-dialog></div>

            <div class="relative min-h-full flex items-start justify-center p-4">
                <div class="relative w-full max-w-lg bg-white rounded-xl shadow-xl my-8">
                    <div class="flex items-center justify-between gap-4 px-6 py-4 border-b border-gray-200">
                        <h2 id="sponsor-create-title" class="text-base font-bold text-gray-900">Add Sponsorship</h2>
                        <button type="button" data-close-dialog class="p-1 rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition" aria-label="Close">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    <form action="{{ route('admin.settings.users.sponsors.store') }}" method="POST" class="p-6 space-y-4">
                        @csrf

                        <div>
                            <label for="sponsor-create-name" class="{{ $label }}">Sponsor Name <span class="text-red-600" aria-hidden="true">*</span></label>
                            <input type="text" id="sponsor-create-name" name="name" required maxlength="120" value="{{ old('name') }}" class="{{ $input }}">
                            <p class="text-xs text-gray-500 mt-1">A company, an NGO or a person. This is what the account is called on screen.</p>
                        </div>

                        <div>
                            <label for="sponsor-create-username" class="{{ $label }}">Username <span class="text-red-600" aria-hidden="true">*</span></label>
                            <input type="text" id="sponsor-create-username" name="username" required maxlength="120" value="{{ old('username') }}" autocomplete="off" class="{{ $input }}">
                            <p class="text-xs text-gray-500 mt-1">What they sign in with at /admin/login.</p>
                        </div>

                        <div>
                            <label for="sponsor-create-email" class="{{ $label }}">Email <span class="text-red-600" aria-hidden="true">*</span></label>
                            <input type="email" id="sponsor-create-email" name="email" required maxlength="190" value="{{ old('email') }}" class="{{ $input }}">
                        </div>

                        <div>
                            <label for="sponsor-create-committed" class="{{ $label }}">Committed Amount (RM)</label>
                            <input type="number" id="sponsor-create-committed" name="sponsor_committed_amount" min="0" step="0.01"
                                   value="{{ old('sponsor_committed_amount') }}" class="{{ $input }}">
                            {{-- Said plainly, because the figure is a promise and not a
                                 calculation: nothing in the system can work it out, and the
                                 sponsor's own screen keeps it apart from what was really used. --}}
                            <p class="text-xs text-gray-500 mt-1">
                                What this sponsor pledged. Optional, typed by hand, and never worked out from a coupon.
                            </p>
                        </div>

                        <div>
                            <label for="sponsor-create-password" class="{{ $label }}">Password <span class="text-red-600" aria-hidden="true">*</span></label>
                            <input type="password" id="sponsor-create-password" name="password" required autocomplete="new-password" class="{{ $input }}">
                            <p class="text-xs text-gray-500 mt-1">At least 10 characters, with letters, numbers and a symbol.</p>
                        </div>

                        <div>
                            <label for="sponsor-create-password-confirm" class="{{ $label }}">Confirm Password <span class="text-red-600" aria-hidden="true">*</span></label>
                            <input type="password" id="sponsor-create-password-confirm" name="password_confirmation" required autocomplete="new-password" class="{{ $input }}">
                        </div>

                        <x-admin.toggle name="is_active" id="sponsor-create-active" :checked="old('is_active', true)" label="Account is active" />

                        <p class="text-xs text-gray-500">
                            The sponsorship role is assigned automatically and is view only. Tag the coupon
                            blocks it funded on Coupon &rarr; Report afterwards.
                        </p>

                        <div class="flex justify-end gap-3 pt-2 border-t border-gray-100">
                            <button type="button" data-close-dialog class="px-4 py-2.5 rounded-lg border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                                Cancel
                            </button>
                            <button type="submit" class="bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-sm">
                                Create Sponsorship
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    {{-- ===================== Edit dialogs: sponsorship ===================== --}}
    @if ($activeTab === 'sponsorship' && $canUpdateSponsor)
        @foreach ($sponsors as $row)
            <div id="sponsor-edit-{{ $row->id }}" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true" aria-labelledby="sponsor-edit-title-{{ $row->id }}">
                <div class="fixed inset-0 bg-gray-900/50" data-close-dialog></div>

                <div class="relative min-h-full flex items-start justify-center p-4">
                    <div class="relative w-full max-w-lg bg-white rounded-xl shadow-xl my-8">
                        <div class="flex items-center justify-between gap-4 px-6 py-4 border-b border-gray-200">
                            <h2 id="sponsor-edit-title-{{ $row->id }}" class="text-base font-bold text-gray-900">Edit {{ $row->name }}</h2>
                            <button type="button" data-close-dialog class="p-1 rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition" aria-label="Close">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>

                        <form action="{{ route('admin.settings.users.sponsors.update', $row) }}" method="POST" class="p-6 space-y-4">
                            @csrf
                            @method('PUT')

                            <div>
                                <label for="sponsor-edit-name-{{ $row->id }}" class="{{ $label }}">Sponsor Name <span class="text-red-600" aria-hidden="true">*</span></label>
                                <input type="text" id="sponsor-edit-name-{{ $row->id }}" name="name" required maxlength="120" value="{{ $row->name }}" class="{{ $input }}">
                            </div>

                            <div>
                                <label for="sponsor-edit-username-{{ $row->id }}" class="{{ $label }}">Username <span class="text-red-600" aria-hidden="true">*</span></label>
                                <input type="text" id="sponsor-edit-username-{{ $row->id }}" name="username" required maxlength="120" value="{{ $row->username }}" class="{{ $input }}">
                            </div>

                            <div>
                                <label for="sponsor-edit-email-{{ $row->id }}" class="{{ $label }}">Email <span class="text-red-600" aria-hidden="true">*</span></label>
                                <input type="email" id="sponsor-edit-email-{{ $row->id }}" name="email" required maxlength="190" value="{{ $row->email }}" class="{{ $input }}">
                            </div>

                            <div>
                                <label for="sponsor-edit-committed-{{ $row->id }}" class="{{ $label }}">Committed Amount (RM)</label>
                                <input type="number" id="sponsor-edit-committed-{{ $row->id }}" name="sponsor_committed_amount" min="0" step="0.01"
                                       value="{{ $row->sponsor_committed_amount }}" class="{{ $input }}">
                                <p class="text-xs text-gray-500 mt-1">Leave blank for no pledge recorded, which is not the same as zero.</p>
                            </div>

                            <div>
                                <label for="sponsor-edit-password-{{ $row->id }}" class="{{ $label }}">New Password</label>
                                <input type="password" id="sponsor-edit-password-{{ $row->id }}" name="password" autocomplete="new-password" class="{{ $input }}">
                                <p class="text-xs text-gray-500 mt-1">Leave blank to keep the current password.</p>
                            </div>

                            <div>
                                <label for="sponsor-edit-password-confirm-{{ $row->id }}" class="{{ $label }}">Confirm New Password</label>
                                <input type="password" id="sponsor-edit-password-confirm-{{ $row->id }}" name="password_confirmation" autocomplete="new-password" class="{{ $input }}">
                            </div>

                            <x-admin.toggle
                                name="is_active"
                                id="sponsor-edit-active-{{ $row->id }}"
                                :checked="$row->is_active"
                                label="Account is active" />

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

    {{-- ===================== Create dialog: monitoring ===================== --}}
    @if ($activeTab === 'monitoring' && $canCreateMonitor)
        <div id="monitor-create" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true" aria-labelledby="monitor-create-title">
            <div class="fixed inset-0 bg-gray-900/50" data-close-dialog></div>

            <div class="relative min-h-full flex items-start justify-center p-4">
                <div class="relative w-full max-w-lg bg-white rounded-xl shadow-xl my-8">
                    <div class="flex items-center justify-between gap-4 px-6 py-4 border-b border-gray-200">
                        <h2 id="monitor-create-title" class="text-base font-bold text-gray-900">Add Monitoring</h2>
                        <button type="button" data-close-dialog class="p-1 rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition" aria-label="Close">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    <form action="{{ route('admin.settings.users.monitors.store') }}" method="POST" class="p-6 space-y-4">
                        @csrf

                        <div>
                            <label for="monitor-create-name" class="{{ $label }}">Organiser Name <span class="text-red-600" aria-hidden="true">*</span></label>
                            <input type="text" id="monitor-create-name" name="name" required maxlength="120" value="{{ old('name') }}" class="{{ $input }}">
                            <p class="text-xs text-gray-500 mt-1">An association, a department or a person. This is what the account is called on screen.</p>
                        </div>

                        <div>
                            <label for="monitor-create-username" class="{{ $label }}">Username <span class="text-red-600" aria-hidden="true">*</span></label>
                            <input type="text" id="monitor-create-username" name="username" required maxlength="120" value="{{ old('username') }}" autocomplete="off" class="{{ $input }}">
                            <p class="text-xs text-gray-500 mt-1">What they sign in with at /admin/login.</p>
                        </div>

                        <div>
                            <label for="monitor-create-email" class="{{ $label }}">Email <span class="text-red-600" aria-hidden="true">*</span></label>
                            <input type="email" id="monitor-create-email" name="email" required maxlength="190" value="{{ old('email') }}" class="{{ $input }}">
                        </div>

                        <div>
                            <label for="monitor-create-password" class="{{ $label }}">Password <span class="text-red-600" aria-hidden="true">*</span></label>
                            <input type="password" id="monitor-create-password" name="password" required autocomplete="new-password" class="{{ $input }}">
                            <p class="text-xs text-gray-500 mt-1">At least 10 characters, with letters, numbers and a symbol.</p>
                        </div>

                        <div>
                            <label for="monitor-create-password-confirm" class="{{ $label }}">Confirm Password <span class="text-red-600" aria-hidden="true">*</span></label>
                            <input type="password" id="monitor-create-password-confirm" name="password_confirmation" required autocomplete="new-password" class="{{ $input }}">
                        </div>

                        {{-- Which events this account may see. The whole of its field of
                             view, so it is stated as plainly as possible: nothing ticked
                             means nothing visible. --}}
                        <fieldset>
                            <legend class="{{ $label }}">Events This Account May Watch</legend>

                            @if ($assignableEvents->isEmpty())
                                <p class="text-xs text-gray-500">
                                    No events exist yet. Create one first, then come back and tick it here.
                                </p>
                            @else
                                <div class="max-h-48 overflow-y-auto rounded-lg border border-gray-300 divide-y divide-gray-100">
                                    @foreach ($assignableEvents as $event)
                                        <label for="monitor-create-event-{{ $event->id }}"
                                               class="flex items-start gap-2.5 px-3.5 py-2.5 hover:bg-gray-50 cursor-pointer">
                                            <input type="checkbox" id="monitor-create-event-{{ $event->id }}"
                                                   name="events[]" value="{{ $event->id }}"
                                                   @checked(in_array($event->id, (array) old('events', [])))
                                                   class="mt-0.5 w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-2 focus:ring-blue-500/40">
                                            <span class="min-w-0">
                                                <span class="block text-sm text-gray-900">{{ $event->title }}</span>
                                                <span class="block text-xs text-gray-500">
                                                    {{ $event->starts_at?->format('d M Y') ?? 'No date' }} &middot; {{ \App\Models\Event::STATUSES[$event->status] ?? $event->status }}
                                                </span>
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                                <p class="text-xs text-gray-500 mt-1.5">
                                    Tick nothing and the account sees nothing until somebody comes back and ticks one.
                                </p>
                            @endif
                        </fieldset>

                        <x-admin.toggle name="is_active" id="monitor-create-active" :checked="old('is_active', true)" label="Account is active" />

                        <p class="text-xs text-gray-500">
                            The monitoring role is assigned automatically and is view only: no create, no edit,
                            no delete, no check-in, no handover, no payment and no messages. The only thing it
                            can do is export the lists of the events ticked above.
                        </p>

                        <div class="flex justify-end gap-3 pt-2 border-t border-gray-100">
                            <button type="button" data-close-dialog class="px-4 py-2.5 rounded-lg border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                                Cancel
                            </button>
                            <button type="submit" class="bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-sm">
                                Create Monitoring
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    {{-- ===================== Edit dialogs: monitoring ===================== --}}
    @if ($activeTab === 'monitoring' && $canUpdateMonitor)
        @foreach ($monitors as $row)
            @php $assigned = $row->monitoredEvents->pluck('id')->all(); @endphp

            <div id="monitor-edit-{{ $row->id }}" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true" aria-labelledby="monitor-edit-title-{{ $row->id }}">
                <div class="fixed inset-0 bg-gray-900/50" data-close-dialog></div>

                <div class="relative min-h-full flex items-start justify-center p-4">
                    <div class="relative w-full max-w-lg bg-white rounded-xl shadow-xl my-8">
                        <div class="flex items-center justify-between gap-4 px-6 py-4 border-b border-gray-200">
                            <h2 id="monitor-edit-title-{{ $row->id }}" class="text-base font-bold text-gray-900">Edit {{ $row->name }}</h2>
                            <button type="button" data-close-dialog class="p-1 rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition" aria-label="Close">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>

                        <form action="{{ route('admin.settings.users.monitors.update', $row) }}" method="POST" class="p-6 space-y-4">
                            @csrf
                            @method('PUT')

                            <div>
                                <label for="monitor-edit-name-{{ $row->id }}" class="{{ $label }}">Organiser Name <span class="text-red-600" aria-hidden="true">*</span></label>
                                <input type="text" id="monitor-edit-name-{{ $row->id }}" name="name" required maxlength="120" value="{{ $row->name }}" class="{{ $input }}">
                            </div>

                            <div>
                                <label for="monitor-edit-username-{{ $row->id }}" class="{{ $label }}">Username <span class="text-red-600" aria-hidden="true">*</span></label>
                                <input type="text" id="monitor-edit-username-{{ $row->id }}" name="username" required maxlength="120" value="{{ $row->username }}" class="{{ $input }}">
                            </div>

                            <div>
                                <label for="monitor-edit-email-{{ $row->id }}" class="{{ $label }}">Email <span class="text-red-600" aria-hidden="true">*</span></label>
                                <input type="email" id="monitor-edit-email-{{ $row->id }}" name="email" required maxlength="190" value="{{ $row->email }}" class="{{ $input }}">
                            </div>

                            <div>
                                <label for="monitor-edit-password-{{ $row->id }}" class="{{ $label }}">New Password</label>
                                <input type="password" id="monitor-edit-password-{{ $row->id }}" name="password" autocomplete="new-password" class="{{ $input }}">
                                <p class="text-xs text-gray-500 mt-1">Leave blank to keep the current password.</p>
                            </div>

                            <div>
                                <label for="monitor-edit-password-confirm-{{ $row->id }}" class="{{ $label }}">Confirm New Password</label>
                                <input type="password" id="monitor-edit-password-confirm-{{ $row->id }}" name="password_confirmation" autocomplete="new-password" class="{{ $input }}">
                            </div>

                            <fieldset>
                                <legend class="{{ $label }}">Events This Account May Watch</legend>

                                @if ($assignableEvents->isEmpty())
                                    <p class="text-xs text-gray-500">No events exist yet.</p>
                                @else
                                    <div class="max-h-48 overflow-y-auto rounded-lg border border-gray-300 divide-y divide-gray-100">
                                        @foreach ($assignableEvents as $event)
                                            <label for="monitor-edit-{{ $row->id }}-event-{{ $event->id }}"
                                                   class="flex items-start gap-2.5 px-3.5 py-2.5 hover:bg-gray-50 cursor-pointer">
                                                <input type="checkbox" id="monitor-edit-{{ $row->id }}-event-{{ $event->id }}"
                                                       name="events[]" value="{{ $event->id }}"
                                                       @checked(in_array($event->id, $assigned))
                                                       class="mt-0.5 w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-2 focus:ring-blue-500/40">
                                                <span class="min-w-0">
                                                    <span class="block text-sm text-gray-900">{{ $event->title }}</span>
                                                    <span class="block text-xs text-gray-500">
                                                        {{ $event->starts_at?->format('d M Y') ?? 'No date' }} &middot; {{ \App\Models\Event::STATUSES[$event->status] ?? $event->status }}
                                                    </span>
                                                </span>
                                            </label>
                                        @endforeach
                                    </div>
                                    {{-- Said plainly because unticking is how sight is taken
                                         away, and it takes effect the moment this is saved. --}}
                                    <p class="text-xs text-gray-500 mt-1.5">
                                        Unticking an event removes this account's sight of it as soon as you save.
                                    </p>
                                @endif
                            </fieldset>

                            <x-admin.toggle
                                name="is_active"
                                id="monitor-edit-active-{{ $row->id }}"
                                :checked="$row->is_active"
                                label="Account is active" />

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

    {{-- ===================== Create dialog: monitoring ===================== --}}
    @if ($activeTab === 'monitoring' && $canCreateMonitor)
        <div id="monitor-create" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true" aria-labelledby="monitor-create-title">
            <div class="fixed inset-0 bg-gray-900/50" data-close-dialog></div>

            <div class="relative min-h-full flex items-start justify-center p-4">
                <div class="relative w-full max-w-lg bg-white rounded-xl shadow-xl my-8">
                    <div class="flex items-center justify-between gap-4 px-6 py-4 border-b border-gray-200">
                        <h2 id="monitor-create-title" class="text-base font-bold text-gray-900">Add Monitoring</h2>
                        <button type="button" data-close-dialog class="p-1 rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition" aria-label="Close">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    <form action="{{ route('admin.settings.users.monitors.store') }}" method="POST" class="p-6 space-y-4">
                        @csrf

                        <div>
                            <label for="monitor-create-name" class="{{ $label }}">Organiser Name <span class="text-red-600" aria-hidden="true">*</span></label>
                            <input type="text" id="monitor-create-name" name="name" required maxlength="120" value="{{ old('name') }}" class="{{ $input }}">
                            <p class="text-xs text-gray-500 mt-1">A company, an association or a person. This is what the account is called on screen.</p>
                        </div>

                        <div>
                            <label for="monitor-create-username" class="{{ $label }}">Username <span class="text-red-600" aria-hidden="true">*</span></label>
                            <input type="text" id="monitor-create-username" name="username" required maxlength="120" value="{{ old('username') }}" autocomplete="off" class="{{ $input }}">
                            <p class="text-xs text-gray-500 mt-1">What they sign in with at /admin/login.</p>
                        </div>

                        <div>
                            <label for="monitor-create-email" class="{{ $label }}">Email <span class="text-red-600" aria-hidden="true">*</span></label>
                            <input type="email" id="monitor-create-email" name="email" required maxlength="190" value="{{ old('email') }}" class="{{ $input }}">
                        </div>

                        <div>
                            <label for="monitor-create-password" class="{{ $label }}">Password <span class="text-red-600" aria-hidden="true">*</span></label>
                            <input type="password" id="monitor-create-password" name="password" required autocomplete="new-password" class="{{ $input }}">
                            <p class="text-xs text-gray-500 mt-1">At least 10 characters, with letters, numbers and a symbol.</p>
                        </div>

                        <div>
                            <label for="monitor-create-password-confirm" class="{{ $label }}">Confirm Password <span class="text-red-600" aria-hidden="true">*</span></label>
                            <input type="password" id="monitor-create-password-confirm" name="password_confirmation" required autocomplete="new-password" class="{{ $input }}">
                        </div>

                        {{-- Which events this account may see, which is the whole of what
                             it can do. A tick list rather than a select, so the answer is
                             readable at a glance and several events are one gesture —
                             the same shape the tournament form uses for handlers. --}}
                        <fieldset class="border-t border-gray-100 pt-4">
                            <legend class="{{ $label }}">Events They May Watch</legend>

                            <p class="text-xs text-gray-500 mb-2">
                                They see Participants, Attendance, Collection, Analytic Reporting and the Coupon
                                screens for these events only, including identity card numbers and payment figures,
                                and can export those lists. Nothing else is visible and nothing can be changed.
                            </p>

                            @forelse ($assignableEvents as $event)
                                <label for="monitor-create-event-{{ $event->id }}"
                                       class="flex items-start gap-2.5 rounded-lg px-2 py-1.5 hover:bg-gray-50 transition cursor-pointer">
                                    <input type="checkbox"
                                           id="monitor-create-event-{{ $event->id }}"
                                           name="events[]"
                                           value="{{ $event->id }}"
                                           @checked(in_array($event->id, old('events', []), false))
                                           class="mt-0.5 w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-2 focus:ring-blue-500/40">
                                    <span class="min-w-0">
                                        <span class="block text-sm text-gray-900 truncate">{{ $event->title }}</span>
                                        <span class="block text-xs text-gray-500">
                                            {{ \App\Support\LocalTime::dateWallClock($event->starts_at) }}
                                            · {{ \App\Models\Event::STATUSES[$event->status] ?? $event->status }}
                                        </span>
                                    </span>
                                </label>
                            @empty
                                <p class="text-xs text-gray-500">
                                    No events exist yet. The account can be created now and the events ticked later.
                                </p>
                            @endforelse
                        </fieldset>

                        <x-admin.toggle name="is_active" id="monitor-create-active" :checked="old('is_active', true)" label="Account is active" />

                        <p class="text-xs text-gray-500">
                            The monitoring role is assigned automatically and is view only. An account with no
                            event ticked can see nothing until one is.
                        </p>

                        <div class="flex justify-end gap-3 pt-2 border-t border-gray-100">
                            <button type="button" data-close-dialog class="px-4 py-2.5 rounded-lg border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                                Cancel
                            </button>
                            <button type="submit" class="bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-sm">
                                Create Monitoring
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    {{-- ===================== Edit dialogs: monitoring ===================== --}}
    @if ($activeTab === 'monitoring' && $canUpdateMonitor)
        @foreach ($monitors as $row)
            @php
                // Ticked from the account unless validation sent the form back, in
                // which case what the operator had chosen is what they see again.
                $ticked = old('events') !== null && (int) old('monitor_id') === (int) $row->id
                    ? array_map('intval', (array) old('events'))
                    : $row->monitoredEvents->pluck('id')->map(fn ($id) => (int) $id)->all();
            @endphp

            <div id="monitor-edit-{{ $row->id }}" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true" aria-labelledby="monitor-edit-title-{{ $row->id }}">
                <div class="fixed inset-0 bg-gray-900/50" data-close-dialog></div>

                <div class="relative min-h-full flex items-start justify-center p-4">
                    <div class="relative w-full max-w-lg bg-white rounded-xl shadow-xl my-8">
                        <div class="flex items-center justify-between gap-4 px-6 py-4 border-b border-gray-200">
                            <h2 id="monitor-edit-title-{{ $row->id }}" class="text-base font-bold text-gray-900">Edit {{ $row->name }}</h2>
                            <button type="button" data-close-dialog class="p-1 rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition" aria-label="Close">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>

                        <form action="{{ route('admin.settings.users.monitors.update', $row) }}" method="POST" class="p-6 space-y-4">
                            @csrf
                            @method('PUT')

                            {{-- Which row's form came back, so a validation error reopens
                                 this dialog with its own ticks rather than another row's. --}}
                            <input type="hidden" name="monitor_id" value="{{ $row->id }}">

                            <div>
                                <label for="monitor-edit-name-{{ $row->id }}" class="{{ $label }}">Organiser Name <span class="text-red-600" aria-hidden="true">*</span></label>
                                <input type="text" id="monitor-edit-name-{{ $row->id }}" name="name" required maxlength="120" value="{{ $row->name }}" class="{{ $input }}">
                            </div>

                            <div>
                                <label for="monitor-edit-username-{{ $row->id }}" class="{{ $label }}">Username <span class="text-red-600" aria-hidden="true">*</span></label>
                                <input type="text" id="monitor-edit-username-{{ $row->id }}" name="username" required maxlength="120" value="{{ $row->username }}" class="{{ $input }}">
                            </div>

                            <div>
                                <label for="monitor-edit-email-{{ $row->id }}" class="{{ $label }}">Email <span class="text-red-600" aria-hidden="true">*</span></label>
                                <input type="email" id="monitor-edit-email-{{ $row->id }}" name="email" required maxlength="190" value="{{ $row->email }}" class="{{ $input }}">
                            </div>

                            <div>
                                <label for="monitor-edit-password-{{ $row->id }}" class="{{ $label }}">New Password</label>
                                <input type="password" id="monitor-edit-password-{{ $row->id }}" name="password" autocomplete="new-password" class="{{ $input }}">
                                <p class="text-xs text-gray-500 mt-1">Leave blank to keep the current password.</p>
                            </div>

                            <div>
                                <label for="monitor-edit-password-confirm-{{ $row->id }}" class="{{ $label }}">Confirm New Password</label>
                                <input type="password" id="monitor-edit-password-confirm-{{ $row->id }}" name="password_confirmation" autocomplete="new-password" class="{{ $input }}">
                            </div>

                            <fieldset class="border-t border-gray-100 pt-4">
                                <legend class="{{ $label }}">Events They May Watch</legend>

                                {{-- Said plainly, because unticking is how sight is taken
                                     away and the effect is immediate on their next page. --}}
                                <p class="text-xs text-gray-500 mb-2">
                                    Unticking an event takes it out of their sight straight away. With none ticked
                                    the account can see nothing.
                                </p>

                                @forelse ($assignableEvents as $event)
                                    <label for="monitor-edit-{{ $row->id }}-event-{{ $event->id }}"
                                           class="flex items-start gap-2.5 rounded-lg px-2 py-1.5 hover:bg-gray-50 transition cursor-pointer">
                                        <input type="checkbox"
                                               id="monitor-edit-{{ $row->id }}-event-{{ $event->id }}"
                                               name="events[]"
                                               value="{{ $event->id }}"
                                               @checked(in_array((int) $event->id, $ticked, true))
                                               class="mt-0.5 w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-2 focus:ring-blue-500/40">
                                        <span class="min-w-0">
                                            <span class="block text-sm text-gray-900 truncate">{{ $event->title }}</span>
                                            <span class="block text-xs text-gray-500">
                                                {{ \App\Support\LocalTime::dateWallClock($event->starts_at) }}
                                                · {{ \App\Models\Event::STATUSES[$event->status] ?? $event->status }}
                                            </span>
                                        </span>
                                    </label>
                                @empty
                                    <p class="text-xs text-gray-500">No events exist yet.</p>
                                @endforelse
                            </fieldset>

                            <x-admin.toggle
                                name="is_active"
                                id="monitor-edit-active-{{ $row->id }}"
                                :checked="$row->is_active"
                                label="Account is active" />

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

        // Reopen the create dialog of the active tab when validation sent the
        // user back with errors.
        @if ($errors->any() && old('username'))
            @php
                $createDialog = match ($activeTab) {
                    'handler' => 'handler-create',
                    'sponsorship' => 'sponsor-create',
                    'monitoring' => 'monitor-create',
                    default => 'user-create',
                };
            @endphp
            document.getElementById('{{ $createDialog }}')?.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
        @endif
    })();
</script>
@endpush
