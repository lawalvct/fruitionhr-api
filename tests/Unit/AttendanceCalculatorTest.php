<?php

use App\Modules\Attendance\Models\AttendanceLog;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Support\AttendanceCalculator;
use App\Modules\Attendance\Support\DayStatus;
use Carbon\Carbon;

function stdShift(int $grace = 15): Shift
{
    return new Shift([
        'start_time' => '08:00',
        'end_time' => '17:00',
        'grace_minutes' => $grace,
        'working_days' => [1, 2, 3, 4, 5], // Mon–Fri
    ]);
}

function logAt(?string $in, ?string $out = null): AttendanceLog
{
    return new AttendanceLog(['clock_in' => $in, 'clock_out' => $out]);
}

// A known Monday and Saturday.
const MON = '2026-07-06';
const SAT = '2026-07-11';

it('marks an on-time full day as present', function () {
    $result = app(AttendanceCalculator::class)->forDay(
        Carbon::parse(MON), stdShift(), logAt('08:00', '17:00'), false, false,
    );

    expect($result->status)->toBe(DayStatus::PRESENT)
        ->and($result->lateMinutes)->toBe(0)
        ->and($result->overtimeMinutes)->toBe(0);
});

it('treats arrival within grace as present, not late', function () {
    $result = app(AttendanceCalculator::class)->forDay(
        Carbon::parse(MON), stdShift(15), logAt('08:15', '17:00'), false, false,
    );

    expect($result->status)->toBe(DayStatus::PRESENT)
        ->and($result->lateMinutes)->toBe(0);
});

it('marks arrival beyond grace as late and records minutes past start', function () {
    $result = app(AttendanceCalculator::class)->forDay(
        Carbon::parse(MON), stdShift(15), logAt('08:25', '17:00'), false, false,
    );

    expect($result->status)->toBe(DayStatus::LATE)
        ->and($result->lateMinutes)->toBe(25);
});

it('marks a missing clock-in on a working day as absent', function () {
    $result = app(AttendanceCalculator::class)->forDay(
        Carbon::parse(MON), stdShift(), null, false, false,
    );

    expect($result->status)->toBe(DayStatus::ABSENT);
});

it('marks leaving before shift end as early exit with minutes', function () {
    $result = app(AttendanceCalculator::class)->forDay(
        Carbon::parse(MON), stdShift(), logAt('08:00', '16:30'), false, false,
    );

    expect($result->status)->toBe(DayStatus::EARLY_EXIT)
        ->and($result->earlyMinutes)->toBe(30);
});

it('records overtime when clocking out after shift end', function () {
    $result = app(AttendanceCalculator::class)->forDay(
        Carbon::parse(MON), stdShift(), logAt('08:00', '19:00'), false, false,
    );

    expect($result->status)->toBe(DayStatus::PRESENT)
        ->and($result->overtimeMinutes)->toBe(120);
});

it('prioritises late over early exit for the primary status but keeps both minute figures', function () {
    $result = app(AttendanceCalculator::class)->forDay(
        Carbon::parse(MON), stdShift(15), logAt('08:30', '16:30'), false, false,
    );

    expect($result->status)->toBe(DayStatus::LATE)
        ->and($result->lateMinutes)->toBe(30)
        ->and($result->earlyMinutes)->toBe(30);
});

it('marks a non-working weekday as weekend', function () {
    $result = app(AttendanceCalculator::class)->forDay(
        Carbon::parse(SAT), stdShift(), null, false, false,
    );

    expect($result->status)->toBe(DayStatus::WEEKEND);
});

it('marks a holiday regardless of clock data', function () {
    $result = app(AttendanceCalculator::class)->forDay(
        Carbon::parse(MON), stdShift(), logAt('08:00', '17:00'), true, false,
    );

    expect($result->status)->toBe(DayStatus::HOLIDAY);
});

it('marks approved leave ahead of holiday and absence', function () {
    $result = app(AttendanceCalculator::class)->forDay(
        Carbon::parse(MON), stdShift(), null, true, true,
    );

    expect($result->status)->toBe(DayStatus::ON_LEAVE);
});

it('marks no_shift when the employee has no shift', function () {
    $result = app(AttendanceCalculator::class)->forDay(
        Carbon::parse(MON), null, logAt('08:00', '17:00'), false, false,
    );

    expect($result->status)->toBe(DayStatus::NO_SHIFT);
});

it('summarises a mixed period correctly', function () {
    $summary = app(AttendanceCalculator::class)->summarize([
        new DayStatus(DayStatus::PRESENT),
        new DayStatus(DayStatus::LATE, lateMinutes: 20),
        new DayStatus(DayStatus::ABSENT),
        new DayStatus(DayStatus::ON_LEAVE),
        new DayStatus(DayStatus::WEEKEND),
        new DayStatus(DayStatus::HOLIDAY),
        new DayStatus(DayStatus::PRESENT, overtimeMinutes: 60),
    ]);

    // working days = present + late + absent (present, late, absent, present) = 4
    expect($summary['working_days'])->toBe(4)
        ->and($summary['days_present'])->toBe(3) // present, late, present
        ->and($summary['days_late'])->toBe(1)
        ->and($summary['days_absent'])->toBe(1)
        ->and($summary['days_on_leave'])->toBe(1)
        ->and($summary['late_minutes'])->toBe(20)
        ->and($summary['overtime_minutes'])->toBe(60);
});
