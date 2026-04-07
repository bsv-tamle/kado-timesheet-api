<?php

namespace Tests\Feature\Api\Admin;

use App\Models\User;
use App\Support\JwtTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_user_with_temp_password_and_onboarding_data(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $response = $this->withHeaders($this->authHeaders($admin))->postJson('/api/v1/admin/users', [
            'full_name' => 'Tran Thi Hoa',
            'email' => 'tran.hoa@company.com',
            'phone' => '0901234567',
            'role' => 'employee',
            'status' => 'active',
            'send_invitation_email' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.email', 'tran.hoa@company.com')
            ->assertJsonPath('data.must_change_password', true)
            ->assertJsonPath('data.onboarding.temp_password_generated', true)
            ->assertJsonPath('data.onboarding.invitation_email_sent', false);

        $this->assertDatabaseHas('users', [
            'email' => 'tran.hoa@company.com',
            'role' => 'employee',
            'must_change_password' => true,
        ]);
    }

    public function test_create_user_rejects_duplicate_email(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        User::factory()->create([
            'email' => 'tran.hoa@company.com',
        ]);

        $response = $this->withHeaders($this->authHeaders($admin))->postJson('/api/v1/admin/users', [
            'full_name' => 'Tran Thi Hoa',
            'email' => 'tran.hoa@company.com',
            'role' => 'employee',
            'status' => 'active',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_admin_can_list_users_with_minimal_pagination_payload(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        User::factory()->count(3)->create([
            'role' => 'employee',
            'status' => 'active',
        ]);

        $response = $this->withHeaders($this->authHeaders($admin))
            ->getJson('/api/v1/admin/users?status=active&page=1&per_page=10');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'current_page',
                    'data',
                    'from',
                    'last_page',
                    'per_page',
                    'to',
                    'total',
                ],
                'message',
            ])
            ->assertJsonMissingPath('data.links')
            ->assertJsonMissingPath('data.next_page_url');
    }

    public function test_admin_can_get_user_detail(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
        $employee = User::factory()->create([
            'full_name' => 'Target User',
            'email' => 'target@example.com',
            'role' => 'employee',
            'status' => 'inactive',
        ]);

        $response = $this->withHeaders($this->authHeaders($admin))
            ->getJson('/api/v1/admin/users/'.$employee->id);

        $response->assertOk()
            ->assertJsonPath('data.id', $employee->id)
            ->assertJsonPath('data.email', 'target@example.com');
    }

    public function test_admin_can_update_user_without_changing_role(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
        $employee = User::factory()->create([
            'full_name' => 'Before Name',
            'phone' => '0901111111',
            'status' => 'active',
            'role' => 'employee',
        ]);

        $response = $this->withHeaders($this->authHeaders($admin))
            ->putJson('/api/v1/admin/users/'.$employee->id, [
                'full_name' => 'After Name',
                'phone' => '0902222222',
                'status' => 'inactive',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.full_name', 'After Name')
            ->assertJsonPath('data.status', 'inactive');

        $this->assertDatabaseHas('users', [
            'id' => $employee->id,
            'full_name' => 'After Name',
            'status' => 'inactive',
        ]);
    }

    public function test_update_user_rejects_role_change(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
        $employee = User::factory()->create([
            'role' => 'employee',
        ]);

        $response = $this->withHeaders($this->authHeaders($admin))
            ->putJson('/api/v1/admin/users/'.$employee->id, [
                'role' => 'admin',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.role.0', 'Changing role is not allowed in this endpoint.');
    }

    public function test_admin_can_lock_and_unlock_user(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
        $employee = User::factory()->create([
            'status' => 'active',
            'role' => 'employee',
        ]);

        $lockResponse = $this->withHeaders($this->authHeaders($admin))
            ->patchJson('/api/v1/admin/users/'.$employee->id.'/status', [
                'status' => 'locked',
            ]);

        $lockResponse->assertOk()
            ->assertJsonPath('data.status', 'locked');

        $this->assertDatabaseHas('users', [
            'id' => $employee->id,
            'status' => 'locked',
        ]);

        $unlockResponse = $this->withHeaders($this->authHeaders($admin))
            ->patchJson('/api/v1/admin/users/'.$employee->id.'/status', [
                'status' => 'active',
            ]);

        $unlockResponse->assertOk()
            ->assertJsonPath('data.status', 'active');
    }

    public function test_cannot_lock_last_active_admin(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $response = $this->withHeaders($this->authHeaders($admin))
            ->patchJson('/api/v1/admin/users/'.$admin->id.'/status', [
                'status' => 'locked',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'Cannot lock or inactivate the last active admin.');
    }

    public function test_admin_can_reset_password_and_force_change_password_flag(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $employee = User::factory()->create([
            'email' => 'employee@company.com',
            'password' => Hash::make('OldPass123!'),
            'must_change_password' => false,
            'role' => 'employee',
            'status' => 'active',
        ]);

        $response = $this->withHeaders($this->authHeaders($admin))
            ->postJson('/api/v1/admin/users/'.$employee->id.'/reset-password', [
                'new_password' => 'NewPass456!',
                'send_invitation_email' => true,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.reset', true)
            ->assertJsonPath('data.must_change_password', true)
            ->assertJsonPath('data.onboarding.temp_password_generated', false);

        $employee->refresh();
        $this->assertTrue((bool) $employee->must_change_password);
        $this->assertTrue(Hash::check('NewPass456!', (string) $employee->password));
    }

    public function test_non_admin_cannot_access_admin_user_apis(): void
    {
        $employee = User::factory()->create([
            'role' => 'employee',
            'status' => 'active',
        ]);

        $response = $this->withHeaders($this->authHeaders($employee))
            ->getJson('/api/v1/admin/users');

        $response->assertStatus(403);
    }

    private function authHeaders(User $user): array
    {
        $token = app(JwtTokenService::class)->createAccessToken($user);

        return [
            'Authorization' => 'Bearer '.$token,
        ];
    }
}
