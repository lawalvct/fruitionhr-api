<?php

namespace App\Modules\Payroll\Support;

use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Employee\Models\Employee;
use App\Modules\Payroll\Models\EmployeeSalary;
use Illuminate\Support\Carbon;

/**
 * Ballie-style pre-run checklist. Payroll cannot be created until every check
 * passes (see dev plan: "Payroll Should Not Run Unless ...").
 */
class PayrollPreflight
{
    public function __construct(private readonly AttendanceService $attendance)
    {
    }

    /**
     * @return list<array{key:string, label:string, passed:bool, detail:?string}>
     */
    public function check(string $period): array
    {
        return [
            $this->attendanceFinalized($period),
            $this->leaveProcessed($period),
            $this->salariesAssigned($period),
            $this->statutoryConfigured($period),
        ];
    }

    public function passes(string $period): bool
    {
        return collect($this->check($period))->every(fn ($c) => $c['passed']);
    }

    private function attendanceFinalized(string $period): array
    {
        $finalized = $this->attendance->isFinalized($period);

        return [
            'key' => 'attendance_finalized',
            'label' => 'Attendance finalized for the period',
            'passed' => $finalized,
            'detail' => $finalized ? null : 'Finalize attendance before running payroll.',
        ];
    }

    private function leaveProcessed(string $period): array
    {
        [$start, $end] = $this->bounds($period);

        $pending = 0;
        if (class_exists(\App\Modules\Leave\Models\LeaveRequest::class)) {
            $pending = \App\Modules\Leave\Models\LeaveRequest::query()
                ->where('status', 'pending')
                ->where('start_date', '<=', $end)
                ->where('end_date', '>=', $start)
                ->count();
        }

        return [
            'key' => 'leave_processed',
            'label' => 'No pending leave requests in the period',
            'passed' => $pending === 0,
            'detail' => $pending === 0 ? null : "{$pending} leave request(s) still pending approval.",
        ];
    }

    private function salariesAssigned(string $period): array
    {
        $activeEmployees = Employee::query()
            ->where('employment_status', Employee::STATUS_ACTIVE)
            ->pluck('id');

        $withSalary = EmployeeSalary::query()
            ->effectiveOn(Carbon::createFromFormat('Y-m', $period)->startOfMonth())
            ->whereIn('employee_id', $activeEmployees)
            ->pluck('employee_id');

        $missing = $activeEmployees->diff($withSalary)->count();

        return [
            'key' => 'salaries_assigned',
            'label' => 'All active employees have a salary',
            'passed' => $missing === 0,
            'detail' => $missing === 0 ? null : "{$missing} active employee(s) have no salary set.",
        ];
    }

    private function statutoryConfigured(string $period): array
    {
        try {
            app(StatutoryCalculator::class)->configsFor($period);
            $ok = true;
        } catch (\Throwable) {
            $ok = false;
        }

        return [
            'key' => 'statutory_configured',
            'label' => 'Statutory rules configured (PAYE, Pension, NHF, NSITF)',
            'passed' => $ok,
            'detail' => $ok ? null : 'Statutory rules are missing for this period.',
        ];
    }

    /** @return array{0:string,1:string} */
    private function bounds(string $period): array
    {
        $start = Carbon::createFromFormat('Y-m', $period)->startOfMonth();

        return [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()];
    }
}
