<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmployeeReportController extends ApiController
{
    public function timesheets(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'project_id' => ['nullable', 'integer', 'min:1', 'exists:projects,id'],
        ]);

        /** @var User $employee */
        $employee = $request->user();

        $month = (string) $validated['month'];
        $monthStart = CarbonImmutable::createFromFormat('Y-m', $month, 'Asia/Tokyo')->startOfMonth();
        $monthEnd = $monthStart->endOfMonth();

        $baseQuery = DB::table('timesheet_entry_details as ted')
            ->join('timesheet_entries as te', 'te.id', '=', 'ted.timesheet_entry_id')
            ->leftJoin('projects as p', 'p.id', '=', 'ted.project_id')
            ->whereNull('ted.deleted_at')
            ->whereNull('te.deleted_at')
            ->where('te.status', 'submitted')
            ->where('te.employee_id', $employee->id)
            ->whereBetween('te.work_date', [
                $monthStart->toDateString(),
                $monthEnd->toDateString(),
            ]);

        if (isset($validated['project_id'])) {
            $baseQuery->where('ted.project_id', (int) $validated['project_id']);
        }

        $totalHours = round((float) ((clone $baseQuery)
            ->selectRaw('COALESCE(SUM(ted.hours_worked), 0) as total_hours')
            ->value('total_hours') ?? 0), 2);

        $projectCount = (int) ((clone $baseQuery)
            ->selectRaw('COUNT(DISTINCT ted.project_id) as project_count')
            ->value('project_count') ?? 0);

        $byProjectRaw = (clone $baseQuery)
            ->select([
                'ted.project_id',
                'p.project_code',
                'p.project_name',
                'p.status as project_status',
            ])
            ->selectRaw('SUM(ted.hours_worked) as total_hours')
            ->groupBy('ted.project_id', 'p.project_code', 'p.project_name', 'p.status')
            ->orderByDesc('total_hours')
            ->get();

        $byProject = $byProjectRaw
            ->map(function (object $row) use ($totalHours): array {
                $projectHours = round((float) $row->total_hours, 2);
                $percentage = $totalHours > 0
                    ? round(($projectHours / $totalHours) * 100, 1)
                    : 0.0;

                return [
                    'project_id' => (int) $row->project_id,
                    'project_code' => $row->project_code,
                    'project_name' => $row->project_name,
                    'project_status' => $row->project_status,
                    'total_hours' => $projectHours,
                    'percentage' => $percentage,
                ];
            })
            ->values()
            ->all();

        $byWorkDateRaw = (clone $baseQuery)
            ->select('te.work_date')
            ->selectRaw('SUM(ted.hours_worked) as total_hours')
            ->groupBy('te.work_date')
            ->orderBy('te.work_date')
            ->get();

        $overtimeHours = 0.0;
        $byWorkDate = $byWorkDateRaw
            ->map(function (object $row) use (&$overtimeHours): array {
                $dailyHours = round((float) $row->total_hours, 2);
                $dailyOvertime = round(max(0, $dailyHours - 8), 2);
                $overtimeHours += $dailyOvertime;

                return [
                    'work_date' => (string) $row->work_date,
                    'total_hours' => $dailyHours,
                    'overtime_hours' => $dailyOvertime,
                ];
            })
            ->values()
            ->all();

        $overtimeHours = round($overtimeHours, 2);

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
                    'project_id' => isset($validated['project_id']) ? (int) $validated['project_id'] : null,
                ],
                'timezone' => 'Asia/Tokyo',
            ],
            'summary' => [
                'total_hours' => $totalHours,
                'project_count' => $projectCount,
                'overtime_hours' => $overtimeHours,
            ],
            'by_project' => $byProject,
            'by_work_date' => $byWorkDate,
            'chart' => $chart,
        ]);
    }
}
