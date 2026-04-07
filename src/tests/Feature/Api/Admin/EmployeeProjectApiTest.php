<?php

namespace Tests\Feature\Api\Admin;

use App\Models\EmployeeProject;
use App\Models\Project;
use App\Models\User;
use App\Support\JwtTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeProjectApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_assign_projects_with_skip_duplicate(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
        $employee = User::factory()->create([
            'role' => 'employee',
            'status' => 'active',
        ]);

        $projectA = Project::factory()->create(['status' => 'active']);
        $projectB = Project::factory()->create(['status' => 'active']);

        EmployeeProject::query()->create([
            'employee_id' => $employee->id,
            'project_id' => $projectA->id,
            'is_active' => true,
            'assigned_at' => now(),
        ]);

        $response = $this->withHeaders($this->authHeaders($admin))->postJson('/api/v1/admin/employee-projects/assign', [
            'employee_id' => $employee->id,
            'project_ids' => [$projectA->id, $projectB->id],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.assigned_count', 1)
            ->assertJsonPath('data.skipped_count', 1)
            ->assertJsonPath('data.skipped_project_ids.0', $projectA->id);

        $this->assertDatabaseHas('employee_projects', [
            'employee_id' => $employee->id,
            'project_id' => $projectB->id,
            'is_active' => true,
        ]);
    }

    public function test_assign_rejects_inactive_project(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
        $employee = User::factory()->create([
            'role' => 'employee',
            'status' => 'active',
        ]);
        $inactiveProject = Project::factory()->create([
            'status' => 'inactive',
        ]);

        $response = $this->withHeaders($this->authHeaders($admin))->postJson('/api/v1/admin/employee-projects/assign', [
            'employee_id' => $employee->id,
            'project_ids' => [$inactiveProject->id],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.inactive_project_ids.0', $inactiveProject->id);
    }

    public function test_admin_can_unassign_project(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
        $employee = User::factory()->create([
            'role' => 'employee',
            'status' => 'active',
        ]);
        $project = Project::factory()->create(['status' => 'active']);

        EmployeeProject::query()->create([
            'employee_id' => $employee->id,
            'project_id' => $project->id,
            'is_active' => true,
            'assigned_at' => now(),
        ]);

        $response = $this->withHeaders($this->authHeaders($admin))->postJson('/api/v1/admin/employee-projects/unassign', [
            'employee_id' => $employee->id,
            'project_ids' => [$project->id],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.unassigned_count', 1);

        $this->assertDatabaseHas('employee_projects', [
            'employee_id' => $employee->id,
            'project_id' => $project->id,
            'is_active' => false,
        ]);
    }

    public function test_list_assignments_hides_archived_projects(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
        $employee = User::factory()->create([
            'role' => 'employee',
            'status' => 'active',
        ]);

        $activeProject = Project::factory()->create(['status' => 'active']);
        $archivedProject = Project::factory()->create(['status' => 'archived']);

        EmployeeProject::query()->create([
            'employee_id' => $employee->id,
            'project_id' => $activeProject->id,
            'is_active' => true,
            'assigned_at' => now(),
        ]);
        EmployeeProject::query()->create([
            'employee_id' => $employee->id,
            'project_id' => $archivedProject->id,
            'is_active' => true,
            'assigned_at' => now(),
        ]);

        $response = $this->withHeaders($this->authHeaders($admin))->getJson('/api/v1/admin/employee-projects?employee_id='.$employee->id);

        $response->assertOk()
            ->assertJsonCount(1, 'data.assigned_projects')
            ->assertJsonPath('data.assigned_projects.0.id', $activeProject->id);
    }

    private function authHeaders(User $user): array
    {
        $token = app(JwtTokenService::class)->createAccessToken($user);

        return [
            'Authorization' => 'Bearer '.$token,
        ];
    }
}
