<?php

namespace App\Http\Controllers\Api;

use App\Models\EmployeeProject;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AdminEmployeeProjectController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $employee = User::query()->find($validated['employee_id']);
        if (! $employee || $employee->role !== 'employee') {
            return $this->errorResponse('Employee not found.', 404);
        }

        $assignedProjects = EmployeeProject::query()
            ->with('project:id,project_code,project_name,status')
            ->where('employee_id', $employee->id)
            ->where('is_active', true)
            ->whereHas('project', function ($query): void {
                $query->where('status', '!=', 'archived');
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
            ->values();

        return $this->successResponse([
            'employee_id' => $employee->id,
            'assigned_projects' => $assignedProjects,
        ]);
    }

    public function assign(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:users,id'],
            'project_ids' => ['required', 'array', 'min:1'],
            'project_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        $employee = User::query()->find($validated['employee_id']);
        if (! $employee || $employee->role !== 'employee') {
            return $this->errorResponse('Employee not found.', 404);
        }

        $requestedProjectIds = array_values(array_unique($validated['project_ids']));

        $projects = Project::query()
            ->whereIn('id', $requestedProjectIds)
            ->get(['id', 'status'])
            ->keyBy('id');

        $missingProjectIds = array_values(array_diff($requestedProjectIds, $projects->keys()->all()));
        if ($missingProjectIds !== []) {
            return $this->errorResponse('Project not found.', 404, [
                'project_ids' => ['Some projects do not exist.'],
                'missing_project_ids' => $missingProjectIds,
            ]);
        }

        $notActiveProjectIds = $projects
            ->filter(static fn (Project $project): bool => $project->status !== 'active')
            ->keys()
            ->values()
            ->all();

        if ($notActiveProjectIds !== []) {
            return $this->errorResponse('Validation error', 422, [
                'project_ids' => ['Only active projects can be assigned.'],
                'inactive_project_ids' => $notActiveProjectIds,
            ]);
        }

        $assignedCount = 0;
        $skippedProjectIds = [];

        foreach ($requestedProjectIds as $projectId) {
            $activeMapping = EmployeeProject::query()
                ->where('employee_id', $employee->id)
                ->where('project_id', $projectId)
                ->where('is_active', true)
                ->first();

            if ($activeMapping) {
                $skippedProjectIds[] = $projectId;

                continue;
            }

            $inactiveMapping = EmployeeProject::query()
                ->where('employee_id', $employee->id)
                ->where('project_id', $projectId)
                ->where('is_active', false)
                ->orderByDesc('id')
                ->first();

            if ($inactiveMapping) {
                $inactiveMapping->forceFill([
                    'is_active' => true,
                    'assigned_at' => Carbon::now(),
                    'unassigned_at' => null,
                ])->save();

                $assignedCount++;

                continue;
            }

            EmployeeProject::query()->create([
                'employee_id' => $employee->id,
                'project_id' => $projectId,
                'assigned_at' => Carbon::now(),
                'is_active' => true,
            ]);

            $assignedCount++;
        }

        return $this->successResponse([
            'assigned_count' => $assignedCount,
            'skipped_count' => count($skippedProjectIds),
            'skipped_project_ids' => $skippedProjectIds,
        ], 'Assignment updated');
    }

    public function unassign(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:users,id'],
            'project_ids' => ['required', 'array', 'min:1'],
            'project_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        $employee = User::query()->find($validated['employee_id']);
        if (! $employee || $employee->role !== 'employee') {
            return $this->errorResponse('Employee not found.', 404);
        }

        $requestedProjectIds = array_values(array_unique($validated['project_ids']));

        $activeMappings = EmployeeProject::query()
            ->where('employee_id', $employee->id)
            ->where('is_active', true)
            ->whereIn('project_id', $requestedProjectIds)
            ->get();

        $mappedProjectIds = $activeMappings->pluck('project_id')->all();
        $missingMappingProjectIds = array_values(array_diff($requestedProjectIds, $mappedProjectIds));

        if ($missingMappingProjectIds !== []) {
            return $this->errorResponse('Assignment not found.', 404, [
                'project_ids' => ['Some project assignments do not exist.'],
                'missing_project_ids' => $missingMappingProjectIds,
            ]);
        }

        $now = Carbon::now();
        EmployeeProject::query()
            ->whereIn('id', $activeMappings->pluck('id')->all())
            ->update([
                'is_active' => false,
                'unassigned_at' => $now,
                'updated_at' => $now,
            ]);

        return $this->successResponse([
            'unassigned_count' => count($requestedProjectIds),
        ], 'Assignment updated');
    }
}
