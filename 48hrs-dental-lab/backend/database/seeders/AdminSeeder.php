<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/** The first Super Admin of a fresh database, from SEED_ADMIN_*. No-op when the e-mail already exists. */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = strtolower(trim((string) env('SEED_ADMIN_EMAIL', '')));
        if ($email === '') {
            return;
        }
        if (User::where('email', $email)->exists()) {
            $this->command?->info("Super Admin {$email} already exists.");

            return;
        }
        User::create([
            'name' => env('SEED_ADMIN_NAME') ?: 'Super Admin',
            'email' => $email,
            'password' => SeedPassword::require('SEED_ADMIN_PASSWORD'),
            'role_key' => 'super_admin',
        ]);
        $this->command?->info("Super Admin {$email} created.");
    }
}
