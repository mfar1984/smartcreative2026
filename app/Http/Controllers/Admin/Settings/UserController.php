<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\AdminLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('q'));

        $status = $request->query('status');
        $status = in_array($status, ['active', 'inactive'], true) ? $status : null;

        $roleId = $request->query('role');
        $roleId = is_numeric($roleId) ? (int) $roleId : null;

        $users = User::query()
            ->with('role:id,name,slug')
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

        return view('admin.settings.users', [
            'users' => $users,
            'roles' => Role::query()->orderBy('name')->get(),
            'search' => $search,
            'status' => $status,
            'roleId' => $roleId,
            'isFiltered' => $search !== '' || $status !== null || $roleId !== null,
            'canCreate' => $request->user()->hasPermission('users.create'),
            'canUpdate' => $request->user()->hasPermission('users.update'),
            'canDelete' => $request->user()->hasPermission('users.delete'),
        ]);
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

    private function activeSuperAdminCount(): int
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('role', fn ($query) => $query->where('slug', Role::SUPER_ADMIN))
            ->count();
    }
}
