<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Models\Student;
use App\Models\TrainingQueueEntry;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Puts today's waiting line together so the training board has something to
 * show straight after a fresh install.
 */
class TrainingQueueSeeder extends Seeder
{
    public function run(): void
    {
        Setting::updateOrCreate(
            ['key' => 'default_training_minutes'],
            ['value' => '30', 'group' => 'training', 'label' => 'Default Training Minutes'],
        );

        $admin = User::where('email', 'admin@example.com')->first();

        $students = Student::where('status', 'active')
            ->orderBy('full_name')
            ->limit(6)
            ->get();

        foreach ($students->values() as $index => $student) {
            TrainingQueueEntry::updateOrCreate(
                ['student_id' => $student->id, 'queue_date' => today()->toDateString()],
                [
                    'position' => $index + 1,
                    'status' => TrainingQueueEntry::WAITING,
                    'preferred_instructor_id' => null,
                    'assigned_duration_minutes' => $index % 2 === 0 ? 30 : 40,
                    // Staggered arrivals so the waiting times look real.
                    'joined_at' => Carbon::now()->subMinutes(($students->count() - $index) * 3),
                    'created_by' => $admin?->id,
                ],
            );
        }
    }
}
