<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Creates the initial panel administrator from BRIDGE_ADMIN_EMAIL and
     * BRIDGE_ADMIN_PASSWORD. The panel has no public registration, so without
     * this there is no way to obtain an account.
     *
     * There is deliberately no default password: this seeder is expected to run
     * unattended from the container entrypoint, and any baked-in credential
     * would be a known admin login on a host that executes docker compose.
     * Absent configuration, seeding is skipped and the operator is told why.
     */
    public function run(): void
    {
        $email = config('bridge.admin.email');
        $password = config('bridge.admin.password');

        if (blank($email) || blank($password)) {
            $this->command?->warn(
                'Skipping admin user: set BRIDGE_ADMIN_EMAIL and BRIDGE_ADMIN_PASSWORD to create one.'
            );

            return;
        }

        // firstOrCreate, not updateOrCreate — a password changed through the UI
        // must not be silently reverted on the next boot.
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => config('bridge.admin.name'),
                'password' => Hash::make($password),
            ],
        );

        $this->command?->info(
            $user->wasRecentlyCreated
                ? "Created admin user {$email}."
                : "Admin user {$email} already exists; left unchanged."
        );
    }
}
