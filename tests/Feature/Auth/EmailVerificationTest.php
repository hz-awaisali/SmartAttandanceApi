<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_sends_code_and_does_not_return_a_token(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Test Admin',
            'email' => 'admin-test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'admin',
        ]);

        $response->assertCreated();
        $response->assertJsonMissingPath('data.token');

        $user = User::where('email', 'admin-test@example.com')->first();
        $this->assertNotNull($user->verification_code);
        $this->assertNull($user->email_verified_at);
    }

    public function test_login_is_blocked_until_email_is_verified(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Test Admin',
            'email' => 'admin-test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'admin',
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'admin-test@example.com',
            'password' => 'password123',
        ])->assertStatus(403);
    }

    public function test_verify_email_with_correct_code_issues_a_token(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Test Admin',
            'email' => 'admin-test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'admin',
        ]);

        $user = User::where('email', 'admin-test@example.com')->first();

        $response = $this->postJson('/api/auth/verify-email', [
            'email' => 'admin-test@example.com',
            'code' => $user->verification_code,
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['user', 'token']]);

        $user->refresh();
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->verification_code);

        $this->postJson('/api/auth/login', [
            'email' => 'admin-test@example.com',
            'password' => 'password123',
        ])->assertOk();
    }

    public function test_verify_email_with_wrong_code_fails(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Test Admin',
            'email' => 'admin-test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'admin',
        ]);

        $this->postJson('/api/auth/verify-email', [
            'email' => 'admin-test@example.com',
            'code' => '000000',
        ])->assertStatus(422);
    }

    public function test_resend_code_issues_a_new_code(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Test Admin',
            'email' => 'admin-test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'admin',
        ]);

        $user = User::where('email', 'admin-test@example.com')->first();
        $firstCode = $user->verification_code;

        $this->postJson('/api/auth/resend-code', [
            'email' => 'admin-test@example.com',
        ])->assertOk();

        $user->refresh();
        $this->assertNotNull($user->verification_code);

        $this->postJson('/api/auth/verify-email', [
            'email' => 'admin-test@example.com',
            'code' => $firstCode,
        ])->assertStatus(422);
    }
}
