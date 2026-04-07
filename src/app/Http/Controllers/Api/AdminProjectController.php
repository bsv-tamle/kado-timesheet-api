<?php

namespace App\Http\Controllers\Api;

use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminProjectController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->normalizeBooleanQuery($request, 'billable_flag');

        $validated = $request->validate([
            'keyword' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'archived'])],
            'billable_flag' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Project::query();

        if (isset($validated['keyword'])) {
            $keyword = trim((string) $validated['keyword']);
            $query->where(function ($q) use ($keyword): void {
                $q->where('project_code', 'like', "%{$keyword}%")
                    ->orWhere('project_name', 'like', "%{$keyword}%");
            });
        }

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (array_key_exists('billable_flag', $validated)) {
            $query->where('billable_flag', (bool) $validated['billable_flag']);
        }

        $projects = $query
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 20));

        $projectItems = collect($projects->items())
            ->map(fn (Project $project): array => $this->serializeProjectSummary($project))
            ->values()
            ->all();

        return $this->successResponse([
            'current_page' => $projects->currentPage(),
            'data' => $projectItems,
            'from' => $projects->firstItem(),
            'last_page' => $projects->lastPage(),
            'per_page' => $projects->perPage(),
            'to' => $projects->lastItem(),
            'total' => $projects->total(),
        ]);
    }

    private function normalizeBooleanQuery(Request $request, string $field): void
    {
        $rawValue = $request->query($field);
        if ($rawValue === null || $rawValue === '') {
            return;
        }

        if (is_bool($rawValue)) {
            $request->merge([$field => $rawValue]);

            return;
        }

        $normalized = strtolower(trim((string) $rawValue));
        if (in_array($normalized, ['true', '1'], true)) {
            $request->merge([$field => true]);

            return;
        }

        if (in_array($normalized, ['false', '0'], true)) {
            $request->merge([$field => false]);
        }
    }

    public function show(int $id): JsonResponse
    {
        $project = Project::query()->find($id);
        if (! $project) {
            return $this->errorResponse('Project not found.', 404);
        }

        return $this->successResponse($this->serializeProjectDetail($project));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_code' => ['required', 'string', 'max:50', 'unique:projects,project_code'],
            'project_name' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(['active', 'inactive', 'archived'])],
            'billable_flag' => ['required', 'boolean'],
            'description' => ['nullable', 'string'],
        ]);

        $project = Project::query()->create($validated);

        return $this->successResponse(
            $this->serializeProjectDetail($project),
            'Project created successfully',
            201
        );
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $project = Project::query()->find($id);
        if (! $project) {
            return $this->errorResponse('Project not found.', 404);
        }

        $validated = $request->validate([
            'project_code' => ['sometimes', 'string', 'max:50', Rule::unique('projects', 'project_code')->ignore($project->id)],
            'project_name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'archived'])],
            'billable_flag' => ['sometimes', 'boolean'],
            'description' => ['nullable', 'string'],
        ]);

        if ($validated === []) {
            return $this->errorResponse('Validation error', 422, [
                'request' => ['At least one field is required for update.'],
            ]);
        }

        $project->fill($validated);
        $project->save();

        return $this->successResponse($this->serializeProjectDetail($project), 'Project updated successfully');
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $project = Project::query()->find($id);
        if (! $project) {
            return $this->errorResponse('Project not found.', 404);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(['active', 'inactive', 'archived'])],
        ]);

        $project->forceFill(['status' => $validated['status']])->save();

        return $this->successResponse([
            'id' => $project->id,
            'status' => $project->status,
        ], 'Project status updated successfully');
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeProjectSummary(Project $project): array
    {
        return [
            'id' => $project->id,
            'project_code' => $project->project_code,
            'project_name' => $project->project_name,
            'status' => $project->status,
            'billable_flag' => (bool) $project->billable_flag,
            'description' => $project->description,
            'updated_at' => $project->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeProjectDetail(Project $project): array
    {
        return [
            'id' => $project->id,
            'project_code' => $project->project_code,
            'project_name' => $project->project_name,
            'status' => $project->status,
            'billable_flag' => (bool) $project->billable_flag,
            'description' => $project->description,
            'updated_at' => $project->updated_at?->toISOString(),
        ];
    }
}
