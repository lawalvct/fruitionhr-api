<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Models\AttendanceLog;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Support\AttendanceCalculator;
use App\Modules\Employee\Models\Employee;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Employee self clock-in/out. Writes the same `attendance_logs` rows manual
 * entry and import use (source=self), so AttendanceCalculator and the
 * monthly finalize() lock need no changes for this to feed into payroll.
 *
 * "Now" is pinned to Africa/Lagos: shift start/end times are plain H:i
 * wall-clock values with no timezone attached (HR already enters them as
 * local time), while config('app.timezone') is UTC — a self clock-in has to
 * be computed in the same wall-clock frame those shift times assume.
 */
class SelfAttendanceClockService
{
    private const TIMEZONE = 'Africa/Lagos';

    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly AttendanceCalculator $calculator,
        private readonly CurrentTenant $tenant,
    ) {
    }

    public function today(Employee $employee): array
    {
        $now = $this->now();
        [$date, $log] = $this->resolveActiveLog($employee, $now);

        return $this->present($employee, $date, $log);
    }

    public function clockIn(Employee $employee, int $actingUserId, ?int $kioskId = null): array
    {
        $now = $this->now();

        return DB::transaction(function () use ($employee, $actingUserId, $now, $kioskId) {
            $this->guardClockMethodEnabled($kioskId);
            $this->guardOpenPeriod($now->format('Y-m'));

            $log = AttendanceLog::query()
                ->where('employee_id', $employee->id)
                ->where('date', $now->toDateString())
                ->lockForUpdate()
                ->first();

            if ($log !== null && $log->source !== AttendanceLog::SOURCE_SELF) {
                throw ValidationException::withMessages([
                    'clock' => ["Today's attendance was already recorded by HR. Contact HR to make changes."],
                ]);
            }

            if ($log !== null && $log->clock_in !== null) {
                throw ValidationException::withMessages([
                    'clock' => ['You already clocked in today.'],
                ]);
            }

            $log = AttendanceLog::query()->updateOrCreate(
                ['employee_id' => $employee->id, 'date' => $now->toDateString()],
                [
                    'clock_in' => $now->format('H:i:s'),
                    'source' => AttendanceLog::SOURCE_SELF,
                    'kiosk_id' => $kioskId,
                    'created_by' => $actingUserId,
                ],
            );

            return $this->present($employee, $now, $log->fresh());
        });
    }

    public function clockOut(Employee $employee, int $actingUserId, ?int $kioskId = null): array
    {
        $now = $this->now();

        return DB::transaction(function () use ($employee, $actingUserId, $now, $kioskId) {
            $this->guardClockMethodEnabled($kioskId);
            $this->guardOpenPeriod($now->format('Y-m'));

            $today = AttendanceLog::query()
                ->where('employee_id', $employee->id)
                ->where('date', $now->toDateString())
                ->lockForUpdate()
                ->first();

            if ($today !== null && $today->source === AttendanceLog::SOURCE_SELF && $today->clock_in !== null) {
                $this->assertNotAlreadyOut($today);

                $today->update(['clock_out' => $now->format('H:i:s'), 'kiosk_id' => $kioskId ?? $today->kiosk_id]);

                return $this->present($employee, $now, $today->fresh());
            }

            // Overnight fallback: shift crossed midnight, clock-in landed on
            // yesterday's row — mirrors AttendanceCalculator's own overnight
            // handling (end_time < start_time belongs to the following day).
            $yesterday = $now->copy()->subDay();
            $shift = $this->attendance->shiftFor($employee, $yesterday);

            if ($shift !== null && $this->isOvernight($shift) && $today === null) {
                $previous = AttendanceLog::query()
                    ->where('employee_id', $employee->id)
                    ->where('date', $yesterday->toDateString())
                    ->lockForUpdate()
                    ->first();

                if ($previous !== null && $previous->source === AttendanceLog::SOURCE_SELF && $previous->clock_in !== null) {
                    $this->assertNotAlreadyOut($previous);

                    $previous->update(['clock_out' => $now->format('H:i:s'), 'kiosk_id' => $kioskId ?? $previous->kiosk_id]);

                    return $this->present($employee, $yesterday, $previous->fresh());
                }
            }

            if ($today !== null && $today->source !== AttendanceLog::SOURCE_SELF) {
                throw ValidationException::withMessages([
                    'clock' => ["Today's attendance was already recorded by HR. Contact HR to make changes."],
                ]);
            }

            throw ValidationException::withMessages([
                'clock' => ["You haven't clocked in yet today."],
            ]);
        });
    }

    private function assertNotAlreadyOut(AttendanceLog $log): void
    {
        if ($log->clock_out !== null) {
            throw ValidationException::withMessages([
                'clock' => ['You already clocked out today.'],
            ]);
        }
    }

    private function guardClockMethodEnabled(?int $kioskId): void
    {
        $tenant = $this->tenant->get();

        if ($kioskId !== null && ! $tenant->attendanceKioskEnabled()) {
            throw ValidationException::withMessages([
                'clock' => ['QR kiosk scanning is disabled for your organisation. Contact HR.'],
            ]);
        }

        if ($kioskId === null && ! $tenant->attendanceSelfClockEnabled()) {
            throw ValidationException::withMessages([
                'clock' => ['Self clock-in is disabled for your organisation. Contact HR.'],
            ]);
        }
    }

    private function guardOpenPeriod(string $period): void
    {
        if ($this->attendance->isFinalized($period)) {
            throw ValidationException::withMessages([
                'clock' => ['Attendance for this period is finalized and can no longer be edited.'],
            ]);
        }
    }

    private function findLog(Employee $employee, string $date): ?AttendanceLog
    {
        return AttendanceLog::query()
            ->where('employee_id', $employee->id)
            ->where('date', $date)
            ->first();
    }

    /**
     * Which day's log is "active" right now: today's, unless today has no
     * self-clock-in yet and yesterday's overnight shift is still open —
     * otherwise a night-shift worker would show as "not clocked in" the
     * moment midnight passes, despite still being on shift.
     *
     * @return array{0: Carbon, 1: ?AttendanceLog}
     */
    private function resolveActiveLog(Employee $employee, Carbon $now): array
    {
        $log = $this->findLog($employee, $now->toDateString());

        if ($log !== null) {
            return [$now, $log];
        }

        $yesterday = $now->copy()->subDay();
        $shift = $this->attendance->shiftFor($employee, $yesterday);

        if ($shift !== null && $this->isOvernight($shift)) {
            $previous = $this->findLog($employee, $yesterday->toDateString());

            if ($previous !== null && $previous->source === AttendanceLog::SOURCE_SELF
                && $previous->clock_in !== null && $previous->clock_out === null) {
                return [$yesterday, $previous];
            }
        }

        return [$now, null];
    }

    /** $date is the log's own day — the previous day for an overnight clock-out, otherwise today. */
    private function present(Employee $employee, Carbon $date, ?AttendanceLog $log): array
    {
        $shift = $this->attendance->shiftFor($employee, $date);
        $day = $this->calculator->forDay($date, $shift, $log, isHoliday: false, isOnLeave: false);

        $clockIn = $log?->clock_in ? substr((string) $log->clock_in, 0, 5) : null;
        $clockOut = $log?->clock_out ? substr((string) $log->clock_out, 0, 5) : null;

        return [
            'date' => $date->toDateString(),
            'state' => match (true) {
                $clockOut !== null => 'clocked_out',
                $clockIn !== null => 'clocked_in',
                default => 'not_clocked_in',
            },
            'clock_in' => $clockIn,
            'clock_out' => $clockOut,
            'status' => $day->status,
            'late_minutes' => $day->lateMinutes,
            'overtime_minutes' => $day->overtimeMinutes,
            'kiosk' => $log?->loadMissing('kiosk')->kiosk?->name,
            'self_clock_enabled' => $this->tenant->get()->attendanceSelfClockEnabled(),
            'kiosk_scanning_enabled' => $this->tenant->get()->attendanceKioskEnabled(),
        ];
    }

    private function isOvernight(Shift $shift): bool
    {
        [$startH, $startM] = array_pad(explode(':', (string) $shift->start_time), 2, '0');
        [$endH, $endM] = array_pad(explode(':', (string) $shift->end_time), 2, '0');

        return ((int) $endH * 60 + (int) $endM) < ((int) $startH * 60 + (int) $startM);
    }

    private function now(): Carbon
    {
        return Carbon::now(self::TIMEZONE);
    }
}
