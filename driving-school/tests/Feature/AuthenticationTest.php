<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/admin/dashboard')->assertRedirect(route('login'));
        $this->get('/instructor/dashboard')->assertRedirect(route('login'));
        $this->get('/student/dashboard')->assertRedirect(route('login'));
    }

    public function test_a_user_can_sign_in_with_valid_credentials(): void
    {
        $user = $this->makeUser(Role::ADMIN, ['email' => 'admin@test.local']);

        $this->post('/login', ['email' => 'admin@test.local', 'password' => 'Password@12345'])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_sign_in_fails_with_a_wrong_password(): void
    {
        $this->makeUser(Role::ADMIN, ['email' => 'admin@test.local']);

        $this->post('/login', ['email' => 'admin@test.local', 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_deactivated_user_cannot_sign_in(): void
    {
        $this->makeUser(Role::ADMIN, ['email' => 'off@test.local', 'is_active' => false]);

        $this->post('/login', ['email' => 'off@test.local', 'password' => 'Password@12345'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_each_role_lands_on_its_own_dashboard(): void
    {
        $admin = $this->makeUser(Role::ADMIN);
        $this->assertSame('admin.dashboard', $admin->homeRoute());

        $instructorUser = $this->makeUser(Role::INSTRUCTOR);
        $this->makeInstructor('Test Instructor', $instructorUser);
        $this->assertSame('instructor.dashboard', $instructorUser->homeRoute());

        $studentUser = $this->makeUser(Role::STUDENT);
        $this->assertSame('student.dashboard', $studentUser->homeRoute());
    }

    public function test_signing_in_and_out_is_written_to_the_audit_log(): void
    {
        $this->makeUser(Role::ADMIN, ['email' => 'admin@test.local']);

        $this->post('/login', ['email' => 'admin@test.local', 'password' => 'Password@12345']);
        $this->post('/logout');

        $this->assertTrue(AuditLog::where('action', 'auth.login')->exists());
        $this->assertTrue(AuditLog::where('action', 'auth.logout')->exists());
    }
}
