<?php

namespace App\Modules\Performance\Controllers;

use App\Modules\Employee\Models\Employee;
use App\Modules\Performance\Models\AppraisalResult;
use App\Modules\Performance\Models\AppraisalScore;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Score distribution, KPI heat-map source data, and per-employee trend (spec §10 Reports). */
class PerformanceReportController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::PERFORMANCE_VIEW), 403);

        $results = AppraisalResult::query()
            ->with('assignment.template')
            ->when($request->filled('cycle_id'), fn ($query) => $query->whereHas(
                'assignment', fn ($inner) => $inner->where('appraisal_cycle_id', $request->integer('cycle_id')),
            ))
            ->get();

        $distribution = $results->groupBy('grade')->map(fn ($group) => $group->count())->sortDesc();

        // Average score per KPI across the population — the heat-map source.
        $kpiAverages = AppraisalScore::query()
            ->when($request->filled('cycle_id'), fn ($query) => $query->whereHas(
                'review.reviewer.assignment',
                fn ($inner) => $inner->where('appraisal_cycle_id', $request->integer('cycle_id')),
            ))
            ->with('templateItem.kpi')
            ->get()
            ->filter(fn ($score) => $score->templateItem?->kpi !== null)
            ->groupBy(fn ($score) => $score->templateItem->kpi->name)
            ->map(fn ($group) => (int) round($group->avg('score_basis_points')))
            ->sortDesc();

        return response()->json(['data' => [
            'results_count' => $results->count(),
            'average_score_basis_points' => $results->count() > 0 ? (int) round($results->avg('final_score_basis_points')) : null,
            'below_passing_count' => $results->filter(function ($result) {
                $min = $result->assignment?->template?->min_passing_basis_points;

                return $min !== null && $result->final_score_basis_points < $min;
            })->count(),
            'distribution' => $distribution,
            'kpi_averages' => $kpiAverages,
        ]]);
    }

    public function employeeTrend(Request $request, Employee $employee): JsonResponse
    {
        abort_unless(
            $request->user()->can(Permissions::PERFORMANCE_VIEW) || $employee->user_id === $request->user()->id,
            403,
        );

        $trend = AppraisalResult::query()
            ->whereHas('assignment', fn ($query) => $query->where('employee_id', $employee->id))
            ->with('assignment.cycle')
            ->get()
            ->sortBy(fn ($result) => $result->assignment->cycle->starts_at)
            ->values()
            ->map(fn ($result) => [
                'cycle' => ['id' => $result->assignment->cycle->id, 'name' => $result->assignment->cycle->name, 'starts_at' => $result->assignment->cycle->starts_at->toDateString()],
                'final_score_basis_points' => $result->final_score_basis_points,
                'grade' => $result->grade,
                'status' => $result->status,
            ]);

        return response()->json(['data' => $trend]);
    }
}
