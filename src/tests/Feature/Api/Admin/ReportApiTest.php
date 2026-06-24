<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Project;
use App\Models\User;
use App\Support\JwtTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_get_timesheet_report_with_expected_summary_and_datasets(): void
    {
        [$admin, $departmentA, $departmentB, $employeeA, $employeeB, $projectA, $projectB] = $this->seedReportBaseData();

        $submittedEntryA = $this->createEntry($employeeA->id, '2026-04-10', 'submitted');
        $this->createDetail($submittedEntryA, $projectA->id, 5.0);
        $this->createDetail($submittedEntryA, $projectB->id, 3.0);

        $submittedEntryB = $this->createEntry($employeeB->id, '2026-04-11', 'submitted');
        $this->createDetail($submittedEntryB, $projectA->id, 7.0);

        $draftEntry = $this->createEntry($employeeA->id, '2026-04-12', 'draft');
        $this->createDetail($draftEntry, $projectA->id, 4.0);

        $entryWithDeletedDetail = $this->createEntry($employeeA->id, '2026-04-13', 'submitted');
        $this->createDetail($entryWithDeletedDetail, $projectA->id, 2.0, now()->toDateTimeString());

        $otherMonthEntry = $this->createEntry($employeeA->id, '2026-03-20', 'submitted');
        $this->createDetail($otherMonthEntry, $projectA->id, 9.0);

        $response = $this->withHeaders($this->authHeaders($admin))
            ->getJson('/api/v1/admin/reports/timesheets?month=2026-04');

        $response->assertOk()
            ->assertJsonPath('data.meta.month', '2026-04')
            ->assertJsonPath('data.summary.total_hours', 15)
            ->assertJsonPath('data.summary.employee_count', 2)
            ->assertJsonPath('data.summary.project_count', 2)
            ->assertJsonPath('data.by_project.0.project_id', $projectA->id)
            ->assertJsonPath('data.by_project.0.total_hours', 12)
            ->assertJsonPath('data.by_project.0.employee_count', 2)
            ->assertJsonPath('data.by_project.1.project_id', $projectB->id)
            ->assertJsonPath('data.by_project.1.project_status', 'archived')
            ->assertJsonPath('data.by_employee_project.0.employee_name', 'Alice Active')
            ->assertJsonPath('data.by_employee_project.0.total_hours', 5)
            ->assertJsonPath('data.by_employee_project.1.employee_name', 'Alice Active')
            ->assertJsonPath('data.by_employee_project.1.total_hours', 3)
            ->assertJsonPath('data.by_employee_project.2.employee_name', 'Bob Inactive')
            ->assertJsonPath('data.by_employee_project.2.total_hours', 7)
            ->assertJsonPath('data.chart.type', 'bar_top_projects')
            ->assertJsonPath('data.chart.labels.0', $projectA->project_name)
            ->assertJsonPath('data.chart.values.0', 12)
            ->assertJsonPath('data.pagination.total', 3);
    }

    public function test_admin_can_filter_report_with_and_logic(): void
    {
        [$admin, $departmentA, $departmentB, $employeeA, $employeeB, $projectA, $projectB] = $this->seedReportBaseData();

        $entryA = $this->createEntry($employeeA->id, '2026-04-10', 'submitted');
        $this->createDetail($entryA, $projectA->id, 5.0);

        $entryB = $this->createEntry($employeeB->id, '2026-04-10', 'submitted');
        $this->createDetail($entryB, $projectA->id, 7.0);

        $entryC = $this->createEntry($employeeB->id, '2026-04-12', 'submitted');
        $this->createDetail($entryC, $projectB->id, 3.0);

        $response = $this->withHeaders($this->authHeaders($admin))
            ->getJson('/api/v1/admin/reports/timesheets?month=2026-04&employee_id='.$employeeB->id.'&project_id='.$projectA->id);

        $response->assertOk()
            ->assertJsonPath('data.summary.total_hours', 7)
            ->assertJsonPath('data.summary.employee_count', 1)
            ->assertJsonPath('data.summary.project_count', 1)
            ->assertJsonPath('data.by_project.0.project_id', $projectA->id)
            ->assertJsonPath('data.by_project.0.total_hours', 7)
            ->assertJsonPath('data.by_employee_project.0.employee_id', $employeeB->id)
            ->assertJsonPath('data.by_employee_project.0.project_id', $projectA->id)
            ->assertJsonPath('data.by_employee_project.0.total_hours', 7)
            ->assertJsonPath('data.pagination.total', 1);
    }

    public function test_admin_can_paginate_employee_project_dataset(): void
    {
        [$admin, $departmentA, $departmentB, $employeeA, $employeeB, $projectA, $projectB] = $this->seedReportBaseData();

        $entry1 = $this->createEntry($employeeA->id, '2026-04-10', 'submitted');
        $this->createDetail($entry1, $projectA->id, 5.0);

        $entry2 = $this->createEntry($employeeA->id, '2026-04-11', 'submitted');
        $this->createDetail($entry2, $projectB->id, 3.0);

        $entry3 = $this->createEntry($employeeB->id, '2026-04-12', 'submitted');
        $this->createDetail($entry3, $projectA->id, 7.0);

        $response = $this->withHeaders($this->authHeaders($admin))
            ->getJson('/api/v1/admin/reports/timesheets?month=2026-04&page=2&per_page=1');

        $response->assertOk()
            ->assertJsonCount(1, 'data.by_employee_project')
            ->assertJsonPath('data.pagination.page', 2)
            ->assertJsonPath('data.pagination.per_page', 1)
            ->assertJsonPath('data.pagination.total', 3)
            ->assertJsonPath('data.by_employee_project.0.employee_name', 'Alice Active')
            ->assertJsonPath('data.by_employee_project.0.project_name', $projectB->project_name);
    }

    public function test_non_admin_cannot_access_report_api(): void
    {
        $employee = User::factory()->create([
            'role' => 'employee',
            'status' => 'active',
        ]);

        $response = $this->withHeaders($this->authHeaders($employee))
            ->getJson('/api/v1/admin/reports/timesheets?month=2026-04');

        $response->assertStatus(403);
    }

    /**
     * @return array{0: User, 1: int, 2: int, 3: User, 4: User, 5: Project, 6: Project}
     */
    private function seedReportBaseData(): array
    {
        $departmentA = $this->createDepartment('QC');
        $departmentB = $this->createDepartment('DEV');

        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $employeeA = User::factory()->create([
            'full_name' => 'Alice Active',
            'role' => 'employee',
            'status' => 'active',
            'department_id' => $departmentA,
        ]);

        $employeeB = User::factory()->create([
            'full_name' => 'Bob Inactive',
            'role' => 'employee',
            'status' => 'inactive',
            'department_id' => $departmentB,
        ]);

        $projectA = Project::factory()->create([
            'project_code' => 'PJ-REPORT-A',
            'project_name' => 'ALLRORA QC',
            'status' => 'active',
        ]);

        $projectB = Project::factory()->create([
            'project_code' => 'PJ-REPORT-B',
            'project_name' => 'WORK DESIGN 4',
            'status' => 'archived',
        ]);

        return [$admin, $departmentA, $departmentB, $employeeA, $employeeB, $projectA, $projectB];
    }

    private function createDepartment(string $name): int
    {
        $now = now();
        $code = strtoupper(substr($name, 0, 3)).'-'.substr(md5($name.$now->timestamp), 0, 6);

        return (int) DB::table('departments')->insertGetId([
            'code' => $code,
            'name' => $name,
            'description' => null,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function createEntry(int $employeeId, string $workDate, string $status): int
    {
        $now = now();

        return (int) DB::table('timesheet_entries')->insertGetId([
            'employee_id' => $employeeId,
            'work_date' => $workDate,
            'period_id' => null,
            'total_hours' => 0,
            'status' => $status,
            'submitted_at' => $status === 'submitted' ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);
    }

    private function createDetail(int $entryId, int $projectId, float $hours, ?string $deletedAt = null): void
    {
        $now = now();

        DB::table('timesheet_entry_details')->insert([
            'timesheet_entry_id' => $entryId,
            'project_id' => $projectId,
            'work_type_id' => null,
            'hours_worked' => $hours,
            'note' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => $deletedAt,
        ]);
    }

    private function authHeaders(User $user): array
    {
        $token = app(JwtTokenService::class)->createAccessToken($user);

        return [
            'Authorization' => 'Bearer '.$token,
        ];
    }
}
