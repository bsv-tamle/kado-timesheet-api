<?php

namespace Tests\Feature\Api\Employee;

use App\Models\EmployeeProject;
use App\Models\Project;
use App\Models\TimesheetEntry;
use App\Models\TimesheetEntryDetail;
use App\Models\User;
use App\Support\JwtTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TimesheetApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_can_list_timesheets_by_month(): void
    {
        $employee = User::factory()->create([
            'role' => 'employee',
            'status' => 'active',
        ]);

        $assignedProject = Project::factory()->create(['status' => 'active']);
        $notAssignedProject = Project::factory()->create(['status' => 'active']);

        EmployeeProject::query()->create([
            'employee_id' => $employee->id,
            'project_id' => $assignedProject->id,
            'is_active' => true,
            'assigned_at' => now(),
        ]);

        $entry = TimesheetEntry::query()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-04-03',
            'total_hours' => 8,
            'status' => 'draft',
        ]);
        $newerEntry = TimesheetEntry::query()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-04-04',
            'total_hours' => 1,
            'status' => 'draft',
        ]);
        TimesheetEntryDetail::query()->create([
            'timesheet_entry_id' => $newerEntry->id,
            'project_id' => $assignedProject->id,
            'hours_worked' => 1,
            'note' => 'Newest day work',
        ]);

        TimesheetEntryDetail::query()->create([
            'timesheet_entry_id' => $entry->id,
            'project_id' => $assignedProject->id,
            'hours_worked' => 5.5,
            'note' => 'Assigned project work',
        ]);
        TimesheetEntryDetail::query()->create([
            'timesheet_entry_id' => $entry->id,
            'project_id' => $notAssignedProject->id,
            'hours_worked' => 2.5,
            'note' => 'Should be hidden',
        ]);

        $response = $this->withHeaders($this->authHeaders($employee))
            ->getJson('/api/v1/timesheets?month=2026-04');

        $response->assertOk()
            ->assertJsonPath('data.month', '2026-04')
            ->assertJsonPath('data.entries.0.entry_id', $newerEntry->id)
            ->assertJsonPath('data.entries.1.entry_id', $entry->id)
            ->assertJsonPath('data.entries.1.total_hours', 5.5)
            ->assertJsonPath('data.entries.1.details.0.project_id', $assignedProject->id)
            ->assertJsonPath('data.summary.total_hours_month', 6.5);
    }

    public function test_employee_can_list_assigned_projects_for_timesheet_form(): void
    {
        $employee = User::factory()->create([
            'role' => 'employee',
            'status' => 'active',
        ]);

        $activeProject = Project::factory()->create(['status' => 'active']);
        $inactiveProject = Project::factory()->create(['status' => 'inactive']);

        EmployeeProject::query()->create([
            'employee_id' => $employee->id,
            'project_id' => $activeProject->id,
            'is_active' => true,
            'assigned_at' => now(),
        ]);
        EmployeeProject::query()->create([
            'employee_id' => $employee->id,
            'project_id' => $inactiveProject->id,
            'is_active' => true,
            'assigned_at' => now(),
        ]);

        $response = $this->withHeaders($this->authHeaders($employee))
            ->getJson('/api/v1/my-projects');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $activeProject->id);
    }

    public function test_employee_can_create_timesheet_entry(): void
    {
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
        EmployeeProject::query()->create([
            'employee_id' => $employee->id,
            'project_id' => $projectB->id,
            'is_active' => true,
            'assigned_at' => now(),
        ]);

        $workDate = Carbon::today('Asia/Tokyo')->subDays(2)->toDateString();

        $response = $this->withHeaders($this->authHeaders($employee))->postJson('/api/v1/timesheets', [
            'work_date' => $workDate,
            'details' => [
                [
                    'project_id' => $projectA->id,
                    'hours_worked' => 3.5,
                    'note' => 'Task A',
                ],
                [
                    'project_id' => $projectB->id,
                    'hours_worked' => 4,
                    'note' => 'Task B',
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.work_date', $workDate)
            ->assertJsonPath('data.total_hours', 7.5)
            ->assertJsonPath('data.created_detail_count', 2);

        $this->assertDatabaseHas('timesheet_entries', [
            'employee_id' => $employee->id,
            'work_date' => $workDate.' 00:00:00',
            'total_hours' => 7.5,
        ]);
    }

    public function test_employee_can_create_timesheet_entry_for_today_in_jst(): void
    {
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

        $workDate = Carbon::today('Asia/Tokyo')->toDateString();

        $response = $this->withHeaders($this->authHeaders($employee))->postJson('/api/v1/timesheets', [
            'work_date' => $workDate,
            'details' => [
                [
                    'project_id' => $project->id,
                    'hours_worked' => 2,
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.work_date', $workDate);
    }

    public function test_create_timesheet_rejects_unassigned_project(): void
    {
        $employee = User::factory()->create([
            'role' => 'employee',
            'status' => 'active',
        ]);
        $project = Project::factory()->create(['status' => 'active']);

        $response = $this->withHeaders($this->authHeaders($employee))->postJson('/api/v1/timesheets', [
            'work_date' => '2026-04-06',
            'details' => [
                [
                    'project_id' => $project->id,
                    'hours_worked' => 1,
                ],
            ],
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Forbidden.')
            ->assertJsonPath('errors.forbidden_project_ids.0', $project->id);
    }

    public function test_employee_can_update_own_entry(): void
    {
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
        EmployeeProject::query()->create([
            'employee_id' => $employee->id,
            'project_id' => $projectB->id,
            'is_active' => true,
            'assigned_at' => now(),
        ]);

        $originalWorkDate = Carbon::today('Asia/Tokyo')->subDays(4)->toDateString();
        $updatedWorkDate = Carbon::today('Asia/Tokyo')->subDays(3)->toDateString();

        $entry = TimesheetEntry::query()->create([
            'employee_id' => $employee->id,
            'work_date' => $originalWorkDate,
            'total_hours' => 2,
            'status' => 'draft',
        ]);

        TimesheetEntryDetail::query()->create([
            'timesheet_entry_id' => $entry->id,
            'project_id' => $projectA->id,
            'hours_worked' => 2,
            'note' => 'Old',
        ]);

        $response = $this->withHeaders($this->authHeaders($employee))->putJson('/api/v1/timesheets/'.$entry->id, [
            'work_date' => $updatedWorkDate,
            'details' => [
                [
                    'project_id' => $projectA->id,
                    'hours_worked' => 3,
                    'note' => 'Updated A',
                ],
                [
                    'project_id' => $projectB->id,
                    'hours_worked' => 1.5,
                    'note' => 'Updated B',
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.entry_id', $entry->id)
            ->assertJsonPath('data.updated_detail_count', 2);

        $this->assertDatabaseHas('timesheet_entries', [
            'id' => $entry->id,
            'total_hours' => 4.5,
        ]);
        $entry->refresh();
        $this->assertSame($updatedWorkDate, $entry->work_date?->toDateString());
        $this->assertDatabaseCount('timesheet_entry_details', 3);
        $this->assertDatabaseHas('timesheet_entry_details', [
            'timesheet_entry_id' => $entry->id,
            'project_id' => $projectB->id,
            'hours_worked' => 1.5,
        ]);
    }

    public function test_employee_cannot_update_other_employee_entry(): void
    {
        $employeeA = User::factory()->create([
            'role' => 'employee',
            'status' => 'active',
        ]);
        $employeeB = User::factory()->create([
            'role' => 'employee',
            'status' => 'active',
        ]);

        $entry = TimesheetEntry::query()->create([
            'employee_id' => $employeeA->id,
            'work_date' => '2026-04-12',
            'total_hours' => 1,
            'status' => 'draft',
        ]);

        $response = $this->withHeaders($this->authHeaders($employeeB))->putJson('/api/v1/timesheets/'.$entry->id, [
            'work_date' => '2026-04-12',
            'details' => [
                [
                    'project_id' => 1,
                    'hours_worked' => 1,
                ],
            ],
        ]);

        $response->assertStatus(403);
    }

    public function test_employee_can_delete_detail_and_entry_is_removed_when_empty(): void
    {
        $employee = User::factory()->create([
            'role' => 'employee',
            'status' => 'active',
        ]);
        $project = Project::factory()->create(['status' => 'active']);

        $entry = TimesheetEntry::query()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-04-13',
            'total_hours' => 2,
            'status' => 'draft',
        ]);

        $detail = TimesheetEntryDetail::query()->create([
            'timesheet_entry_id' => $entry->id,
            'project_id' => $project->id,
            'hours_worked' => 2,
        ]);

        $response = $this->withHeaders($this->authHeaders($employee))
            ->deleteJson('/api/v1/timesheets/details/'.$detail->id);

        $response->assertOk()
            ->assertJsonPath('data.entry_id', $entry->id)
            ->assertJsonPath('data.entry_deleted', true);

        $this->assertSoftDeleted('timesheet_entry_details', ['id' => $detail->id]);
        $this->assertSoftDeleted('timesheet_entries', ['id' => $entry->id]);
    }

    public function test_non_employee_cannot_access_timesheet_apis(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $response = $this->withHeaders($this->authHeaders($admin))
            ->getJson('/api/v1/timesheets?month=2026-04');

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
