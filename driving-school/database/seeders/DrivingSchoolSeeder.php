<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\Instructor;
use App\Models\Lesson;
use App\Models\LessonTopic;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentInstructorAssignment;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\StudentProgressService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Builds a realistic school, including the exact scenario the specification
 * asks to be verifiable by hand:
 *
 *   Xasan Maxamuud  → Ilyas, Maryan Cabdi Faarax
 *   Nasteexo Aadan  → Ahmed, Fatima
 */
class DrivingSchoolSeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::where('name', Role::ADMIN)->firstOrFail();
        $instructorRole = Role::where('name', Role::INSTRUCTOR)->firstOrFail();
        $studentRole = Role::where('name', Role::STUDENT)->firstOrFail();

        $admin = User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'role_id' => $adminRole->id,
                'name' => 'Cabdiraxmaan Cali',
                'phone' => '+252611000001',
                'password' => Hash::make('Admin@12345'),
                'is_active' => true,
            ],
        );

        /* ---------------------------------------------------------------
         | Instructors
         | --------------------------------------------------------------- */
        $instructorData = [
            ['Xasan Maxamuud', 'xasan@example.com', '+252611000101', 'Senior driving instructor, 8 years'],
            ['Nasteexo Aadan', 'nasteexo@example.com', '+252611000102', 'Driving instructor, 5 years'],
            ['Cabdi Yuusuf', 'cabdi@example.com', '+252611000103', 'Driving instructor, 3 years'],
        ];

        $instructors = [];

        foreach ($instructorData as $index => [$name, $email, $phone, $qualification]) {
            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'role_id' => $instructorRole->id,
                    'name' => $name,
                    'phone' => $phone,
                    'password' => Hash::make('Instructor@12345'),
                    'is_active' => true,
                ],
            );

            $instructors[$index] = Instructor::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'instructor_number' => 'INS-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                    'full_name' => $name,
                    'phone' => $phone,
                    'email' => $email,
                    'address' => 'Mogadishu',
                    'qualification' => $qualification,
                    'joining_date' => Carbon::today()->subMonths(18 - $index * 4),
                    'status' => 'active',
                ],
            );
        }

        [$xasan, $nasteexo, $cabdi] = $instructors;

        /* ---------------------------------------------------------------
         | Vehicles — 7 in the fleet, assigned across the instructors
         | --------------------------------------------------------------- */
        $vehicleData = [
            ['AD-01', 'Toyota', 'Corolla', 2018, 'White', 82_000, 'in_training', $xasan->id],
            ['AD-02', 'Toyota', 'Vitz', 2016, 'Silver', 104_500, 'in_training', $nasteexo->id],
            ['AD-03', 'Nissan', 'Sunny', 2019, 'Blue', 61_200, 'in_training', $cabdi->id],
            ['AD-04', 'Toyota', 'Probox', 2015, 'White', 158_000, 'available', null],
            ['AD-05', 'Hyundai', 'Accent', 2020, 'Grey', 44_800, 'available', null],
            ['AD-06', 'Suzuki', 'Alto', 2017, 'Red', 96_300, 'maintenance', null],
            ['AD-07', 'Toyota', 'Corolla', 2021, 'Black', 21_400, 'available', $xasan->id],
        ];

        foreach ($vehicleData as $index => [$plate, $make, $model, $year, $color, $mileage, $status, $instructorId]) {
            Vehicle::updateOrCreate(
                ['plate_number' => $plate],
                [
                    'vehicle_number' => 'VEH-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                    'make' => $make,
                    'model' => $model,
                    'year' => $year,
                    'color' => $color,
                    'mileage' => $mileage,
                    'status' => $status,
                    'instructor_id' => $instructorId,
                ],
            );
        }

        /* ---------------------------------------------------------------
         | Students — the isolation scenario plus six more
         | --------------------------------------------------------------- */
        $studentData = [
            // [name, phone, instructor, required days, present days, status]
            ['Ilyas Maxamed', '+252612000001', $xasan->id, 24, 18, 'active'],
            ['Maryan Cabdi Faarax', '+252612000002', $xasan->id, 24, 21, 'active'],
            ['Ahmed Nuur', '+252612000003', $nasteexo->id, 24, 12, 'active'],
            ['Fatima Xuseen', '+252612000004', $nasteexo->id, 24, 9, 'active'],
            ['Cabdullaahi Warsame', '+252612000005', $cabdi->id, 20, 20, 'active'],
            ['Hodan Ibraahim', '+252612000006', $cabdi->id, 24, 6, 'active'],
            ['Yuusuf Maxamuud', '+252612000007', $xasan->id, 30, 4, 'active'],
            ['Sahra Cali', '+252612000008', $nasteexo->id, 24, 15, 'active'],
            ['Maxamed Siyaad', '+252612000009', $cabdi->id, 24, 2, 'suspended'],
            ['Amina Xasan', '+252612000010', $xasan->id, 24, 11, 'active'],
        ];

        $topicIds = LessonTopic::pluck('id')->all();
        $progress = app(StudentProgressService::class);

        foreach ($studentData as $index => [$name, $phone, $instructorId, $requiredDays, $presentDays, $status]) {
            $startDate = Carbon::today()->subDays(30 + $index * 3);

            $student = Student::updateOrCreate(
                ['phone' => $phone],
                [
                    'student_number' => 'STD-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                    'full_name' => $name,
                    'email' => str($name)->lower()->replace(' ', '.')->append('@example.com')->toString(),
                    'address' => 'Mogadishu',
                    'date_of_birth' => Carbon::today()->subYears(19 + $index % 8),
                    'gender' => $index % 2 === 0 ? 'male' : 'female',
                    'license_type' => 'B',
                    'start_date' => $startDate,
                    'required_training_days' => $requiredDays,
                    'current_instructor_id' => $instructorId,
                    'status' => $status,
                    'total_fee' => 300,
                ],
            );

            if (! $student->assignments()->exists()) {
                StudentInstructorAssignment::create([
                    'student_id' => $student->id,
                    'instructor_id' => $instructorId,
                    'assigned_from' => $startDate,
                    'is_current' => true,
                    'reason' => 'Initial assignment',
                    'created_by' => $admin->id,
                ]);
            }

            // Attendance runs up to yesterday, so the trend charts have recent
            // data while today stays open for the check-in demo.
            if (! $student->attendance()->exists()) {
                $lastDay = Carbon::yesterday();

                for ($day = 0; $day < $presentDays; $day++) {
                    $date = $lastDay->copy()->subDays($presentDays - 1 - $day);

                    // The lesson worked on that day is part of that day's
                    // attendance record, so it is created and linked here.
                    $lesson = Lesson::create([
                        'student_id' => $student->id,
                        'instructor_id' => $instructorId,
                        'vehicle_id' => Vehicle::where('instructor_id', $instructorId)->value('id'),
                        'lesson_topic_id' => $topicIds[($index + $day) % count($topicIds)],
                        'lesson_date' => $date,
                        'topic' => 'Session '.($day + 1),
                        'performance' => ['excellent', 'very_good', 'good', 'average'][($index + $day) % 4],
                        'duration_minutes' => 60,
                        'status' => 'completed',
                        'recorded_by' => $admin->id,
                    ]);

                    Attendance::create([
                        'student_id' => $student->id,
                        'instructor_id' => $instructorId,
                        'lesson_id' => $lesson->id,
                        'attendance_date' => $date,
                        'check_in_time' => sprintf('%02d:%02d:00', 8 + $day % 3, ($day * 7) % 60),
                        'status' => 'present',
                        'recorded_by' => $admin->id,
                    ]);
                }

                // An absence a little earlier, so the reports have variety.
                if ($presentDays > 3) {
                    Attendance::create([
                        'student_id' => $student->id,
                        'instructor_id' => $instructorId,
                        'attendance_date' => $lastDay->copy()->subDays($presentDays + 1),
                        'status' => 'absent',
                        'notes' => 'Called in sick',
                        'recorded_by' => $admin->id,
                    ]);
                }
            }

            $progress->recalculate($student);
        }

        /* ---------------------------------------------------------------
         | A student login for the demo
         | --------------------------------------------------------------- */
        $ilyas = Student::where('full_name', 'Ilyas Maxamed')->first();

        $studentUser = User::updateOrCreate(
            ['email' => 'student@example.com'],
            [
                'role_id' => $studentRole->id,
                'name' => $ilyas->full_name,
                'phone' => $ilyas->phone,
                'password' => Hash::make('Student@12345'),
                'is_active' => true,
            ],
        );

        $ilyas->forceFill(['user_id' => $studentUser->id])->save();
    }
}
