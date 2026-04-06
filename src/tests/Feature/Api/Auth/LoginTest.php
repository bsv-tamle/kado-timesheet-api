<?php

namespace Tests\Feature\Api\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_successfully(): void
    {
        $user = User::factory()->create([
            'full_name' => 'Test Employee',
            'email' => 'employee@example.com',
            'password' => Hash::make('Secret123!'),
            'role' => 'employee',
            'status' => 'active',
            'must_change_password' => true,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Secret123!',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'access_token',
                    'token_type',
                    'expires_in',
                    'user' => ['id', 'full_name', 'email', 'role', 'must_change_password'],
                ],
                'message',
            ])
            ->assertJsonPath('data.user.email', 'employee@example.com');
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'employee@example.com',
            'password' => Hash::make('Secret123!'),
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'employee@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401);
    }

    public function test_login_fails_when_account_is_not_active(): void
    {
        User::factory()->create([
            'email' => 'employee@example.com',
            'password' => Hash::make('Secret123!'),
            'status' => 'locked',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'employee@example.com',
            'password' => 'Secret123!',
        ]);

        $response->assertStatus(403);
    }
}

