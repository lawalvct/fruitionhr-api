<?php

namespace App\Modules\Reports\Services;

use App\Models\User;
use App\Modules\Attendance\Models\AttendanceSummary;
use App\Modules\Employee\Models\Employee;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Performance\Models\AppraisalResult;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\Vacancy;
use App\Support\Authorization\Permissions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ReportOverviewService
{
    public function build(User $user, int $year): array
    {
        $access = [
            'workforce' => $user->can(Permissions::EMPLOYEES_VIEW),
            'attendance' => $user->can(Permissions::ATTENDANCE_VIEW),
            'leave' => $user->can(Permissions::LEAVE_VIEW),
            'payroll' => $user->can(Permissions::PAYROLL_VIEW),
            'performance' => $user->can(Permissions::PERFORMANCE_VIEW),
            'recruitment' => $user->can(Permissions::RECRUITMENT_VIEW),
        ];

        return [
            'year' => $year,
            'generated_at' => now()->toISOString(),
            'access' => $access,
            'workforce' => $access['workforce'] ? $this->workforce($year) : null,
            'attendance' => $access['attendance'] ? $this->attendance($year) : null,
            'leave' => $access['leave'] ? $this->leave($year) : null,
            'payroll' => $access['payroll'] ? $this->payroll($year) : null,
            'performance' => $access['performance'] ? $this->performance($year) : null,
            'recruitment' => $access['recruitment'] ? $this->recruitment($year) : null,
        ];
    }

    private function workforce(int $year): array
    {
        $employees = Employee::query()
            ->with('currentAssignment.department:id,name')
            ->get(['id', 'gender', 'employment_status', 'hired_at', 'exited_at']);

        $current = $employees->where('employment_status', '!=', Employee::STATUS_EXITED);
        $statuses = $employees->countBy('employment_status');
        $newHires = $employees->filter(fn (Employee $employee) => $employee->hired_at?->year === $year);
        $exits = $employees->filter(fn (Employee $employee) => $employee->exited_at?->year === $year);

        return [
            'total' => $current->count(),
            'active' => (int) ($statuses[Employee::STATUS_ACTIVE] ?? 0),
            'on_leave' => (int) ($statuses[Employee::STATUS_ON_LEAVE] ?? 0),
            'suspended' => (int) ($statuses[Employee::STATUS_SUSPENDED] ?? 0),
            'new_hires' => $newHires->count(),
            'exits' => $exits->count(),
            'by_department' => $this->labelCounts(
                $current->map(fn (Employee $employee) => $employee->currentAssignment?->department?->name ?? 'Unassigned'),
            ),
            'by_gender' => $this->labelCounts(
                $current->map(fn (Employee $employee) => $employee->gender ? ucfirst($employee->gender) : 'Not specified'),
            ),
            'movement_by_month' => $this->months($year)->map(function (array $month) use ($newHires, $exits): array {
                return [
                    ...$month,
                    'hires' => $newHires->filter(fn (Employee $employee) => $employee->hired_at?->month === $month['month'])->count(),
                    'exits' => $exits->filter(fn (Employee $employee) => $employee->exited_at?->month === $month['month'])->count(),
                ];
            })->all(),
        ];
    }

    private function attendance(int $year): array
    {
        $rows = AttendanceSummary::query()
            ->where('period', 'like', $year.'-%')
            ->where('status', AttendanceSummary::STATUS_FINALIZED)
            ->selectRaw('period, COUNT(*) as employee_count, SUM(working_days) as working_days, SUM(days_present) as days_present, SUM(days_late) as days_late, SUM(days_absent) as days_absent, SUM(days_on_leave) as days_on_leave, SUM(late_minutes) as late_minutes, SUM(overtime_minutes) as overtime_minutes')
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->keyBy('period');

        $periods = $this->months($year)->map(function (array $month) use ($rows): array {
            $row = $rows->get($month['period']);

            return [
                ...$month,
                'finalized' => $row !== null,
                'employee_count' => (int) ($row?->employee_count ?? 0),
                'working_days' => (int) ($row?->working_days ?? 0),
                'present' => (int) ($row?->days_present ?? 0),
                'late' => (int) ($row?->days_late ?? 0),
                'absent' => (int) ($row?->days_absent ?? 0),
                'on_leave' => (int) ($row?->days_on_leave ?? 0),
                'late_minutes' => (int) ($row?->late_minutes ?? 0),
                'overtime_minutes' => (int) ($row?->overtime_minutes ?? 0),
            ];
        });

        $present = $periods->sum('present');
        $absent = $periods->sum('absent');

        return [
            'finalized_periods' => $periods->where('finalized', true)->count(),
            'present_days' => $present,
            'absent_days' => $absent,
            'late_days' => $periods->sum('late'),
            'leave_days' => $periods->sum('on_leave'),
            'overtime_minutes' => $periods->sum('overtime_minutes'),
            'attendance_rate' => ($present + $absent) > 0
                ? round(($present / ($present + $absent)) * 100, 1)
                : null,
            'by_period' => $periods->all(),
        ];
    }

    private function leave(int $year): array
    {
        $requests = LeaveRequest::query()
            ->whereYear('start_date', $year)
            ->with('leaveType:id,name')
            ->get();
        $approved = $requests->where('status', LeaveRequest::STATUS_APPROVED);
        $statuses = $requests->countBy('status');

        return [
            'requests' => $requests->count(),
            'requested_days' => $requests->sum('days'),
            'approved_days' => $approved->sum('days'),
            'pending' => (int) ($statuses[LeaveRequest::STATUS_PENDING] ?? 0),
            'by_status' => $this->labelCounts($requests->map(fn (LeaveRequest $request) => ucfirst($request->status))),
            'by_type' => $approved
                ->groupBy(fn (LeaveRequest $request) => $request->leaveType?->name ?? 'Unspecified')
                ->map(fn (Collection $group, string $label) => [
                    'label' => $label,
                    'requests' => $group->count(),
                    'days' => $group->sum('days'),
                ])
                ->sortByDesc('days')
                ->values()
                ->all(),
            'by_month' => $this->months($year)->map(fn (array $month) => [
                ...$month,
                'days' => $approved
                    ->filter(fn (LeaveRequest $request) => $request->start_date->month === $month['month'])
                    ->sum('days'),
            ])->all(),
        ];
    }

    private function payroll(int $year): array
    {
        $completedStatuses = [
            PayrollRun::STATUS_APPROVED,
            PayrollRun::STATUS_LOCKED,
            PayrollRun::STATUS_PAID,
        ];
        $runs = PayrollRun::query()
            ->where('period', 'like', $year.'-%')
            ->where('is_reversal', false)
            ->whereIn('status', $completedStatuses)
            ->orderBy('period')
            ->get();

        return [
            'completed_runs' => $runs->count(),
            'employee_payments' => $runs->sum('employee_count'),
            'total_gross' => $runs->sum('total_gross'),
            'total_net' => $runs->sum('total_net'),
            'total_statutory' => $runs->sum('total_statutory'),
            'total_deductions' => $runs->sum('total_deductions'),
            'total_employer_cost' => $runs->sum('total_employer_cost'),
            'by_period' => $this->months($year)->map(function (array $month) use ($runs): array {
                $run = $runs->firstWhere('period', $month['period']);

                return [
                    ...$month,
                    'status' => $run?->status,
                    'employee_count' => (int) ($run?->employee_count ?? 0),
                    'gross' => (int) ($run?->total_gross ?? 0),
                    'net' => (int) ($run?->total_net ?? 0),
                    'employer_cost' => (int) ($run?->total_employer_cost ?? 0),
                ];
            })->all(),
        ];
    }

    private function performance(int $year): array
    {
        $results = AppraisalResult::query()
            ->whereYear('finalised_at', $year)
            ->with('assignment.template')
            ->get();
        $average = $results->avg('final_score_basis_points');

        return [
            'results' => $results->count(),
            'average_score_basis_points' => $average === null ? null : (int) round($average),
            'below_passing' => $results->filter(function (AppraisalResult $result): bool {
                $minimum = $result->assignment?->template?->min_passing_basis_points;

                return $minimum !== null && $result->final_score_basis_points < $minimum;
            })->count(),
            'by_grade' => $this->labelCounts($results->map(fn (AppraisalResult $result) => $result->grade ?: 'Unrated')),
            'by_month' => $this->months($year)->map(function (array $month) use ($results): array {
                $monthly = $results->filter(fn (AppraisalResult $result) => $result->finalised_at?->month === $month['month']);
                $average = $monthly->avg('final_score_basis_points');

                return [
                    ...$month,
                    'results' => $monthly->count(),
                    'average_score_basis_points' => $average === null ? null : (int) round($average),
                ];
            })->all(),
        ];
    }

    private function recruitment(int $year): array
    {
        $applications = Application::query()->whereYear('applied_at', $year)->get();
        $hired = $applications->where('stage', 'hired')->count();

        return [
            'applications' => $applications->count(),
            'hired' => $hired,
            'open_vacancies' => Vacancy::query()->where('status', Vacancy::STATUS_OPEN)->count(),
            'open_positions' => Vacancy::query()->where('status', Vacancy::STATUS_OPEN)->sum('positions_available'),
            'hire_rate' => $applications->count() > 0 ? round(($hired / $applications->count()) * 100, 1) : null,
            'by_stage' => collect(Application::STAGES)
                ->map(fn (string $stage) => [
                    'label' => (string) str($stage)->replace('_', ' ')->headline(),
                    'value' => $applications->where('stage', $stage)->count(),
                ])
                ->filter(fn (array $item) => $item['value'] > 0)
                ->values()
                ->all(),
            'by_source' => $this->labelCounts(
                $applications->map(fn (Application $application) => $application->source
                    ? (string) str($application->source)->replace('_', ' ')->headline()
                    : 'Not specified'),
            ),
            'by_month' => $this->months($year)->map(fn (array $month) => [
                ...$month,
                'applications' => $applications
                    ->filter(fn (Application $application) => $application->applied_at?->month === $month['month'])
                    ->count(),
                'hired' => $applications
                    ->filter(fn (Application $application) => $application->hired_at?->month === $month['month'])
                    ->count(),
            ])->all(),
        ];
    }

    /** @param Collection<int, string> $labels */
    private function labelCounts(Collection $labels): array
    {
        return $labels
            ->countBy()
            ->map(fn (int $value, string $label) => ['label' => $label, 'value' => $value])
            ->sortByDesc('value')
            ->values()
            ->all();
    }

    /** @return Collection<int, array{month: int, period: string, label: string}> */
    private function months(int $year): Collection
    {
        return collect(range(1, 12))->map(fn (int $month) => [
            'month' => $month,
            'period' => sprintf('%d-%02d', $year, $month),
            'label' => Carbon::create($year, $month, 1)->format('M'),
        ]);
    }
}
