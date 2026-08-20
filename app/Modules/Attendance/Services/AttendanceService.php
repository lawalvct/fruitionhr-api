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
     * Mark many employees across one or more dates in a single period.
     *
     * Status is derived, never stored, so a status is only ever expressed as
     * clock times: "present" writes the employee's own shift hours, "late"
     * writes an arrival past the grace period, and "absent" removes the log so
     * the calculator falls back to ABSENT on its own. That keeps
     * AttendanceCalculator the one place a day's outcome is decided.
     *
     * Days the company does not own are never touched: holidays, approved
     * leave, non-working weekdays and employees with no shift are reported as
     * skipped rather than silently overwritten — attendance must not contradict
     * the leave module or the holiday calendar.
     *
     * @param  int[]  $employeeIds
     * @param  string[]  $dates  Y-m-d, all within one YYYY-MM period
     * @param  bool  $overwrite  replace days that already carry a log
     * @return array{marked:int,cleared:int,skipped:list<array{employee_id:int,employee:string,date:string,reason:string}>}
     */
    public function bulkMark(
        array $employeeIds,
        array $dates,
        string $status,
        ?string $clockIn,
        ?string $clockOut,
        ?string $note,
        User $actor,
        bool $overwrite = false,
    ): array {
        $period = substr((string) reset($dates), 0, 7);

        $employees = Employee::query()
            ->whereIn('id', $employeeIds)
            ->where('employment_status', '!=', Employee::STATUS_EXITED)
            ->get();

        $marked = 0;
        $cleared = 0;
        $skipped = [];

        DB::transaction(function () use (
            $employees, $dates, $period, $status, $clockIn, $clockOut, $note,
            $actor, $overwrite, &$marked, &$cleared, &$skipped
        ): void {
            foreach ($employees as $employee) {
                // Reuse the same derivation the grid shows, so "why was this
                // day skipped?" always matches what the user is looking at.
                $days = $this->daysFor($employee, $period);

                $logs = AttendanceLog::query()
                    ->where('employee_id', $employee->id)
                    ->whereIn('date', $dates)
                    ->get()
                    ->keyBy(fn (AttendanceLog $log) => $log->date->toDateString());

                foreach ($dates as $date) {
                    $current = $days[$date] ?? null;

                    if ($current === null || ! $current->isWorkingDay()) {
                        $skipped[] = $this->skip($employee, $date, $current?->status ?? DayStatus::NO_SHIFT);

                        continue;
                    }

                    $existing = $logs->get($date);

                    if ($existing !== null && ! $overwrite) {
                        $skipped[] = $this->skip($employee, $date, 'already_recorded');

                        continue;
                    }

                    if ($status === DayStatus::ABSENT) {
                        if ($existing === null) {
                            $skipped[] = $this->skip($employee, $date, 'already_absent');

                            continue;
                        }

                        $existing->delete();
                        $cleared++;

                        continue;
                    }

                    $shift = $this->shiftFor($employee, Carbon::parse($date));

                    if ($shift === null) {
                        $skipped[] = $this->skip($employee, $date, DayStatus::NO_SHIFT);

                        continue;
                    }

                    AttendanceLog::query()->updateOrCreate(
                        ['employee_id' => $employee->id, 'date' => $date],
                        [
                            'clock_in' => $clockIn ?? $this->defaultClockIn($shift, $status),
                            'clock_out' => $clockOut ?? $this->time($shift->end_time),
                            'note' => $note,
                            'source' => AttendanceLog::SOURCE_MANUAL,
                            'created_by' => $actor->id,
                        ],
                    );

                    $marked++;
                }
            }
        });

        return ['marked' => $marked, 'cleared' => $cleared, 'skipped' => $skipped];
    }

    /**
     * Clock-in that produces the requested status against this shift. "Late"
     * has to land past the grace period, otherwise the calculator would read
     * it back as present.
     */
    private function defaultClockIn(Shift $shift, string $status): string
    {
        $start = $this->time($shift->start_time);

        if ($status !== DayStatus::LATE) {
            return $start;
        }

        [$hours, $minutes] = array_map('intval', explode(':', $start));
        $total = $hours * 60 + $minutes + (int) $shift->grace_minutes + 1;
        $total = min($total, 23 * 60 + 59);

        return sprintf('%02d:%02d', intdiv($total, 60), $total % 60);
    }

    /** Normalise 'H:i:s' shift columns down to the 'H:i' logs are stored in. */
    private function time(mixed $value): string
    {
        return substr((string) $value, 0, 5);
    }

    /** @return array{employee_id:int,employee:string,date:string,reason:string} */
    private function skip(Employee $employee, string $date, string $reason): array
    {
        return [
            'employee_id' => $employee->id,
            'employee' => $employee->full_name,
            'date' => $date,
            'reason' => $reason,
        ];
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

    /**
     * The shift in effect for one employee on one date — same assignment
     * lookup and single-active-shift fallback `daysFor()` uses per day.
     */
    public function shiftFor(Employee $employee, Carbon $date): ?Shift
    {
        $assignment = ShiftAssignment::query()
            ->with('shift')
            ->where('employee_id', $employee->id)
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $date->toDateString());
            })
            ->orderByDesc('effective_from')
            ->first();

        if ($assignment !== null) {
            return $assignment->shift;
        }

        $active = Shift::query()->where('is_active', true)->get();

        return $active->count() === 1 ? $active->first() : null;
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
