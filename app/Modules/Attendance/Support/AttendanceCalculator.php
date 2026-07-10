<?php

namespace App\Modules\Attendance\Support;

use App\Modules\Attendance\Models\AttendanceLog;
use App\Modules\Attendance\Models\Shift;
use Carbon\CarbonInterface;

/**
 * Pure attendance logic — no DB, no side effects. Given a day's context it
 * derives the status and minute figures. Unit-tested exhaustively; payroll
 * depends on these numbers, so keep it deterministic.
 */
class AttendanceCalculator
{
    /**
     * @param  bool  $isHoliday  the date is on the holiday calendar
     * @param  bool  $isOnLeave  the employee has approved leave covering the date
     */
    public function forDay(
        CarbonInterface $date,
        ?Shift $shift,
        ?AttendanceLog $log,
        bool $isHoliday,
        bool $isOnLeave,
    ): DayStatus {
        // Leave and holidays win regardless of any clock data.
        if ($isOnLeave) {
            return new DayStatus(DayStatus::ON_LEAVE);
        }

        if ($isHoliday) {
            return new DayStatus(DayStatus::HOLIDAY);
        }

        if ($shift === null) {
            return new DayStatus(DayStatus::NO_SHIFT);
        }

        $workingDays = $shift->working_days ?? [];
        if (! in_array($date->isoWeekday(), $workingDays, true)) {
            return new DayStatus(DayStatus::WEEKEND);
        }

        // Working day from here on.
        $clockIn = $this->minutes($log?->clock_in);
        if ($clockIn === null) {
            return new DayStatus(DayStatus::ABSENT);
        }

        $start = $this->minutes($shift->start_time) ?? 0;
        $end = $this->minutes($shift->end_time) ?? 0;
        $grace = (int) $shift->grace_minutes;

        $lateMinutes = max(0, $clockIn - $start);
        $isLate = $lateMinutes > $grace;

        $clockOut = $this->minutes($log?->clock_out);
        $earlyMinutes = 0;
        $overtimeMinutes = 0;

        if ($clockOut !== null) {
            $earlyMinutes = max(0, $end - $clockOut);
            $overtimeMinutes = max(0, $clockOut - $end);
        }

        $status = match (true) {
            $isLate => DayStatus::LATE,
            $earlyMinutes > 0 => DayStatus::EARLY_EXIT,
            default => DayStatus::PRESENT,
        };

        return new DayStatus(
            status: $status,
            // Only record late minutes once past the grace period.
            lateMinutes: $isLate ? $lateMinutes : 0,
            earlyMinutes: $earlyMinutes,
            overtimeMinutes: $overtimeMinutes,
        );
    }

    /**
     * Aggregate a period's day statuses into summary counters.
     *
     * @param  DayStatus[]  $days
     * @return array{working_days:int,days_present:int,days_late:int,days_absent:int,days_on_leave:int,late_minutes:int,overtime_minutes:int}
     */
    public function summarize(array $days): array
    {
        $summary = [
            'working_days' => 0,
            'days_present' => 0,
            'days_late' => 0,
            'days_absent' => 0,
            'days_on_leave' => 0,
            'late_minutes' => 0,
            'overtime_minutes' => 0,
        ];

        foreach ($days as $day) {
            if ($day->isWorkingDay()) {
                $summary['working_days']++;
            }

            if ($day->isPresent()) {
                $summary['days_present']++;
            }

            if ($day->status === DayStatus::LATE) {
                $summary['days_late']++;
            }

            if ($day->status === DayStatus::ABSENT) {
                $summary['days_absent']++;
            }

            if ($day->status === DayStatus::ON_LEAVE) {
                $summary['days_on_leave']++;
            }

            $summary['late_minutes'] += $day->lateMinutes;
            $summary['overtime_minutes'] += $day->overtimeMinutes;
        }

        return $summary;
    }

    /** Normalise an 'H:i' / 'H:i:s' time to minutes-of-day, or null. */
    private function minutes(mixed $time): ?int
    {
        if ($time === null || $time === '') {
            return null;
        }

        [$h, $m] = array_pad(explode(':', (string) $time), 2, '0');

        return ((int) $h) * 60 + (int) $m;
    }
}
