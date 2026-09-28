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

        // The next waiting student starts automatically when one finishes, so
        // nobody has to pick them.
        Setting::updateOrCreate(
            ['key' => 'training_auto_start_next'],
            ['value' => '1', 'group' => 'training', 'label' => 'Auto-start Next Student'],
        );

        // Off by default: each teacher's line is their own students for the
        // day. An admin can switch the whole centre to one shared line.
        Setting::updateOrCreate(
            ['key' => 'training_shared_queue'],
            ['value' => '0', 'group' => 'training', 'label' => 'One Shared Training Queue'],
        );

        $admin = User::where('email', 'admin@example.com')->first();

        // Every active student joins their own teacher's queue for today.
        $students = Student::where('status', 'active')->orderBy('full_name')->get();

        foreach ($students->values() as $index => $student) {
            TrainingQueueEntry::updateOrCreate(
                ['student_id' => $student->id, 'queue_date' => today()->toDateString()],
                [
                    'position' => $index + 1,
                    'status' => TrainingQueueEntry::WAITING,
                    'preferred_instructor_id' => null,
                    'assigned_duration_minutes' => $index % 2 === 0 ? 30 : 40,
                    // Staggered arrivals so the FIFO order and waiting times
                    // look like a real morning.
                    'joined_at' => Carbon::now()->subMinutes(($students->count() - $index) * 3),
                    'created_by' => $admin?->id,
                ],
            );
        }
    }
}
