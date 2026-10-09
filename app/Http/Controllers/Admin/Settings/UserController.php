<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreHandlerRequest;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateHandlerRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\AdminLogger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class UserController extends Controller
{
    /**
     * Tab slug => label and icon name understood by the admin icon component.
     */
    public const TABS = [
        'users' => ['label' => 'Users', 'icon' => 'users'],
        'handler' => ['label' => 'Handler', 'icon' => 'trophy'],
    ];

    /**
     * The permission each tab needs before it is drawn at all.
     *
     * Two separate sets on purpose: a role can be given handler management
     * without being given administrator management. A tab the role cannot see is
     * not rendered and cannot be reached by editing the query string either.
     */
    private const TAB_PERMISSIONS = [
        'users' => 'users.view',
        'handler' => 'handlers.view',
    ];

    public function index(Request $request)
    {
        $tabs = array_filter(
            self::TABS,
            fn (array $tab, string $slug) => $request->user()->hasPermission(self::TAB_PERMISSIONS[$slug]),
            ARRAY_FILTER_USE_BOTH,
        );

        $activeTab = $this->resolveTab($request->query('tab'), $tabs);

        $search = trim((string) $request->query('q'));

        $status = $request->query('status');
        $status = in_array($status, ['active', 'inactive'], true) ? $status : null;

        $roleId = $request->query('role');
        $roleId = is_numeric($roleId) ? (int) $roleId : null;

        return view('admin.settings.users', [
            'tabs' => $tabs,
            'activeTab' => $activeTab,

            // Only the tab being drawn is queried, so the other list costs nothing.
            'users' => $activeTab === 'users' ? $this->userRows($search, $status, $roleId) : null,
            'handlers' => $activeTab === 'handler' ? $this->handlerRows($search, $status) : null,

            'roles' => Role::query()->orderBy('name')->get(),
            'search' => $search,
            'status' => $status,
            'roleId' => $roleId,
            'isFiltered' => $search !== '' || $status !== null || ($activeTab === 'users' && $roleId !== null),
            'canCreate' => $request->user()->hasPermission('users.create'),
            'canUpdate' => $request->user()->hasPermission('users.update'),
            'canDelete' => $request->user()->hasPermission('users.delete'),
            'canCreateHandler' => $request->user()->hasPermission('handlers.create'),
            'canUpdateHandler' => $request->user()->hasPermission('handlers.update'),
            'canDeleteHandler' => $request->user()->hasPermission('handlers.delete'),
        ]);
    }

    /**
     * The Users tab: administrator accounts, handlers excluded.
     *
     * The exclusion is what keeps the two tabs from overlapping. Without it an
     * account would appear on both lists and be editable from either, which is
     * the opposite of keeping the two permissions apart.
     */
    private function userRows(string $search, ?string $status, ?int $roleId): LengthAwarePaginator
    {
        return User::query()
            ->with('role:id,name,slug,is_active')
            ->where('is_handler', false)
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($status !== null, fn ($query) => $query->where('is_active', $status === 'active'))
            ->when($roleId !== null, fn ($query) => $query->where('role_id', $roleId))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();
    }

    /**
     * The Handler tab: handler accounts and the tournaments they run.
     *
     * The relation is eager loaded because the tournaments column reads it on
     * every row. Its own page name, so a page number from one tab is not carried
     * into the other.
     */
    private function handlerRows(string $search, ?string $status): LengthAwarePaginator
    {
        return User::query()
            ->with('handledTournaments')
            ->where('is_handler', true)
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($status !== null, fn ($query) => $query->where('is_active', $status === 'active'))
            ->orderBy('name')
            ->paginate(15, ['*'], 'handler_page')
            ->withQueryString();
    }

    /**
     * @param  array<string, array<string, string>>  $allowed  Tabs this role may see.
     */
    private function resolveTab(?string $tab, array $allowed): string
    {
        if (array_key_exists((string) $tab, $allowed)) {
            return (string) $tab;
        }

        // Falls back to the first tab the role may see rather than always to
        // users, because users itself can be the one that is not allowed.
        return (string) (array_key_first($allowed) ?? 'users');
    }

    /**
     * Refuse a row that belongs to the other tab.
     *
     * The two tabs are two lists over one table, so an id from one is a valid
     * route parameter on the other's routes. Without this a role granted only
     * handler management could edit an administrator by changing the number in
     * the URL, which would undo the whole point of keeping the permissions apart.
     */
    private function refuseCrossTab(User $user, bool $handler): void
    {
        if ($user->isHandler() === $handler) {
            return;
        }

        throw new AccessDeniedHttpException($handler
            ? 'That account is not a handler.'
            : 'Handler accounts are managed on the Handler tab.');
    }

    public function store(StoreUserRequest $request)
    {
        $user = User::create($request->validated());

        AdminLogger::activity('users.create', sprintf('Created user %s.', $user->logLabel()));
        AdminLogger::audit($user, 'created', null, [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'role_id' => $user->role_id,
            'is_active' => $user->is_active,
        ]);

        return redirect()
            ->route('admin.settings.users')
            ->with('status', sprintf('User %s created.', $user->username));
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        $this->refuseCrossTab($user, false);

        $validated = $request->validated();

        $before = [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'role_id' => $user->role_id,
            'is_active' => $user->is_active,
        ];

        // Stop an administrator locking themselves out of their own session by
        // deactivating or demoting their own account from this screen.
        if ($user->is($request->user())) {
            if (! $validated['is_active']) {
                throw ValidationException::withMessages([
                    'is_active' => 'You cannot deactivate your own account.',
                ]);
            }

            if ((int) $validated['role_id'] !== (int) $user->role_id) {
                throw ValidationException::withMessages([
                    'role_id' => 'You cannot change your own role. Ask another administrator to do it.',
                ]);
            }
        }

        // A blank password field leaves the existing password in place.
        if (blank($validated['password'] ?? null)) {
            unset($validated['password']);
        }

        $user->update($validated);

        AdminLogger::activity('users.update', sprintf('Updated user %s.', $user->logLabel()));
        AdminLogger::audit($user, 'updated', $before, [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'role_id' => $user->role_id,
            'is_active' => $user->is_active,
            'password' => array_key_exists('password', $validated) ? '[redacted]' : null,
        ]);

        return redirect()
            ->route('admin.settings.users')
            ->with('status', sprintf('User %s updated.', $user->username));
    }

    public function destroy(Request $request, User $user)
    {
        $this->refuseCrossTab($user, false);

        if ($user->is($request->user())) {
            return redirect()
                ->route('admin.settings.users')
                ->withErrors(['user' => 'You cannot delete your own account.']);
        }

        // Never leave the system without a usable super admin.
        if ($user->role?->isSuperAdmin() && $this->activeSuperAdminCount() <= 1) {
            return redirect()
                ->route('admin.settings.users')
                ->withErrors(['user' => 'This is the last active Super Admin. Create another one before deleting this account.']);
        }

        $label = $user->logLabel();

        AdminLogger::audit($user, 'deleted', [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'role_id' => $user->role_id,
        ], null);

        $user->delete();

        AdminLogger::activity('users.delete', sprintf('Deleted user %s.', $label));

        return redirect()
            ->route('admin.settings.users')
            ->with('status', 'User deleted.');
    }

    /* ---------------------------------------------------------------------
     | Handler tab
     *
     | A handler is an ordinary users row carrying the handler role with
     | is_handler set. These three endpoints are the only ones that write that
     | pair, and they write it as a constant.
     * ------------------------------------------------------------------ */

    /**
     * Create a handler account.
     *
     * The role is not a field on this form and no role is ever read from the
     * request. If it were, a role granted only handlers.create could mint a super
     * admin by posting a role_id, which is a straight privilege escalation. So the
     * role is looked up by its fixed slug and written last, where nothing in the
     * payload can reach it.
     */
    public function storeHandler(StoreHandlerRequest $request)
    {
        $role = Role::where('slug', Role::HANDLER)->firstOrFail();

        $attributes = $request->validated();
        $attributes['role_id'] = $role->id;
        $attributes['is_handler'] = true;

        $user = User::create($attributes);

        AdminLogger::activity('handlers.create', sprintf('Created handler %s.', $user->logLabel()));
        AdminLogger::audit($user, 'created', null, [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'role_id' => $user->role_id,
            'is_handler' => true,
            'is_active' => $user->is_active,
        ]);

        return redirect()
            ->route('admin.settings.users', ['tab' => 'handler'])
            ->with('status', sprintf('Handler %s created.', $user->username));
    }

    public function updateHandler(UpdateHandlerRequest $request, User $user)
    {
        $this->refuseCrossTab($user, true);

        $validated = $request->validated();

        $before = [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'is_active' => $user->is_active,
        ];

        // A blank password field leaves the existing password in place.
        if (blank($validated['password'] ?? null)) {
            unset($validated['password']);
        }

        // Neither the role nor the flag is in the payload, so an edit here cannot
        // turn a handler into anything else.
        $user->update($validated);

        AdminLogger::activity('handlers.update', sprintf('Updated handler %s.', $user->logLabel()));
        AdminLogger::audit($user, 'updated', $before, [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'is_active' => $user->is_active,
            'password' => array_key_exists('password', $validated) ? '[redacted]' : null,
        ]);

        return redirect()
            ->route('admin.settings.users', ['tab' => 'handler'])
            ->with('status', sprintf('Handler %s updated.', $user->username));
    }

    /**
     * Delete a handler account.
     *
     * The pivot cascades, so each tournament it was assigned to simply loses that
     * handler and is otherwise untouched. How many is recorded, because that is
     * the part somebody will want to know afterwards.
     */
    public function destroyHandler(Request $request, User $user)
    {
        $this->refuseCrossTab($user, true);

        $label = $user->logLabel();
        $assigned = $user->handledTournaments()->count();

        AdminLogger::audit($user, 'deleted', [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'role_id' => $user->role_id,
            'is_handler' => true,
            'assigned_tournaments' => $assigned,
        ], null);

        $user->delete();

        AdminLogger::activity('handlers.delete', $assigned === 0
            ? sprintf('Deleted handler %s.', $label)
            : sprintf('Deleted handler %s, unassigning %d tournament(s).', $label, $assigned));

        return redirect()
            ->route('admin.settings.users', ['tab' => 'handler'])
            ->with('status', 'Handler deleted.');
    }

    private function activeSuperAdminCount(): int
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('role', fn ($query) => $query->where('slug', Role::SUPER_ADMIN))
            ->count();
    }
}
