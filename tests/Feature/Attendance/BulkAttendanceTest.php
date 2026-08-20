<?php

use App\Models\User;
use App\Modules\Attendance\Models\AttendanceLog;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Models\ShiftAssignment;
use App\Modules\Company\Models\HolidayCalendar;
use App\Modules\Company\Models\HolidayDate;
use App\Modules\Employee\Models\Employee;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantRoleProvisioner;
use App\Support\Tenancy\CurrentTenant;

/**
 * Self-contained tenant setup. Deliberately not reusing AttendanceTest's
 * attendanceOwner(): Pest loads every test file into one namespace, so two
 * files sharing a helper name is a fatal redeclare.
 *
 * @return array{0: Tenant, 1: User}
 */
function bulkAttendanceOwner(): array
{
    $tenant = Tenant::factory()->create();
    app(TenantRoleProvisioner::class)->provision($tenant);
    app(CurrentTenant::class)->set($tenant);
    setPermissionsTeamId($tenant->id);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole('owner');
    test()->actingAs($user);

    return [$tenant, $user];
}

beforeEach(function () {
    [$this->tenant, $this->user] = bulkAttendanceOwner();

    // Mon-Fri, 08:00-17:00, 15 minutes of grace.
    $this->shift = Shift::factory()->create([
        'start_time' => '08:00',
        'end_time' => '17:00',
        'grace_minutes' => 15,
        'working_days' => [1, 2, 3, 4, 5],
    ]);
});

/** An employee on the standard shift for the whole of August 2026. */
function bulkEmployee(string $first = 'Ada'): Employee
{
    $employee = Employee::factory()->create(['first_name' => $first]);

    ShiftAssignment::query()->create([
        'employee_id' => $employee->id,
        'shift_id' => test()->shift->id,
        'effective_from' => '2026-08-01',
        'is_current' => true,
    ]);

    return $employee;
}

test('marking everyone present writes each employee their own shift hours', function () {
    $one = bulkEmployee('Ada');
    $two = bulkEmployee('Chidi');

    // 2026-08-20 is a Thursday.
    $this->postJson('/api/v1/attendance-logs/bulk', [
        'employee_ids' => [$one->id, $two->id],
        'date' => '2026-08-20',
        'status' => 'present',
    ])->assertOk()->assertJsonPath('data.marked', 2);

    $logs = AttendanceLog::query()->where('date', '2026-08-20')->get();

    expect($logs)->toHaveCount(2)
        ->and(substr((string) $logs[0]->clock_in, 0, 5))->toBe('08:00')
        ->and(substr((string) $logs[0]->clock_out, 0, 5))->toBe('17:00');

    $this->getJson('/api/v1/attendance?period=2026-08')
        ->assertOk()
        ->assertJsonPath('data.rows.0.days.2026-08-20.status', 'present')
        ->assertJsonPath('data.rows.1.days.2026-08-20.status', 'present');
});

test('marking late lands past the grace period so it reads back as late', function () {
    $employee = bulkEmployee();

    $this->postJson('/api/v1/attendance-logs/bulk', [
        'employee_ids' => [$employee->id],
        'date' => '2026-08-20',
        'status' => 'late',
    ])->assertOk();

    // 08:00 start + 15 grace + 1 = 08:16. Anything inside the grace window
    // would derive as "present" and silently contradict what was asked for.
    $log = AttendanceLog::query()->firstOrFail();
    expect(substr((string) $log->clock_in, 0, 5))->toBe('08:16');

    $this->getJson('/api/v1/attendance?period=2026-08')
        ->assertOk()
        ->assertJsonPath('data.rows.0.days.2026-08-20.status', 'late');
});

test('a date range covers every working day and skips the weekend', function () {
    $employee = bulkEmployee();

    // Mon 17th to Sun 23rd: five working days, Saturday and Sunday skipped.
    $response = $this->postJson('/api/v1/attendance-logs/bulk', [
        'employee_ids' => [$employee->id],
        'from' => '2026-08-17',
        'to' => '2026-08-23',
        'status' => 'present',
    ])->assertOk();

    expect($response->json('data.marked'))->toBe(5)
        ->and(collect($response->json('data.skipped'))->pluck('reason')->all())
        ->toBe(['weekend', 'weekend']);

    expect(AttendanceLog::query()->count())->toBe(5);
});

test('a range spanning two months is rejected', function () {
    $employee = bulkEmployee();

    $this->postJson('/api/v1/attendance-logs/bulk', [
        'employee_ids' => [$employee->id],
        'from' => '2026-08-28',
        'to' => '2026-09-02',
        'status' => 'present',
    ])->assertUnprocessable()->assertJsonValidationErrors('to');
});

test('holidays and approved leave are never overwritten', function () {
    $employee = bulkEmployee();

    HolidayDate::factory()->create([
        'holiday_calendar_id' => HolidayCalendar::factory()->create(['year' => 2026])->id,
        'date' => '2026-08-19',
    ]);

    LeaveRequest::factory()->create([
        'employee_id' => $employee->id,
        'leave_type_id' => LeaveType::factory()->create()->id,
        'start_date' => '2026-08-20',
        'end_date' => '2026-08-20',
        'days' => 1,
        'status' => LeaveRequest::STATUS_APPROVED,
        'requested_by' => $this->user->id,
    ]);

    $response = $this->postJson('/api/v1/attendance-logs/bulk', [
        'employee_ids' => [$employee->id],
        'from' => '2026-08-19',
        'to' => '2026-08-20',
        'status' => 'present',
    ])->assertOk();

    expect($response->json('data.marked'))->toBe(0)
        ->and(collect($response->json('data.skipped'))->pluck('reason')->all())
        ->toBe(['holiday', 'on_leave']);

    // Attendance must not be able to contradict the leave module.
    expect(AttendanceLog::query()->count())->toBe(0);
});

test('existing records are left alone unless overwrite is asked for', function () {
    $employee = bulkEmployee();

    // A real kiosk clock-in that must not be flattened to the shift default.
    $this->postJson('/api/v1/attendance-logs', [
        'employee_id' => $employee->id,
        'date' => '2026-08-20',
        'clock_in' => '08:42',
        'clock_out' => '17:30',
    ])->assertCreated();

    $response = $this->postJson('/api/v1/attendance-logs/bulk', [
        'employee_ids' => [$employee->id],
        'date' => '2026-08-20',
        'status' => 'present',
    ])->assertOk();

    expect($response->json('data.marked'))->toBe(0)
        ->and($response->json('data.skipped.0.reason'))->toBe('already_recorded')
        ->and(substr((string) AttendanceLog::query()->value('clock_in'), 0, 5))->toBe('08:42');

    $this->postJson('/api/v1/attendance-logs/bulk', [
        'employee_ids' => [$employee->id],
        'date' => '2026-08-20',
        'status' => 'present',
        'overwrite' => true,
    ])->assertOk()->assertJsonPath('data.marked', 1);

    expect(substr((string) AttendanceLog::query()->value('clock_in'), 0, 5))->toBe('08:00');
});

test('marking absent clears the day so the calculator derives absent', function () {
    $employee = bulkEmployee();

    $this->postJson('/api/v1/attendance-logs/bulk', [
        'employee_ids' => [$employee->id],
        'date' => '2026-08-20',
        'status' => 'present',
    ])->assertOk();

    $this->postJson('/api/v1/attendance-logs/bulk', [
        'employee_ids' => [$employee->id],
        'date' => '2026-08-20',
        'status' => 'absent',
        'overwrite' => true,
    ])->assertOk()->assertJsonPath('data.cleared', 1);

    expect(AttendanceLog::query()->count())->toBe(0);

    $this->getJson('/api/v1/attendance?period=2026-08')
        ->assertOk()
        ->assertJsonPath('data.rows.0.days.2026-08-20.status', 'absent');
});

test('marking absent on a day with no record is reported, not counted', function () {
    $employee = bulkEmployee();

    $this->postJson('/api/v1/attendance-logs/bulk', [
        'employee_ids' => [$employee->id],
        'date' => '2026-08-20',
        'status' => 'absent',
    ])->assertOk()
        ->assertJsonPath('data.cleared', 0)
        ->assertJsonPath('data.skipped.0.reason', 'already_absent');
});

test('custom clock times override the shift defaults', function () {
    $employee = bulkEmployee();

    $this->postJson('/api/v1/attendance-logs/bulk', [
        'employee_ids' => [$employee->id],
        'date' => '2026-08-20',
        'status' => 'present',
        'clock_in' => '07:30',
        'clock_out' => '18:45',
        'note' => 'Stock count',
    ])->assertOk();

    $log = AttendanceLog::query()->firstOrFail();
    expect(substr((string) $log->clock_in, 0, 5))->toBe('07:30')
        ->and(substr((string) $log->clock_out, 0, 5))->toBe('18:45')
        ->and($log->note)->toBe('Stock count');
});

test('an employee with no shift is skipped rather than guessed at', function () {
    // No assignment, and a second active shift so the single-shift fallback is off.
    Shift::factory()->create(['name' => 'Second shift']);
    $employee = Employee::factory()->create();

    $this->postJson('/api/v1/attendance-logs/bulk', [
        'employee_ids' => [$employee->id],
        'date' => '2026-08-20',
        'status' => 'present',
    ])->assertOk()
        ->assertJsonPath('data.marked', 0)
        ->assertJsonPath('data.skipped.0.reason', 'no_shift');
});

test('a finalized period rejects bulk marking', function () {
    $employee = bulkEmployee();

    $this->postJson('/api/v1/attendance-periods/2026-08/finalize')->assertOk();

    $this->postJson('/api/v1/attendance-logs/bulk', [
        'employee_ids' => [$employee->id],
        'date' => '2026-08-20',
        'status' => 'present',
    ])->assertStatus(409);
});

test('only present, late and absent can be set by hand', function () {
    $employee = bulkEmployee();

    foreach (['on_leave', 'holiday', 'weekend', 'no_shift', 'early_exit'] as $status) {
        $this->postJson('/api/v1/attendance-logs/bulk', [
            'employee_ids' => [$employee->id],
            'date' => '2026-08-20',
            'status' => $status,
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
    }
});

test('bulk marking requires the attendance.manage permission', function () {
    $employee = bulkEmployee();

    $viewer = User::factory()->create(['tenant_id' => $this->tenant->id]);
    setPermissionsTeamId($this->tenant->id);
    $viewer->assignRole('employee');

    $this->actingAs($viewer)->postJson('/api/v1/attendance-logs/bulk', [
        'employee_ids' => [$employee->id],
        'date' => '2026-08-20',
        'status' => 'present',
    ])->assertForbidden();
});

test('employees from another tenant cannot be marked', function () {
    $mine = bulkEmployee()->id;

    $other = Tenant::factory()->create();
    app(CurrentTenant::class)->set($other);
    $theirs = Employee::factory()->create()->id;
    app(CurrentTenant::class)->set($this->tenant);

    $this->postJson('/api/v1/attendance-logs/bulk', [
        'employee_ids' => [$mine, $theirs],
        'date' => '2026-08-20',
        'status' => 'present',
    ])->assertUnprocessable()->assertJsonValidationErrors('employee_ids.1');
});
