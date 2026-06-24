<?php

namespace App\Http\Controllers\Api;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AdminReportController extends ApiController
{
    public function timesheets(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'employee_id' => ['nullable', 'integer', 'min:1', 'exists:users,id'],
            'project_id' => ['nullable', 'integer', 'min:1', 'exists:projects,id'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $month = (string) $validated['month'];
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 50);

        $monthStart = CarbonImmutable::createFromFormat('Y-m', $month, 'Asia/Tokyo')->startOfMonth();
        $monthEnd = $monthStart->endOfMonth();

        $baseQuery = DB::table('timesheet_entry_details as ted')
            ->join('timesheet_entries as te', 'te.id', '=', 'ted.timesheet_entry_id')
            ->leftJoin('users as u', 'u.id', '=', 'te.employee_id')
            ->leftJoin('departments as d', 'd.id', '=', 'u.department_id')
            ->leftJoin('projects as p', 'p.id', '=', 'ted.project_id')
            ->whereNull('ted.deleted_at')
            ->whereNull('te.deleted_at')
            ->where('te.status', 'submitted')
            ->whereBetween('te.work_date', [
                $monthStart->toDateString(),
                $monthEnd->toDateString(),
            ]);

        if (isset($validated['employee_id'])) {
            $baseQuery->where('te.employee_id', (int) $validated['employee_id']);
        }

        if (isset($validated['project_id'])) {
            $baseQuery->where('ted.project_id', (int) $validated['project_id']);
        }

        $summaryRaw = (clone $baseQuery)
            ->selectRaw('COALESCE(SUM(ted.hours_worked), 0) as total_hours')
            ->selectRaw('COUNT(DISTINCT te.employee_id) as employee_count')
            ->selectRaw('COUNT(DISTINCT ted.project_id) as project_count')
            ->first();

        $totalHours = round((float) ($summaryRaw->total_hours ?? 0), 2);
        $employeeCount = (int) ($summaryRaw->employee_count ?? 0);
        $projectCount = (int) ($summaryRaw->project_count ?? 0);

        $byProject = (clone $baseQuery)
            ->select([
                'ted.project_id',
                'p.project_code',
                'p.project_name',
                'p.status as project_status',
            ])
            ->selectRaw('COUNT(DISTINCT te.employee_id) as employee_count')
            ->selectRaw('SUM(ted.hours_worked) as total_hours')
            ->groupBy('ted.project_id', 'p.project_code', 'p.project_name', 'p.status')
            ->orderByDesc('total_hours')
            ->get()
            ->map(fn (object $row): array => [
                'project_id' => (int) $row->project_id,
                'project_code' => $row->project_code,
                'project_name' => $row->project_name,
                'project_status' => $row->project_status,
                'employee_count' => (int) $row->employee_count,
                'total_hours' => round((float) $row->total_hours, 2),
            ])
            ->values()
            ->all();

        $employeeProjectGroups = (clone $baseQuery)
            ->select([
                'te.employee_id',
                'u.full_name as employee_name',
                'u.department_id',
                'd.name as department_name',
                'ted.project_id',
                'p.project_name',
            ])
            ->selectRaw('SUM(ted.hours_worked) as total_hours')
            ->groupBy(
                'te.employee_id',
                'u.full_name',
                'u.department_id',
                'd.name',
                'ted.project_id',
                'p.project_name'
            )
            ->orderBy('u.full_name')
            ->orderBy('p.project_name')
            ->get()
            ->map(fn (object $row): array => [
                'employee_id' => (int) $row->employee_id,
                'employee_name' => $row->employee_name,
                'department_id' => $row->department_id !== null ? (int) $row->department_id : null,
                'department_name' => $row->department_name,
                'project_id' => (int) $row->project_id,
                'project_name' => $row->project_name,
                'total_hours' => round((float) $row->total_hours, 2),
            ])
            ->values();

        $employeeProjectPage = $this->paginateCollection($employeeProjectGroups, $page, $perPage);

        $chartSource = collect($byProject)->take(10);
        $chart = [
            'type' => 'bar_top_projects',
            'labels' => $chartSource->map(fn (array $row): ?string => $row['project_name'])->values()->all(),
            'values' => $chartSource->map(fn (array $row): float => (float) $row['total_hours'])->values()->all(),
        ];

        return $this->successResponse([
            'meta' => [
                'month' => $month,
                'filters' => [
                    'employee_id' => $validated['employee_id'] ?? null,
                    'project_id' => $validated['project_id'] ?? null,
                ],
                'timezone' => 'Asia/Tokyo',
            ],
            'summary' => [
                'total_hours' => $totalHours,
                'employee_count' => $employeeCount,
                'project_count' => $projectCount,
            ],
            'by_project' => $byProject,
            'by_employee_project' => $employeeProjectPage['items'],
            'chart' => $chart,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $employeeProjectPage['total'],
            ],
        ]);
    }

    /**
     * @param Collection<int, array<string, mixed>> $items
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    private function paginateCollection(Collection $items, int $page, int $perPage): array
    {
        $total = $items->count();
        $offset = max(0, ($page - 1) * $perPage);

        return [
            'items' => $items->slice($offset, $perPage)->values()->all(),
            'total' => $total,
        ];
    }
}
