<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * php artisan db:seed
 *   SEED_MODE=base (the default): catalogue, roles, settings, sequences, and the first
 *     Super Admin from SEED_ADMIN_EMAIL / SEED_ADMIN_NAME / SEED_ADMIN_PASSWORD. No demo
 *     accounts. Safe to run again (idempotent).
 *   SEED_MODE=demo (only when asked for): replaces ALL data with the demo lab; every demo
 *     account gets SEED_USER_PASSWORD. Refused in production unless ALLOW_DEMO_SEED=true.
 * Pass these as environment variables for the one command (they need not live in .env).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $mode = env('SEED_MODE') ?: 'base';
        match ($mode) {
            'demo' => $this->call(DemoSeeder::class),
            'base' => $this->call([BaseSeeder::class, AdminSeeder::class]),
            default => throw new \RuntimeException("Unknown SEED_MODE \"{$mode}\" (use demo or base)."),
        };
    }
}
