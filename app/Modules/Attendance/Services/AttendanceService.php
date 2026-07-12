<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Models\AttendanceLog;
use App\Modules\Attendance\Models\AttendanceSummary;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Models\ShiftAssignment;
use App\Modules\Attendance\Support\AttendanceCalculator;
use App\Modules\Attendance\Support\DayStatus;
use App\Modules\Company\Models\HolidayDate;
use App\Modules\Employee\Models\Employee;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds per-day statuses and month summaries from raw logs, shifts,
 * holidays and approved leave. Payroll consumes only finalized summaries.
 */
class AttendanceService
{
    public function __construct(private readonly AttendanceCalculator $calculator)
    {
    }

    /**
     * Per-day statuses for one employee across a YYYY-MM period.
     *
     * @return array<string, DayStatus> keyed by Y-m-d
     */
    public function daysFor(Employee $employee, string $period): array
    {
        [$start, $end] = $this->periodBounds($period);

        $assignments = ShiftAssignment::query()
            ->with('shift')
            ->where('employee_id', $employee->id)
            ->whereDate('effective_from', '<=', $end->toDateString())
            ->where(function ($query) use ($start): void {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $start->toDateString());
            })
            ->orderByDesc('effective_from')
            ->get();

        $activeShifts = Shift::query()->where('is_active', true)->get();
        $fallbackShift = $activeShifts->count() === 1 ? $activeShifts->first() : null;
        $holidays = $this->holidayDates($start, $end);
        $leaveDates = $this->leaveDates($employee->id, $start, $end);

        $logs = AttendanceLog::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->keyBy(fn (AttendanceLog $log) => $log->date->toDateString());

        $days = [];

        foreach (CarbonPeriod::create($start, $end) as $date) {
            $key = $date->toDateString();
            $assignment = $assignments->first(fn (ShiftAssignment $item): bool =>
                $item->effective_from->lte($date)
                && ($item->effective_to === null || $item->effective_to->gte($date))
            );

            $days[$key] = $this->calculator->forDay(
                $date,
                $assignment?->shift ?? $fallbackShift,
                $logs->get($key),
                $holidays->contains($key),
                $leaveDates->contains($key),
            );
        }

        return $days;
    }

    /**
     * Compute and persist finalized summaries for all active employees for
     * the period, then lock them. Idempotent overwrite of open summaries.
     *
     * @return int number of summaries finalized
     */
    public function finalize(string $period, User $finalizedBy): int
    {
        $employees = Employee::query()
            ->where('employment_status', '!=', Employee::STATUS_EXITED)
            ->get();

        return DB::transaction(function () use ($employees, $period, $finalizedBy): int {
            $count = 0;

            foreach ($employees as $employee) {
                $days = $this->daysFor($employee, $period);
                $totals = $this->calculator->summarize(array_values($days));

                AttendanceSummary::query()->updateOrCreate(
                    ['employee_id' => $employee->id, 'period' => $period],
                    [
                        ...$totals,
                        'status' => AttendanceSummary::STATUS_FINALIZED,
                        'finalized_by' => $finalizedBy->id,
                        'finalized_at' => now(),
                    ],
                );

                $count++;
            }

            return $count;
        });
    }

    public function isFinalized(string $period): bool
    {
        return AttendanceSummary::query()
            ->where('period', $period)
            ->where('status', AttendanceSummary::STATUS_FINALIZED)
            ->exists();
    }

    private function currentShift(int $employeeId): ?Shift
    {
        $assignment = ShiftAssignment::query()
            ->where('employee_id', $employeeId)
            ->where('is_current', true)
            ->first();

        if ($assignment !== null) {
            return $assignment->shift;
        }

        // Fallback: if the tenant has exactly one active shift, use it — keeps
        // small companies working without per-employee assignment.
        $active = Shift::query()->where('is_active', true)->get();

        return $active->count() === 1 ? $active->first() : null;
    }

    private function holidayDates(Carbon $start, Carbon $end): Collection
    {
        return HolidayDate::query()
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->pluck('date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString());
    }

    /**
     * Dates covered by approved leave. Resolved dynamically so this service
     * works before the Leave module ships (returns empty then).
     */
    private function leaveDates(int $employeeId, Carbon $start, Carbon $end): Collection
    {
        $model = 'App\\Modules\\Leave\\Models\\LeaveRequest';

        if (! class_exists($model)) {
            return collect();
        }

        $requests = $model::query()
            ->where('employee_id', $employeeId)
            ->where('status', 'approved')
            ->where('start_date', '<=', $end->toDateString())
            ->where('end_date', '>=', $start->toDateString())
            ->get(['start_date', 'end_date']);

        $dates = collect();

        foreach ($requests as $request) {
            foreach (CarbonPeriod::create($request->start_date, $request->end_date) as $date) {
                if ($date->betweenIncluded($start, $end)) {
                    $dates->push($date->toDateString());
                }
            }
        }

        return $dates->unique()->values();
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function periodBounds(string $period): array
    {
        $start = Carbon::createFromFormat('Y-m', $period)->startOfMonth();

        return [$start->copy(), $start->copy()->endOfMonth()];
    }
}
