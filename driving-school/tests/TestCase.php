<?php

namespace Tests;

use App\Models\Instructor;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

abstract class TestCase extends BaseTestCase
{
    protected function seedReferenceData(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(ReferenceDataSeeder::class);
    }

    protected function makeUser(string $role, array $attributes = []): User
    {
        return User::create([
            'role_id' => Role::where('name', $role)->value('id'),
            'name' => $attributes['name'] ?? ucfirst($role).' User',
            'email' => $attributes['email'] ?? $role.'-'.uniqid().'@example.com',
            'password' => Hash::make('Password@12345'),
            'is_active' => $attributes['is_active'] ?? true,
        ]);
    }

    protected function makeInstructor(string $name, ?User $user = null): Instructor
    {
        $user ??= $this->makeUser(Role::INSTRUCTOR, ['name' => $name]);

        return Instructor::create([
            'instructor_number' => 'INS-'.str_pad((string) (Instructor::count() + 1), 4, '0', STR_PAD_LEFT),
            'user_id' => $user->id,
            'full_name' => $name,
            'phone' => '+2526110000'.rand(10, 99),
            'joining_date' => Carbon::today()->subYear(),
            'status' => 'active',
        ]);
    }

    protected function makeStudent(string $name, ?Instructor $instructor = null, array $attributes = []): Student
    {
        return Student::create([
            'student_number' => 'STD-'.str_pad((string) (Student::count() + 1), 4, '0', STR_PAD_LEFT),
            'full_name' => $name,
            'phone' => '+2526120000'.rand(10, 99),
            'start_date' => $attributes['start_date'] ?? Carbon::today()->subDays(30),
            'required_training_days' => $attributes['required_training_days'] ?? 24,
            'current_instructor_id' => $instructor?->id,
            'status' => $attributes['status'] ?? 'active',
        ]);
    }

    protected function makeVehicle(string $plate, ?Instructor $instructor = null): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => 'VEH-'.str_pad((string) (Vehicle::count() + 1), 4, '0', STR_PAD_LEFT),
            'plate_number' => $plate,
            'make' => 'Toyota',
            'model' => 'Corolla',
            'year' => 2019,
            'mileage' => 50000,
            'status' => 'in_training',
            'instructor_id' => $instructor?->id,
        ]);
    }
}
