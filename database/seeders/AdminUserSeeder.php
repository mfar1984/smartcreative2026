<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /**
     * Create the first super admin.
     *
     * Credentials come from the environment so no password is written into a
     * file that could end up in version control. Set ADMIN_USERNAME,
     * ADMIN_EMAIL and ADMIN_PASSWORD in .env before running this seeder.
     */
    public function run(): void
    {
        $username = env('ADMIN_USERNAME');
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');

        if (blank($username) || blank($email) || blank($password)) {
            $this->command?->warn(
                'AdminUserSeeder skipped: set ADMIN_USERNAME, ADMIN_EMAIL and ADMIN_PASSWORD in .env first.'
            );

            return;
        }

        $role = Role::query()->where('slug', Role::SUPER_ADMIN)->first();

        if ($role === null) {
            $this->command?->warn('AdminUserSeeder skipped: run RolesAndPermissionsSeeder first.');

            return;
        }

        // Matched on email so re-running the seeder updates the existing
        // account rather than failing on the unique constraint.
        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => env('ADMIN_NAME', 'Administrator'),
                'username' => $username,
                'password' => $password,
                'role_id' => $role->id,
                'is_active' => true,
            ],
        );

        $this->command?->info("Super admin ready: {$user->username} <{$user->email}>");
    }
}
