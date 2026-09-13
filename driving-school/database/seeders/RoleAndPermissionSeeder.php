<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            Role::ADMIN => ['label' => 'Administrator', 'description' => 'Full access to every module.'],
            Role::INSTRUCTOR => ['label' => 'Instructor', 'description' => 'Own students, attendance, lessons, vehicles and loan only.'],
            Role::STUDENT => ['label' => 'Student', 'description' => 'Read-only access to own records.'],
        ];

        foreach ($roles as $name => $attributes) {
            Role::updateOrCreate(['name' => $name], $attributes);
        }

        $permissions = [
            'students' => ['students.view' => 'View students', 'students.manage' => 'Create and edit students'],
            'instructors' => ['instructors.view' => 'View instructors', 'instructors.manage' => 'Create and edit instructors'],
            'vehicles' => ['vehicles.view' => 'View vehicles', 'vehicles.manage' => 'Create and edit vehicles'],
            'attendance' => ['attendance.view' => 'View attendance', 'attendance.manage' => 'Record and edit attendance'],
            'lessons' => ['lessons.view' => 'View lessons', 'lessons.manage' => 'Record and edit lessons'],
            'transfers' => ['transfers.view' => 'View transfers', 'transfers.manage' => 'Transfer students'],
            'finance' => [
                'finance.view' => 'View company finance',
                'finance.manage' => 'Manage expenses, debts and payments',
                'loans.view' => 'View instructor loans',
                'loans.manage' => 'Issue loans and record repayments',
            ],
            'reports' => ['reports.view' => 'Run reports'],
            'system' => [
                'users.manage' => 'Manage users and permissions',
                'audit.view' => 'View audit logs',
                'settings.manage' => 'Manage system settings',
            ],
        ];

        foreach ($permissions as $group => $items) {
            foreach ($items as $name => $label) {
                Permission::updateOrCreate(['name' => $name], ['label' => $label, 'group' => $group]);
            }
        }

        $admin = Role::where('name', Role::ADMIN)->first();
        $admin->permissions()->sync(Permission::pluck('id'));

        // An instructor's permissions cover only his own work; the policies
        // then narrow every query to his own records.
        $instructor = Role::where('name', Role::INSTRUCTOR)->first();
        $instructor->permissions()->sync(Permission::whereIn('name', [
            'students.view',
            'attendance.view', 'attendance.manage',
            'lessons.view', 'lessons.manage',
            'vehicles.view',
            'transfers.view', 'transfers.manage',
            'reports.view',
        ])->pluck('id'));

        $student = Role::where('name', Role::STUDENT)->first();
        $student->permissions()->sync(Permission::whereIn('name', [
            'attendance.view', 'lessons.view',
        ])->pluck('id'));
    }
}
