<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Idempotent — running this multiple times is safe.
     *
     * Credentials come from env so production deploys never ship with
     * predictable secrets. If env is unset (e.g. dev / first-time bootstrap),
     * sensible defaults are used and a warning is emitted in the seed output.
     */
    public function run(): void
    {
        $email = env('ADMIN_USER_EMAIL', 'admin@lokato.local');
        $password = env('ADMIN_USER_PASSWORD');

        if (empty($password)) {
            $password = 'lokato-admin-' . bin2hex(random_bytes(4));
            $this->command?->warn(
                "ADMIN_USER_PASSWORD env-var was unset. Generated random password: {$password}"
            );
            $this->command?->warn(
                'Save it somewhere safe or set ADMIN_USER_PASSWORD in .env before the next seed.'
            );
        }

        User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => env('ADMIN_USER_NAME', 'Lokato Admin'),
                'password' => Hash::make($password),
            ]
        );

        $this->command?->info("Admin user seeded: {$email}");
    }
}
