<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Department;
use App\Models\Program;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private function createTeacher(string $email = 'teacher@example.com', string $employeeNo = 'EMP-001'): User
    {
        $user = User::factory()->create(['email' => $email, 'role' => 'teacher', 'status' => 'active']);
        $department = Department::create(['name' => 'CS Department']);

        Teacher::create([
            'user_id' => $user->id,
            'department_id' => $department->id,
            'employee_no' => $employeeNo,
            'designation' => 'Lecturer',
        ]);

        return $user;
    }

    private function createStudent(string $email = 'student@example.com', string $registrationNo = 'REG-001'): User
    {
        $user = User::factory()->create(['email' => $email, 'role' => 'student', 'status' => 'active']);
        $department = Department::create(['name' => 'SE Department']);

        $program = Program::create([
            'department_id' => $department->id,
            'name' => 'BS Software Engineering',
            'code' => 'BSSE',
        ]);

        $batch = Batch::create([
            'program_id' => $program->id,
            'batch_name' => 'Fall 2024',
            'start_year' => 2024,
            'end_year' => 2028,
            'semester' => 1,
            'shift' => 'Morning',
        ]);

        Student::create([
            'user_id' => $user->id,
            'registration_no' => $registrationNo,
            'department_id' => $department->id,
            'batch_id' => $batch->id,
        ]);

        return $user;
    }

    public function test_forgot_password_verifies_teacher_email_with_employee_no(): void
    {
        $this->createTeacher();

        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => 'teacher@example.com',
            'identity_no' => 'EMP-001',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Identity verified. You can now set a new password.');
    }

    public function test_forgot_password_verifies_student_email_with_registration_no(): void
    {
        $this->createStudent();

        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => 'student@example.com',
            'identity_no' => 'REG-001',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Identity verified. You can now set a new password.');
    }

    public function test_forgot_password_rejects_wrong_identity_no(): void
    {
        $this->createTeacher();

        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => 'teacher@example.com',
            'identity_no' => 'EMP-999',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('identity_no');
    }

    public function test_forgot_password_rejects_unknown_email(): void
    {
        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => 'unknown@example.com',
            'identity_no' => 'EMP-001',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_forgot_password_is_not_available_for_admin_accounts(): void
    {
        User::factory()->create(['email' => 'admin@example.com', 'role' => 'admin']);

        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => 'admin@example.com',
            'identity_no' => 'anything',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('identity_no');
    }

    public function test_reset_password_updates_password_when_email_and_identity_match(): void
    {
        $user = $this->createTeacher();
        $user->createToken('api-token');

        $response = $this->postJson('/api/auth/reset-password', [
            'email' => 'teacher@example.com',
            'identity_no' => 'EMP-001',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Your password has been reset.');

        $user->refresh();
        $this->assertTrue(Hash::check('new-password-123', $user->password));
        // Existing API tokens are revoked so every device must log in again.
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_reset_password_rejects_wrong_identity_no(): void
    {
        $user = $this->createTeacher();

        $response = $this->postJson('/api/auth/reset-password', [
            'email' => 'teacher@example.com',
            'identity_no' => 'EMP-999',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('identity_no');

        $this->assertFalse(Hash::check('new-password-123', $user->fresh()->password));
    }

    public function test_reset_password_requires_confirmation_and_min_length(): void
    {
        $this->createTeacher();

        $response = $this->postJson('/api/auth/reset-password', [
            'email' => 'teacher@example.com',
            'identity_no' => 'EMP-001',
            'password' => 'short',
            'password_confirmation' => 'different',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_user_can_login_with_the_new_password(): void
    {
        $this->createStudent();

        $this->postJson('/api/auth/reset-password', [
            'email' => 'student@example.com',
            'identity_no' => 'REG-001',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk();

        $response = $this->postJson('/api/auth/login', [
            'email' => 'student@example.com',
            'password' => 'new-password-123',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['data' => ['user', 'token']]);
    }
}
