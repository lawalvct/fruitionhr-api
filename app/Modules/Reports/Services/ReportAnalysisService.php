<?php

namespace App\Modules\Reports\Services;

use App\Modules\Attendance\Models\AttendanceSummary;
use App\Modules\Company\Models\Department;
use App\Modules\Employee\Models\Employee;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Performance\Models\AppraisalResult;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\Vacancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ReportAnalysisService
{
    public const MODULES = [
        'workforce',
        'attendance',
        'leave',
        'payroll',
        'performance',
        'recruitment',
    ];

    private const TABLE_LIMIT = 100;

    private ?int $tableLimit = self::TABLE_LIMIT;

    public function build(
        string $module,
        int $year,
        array $filters = [],
        ?int $tableLimit = self::TABLE_LIMIT,
    ): array {
        abort_unless(in_array($module, self::MODULES, true), 404);
        $this->tableLimit = $tableLimit;

        $analysis = match ($module) {
            'workforce' => $this->workforce($year, $filters),
            'attendance' => $this->attendance($year, $filters),
            'leave' => $this->leave($year, $filters),
            'payroll' => $this->payroll($year, $filters),
            'performance' => $this->performance($year, $filters),
            'recruitment' => $this->recruitment($year, $filters),
        };

        return [
            'module' => $module,
            'title' => $this->title($module),
            'year' => $year,
            'generated_at' => now()->toISOString(),
            'filters' => [
                'available' => $this->availableFilters($module, $year),
                'applied' => $this->appliedFilters($module, $filters),
            ],
            ...$analysis,
        ];
    }

    private function workforce(int $year, array $filters): array
    {
        $employees = Employee::query()
            ->with(['currentAssignment.department', 'currentAssignment.position'])
            ->when($filters['department_id'] ?? null, fn (Builder $query, int $departmentId) => $query
                ->whereHas('currentAssignment', fn (Builder $assignment) => $assignment->where('department_id', $departmentId)))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query
                ->where('employment_status', $status))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        $newHires = $employees->filter(fn (Employee $employee) => $employee->hired_at?->year === $year);
        $exits = $employees->filter(fn (Employee $employee) => $employee->exited_at?->year === $year);
        $statusCounts = $employees->countBy('employment_status');

        return [
            'metrics' => [
                $this->metric('employees', 'Employees', $employees->count()),
                $this->metric('active', 'Active', (int) ($statusCounts[Employee::STATUS_ACTIVE] ?? 0)),
                $this->metric('new_hires', 'New hires', $newHires->count()),
                $this->metric('exits', 'Exits', $exits->count()),
            ],
            'datasets' => [
                [
                    'key' => 'movement',
                    'title' => 'Workforce movement',
                    'type' => 'line',
                    'x_key' => 'label',
                    'series' => [
                        $this->series('hires', 'Hires'),
                        $this->series('exits', 'Exits'),
                    ],
                    'data' => $this->months($year)->map(fn (array $month) => [
                        ...$month,
                        'hires' => $newHires->filter(fn (Employee $employee) => $employee->hired_at?->month === $month['month'])->count(),
                        'exits' => $exits->filter(fn (Employee $employee) => $employee->exited_at?->month === $month['month'])->count(),
                    ])->all(),
                ],
                $this->breakdownDataset(
                    'departments',
                    'Employees by department',
                    $this->countBreakdown($employees, fn (Employee $employee) => $this->employeeDepartment($employee)),
                ),
                $this->breakdownDataset(
                    'statuses',
                    'Employees by status',
                    $this->countBreakdown($employees, fn (Employee $employee) => $this->label($employee->employment_status)),
                ),
                $this->breakdownDataset(
                    'gender',
                    'Employees by gender',
                    $this->countBreakdown($employees, fn (Employee $employee) => $employee->gender ? $this->label($employee->gender) : 'Not specified'),
                ),
            ],
            'table' => $this->table(
                'Employee records',
                [
                    $this->column('employee_number', 'Employee no.'),
                    $this->column('employee', 'Employee'),
                    $this->column('department', 'Department'),
                    $this->column('position', 'Position'),
                    $this->column('status', 'Status', 'status'),
                    $this->column('hired_at', 'Hired', 'date'),
                    $this->column('exited_at', 'Exited', 'date'),
                ],
                $employees->map(fn (Employee $employee) => [
                    'id' => $employee->id,
                    'employee_number' => $employee->employee_number,
                    'employee' => $employee->full_name,
                    'department' => $this->employeeDepartment($employee),
                    'position' => $employee->currentAssignment?->position?->title ?? 'Unassigned',
                    'status' => $employee->employment_status,
                    'hired_at' => $employee->hired_at?->toDateString(),
                    'exited_at' => $employee->exited_at?->toDateString(),
                ]),
            ),
        ];
    }

    private function attendance(int $year, array $filters): array
    {
        $rows = AttendanceSummary::query()
            ->with(['employee.currentAssignment.department'])
            ->where('period', 'like', $year.'-%')
            ->when($filters['period'] ?? null, fn (Builder $query, string $period) => $query->where('period', $period))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['department_id'] ?? null, fn (Builder $query, int $departmentId) => $query
                ->whereHas('employee.currentAssignment', fn (Builder $assignment) => $assignment->where('department_id', $departmentId)))
            ->orderByDesc('period')
            ->orderBy('employee_id')
            ->get();

        $present = (int) $rows->sum('days_present');
        $absent = (int) $rows->sum('days_absent');
        $rate = ($present + $absent) > 0 ? round(($present / ($present + $absent)) * 100, 1) : null;
        $byDepartment = $rows
            ->groupBy(fn (AttendanceSummary $summary) => $this->employeeDepartment($summary->employee))
            ->map(fn (Collection $group, mixed $label) => [
                'label' => (string) $label,
                'present' => (int) $group->sum('days_present'),
                'absent' => (int) $group->sum('days_absent'),
                'late' => (int) $group->sum('days_late'),
            ])
            ->values()
            ->all();

        return [
            'metrics' => [
                $this->metric('attendance_rate', 'Attendance rate', $rate, 'percent'),
                $this->metric('present_days', 'Present days', $present),
                $this->metric('absent_days', 'Absent days', $absent),
                $this->metric('late_minutes', 'Late minutes', (int) $rows->sum('late_minutes'), 'minutes'),
            ],
            'datasets' => [
                [
                    'key' => 'attendance_trend',
                    'title' => 'Attendance trend',
                    'type' => 'line',
                    'x_key' => 'label',
                    'series' => [
                        $this->series('present', 'Present'),
                        $this->series('absent', 'Absent'),
                        $this->series('late', 'Late'),
                        $this->series('on_leave', 'On leave'),
                    ],
                    'data' => $this->months($year)->map(function (array $month) use ($rows): array {
                        $monthly = $rows->where('period', $month['period']);

                        return [
                            ...$month,
                            'present' => (int) $monthly->sum('days_present'),
                            'absent' => (int) $monthly->sum('days_absent'),
                            'late' => (int) $monthly->sum('days_late'),
                            'on_leave' => (int) $monthly->sum('days_on_leave'),
                        ];
                    })->all(),
                ],
                [
                    'key' => 'attendance_by_department',
                    'title' => 'Attendance by department',
                    'type' => 'bar',
                    'x_key' => 'label',
                    'series' => [
                        $this->series('present', 'Present'),
                        $this->series('absent', 'Absent'),
                        $this->series('late', 'Late'),
                    ],
                    'data' => $byDepartment,
                ],
            ],
            'table' => $this->table(
                'Attendance summaries',
                [
                    $this->column('period', 'Period'),
                    $this->column('employee', 'Employee'),
                    $this->column('department', 'Department'),
                    $this->column('present', 'Present', 'number'),
                    $this->column('absent', 'Absent', 'number'),
                    $this->column('late', 'Late', 'number'),
                    $this->column('late_minutes', 'Late time', 'minutes'),
                    $this->column('overtime_minutes', 'Overtime', 'minutes'),
                    $this->column('status', 'Status', 'status'),
                ],
                $rows->map(fn (AttendanceSummary $summary) => [
                    'id' => $summary->id,
                    'period' => $summary->period,
                    'employee' => $summary->employee?->full_name,
                    'department' => $this->employeeDepartment($summary->employee),
                    'present' => (int) $summary->days_present,
                    'absent' => (int) $summary->days_absent,
                    'late' => (int) $summary->days_late,
                    'late_minutes' => (int) $summary->late_minutes,
                    'overtime_minutes' => (int) $summary->overtime_minutes,
                    'status' => $summary->status,
                ]),
            ),
        ];
    }

    private function leave(int $year, array $filters): array
    {
        $requests = LeaveRequest::query()
            ->with(['employee.currentAssignment.department', 'leaveType'])
            ->whereYear('start_date', $year)
            ->when($filters['period'] ?? null, fn (Builder $query, string $period) => $query
                ->whereMonth('start_date', (int) Str::after($period, '-')))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['department_id'] ?? null, fn (Builder $query, int $departmentId) => $query
                ->whereHas('employee.currentAssignment', fn (Builder $assignment) => $assignment->where('department_id', $departmentId)))
            ->orderByDesc('start_date')
            ->get();

        $approved = $requests->where('status', LeaveRequest::STATUS_APPROVED);
        $approvalRate = $requests->count() > 0 ? round(($approved->count() / $requests->count()) * 100, 1) : null;

        return [
            'metrics' => [
                $this->metric('requests', 'Requests', $requests->count()),
                $this->metric('requested_days', 'Requested days', (int) $requests->sum('days')),
                $this->metric('approved_days', 'Approved days', (int) $approved->sum('days')),
                $this->metric('approval_rate', 'Approval rate', $approvalRate, 'percent'),
            ],
            'datasets' => [
                [
                    'key' => 'leave_trend',
                    'title' => 'Leave trend',
                    'type' => 'line',
                    'x_key' => 'label',
                    'series' => [
                        $this->series('requests', 'Requests'),
                        $this->series('approved_days', 'Approved days'),
                    ],
                    'data' => $this->months($year)->map(function (array $month) use ($requests): array {
                        $monthly = $requests->filter(fn (LeaveRequest $request) => $request->start_date->month === $month['month']);

                        return [
                            ...$month,
                            'requests' => $monthly->count(),
                            'approved_days' => (int) $monthly
                                ->where('status', LeaveRequest::STATUS_APPROVED)
                                ->sum('days'),
                        ];
                    })->all(),
                ],
                $this->breakdownDataset(
                    'leave_statuses',
                    'Requests by status',
                    $this->countBreakdown($requests, fn (LeaveRequest $request) => $this->label($request->status)),
                ),
                [
                    'key' => 'leave_types',
                    'title' => 'Approved days by leave type',
                    'type' => 'bar',
                    'x_key' => 'label',
                    'series' => [$this->series('value', 'Days')],
                    'data' => $approved
                        ->groupBy(fn (LeaveRequest $request) => $request->leaveType?->name ?? 'Unspecified')
                        ->map(fn (Collection $group, mixed $label) => [
                            'label' => (string) $label,
                            'value' => (int) $group->sum('days'),
                        ])
                        ->sortByDesc('value')
                        ->values()
                        ->all(),
                ],
            ],
            'table' => $this->table(
                'Leave requests',
                [
                    $this->column('employee', 'Employee'),
                    $this->column('department', 'Department'),
                    $this->column('leave_type', 'Leave type'),
                    $this->column('start_date', 'Starts', 'date'),
                    $this->column('end_date', 'Ends', 'date'),
                    $this->column('days', 'Days', 'number'),
                    $this->column('status', 'Status', 'status'),
                ],
                $requests->map(fn (LeaveRequest $request) => [
                    'id' => $request->id,
                    'employee' => $request->employee?->full_name,
                    'department' => $this->employeeDepartment($request->employee),
                    'leave_type' => $request->leaveType?->name,
                    'start_date' => $request->start_date?->toDateString(),
                    'end_date' => $request->end_date?->toDateString(),
                    'days' => (int) $request->days,
                    'status' => $request->status,
                ]),
            ),
        ];
    }

    private function payroll(int $year, array $filters): array
    {
        // Keep this at run level: payroll.view does not grant access to individual salaries.
        $runs = PayrollRun::query()
            ->where('period', 'like', $year.'-%')
            ->where('is_reversal', false)
            ->when($filters['period'] ?? null, fn (Builder $query, string $period) => $query->where('period', $period))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->orderByDesc('period')
            ->orderByDesc('id')
            ->get();

        return [
            'metrics' => [
                $this->metric('runs', 'Payroll runs', $runs->count()),
                $this->metric('employee_payments', 'Employee payments', (int) $runs->sum('employee_count')),
                $this->metric('total_gross', 'Gross payroll', (int) $runs->sum('total_gross'), 'money'),
                $this->metric('total_net', 'Net payroll', (int) $runs->sum('total_net'), 'money'),
                $this->metric('total_deductions', 'Total deductions', (int) $runs->sum('total_deductions'), 'money'),
                $this->metric('employer_cost', 'Additional employer cost', (int) $runs->sum('total_employer_cost'), 'money'),
            ],
            'datasets' => [
                [
                    'key' => 'payroll_trend',
                    'title' => 'Payroll cost trend',
                    'type' => 'line',
                    'x_key' => 'label',
                    'series' => [
                        $this->series('gross', 'Gross', 'money'),
                        $this->series('net', 'Net', 'money'),
                        $this->series('employer_cost', 'Employer cost', 'money'),
                    ],
                    'data' => $this->months($year)->map(function (array $month) use ($runs): array {
                        $monthly = $runs->where('period', $month['period']);

                        return [
                            ...$month,
                            'gross' => (int) $monthly->sum('total_gross'),
                            'net' => (int) $monthly->sum('total_net'),
                            'employer_cost' => (int) $monthly->sum('total_employer_cost'),
                        ];
                    })->all(),
                ],
                [
                    'key' => 'payroll_statuses',
                    'title' => 'Runs by status',
                    'type' => 'donut',
                    'x_key' => 'label',
                    'series' => [$this->series('value', 'Runs')],
                    'data' => $this->countBreakdown($runs, fn (PayrollRun $run) => $this->label($run->status)),
                ],
            ],
            'table' => $this->table(
                'Payroll runs',
                [
                    $this->column('period', 'Period'),
                    $this->column('status', 'Status', 'status'),
                    $this->column('employees', 'Employees', 'number'),
                    $this->column('gross', 'Gross', 'money'),
                    $this->column('statutory', 'Statutory', 'money'),
                    $this->column('deductions', 'Deductions', 'money'),
                    $this->column('net', 'Net', 'money'),
                    $this->column('employer_cost', 'Employer cost', 'money'),
                ],
                $runs->map(fn (PayrollRun $run) => [
                    'id' => $run->id,
                    'period' => $run->period,
                    'status' => $run->status,
                    'employees' => (int) $run->employee_count,
                    'gross' => (int) $run->total_gross,
                    'statutory' => (int) $run->total_statutory,
                    'deductions' => (int) $run->total_deductions,
                    'net' => (int) $run->total_net,
                    'employer_cost' => (int) $run->total_employer_cost,
                ]),
            ),
        ];
    }

    private function performance(int $year, array $filters): array
    {
        $results = AppraisalResult::query()
            ->with([
                'assignment.cycle',
                'assignment.template',
                'assignment.employee.currentAssignment.department',
            ])
            ->whereYear('finalised_at', $year)
            ->when($filters['period'] ?? null, fn (Builder $query, string $period) => $query
                ->whereMonth('finalised_at', (int) Str::after($period, '-')))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['department_id'] ?? null, fn (Builder $query, int $departmentId) => $query
                ->whereHas('assignment.employee.currentAssignment', fn (Builder $assignment) => $assignment->where('department_id', $departmentId)))
            ->orderByDesc('finalised_at')
            ->get();

        $average = $results->avg('final_score_basis_points');
        $belowPassing = $results->filter(function (AppraisalResult $result): bool {
            $minimum = $result->assignment?->template?->min_passing_basis_points;

            return $minimum !== null && $result->final_score_basis_points < $minimum;
        })->count();

        return [
            'metrics' => [
                $this->metric('results', 'Completed appraisals', $results->count()),
                $this->metric('average_score', 'Average score', $average === null ? null : (int) round($average), 'basis_points'),
                $this->metric('below_passing', 'Below passing', $belowPassing),
                $this->metric('acknowledged', 'Acknowledged', $results->where('status', AppraisalResult::STATUS_ACKNOWLEDGED)->count()),
            ],
            'datasets' => [
                [
                    'key' => 'performance_trend',
                    'title' => 'Average performance score',
                    'type' => 'line',
                    'x_key' => 'label',
                    'series' => [$this->series('average_score', 'Average score', 'basis_points')],
                    'data' => $this->months($year)->map(function (array $month) use ($results): array {
                        $average = $results
                            ->filter(fn (AppraisalResult $result) => $result->finalised_at?->month === $month['month'])
                            ->avg('final_score_basis_points');

                        return [
                            ...$month,
                            'average_score' => $average === null ? null : (int) round($average),
                        ];
                    })->all(),
                ],
                $this->breakdownDataset(
                    'performance_grades',
                    'Results by grade',
                    $this->countBreakdown($results, fn (AppraisalResult $result) => $result->grade ?: 'Unrated'),
                ),
                $this->breakdownDataset(
                    'performance_departments',
                    'Results by department',
                    $this->countBreakdown(
                        $results,
                        fn (AppraisalResult $result) => $this->employeeDepartment($result->assignment?->employee),
                    ),
                ),
            ],
            'table' => $this->table(
                'Appraisal results',
                [
                    $this->column('employee', 'Employee'),
                    $this->column('department', 'Department'),
                    $this->column('cycle', 'Cycle'),
                    $this->column('score', 'Score', 'basis_points'),
                    $this->column('grade', 'Grade'),
                    $this->column('status', 'Status', 'status'),
                    $this->column('finalised_at', 'Finalised', 'datetime'),
                ],
                $results->map(fn (AppraisalResult $result) => [
                    'id' => $result->id,
                    'employee' => $result->assignment?->employee?->full_name,
                    'department' => $this->employeeDepartment($result->assignment?->employee),
                    'cycle' => $result->assignment?->cycle?->name,
                    'score' => (int) $result->final_score_basis_points,
                    'grade' => $result->grade,
                    'status' => $result->status,
                    'finalised_at' => $result->finalised_at?->toISOString(),
                ]),
            ),
        ];
    }

    private function recruitment(int $year, array $filters): array
    {
        $applications = Application::query()
            ->with(['applicant', 'vacancy.requisition.department'])
            ->whereYear('applied_at', $year)
            ->when($filters['period'] ?? null, fn (Builder $query, string $period) => $query
                ->whereMonth('applied_at', (int) Str::after($period, '-')))
            ->when($filters['stage'] ?? null, fn (Builder $query, string $stage) => $query->where('stage', $stage))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query
                ->whereHas('vacancy', fn (Builder $vacancy) => $vacancy->where('status', $status)))
            ->when($filters['department_id'] ?? null, fn (Builder $query, int $departmentId) => $query
                ->whereHas('vacancy.requisition', fn (Builder $requisition) => $requisition->where('department_id', $departmentId)))
            ->orderByDesc('applied_at')
            ->get();

        $vacancies = Vacancy::query()
            ->with('requisition.department')
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['department_id'] ?? null, fn (Builder $query, int $departmentId) => $query
                ->whereHas('requisition', fn (Builder $requisition) => $requisition->where('department_id', $departmentId)))
            ->get();

        $hired = $applications->where('stage', 'hired')->count();
        $hireRate = $applications->count() > 0 ? round(($hired / $applications->count()) * 100, 1) : null;

        return [
            'metrics' => [
                $this->metric('applications', 'Applications', $applications->count()),
                $this->metric('hired', 'Hired', $hired),
                $this->metric('hire_rate', 'Hire rate', $hireRate, 'percent'),
                $this->metric('open_vacancies', 'Open vacancies', $vacancies->where('status', Vacancy::STATUS_OPEN)->count()),
                $this->metric('open_positions', 'Open positions', (int) $vacancies
                    ->where('status', Vacancy::STATUS_OPEN)
                    ->sum('positions_available')),
            ],
            'datasets' => [
                [
                    'key' => 'recruitment_trend',
                    'title' => 'Applications and hires',
                    'type' => 'line',
                    'x_key' => 'label',
                    'series' => [
                        $this->series('applications', 'Applications'),
                        $this->series('hired', 'Hired'),
                    ],
                    'data' => $this->months($year)->map(function (array $month) use ($applications): array {
                        $monthly = $applications->filter(fn (Application $application) => $application->applied_at?->month === $month['month']);

                        return [
                            ...$month,
                            'applications' => $monthly->count(),
                            'hired' => $monthly->where('stage', 'hired')->count(),
                        ];
                    })->all(),
                ],
                $this->breakdownDataset(
                    'application_stages',
                    'Applications by stage',
                    $this->countBreakdown($applications, fn (Application $application) => $this->label($application->stage)),
                ),
                $this->breakdownDataset(
                    'application_sources',
                    'Applications by source',
                    $this->countBreakdown(
                        $applications,
                        fn (Application $application) => $application->source ? $this->label($application->source) : 'Not specified',
                    ),
                ),
            ],
            'table' => $this->table(
                'Applications',
                [
                    $this->column('applicant', 'Applicant'),
                    $this->column('vacancy', 'Vacancy'),
                    $this->column('department', 'Department'),
                    $this->column('stage', 'Stage', 'status'),
                    $this->column('source', 'Source'),
                    $this->column('applied_at', 'Applied', 'datetime'),
                    $this->column('hired_at', 'Hired', 'datetime'),
                ],
                $applications->map(fn (Application $application) => [
                    'id' => $application->id,
                    'applicant' => $application->applicant?->full_name,
                    'vacancy' => $application->vacancy?->title,
                    'department' => $application->vacancy?->requisition?->department?->name ?? 'Unassigned',
                    'stage' => $application->stage,
                    'source' => $application->source ? $this->label($application->source) : 'Not specified',
                    'applied_at' => $application->applied_at?->toISOString(),
                    'hired_at' => $application->hired_at?->toISOString(),
                ]),
            ),
        ];
    }

    private function title(string $module): string
    {
        return match ($module) {
            'workforce' => 'Workforce analysis',
            'attendance' => 'Attendance analysis',
            'leave' => 'Leave analysis',
            'payroll' => 'Payroll analysis',
            'performance' => 'Performance analysis',
            'recruitment' => 'Recruitment analysis',
        };
    }

    private function availableFilters(string $module, int $year): array
    {
        $available = [];

        if (in_array($module, ['workforce', 'attendance', 'leave', 'performance', 'recruitment'], true)) {
            $available['departments'] = Department::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Department $department) => [
                    'value' => $department->id,
                    'label' => $department->name,
                ])
                ->all();
        }

        if ($module !== 'workforce') {
            $available['periods'] = $this->months($year)
                ->map(fn (array $month) => [
                    'value' => $month['period'],
                    'label' => Carbon::create($year, $month['month'], 1)->format('F Y'),
                ])
                ->all();
        }

        $available['statuses'] = collect($this->statuses($module))
            ->map(fn (string $status) => ['value' => $status, 'label' => $this->label($status)])
            ->all();

        if ($module === 'recruitment') {
            $available['stages'] = collect(Application::STAGES)
                ->map(fn (string $stage) => ['value' => $stage, 'label' => $this->label($stage)])
                ->all();
        }

        return $available;
    }

    private function appliedFilters(string $module, array $filters): array
    {
        $keys = match ($module) {
            'workforce' => ['department_id', 'status'],
            'attendance', 'leave', 'performance' => ['department_id', 'period', 'status'],
            'payroll' => ['period', 'status'],
            'recruitment' => ['department_id', 'period', 'status', 'stage'],
        };

        return collect($keys)
            ->mapWithKeys(function (string $key) use ($filters): array {
                $value = $filters[$key] ?? null;

                return [$key => $key === 'department_id' && $value !== null ? (int) $value : $value];
            })
            ->all();
    }

    /** @return list<string> */
    private function statuses(string $module): array
    {
        return match ($module) {
            'workforce' => ['active', 'on_leave', 'suspended', 'exited'],
            'attendance' => ['open', 'finalized'],
            'leave' => ['pending', 'approved', 'rejected', 'cancelled'],
            'payroll' => ['draft', 'calculating', 'review', 'pending_approval', 'approved', 'locked', 'paid', 'reversed'],
            'performance' => [
                'pending_calibration',
                'pending_approval',
                'approved',
                'rejected',
                'acknowledged',
                'appealed',
                'appeal_resolved',
            ],
            'recruitment' => ['draft', 'open', 'closed'],
        };
    }

    private function employeeDepartment(?Employee $employee): string
    {
        return $employee?->currentAssignment?->department?->name ?? 'Unassigned';
    }

    private function label(string $value): string
    {
        return (string) Str::of($value)->replace('_', ' ')->headline();
    }

    private function metric(string $key, string $label, int|float|null $value, string $format = 'number'): array
    {
        return compact('key', 'label', 'value', 'format');
    }

    private function series(string $key, string $label, string $format = 'number'): array
    {
        return compact('key', 'label', 'format');
    }

    private function column(string $key, string $label, string $format = 'text'): array
    {
        return compact('key', 'label', 'format');
    }

    private function breakdownDataset(string $key, string $title, array $data): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'type' => 'donut',
            'x_key' => 'label',
            'series' => [$this->series('value', 'Count')],
            'data' => $data,
        ];
    }

    private function countBreakdown(Collection $items, callable $label): array
    {
        return $items
            ->map($label)
            ->countBy()
            ->map(fn (int $value, mixed $label) => [
                'label' => (string) $label,
                'value' => $value,
            ])
            ->sortByDesc('value')
            ->values()
            ->all();
    }

    private function table(string $title, array $columns, Collection $rows): array
    {
        $count = $rows->count();

        return [
            'title' => $title,
            'columns' => $columns,
            'rows' => ($this->tableLimit === null ? $rows : $rows->take($this->tableLimit))->values()->all(),
            'meta' => [
                'count' => $count,
                'limit' => $this->tableLimit,
                'limited' => $this->tableLimit !== null && $count > $this->tableLimit,
            ],
        ];
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
