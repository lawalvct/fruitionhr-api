<?php

use App\Models\User;
use App\Modules\Attendance\Models\AttendanceSummary;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Models\ShiftAssignment;
use App\Modules\Employee\Models\Employee;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantRoleProvisioner;
use App\Support\Tenancy\CurrentTenant;

function attendanceOwner(): array
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
    [$this->tenant, $this->user] = attendanceOwner();
});

test('a shift can be created, listed, updated and deleted', function () {
    $create = $this->postJson('/api/v1/shifts', [
        'name' => 'Day Shift',
        'start_time' => '08:00',
        'end_time' => '17:00',
        'grace_minutes' => 15,
        'working_days' => [1, 2, 3, 4, 5],
    ])->assertCreated();

    $id = $create->json('data.id');
    expect($create->json('data.working_days'))->toBe([1, 2, 3, 4, 5]);

    $this->getJson('/api/v1/shifts')->assertOk()->assertJsonCount(1, 'data');

    $this->putJson("/api/v1/shifts/{$id}", [
        'name' => 'Day Shift',
        'start_time' => '09:00',
        'end_time' => '18:00',
        'grace_minutes' => 10,
        'working_days' => [1, 2, 3, 4, 5, 6],
    ])->assertOk()->assertJsonPath('data.start_time', '09:00');

    $this->deleteJson("/api/v1/shifts/{$id}")->assertNoContent();
});

test('shift validation rejects equal start and end times and invalid weekdays', function () {
    $this->postJson('/api/v1/shifts', [
        'name' => 'Bad',
        'start_time' => '17:00',
        'end_time' => '17:00',
        'working_days' => [1, 9],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['end_time', 'working_days.1']);
});

test('an overnight shift can be created', function () {
    $this->postJson('/api/v1/shifts', [
        'name' => 'Night Shift',
        'start_time' => '19:00',
        'end_time' => '07:00',
        'working_days' => [1, 2, 3, 4, 5],
    ])->assertCreated()
        ->assertJsonPath('data.start_time', '19:00')
        ->assertJsonPath('data.end_time', '07:00');
});

test('shift assignments can be listed and changed', function () {
    $employee = Employee::factory()->create();
    $dayShift = Shift::factory()->create(['name' => 'Day Shift']);
    $nightShift = Shift::factory()->create(['name' => 'Night Shift', 'start_time' => '19:00', 'end_time' => '07:00']);

    $this->getJson('/api/v1/shift-assignments')
        ->assertOk()
        ->assertJsonPath('data.0.employee.id', $employee->id)
        ->assertJsonPath('data.0.assignment', null);

    $this->postJson('/api/v1/shift-assignments', [
        'employee_id' => $employee->id,
        'shift_id' => $dayShift->id,
        'effective_from' => '2026-07-01',
    ])->assertCreated();

    $this->postJson('/api/v1/shift-assignments', [
        'employee_id' => $employee->id,
        'shift_id' => $nightShift->id,
        'effective_from' => '2026-08-01',
    ])->assertCreated();

    $this->getJson('/api/v1/shift-assignments')
        ->assertOk()
        ->assertJsonPath('data.0.assignment.shift.id', $nightShift->id)
        ->assertJsonPath('data.0.assignment.effective_from', '2026-08-01');

    $this->deleteJson("/api/v1/shifts/{$nightShift->id}")
        ->assertStatus(409);

    expect(ShiftAssignment::query()->where('employee_id', $employee->id)->count())->toBe(2)
        ->and(ShiftAssignment::query()->where('shift_id', $dayShift->id)->value('is_current'))->toBeFalse();
});

test('attendance uses the shift effective on each day', function () {
    $employee = Employee::factory()->create();
    $dayShift = Shift::factory()->create([
        'start_time' => '08:00',
        'end_time' => '17:00',
        'grace_minutes' => 15,
    ]);
    $nightShift = Shift::factory()->create([
        'start_time' => '19:00',
        'end_time' => '07:00',
        'grace_minutes' => 15,
    ]);

    ShiftAssignment::query()->create([
        'employee_id' => $employee->id,
        'shift_id' => $dayShift->id,
        'effective_from' => '2026-07-01',
        'effective_to' => '2026-07-15',
        'is_current' => false,
    ]);
    ShiftAssignment::query()->create([
        'employee_id' => $employee->id,
        'shift_id' => $nightShift->id,
        'effective_from' => '2026-07-15',
        'is_current' => true,
    ]);

    $this->postJson('/api/v1/attendance-logs', [
        'employee_id' => $employee->id,
        'date' => '2026-07-14',
        'clock_in' => '08:30',
        'clock_out' => '17:00',
    ])->assertCreated();
    $this->postJson('/api/v1/attendance-logs', [
        'employee_id' => $employee->id,
        'date' => '2026-07-16',
        'clock_in' => '19:30',
        'clock_out' => '07:00',
    ])->assertCreated();

    $this->getJson('/api/v1/attendance?period=2026-07')
        ->assertOk()
        ->assertJsonPath('data.rows.0.days.2026-07-14.late_minutes', 30)
        ->assertJsonPath('data.rows.0.days.2026-07-16.late_minutes', 30);
});

test('an attendance import template can be downloaded', function () {
    $this->get('/api/v1/attendance/import-template.xlsx?period=2026-07')
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

test('a manual attendance log can be recorded and updates the grid', function () {
    $shift = Shift::factory()->create();
    $employee = Employee::factory()->create();
    ShiftAssignment::query()->create([
        'employee_id' => $employee->id,
        'shift_id' => $shift->id,
        'effective_from' => '2026-07-01',
        'is_current' => true,
    ]);

    $this->postJson('/api/v1/attendance-logs', [
        'employee_id' => $employee->id,
        'date' => '2026-07-06', // a Monday
        'clock_in' => '08:30',  // 30 min late, grace 15 → late
        'clock_out' => '17:00',
    ])->assertCreated();

    $grid = $this->getJson('/api/v1/attendance?period=2026-07')->assertOk();

    expect($grid->json('data.is_finalized'))->toBeFalse()
        ->and($grid->json('data.rows.0.days.2026-07-06.status'))->toBe('late')
        ->and($grid->json('data.rows.0.days.2026-07-06.late_minutes'))->toBe(30);
});

test('finalizing a period locks summaries and blocks further edits', function () {
    $shift = Shift::factory()->create();
    $employee = Employee::factory()->create();
    ShiftAssignment::query()->create([
        'employee_id' => $employee->id,
        'shift_id' => $shift->id,
        'effective_from' => '2026-07-01',
        'is_current' => true,
    ]);

    $this->postJson('/api/v1/attendance-logs', [
        'employee_id' => $employee->id,
        'date' => '2026-07-06',
        'clock_in' => '08:00',
        'clock_out' => '17:00',
    ])->assertCreated();

    $this->postJson('/api/v1/attendance-periods/2026-07/finalize')
        ->assertOk()
        ->assertJsonPath('data.finalized', 1);

    $summary = AttendanceSummary::query()->where('employee_id', $employee->id)->firstOrFail();
    expect($summary->status)->toBe(AttendanceSummary::STATUS_FINALIZED)
        ->and($summary->days_present)->toBe(1);

    // Editing a finalized period is rejected.
    $this->postJson('/api/v1/attendance-logs', [
        'employee_id' => $employee->id,
        'date' => '2026-07-07',
        'clock_in' => '08:00',
        'clock_out' => '17:00',
    ])->assertStatus(409);
});

test('finalize requires the attendance.approve permission', function () {
    $manager = User::factory()->create(['tenant_id' => $this->tenant->id]);
    setPermissionsTeamId($this->tenant->id);
    $manager->assignRole('employee'); // no attendance.approve

    $this->actingAs($manager)
        ->postJson('/api/v1/attendance-periods/2026-07/finalize')
        ->assertForbidden();
});

test('attendance data is tenant isolated', function () {
    $shift = Shift::factory()->create();
    $employee = Employee::factory()->create();
    ShiftAssignment::query()->create([
        'employee_id' => $employee->id, 'shift_id' => $shift->id,
        'effective_from' => '2026-07-01', 'is_current' => true,
    ]);
    $this->postJson('/api/v1/attendance-logs', [
        'employee_id' => $employee->id, 'date' => '2026-07-06',
        'clock_in' => '08:00', 'clock_out' => '17:00',
    ])->assertCreated();

    // Another tenant sees no shifts and an empty grid.
    $otherTenant = Tenant::factory()->create();
    app(TenantRoleProvisioner::class)->provision($otherTenant);
    app(CurrentTenant::class)->set($otherTenant);
    setPermissionsTeamId($otherTenant->id);
    $otherOwner = User::factory()->create(['tenant_id' => $otherTenant->id]);
    $otherOwner->assignRole('owner');

    $this->actingAs($otherOwner)->getJson('/api/v1/shifts')
        ->assertOk()->assertJsonCount(0, 'data');

    $this->actingAs($otherOwner)->getJson('/api/v1/attendance?period=2026-07')
        ->assertOk()->assertJsonPath('data.rows', []);
});
