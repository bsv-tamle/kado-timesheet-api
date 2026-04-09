<?php

namespace Tests\Feature\Api\Auth;

use App\Mail\PasswordResetLinkMail;
use App\Models\User;
use App\Support\JwtTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_returns_generic_success_and_sends_mail_when_user_exists(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'employee@example.com',
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => $user->email,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.sent', true)
            ->assertJsonPath('message', 'If this email exists, reset instructions have been sent.');

        Mail::assertSent(PasswordResetLinkMail::class);
        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => $user->email,
        ]);
    }

    public function test_forgot_password_returns_generic_success_when_user_not_found(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'not-found@example.com',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.sent', true)
            ->assertJsonPath('message', 'If this email exists, reset instructions have been sent.');

        Mail::assertNothingSent();
    }

    public function test_forgot_password_is_rate_limited(): void
    {
        User::factory()->create([
            'email' => 'employee@example.com',
            'status' => 'active',
        ]);

        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'employee@example.com',
        ])->assertOk();

        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'employee@example.com',
        ])->assertStatus(429);
    }

    public function test_user_can_reset_password_with_valid_token(): void
    {
        $user = User::factory()->create([
            'email' => 'employee@example.com',
            'password' => Hash::make('OldPass123!'),
            'must_change_password' => true,
        ]);

        $token = Password::createToken($user);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'NewPass123!',
            'password_confirmation' => 'NewPass123!',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.reset', true)
            ->assertJsonPath('message', 'Password reset successfully');

        $user->refresh();
        $this->assertTrue(Hash::check('NewPass123!', (string) $user->password));
        $this->assertFalse((bool) $user->must_change_password);
        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => $user->email,
        ]);
    }

    public function test_reset_password_rejects_invalid_token(): void
    {
        $user = User::factory()->create([
            'email' => 'employee@example.com',
        ]);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => 'invalid-token',
            'password' => 'NewPass123!',
            'password_confirmation' => 'NewPass123!',
        ]);

        $response->assertStatus(410)
            ->assertJsonPath('message', 'Reset token is invalid or expired.');
    }

    public function test_user_can_change_password_when_authenticated(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('OldPass123!'),
            'must_change_password' => true,
            'status' => 'active',
        ]);

        $response = $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/v1/auth/change-password', [
                'current_password' => 'OldPass123!',
                'new_password' => 'NewPass123!',
                'new_password_confirmation' => 'NewPass123!',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.changed', true)
            ->assertJsonPath('message', 'Password changed successfully');

        $user->refresh();
        $this->assertTrue(Hash::check('NewPass123!', (string) $user->password));
        $this->assertFalse((bool) $user->must_change_password);
    }

    public function test_change_password_fails_when_current_password_is_wrong(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('OldPass123!'),
            'status' => 'active',
        ]);

        $response = $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/v1/auth/change-password', [
                'current_password' => 'WrongPass123!',
                'new_password' => 'NewPass123!',
                'new_password_confirmation' => 'NewPass123!',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.current_password.0', 'Current password is incorrect.');
    }

    private function authHeaders(User $user): array
    {
        $token = app(JwtTokenService::class)->createAccessToken($user);

        return [
            'Authorization' => 'Bearer '.$token,
        ];
    }
}
