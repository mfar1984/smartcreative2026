<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RoleRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Services\AdminLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoleController extends Controller
{
    /**
     * List of roles, with search and a status filter.
     */
    public function index(Request $request)
    {
        $search = trim((string) $request->query('q'));
        $status = $request->query('status');
        $status = in_array($status, ['active', 'inactive'], true) ? $status : null;

        $roles = Role::query()
            ->withCount('users')
            ->withCount('permissions')
            ->when($search !== '', fn ($query) => $query->where(function ($inner) use ($search) {
                $inner->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            }))
            ->when($status !== null, fn ($query) => $query->where('is_active', $status === 'active'))
            ->orderByDesc('is_protected')
            ->orderBy('name')
            ->get();

        // Which roles can actually sign in, resolved in one query rather than one
        // per row. A super admin always can; any other role needs admin.access on
        // the pivot. A role that lacks it, and already has users, is the trap the
        // owner hit — the index flags it so those broken accounts are not invisible.
        $roleIdsWithAccess = Role::query()
            ->whereHas('permissions', fn ($query) => $query->where('slug', Permission::ADMIN_ACCESS))
            ->pluck('id')
            ->all();

        $roles->each(function (Role $role) use ($roleIdsWithAccess): void {
            $role->setAttribute(
                'grants_admin_access',
                $role->isSuperAdmin() || in_array($role->id, $roleIdsWithAccess, true),
            );
        });

        return view('admin.settings.roles.index', [
            'roles' => $roles,
            'permissionTotal' => Permission::count(),
            'search' => $search,
            'status' => $status,
            'isFiltered' => $search !== '' || $status !== null,
            'canCreate' => $request->user()->hasPermission('roles.create'),
            'canUpdate' => $request->user()->hasPermission('roles.update'),
            'canDelete' => $request->user()->hasPermission('roles.delete'),
        ]);
    }

    public function create()
    {
        return view('admin.settings.roles.form', $this->formData(new Role(['is_active' => true]), 'create'));
    }

    public function store(RoleRequest $request)
    {
        $role = DB::transaction(function () use ($request) {
            $role = Role::create([
                ...$request->roleAttributes(),
                'slug' => $request->slug(),
                'is_protected' => false,
            ]);

            $role->permissions()->sync($request->permissionIds());

            return $role;
        });

        AdminLogger::activity('roles.create', sprintf('Created role %s.', $role->name));
        AdminLogger::audit($role, 'created', null, [
            'name' => $role->name,
            'slug' => $role->slug,
            'is_active' => $role->is_active,
            'permissions' => count($request->permissionIds()),
        ]);

        return redirect()
            ->route('admin.settings.roles')
            ->with('status', sprintf('Role %s created.', $role->name))
            ->with($this->adminAccessWarning($role));
    }

    /**
     * Read only view of a role's matrix.
     */
    public function show(Role $role)
    {
        return view('admin.settings.roles.form', $this->formData($role, 'show'));
    }

    public function edit(Role $role)
    {
        return view('admin.settings.roles.form', $this->formData($role, 'edit'));
    }

    public function update(RoleRequest $request, Role $role)
    {
        $before = [
            'name' => $role->name,
            'description' => $role->description,
            'is_active' => $role->is_active,
            'permissions' => $role->permissions()->count(),
        ];

        DB::transaction(function () use ($request, $role) {
            $attributes = $request->roleAttributes();

            // The super admin must stay usable, so its name may change but it
            // can neither be switched off nor have permissions taken away.
            if ($role->isSuperAdmin()) {
                $attributes['is_active'] = true;
                $role->update($attributes);
                $role->permissions()->sync(Permission::query()->pluck('id')->all());

                return;
            }

            $role->update($attributes);
            $role->permissions()->sync($request->permissionIds());
        });

        $role->refresh();

        AdminLogger::activity('roles.update', sprintf('Updated role %s.', $role->name));
        AdminLogger::audit($role, 'updated', $before, [
            'name' => $role->name,
            'description' => $role->description,
            'is_active' => $role->is_active,
            'permissions' => $role->permissions()->count(),
        ]);

        return redirect()
            ->route('admin.settings.roles')
            ->with('status', sprintf('Role %s saved.', $role->name))
            ->with($this->adminAccessWarning($role));
    }

    /**
     * A one-off warning when a role was just saved without admin access.
     *
     * Not a hard block: a role parked as a template or deliberately dormant is a
     * real thing, so the operator may proceed. But admin.access is one box among
     * 124, and its absence is otherwise invisible — the exact trap that locked the
     * owner's new user out — so the save is called out plainly. A super admin always
     * holds it, so there is nothing to warn about there.
     *
     * @return array<string, string>
     */
    private function adminAccessWarning(Role $role): array
    {
        if ($role->grantsAdminAccess()) {
            return [];
        }

        $assigned = $role->users()->count();

        $message = sprintf(
            'Heads up: %s does not have "Access the admin area", so nobody with this role can sign in to the admin.',
            $role->name,
        );

        if ($assigned > 0) {
            $message .= sprintf(
                ' %d %s already assigned to this role and cannot sign in until you tick that box.',
                $assigned,
                $assigned === 1 ? 'user is' : 'users are',
            );
        }

        return ['warning' => $message];
    }

    public function destroy(Role $role)
    {
        if ($role->is_protected) {
            return redirect()
                ->route('admin.settings.roles')
                ->withErrors(['role' => sprintf('%s is a system role and cannot be deleted.', $role->name)]);
        }

        // Deleting a role would leave its users without admin access, so make
        // the operator move them first rather than silently locking them out.
        $userCount = $role->users()->count();

        if ($userCount > 0) {
            return redirect()
                ->route('admin.settings.roles')
                ->withErrors([
                    'role' => sprintf(
                        '%s is assigned to %d %s. Reassign them before deleting this role.',
                        $role->name,
                        $userCount,
                        $userCount === 1 ? 'user' : 'users',
                    ),
                ]);
        }

        AdminLogger::audit($role, 'deleted', [
            'name' => $role->name,
            'slug' => $role->slug,
        ], null);

        $name = $role->name;
        $role->delete();

        AdminLogger::activity('roles.delete', sprintf('Deleted role %s.', $name));

        return redirect()
            ->route('admin.settings.roles')
            ->with('status', sprintf('Role %s deleted.', $name));
    }

    /**
     * Shared payload for create, show and edit.
     *
     * @return array<string, mixed>
     */
    private function formData(Role $role, string $mode): array
    {
        return [
            'role' => $role,
            'mode' => $mode,
            'readonly' => $mode === 'show' || $role->isSuperAdmin(),
            'matrix' => Permission::matrix(),
            'actionColumns' => Permission::ACTION_COLUMNS,
            'granted' => $role->exists ? $role->permissions()->pluck('permissions.id')->all() : [],
            'permissionTotal' => Permission::count(),

            // The id of the load-bearing admin.access box, so the form can wire the
            // live "this role cannot sign in" warning to that one checkbox, and the
            // count of users already stuck behind a role that lacks it.
            'adminAccessId' => Permission::query()->where('slug', Permission::ADMIN_ACCESS)->value('id'),
            'assignedUserCount' => $role->exists ? $role->users()->count() : 0,
        ];
    }
}
