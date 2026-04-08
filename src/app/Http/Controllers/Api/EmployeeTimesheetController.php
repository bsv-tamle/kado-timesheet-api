<?php

namespace App\Http\Controllers\Api;

use App\Models\EmployeeProject;
use App\Models\TimesheetEntry;
use App\Models\TimesheetEntryDetail;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EmployeeTimesheetController extends ApiController
{
    public function myProjects(Request $request): JsonResponse
    {
        /** @var User $employee */
        $employee = $request->user();

        $projects = EmployeeProject::query()
            ->with('project:id,project_code,project_name,status')
            ->where('employee_id', $employee->id)
            ->where('is_active', true)
            ->whereHas('project', function ($query): void {
                $query->where('status', 'active');
            })
            ->orderByDesc('id')
            ->get()
            ->map(static function (EmployeeProject $mapping): array {
                return [
                    'id' => $mapping->project->id,
                    'project_code' => $mapping->project->project_code,
                    'project_name' => $mapping->project->project_name,
                    'status' => $mapping->project->status,
                ];
            })
            ->values()
            ->all();

        return $this->successResponse($projects);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
        ]);

        /** @var User $employee */
        $employee = $request->user();

        $monthDate = Carbon::createFromFormat('Y-m', $validated['month'])->startOfMonth();
        $startDate = $monthDate->copy()->startOfMonth()->toDateString();
        $endDate = $monthDate->copy()->endOfMonth()->toDateString();
        $assignedProjectIds = $this->assignedActiveProjectIds($employee->id);

        $entries = TimesheetEntry::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$startDate, $endDate])
            ->with([
                'details' => function ($query): void {
                    $query->orderBy('id');
                },
                'details.project:id,project_code,project_name,status',
            ])
            ->orderByDesc('work_date')
            ->get();

        $entryRows = [];
        $totalHoursMonth = 0.0;

        foreach ($entries as $entry) {
            $detailRows = [];
            $entryTotalHours = 0.0;

            foreach ($entry->details as $detail) {
                if (! in_array($detail->project_id, $assignedProjectIds, true)) {
                    continue;
                }

                if (! $detail->project) {
                    continue;
                }

                $hours = (float) $detail->hours_worked;
                $entryTotalHours += $hours;

                $detailRows[] = [
                    'detail_id' => $detail->id,
                    'project_id' => $detail->project_id,
                    'project_code' => $detail->project->project_code,
                    'project_name' => $detail->project->project_name,
                    'hours_worked' => $hours,
                    'note' => $detail->note,
                ];
            }

            if ($detailRows === []) {
                continue;
            }

            $entryTotalHours = round($entryTotalHours, 2);
            $totalHoursMonth += $entryTotalHours;

            $entryRows[] = [
                'entry_id' => $entry->id,
                'work_date' => $entry->work_date?->toDateString(),
                'total_hours' => $entryTotalHours,
                'details' => $detailRows,
            ];
        }

        return $this->successResponse([
            'month' => $validated['month'],
            'entries' => $entryRows,
            'summary' => [
                'total_hours_month' => round($totalHoursMonth, 2),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'work_date' => ['required', 'date_format:Y-m-d'],
            'details' => ['required', 'array', 'min:1'],
            'details.*.project_id' => ['required', 'integer', 'distinct'],
            'details.*.hours_worked' => ['required', 'numeric', 'min:0', 'max:24'],
            'details.*.note' => ['nullable', 'string', 'max:1000'],
        ]);

        /** @var User $employee */
        $employee = $request->user();
        $workDate = Carbon::createFromFormat('Y-m-d', $validated['work_date'], 'Asia/Tokyo')->startOfDay();

        if ($workDate->greaterThan(Carbon::today('Asia/Tokyo'))) {
            return $this->errorResponse('Validation error', 422, [
                'work_date' => ['work_date cannot be in the future.'],
            ]);
        }

        $exists = TimesheetEntry::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $workDate->toDateString())
            ->exists();

        if ($exists) {
            return $this->errorResponse('Timesheet entry already exists for this work date.', 409);
        }

        $projectIds = array_values(array_unique(array_map(
            static fn (array $detail): int => (int) $detail['project_id'],
            $validated['details']
        )));

        $forbiddenProjectIds = $this->forbiddenProjectIds($employee->id, $projectIds);
        if ($forbiddenProjectIds !== []) {
            return $this->errorResponse('Forbidden.', 403, [
                'project_ids' => ['Some projects are not assigned to current employee.'],
                'forbidden_project_ids' => $forbiddenProjectIds,
            ]);
        }

        $totalHours = $this->sumDetailsHours($validated['details']);
        if ($totalHours > 24) {
            return $this->errorResponse('Validation error', 422, [
                'details' => ['Total hours per work date must not exceed 24.'],
            ]);
        }

        $entry = DB::transaction(function () use ($employee, $workDate, $validated, $totalHours): TimesheetEntry {
            $entry = TimesheetEntry::query()->create([
                'employee_id' => $employee->id,
                'work_date' => $workDate->toDateString(),
                'total_hours' => $totalHours,
                'status' => 'draft',
            ]);

            foreach ($validated['details'] as $detail) {
                TimesheetEntryDetail::query()->create([
                    'timesheet_entry_id' => $entry->id,
                    'project_id' => (int) $detail['project_id'],
                    'hours_worked' => (float) $detail['hours_worked'],
                    'note' => $detail['note'] ?? null,
                ]);
            }

            return $entry;
        });

        return $this->successResponse([
            'entry_id' => $entry->id,
            'work_date' => $entry->work_date?->toDateString(),
            'total_hours' => (float) $entry->total_hours,
            'created_detail_count' => count($validated['details']),
        ], 'Timesheet entry created successfully', 201);
    }

    public function update(Request $request, int $entryId): JsonResponse
    {
        $validated = $request->validate([
            'work_date' => ['required', 'date_format:Y-m-d'],
            'details' => ['required', 'array', 'min:1'],
            'details.*.detail_id' => ['nullable', 'integer'],
            'details.*.project_id' => ['required', 'integer', 'distinct'],
            'details.*.hours_worked' => ['required', 'numeric', 'min:0', 'max:24'],
            'details.*.note' => ['nullable', 'string', 'max:1000'],
        ]);

        /** @var User $employee */
        $employee = $request->user();

        $entry = TimesheetEntry::query()->find($entryId);
        if (! $entry) {
            return $this->errorResponse('Timesheet entry not found.', 404);
        }

        if ((int) $entry->employee_id !== (int) $employee->id) {
            return $this->errorResponse('Forbidden.', 403);
        }

        $workDate = Carbon::createFromFormat('Y-m-d', $validated['work_date'], 'Asia/Tokyo')->startOfDay();
        if ($workDate->greaterThan(Carbon::today('Asia/Tokyo'))) {
            return $this->errorResponse('Validation error', 422, [
                'work_date' => ['work_date cannot be in the future.'],
            ]);
        }

        $duplicateDate = TimesheetEntry::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $workDate->toDateString())
            ->where('id', '!=', $entry->id)
            ->exists();

        if ($duplicateDate) {
            return $this->errorResponse('Timesheet entry already exists for this work date.', 409);
        }

        $projectIds = array_values(array_unique(array_map(
            static fn (array $detail): int => (int) $detail['project_id'],
            $validated['details']
        )));

        $forbiddenProjectIds = $this->forbiddenProjectIds($employee->id, $projectIds);
        if ($forbiddenProjectIds !== []) {
            return $this->errorResponse('Forbidden.', 403, [
                'project_ids' => ['Some projects are not assigned to current employee.'],
                'forbidden_project_ids' => $forbiddenProjectIds,
            ]);
        }

        $totalHours = $this->sumDetailsHours($validated['details']);
        if ($totalHours > 24) {
            return $this->errorResponse('Validation error', 422, [
                'details' => ['Total hours per work date must not exceed 24.'],
            ]);
        }

        DB::transaction(function () use ($entry, $validated, $workDate, $totalHours): void {
            $entry->forceFill([
                'work_date' => $workDate->toDateString(),
                'total_hours' => $totalHours,
            ])->save();

            $entry->details()->delete();

            foreach ($validated['details'] as $detail) {
                TimesheetEntryDetail::query()->create([
                    'timesheet_entry_id' => $entry->id,
                    'project_id' => (int) $detail['project_id'],
                    'hours_worked' => (float) $detail['hours_worked'],
                    'note' => $detail['note'] ?? null,
                ]);
            }
        });

        return $this->successResponse([
            'entry_id' => $entry->id,
            'updated_detail_count' => count($validated['details']),
        ], 'Timesheet entry updated successfully');
    }

    public function destroyDetail(Request $request, int $detailId): JsonResponse
    {
        /** @var User $employee */
        $employee = $request->user();

        $detail = TimesheetEntryDetail::query()->with('entry')->find($detailId);
        if (! $detail) {
            return $this->errorResponse('Timesheet detail not found.', 404);
        }

        if (! $detail->entry || (int) $detail->entry->employee_id !== (int) $employee->id) {
            return $this->errorResponse('Forbidden.', 403);
        }

        $entry = $detail->entry;

        $result = DB::transaction(function () use ($detail, $entry): array {
            $detail->delete();

            $remainingHours = (float) $entry->details()->sum('hours_worked');
            if ($remainingHours <= 0) {
                $entry->forceFill([
                    'total_hours' => 0,
                ])->save();
                $entry->delete();

                return [
                    'entry_id' => $entry->id,
                    'entry_deleted' => true,
                    'remaining_total_hours' => 0.0,
                ];
            }

            $remainingHours = round($remainingHours, 2);
            $entry->forceFill([
                'total_hours' => $remainingHours,
            ])->save();

            return [
                'entry_id' => $entry->id,
                'entry_deleted' => false,
                'remaining_total_hours' => $remainingHours,
            ];
        });

        return $this->successResponse($result, 'Timesheet detail deleted successfully');
    }

    /**
     * @return list<int>
     */
    private function assignedActiveProjectIds(int $employeeId): array
    {
        return EmployeeProject::query()
            ->where('employee_id', $employeeId)
            ->where('is_active', true)
            ->whereHas('project', function ($query): void {
                $query->where('status', 'active');
            })
            ->pluck('project_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @param list<int> $projectIds
     *
     * @return list<int>
     */
    private function forbiddenProjectIds(int $employeeId, array $projectIds): array
    {
        $allowedProjectIds = $this->assignedActiveProjectIds($employeeId);

        return array_values(array_diff($projectIds, $allowedProjectIds));
    }

    /**
     * @param array<int, array<string, mixed>> $details
     */
    private function sumDetailsHours(array $details): float
    {
        $totalHours = 0.0;
        foreach ($details as $detail) {
            $totalHours += (float) $detail['hours_worked'];
        }

        return round($totalHours, 2);
    }
}
