<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * What a real installation needs, and nothing else.
 *
 * Roles, permissions and the reference lists (lesson topics, expense
 * categories) are the system's own furniture — an install without them cannot
 * work. Students, instructors, vehicles, attendance, lessons and payments are
 * the school's records, and seeding invented ones into a live database is how
 * a demo ends up mixed into a real register.
 *
 * The demo data now lives behind DemoDataSeeder, which must be asked for by
 * name and refuses to run outside a local or testing environment:
 *
 *     php artisan db:seed --class=DemoDataSeeder
 *
 * Real students come from the school's own register instead:
 *
 *     php artisan alpha-school:import --dry-run
 *     php artisan alpha-school:import
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleAndPermissionSeeder::class,
            ReferenceDataSeeder::class,
        ]);
    }
}
