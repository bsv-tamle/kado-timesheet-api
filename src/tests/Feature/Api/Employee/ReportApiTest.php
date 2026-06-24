<?php

namespace Tests\Feature\Api\Employee;

use App\Models\Project;
use App\Models\User;
use App\Support\JwtTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_can_get_personal_timesheet_report_with_expected_summary_and_datasets(): void
    {
        [$employeeA, $employeeB, $projectA, $projectB] = $this->seedReportBaseData();

        $submittedEntryA = $this->createEntry($employeeA->id, '2026-04-10', 'submitted');
        $this->createDetail($submittedEntryA, $projectA->id, 5.0);
        $this->createDetail($submittedEntryA, $projectB->id, 3.0);

        $submittedEntryB = $this->createEntry($employeeA->id, '2026-04-11', 'submitted');
        $this->createDetail($submittedEntryB, $projectA->id, 7.0);

        $otherEmployeeEntry = $this->createEntry($employeeB->id, '2026-04-11', 'submitted');
        $this->createDetail($otherEmployeeEntry, $projectA->id, 20.0);

        $draftEntry = $this->createEntry($employeeA->id, '2026-04-12', 'draft');
        $this->createDetail($draftEntry, $projectA->id, 4.0);

        $entryWithDeletedDetail = $this->createEntry($employeeA->id, '2026-04-13', 'submitted');
        $this->createDetail($entryWithDeletedDetail, $projectA->id, 2.0, now()->toDateTimeString());

        $otherMonthEntry = $this->createEntry($employeeA->id, '2026-03-20', 'submitted');
        $this->createDetail($otherMonthEntry, $projectA->id, 9.0);

        $response = $this->withHeaders($this->authHeaders($employeeA))
            ->getJson('/api/v1/my/reports/timesheets?month=2026-04');

        $response->assertOk()
            ->assertJsonPath('data.meta.month', '2026-04')
            ->assertJsonPath('data.summary.total_hours', 15)
            ->assertJsonPath('data.summary.project_count', 2)
            ->assertJsonPath('data.summary.overtime_hours', 0)
            ->assertJsonPath('data.by_project.0.project_id', $projectA->id)
            ->assertJsonPath('data.by_project.0.total_hours', 12)
            ->assertJsonPath('data.by_project.0.percentage', 80)
            ->assertJsonPath('data.by_project.1.project_id', $projectB->id)
            ->assertJsonPath('data.by_project.1.project_status', 'archived')
            ->assertJsonPath('data.by_project.1.total_hours', 3)
            ->assertJsonPath('data.by_project.1.percentage', 20)
            ->assertJsonPath('data.by_work_date.0.work_date', '2026-04-10')
            ->assertJsonPath('data.by_work_date.0.total_hours', 8)
            ->assertJsonPath('data.by_work_date.1.work_date', '2026-04-11')
            ->assertJsonPath('data.by_work_date.1.total_hours', 7)
            ->assertJsonPath('data.chart.type', 'bar_top_projects')
            ->assertJsonPath('data.chart.labels.0', $projectA->project_name)
            ->assertJsonPath('data.chart.values.0', 12);
    }

    public function test_employee_report_calculates_overtime_hours_from_daily_totals(): void
    {
        [$employee] = $this->seedReportBaseData();
        $project = Project::factory()->create(['status' => 'active']);

        $regularDay = $this->createEntry($employee->id, '2026-04-10', 'submitted');
        $this->createDetail($regularDay, $project->id, 8.0);

        $overtimeDay = $this->createEntry($employee->id, '2026-04-11', 'submitted');
        $this->createDetail($overtimeDay, $project->id, 10.0);

        $response = $this->withHeaders($this->authHeaders($employee))
            ->getJson('/api/v1/my/reports/timesheets?month=2026-04');

        $response->assertOk()
            ->assertJsonPath('data.summary.total_hours', 18)
            ->assertJsonPath('data.summary.overtime_hours', 2)
            ->assertJsonPath('data.by_work_date.0.overtime_hours', 0)
            ->assertJsonPath('data.by_work_date.1.overtime_hours', 2);
    }

    public function test_employee_can_filter_personal_report_by_project(): void
    {
        [$employee] = $this->seedReportBaseData();
        [$projectA, $projectB] = [
            Project::factory()->create(['status' => 'active']),
            Project::factory()->create(['status' => 'active']),
        ];

        $entryA = $this->createEntry($employee->id, '2026-04-10', 'submitted');
        $this->createDetail($entryA, $projectA->id, 5.0);

        $entryB = $this->createEntry($employee->id, '2026-04-11', 'submitted');
        $this->createDetail($entryB, $projectB->id, 3.0);

        $response = $this->withHeaders($this->authHeaders($employee))
            ->getJson('/api/v1/my/reports/timesheets?month=2026-04&project_id='.$projectA->id);

        $response->assertOk()
            ->assertJsonPath('data.meta.filters.project_id', $projectA->id)
            ->assertJsonPath('data.summary.total_hours', 5)
            ->assertJsonPath('data.summary.project_count', 1)
            ->assertJsonCount(1, 'data.by_project')
            ->assertJsonPath('data.by_project.0.project_id', $projectA->id)
            ->assertJsonPath('data.by_project.0.percentage', 100)
            ->assertJsonCount(1, 'data.by_work_date');
    }

    public function test_employee_cannot_see_other_employees_report_data(): void
    {
        [$employeeA, $employeeB] = $this->seedReportBaseData();
        $project = Project::factory()->create(['status' => 'active']);

        $entry = $this->createEntry($employeeB->id, '2026-04-10', 'submitted');
        $this->createDetail($entry, $project->id, 12.0);

        $response = $this->withHeaders($this->authHeaders($employeeA))
            ->getJson('/api/v1/my/reports/timesheets?month=2026-04');

        $response->assertOk()
            ->assertJsonPath('data.summary.total_hours', 0)
            ->assertJsonPath('data.summary.project_count', 0)
            ->assertJsonPath('data.by_project', [])
            ->assertJsonPath('data.by_work_date', []);
    }

    public function test_admin_cannot_access_employee_report_api(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $response = $this->withHeaders($this->authHeaders($admin))
            ->getJson('/api/v1/my/reports/timesheets?month=2026-04');

        $response->assertStatus(403);
    }

    /**
     * @return array{0: User, 1: User, 2: Project, 3: Project}
     */
    private function seedReportBaseData(): array
    {
        $employeeA = User::factory()->create([
            'full_name' => 'Alice Active',
            'role' => 'employee',
            'status' => 'active',
        ]);

        $employeeB = User::factory()->create([
            'full_name' => 'Bob Other',
            'role' => 'employee',
            'status' => 'active',
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

        return [$employeeA, $employeeB, $projectA, $projectB];
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
