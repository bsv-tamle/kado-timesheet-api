<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Project;
use App\Models\User;
use App\Support\JwtTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_project(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $response = $this->withHeaders($this->authHeaders($admin))->postJson('/api/v1/admin/projects', [
            'project_code' => 'PJ-10963',
            'project_name' => 'WORK DESIGN PLATFORM_2026/4',
            'status' => 'active',
            'billable_flag' => true,
            'description' => 'Dự án WDP tháng 04/2026',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.project_code', 'PJ-10963')
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('projects', [
            'project_code' => 'PJ-10963',
            'status' => 'active',
        ]);
    }

    public function test_admin_can_get_project_detail(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $project = Project::factory()->create([
            'project_code' => 'PJ-77777',
            'project_name' => 'Detail Target',
            'status' => 'inactive',
            'billable_flag' => true,
            'description' => 'Project detail',
        ]);

        $response = $this->withHeaders($this->authHeaders($admin))
            ->getJson('/api/v1/admin/projects/'.$project->id);

        $response->assertOk()
            ->assertJsonPath('data.id', $project->id)
            ->assertJsonPath('data.project_code', 'PJ-77777')
            ->assertJsonPath('data.project_name', 'Detail Target')
            ->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.billable_flag', true);
    }

    public function test_admin_can_filter_projects_by_billable_flag_with_string_query(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        Project::factory()->create([
            'project_code' => 'PJ-BILLABLE-TRUE',
            'billable_flag' => true,
        ]);
        Project::factory()->create([
            'project_code' => 'PJ-BILLABLE-FALSE',
            'billable_flag' => false,
        ]);

        $response = $this->withHeaders($this->authHeaders($admin))
            ->getJson('/api/v1/admin/projects?billable_flag=true&page=1&per_page=10');

        $response->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.project_code', 'PJ-BILLABLE-TRUE');
    }

    public function test_get_project_detail_returns_404_when_project_not_found(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $response = $this->withHeaders($this->authHeaders($admin))
            ->getJson('/api/v1/admin/projects/999999');

        $response->assertNotFound()
            ->assertJsonPath('message', 'Project not found.');
    }

    public function test_create_project_requires_unique_project_code(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        Project::factory()->create([
            'project_code' => 'PJ-10963',
        ]);

        $response = $this->withHeaders($this->authHeaders($admin))->postJson('/api/v1/admin/projects', [
            'project_code' => 'PJ-10963',
            'project_name' => 'Duplicate Code Project',
            'status' => 'active',
            'billable_flag' => true,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['project_code']);
    }

    public function test_admin_can_update_project(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $project = Project::factory()->create([
            'project_name' => 'Old Project Name',
            'status' => 'inactive',
            'billable_flag' => false,
        ]);

        $response = $this->withHeaders($this->authHeaders($admin))->putJson('/api/v1/admin/projects/'.$project->id, [
            'project_name' => 'WORK DESIGN PLATFORM_2026/5',
            'status' => 'active',
            'billable_flag' => true,
            'description' => 'Cập nhật scope tháng 05',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.project_name', 'WORK DESIGN PLATFORM_2026/5')
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'project_name' => 'WORK DESIGN PLATFORM_2026/5',
            'status' => 'active',
        ]);
    }

    public function test_admin_can_archive_project_with_status_api(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $project = Project::factory()->create([
            'status' => 'active',
        ]);

        $response = $this->withHeaders($this->authHeaders($admin))->patchJson('/api/v1/admin/projects/'.$project->id.'/status', [
            'status' => 'archived',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.id', $project->id)
            ->assertJsonPath('data.status', 'archived');

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'status' => 'archived',
        ]);
    }

    public function test_non_admin_cannot_access_project_apis(): void
    {
        $employee = User::factory()->create([
            'role' => 'employee',
            'status' => 'active',
        ]);

        $response = $this->withHeaders($this->authHeaders($employee))->getJson('/api/v1/admin/projects');

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
