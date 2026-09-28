<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The demo school — invented students, instructors, vehicles, attendance,
 * lessons, finance and a training queue.
 *
 * Useful for a fresh checkout and for the test suite, ruinous in a live
 * database, so it is no longer part of `db:seed` and refuses to run anywhere
 * but local and testing. Ask for it by name:
 *
 *     php artisan db:seed --class=DemoDataSeeder
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'DemoDataSeeder creates invented school records and will not run in '
                .app()->environment().'. Import the real register with alpha-school:import instead.',
            );
        }

        $this->call([
            RoleAndPermissionSeeder::class,
            ReferenceDataSeeder::class,
            DrivingSchoolSeeder::class,
            FinanceSeeder::class,
            TrainingQueueSeeder::class,
        ]);
    }
}
