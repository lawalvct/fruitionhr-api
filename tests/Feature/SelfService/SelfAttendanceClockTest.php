<?php

use App\Modules\Attendance\Models\AttendanceLog;
use App\Modules\Attendance\Models\AttendanceKiosk;
use App\Modules\Attendance\Models\AttendanceSummary;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Models\ShiftAssignment;
use App\Modules\Attendance\Support\KioskToken;
use App\Modules\Employee\Models\Employee;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantRoleProvisioner;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Carbon;

// 2026-07-06 is a Monday (see AttendanceTest.php) — a working day for the
// default Mon–Fri shift, so the calculator won't short-circuit to "weekend".
function freezeAt(string $lagosDateTime): void
{
    Carbon::setTestNow(Carbon::parse($lagosDateTime, 'Africa/Lagos'));
}

beforeEach(function (): void {
    [$this->tenant, $this->employeeUser, $this->hr, $this->employee] = selfServiceTenant();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

test('employee can clock in and clock out for today', function (): void {
    Shift::factory()->create(['start_time' => '08:00', 'end_time' => '17:00', 'grace_minutes' => 15]);

    freezeAt('2026-07-06 08:05:00');
    $this->postJson('/api/v1/self/attendance/clock-in')
        ->assertCreated()
        ->assertJsonPath('data.state', 'clocked_in')
        ->assertJsonPath('data.clock_in', '08:05')
        ->assertJsonPath('data.clock_out', null);

    $log = AttendanceLog::query()->where('employee_id', $this->employee->id)->sole();
    expect($log->source)->toBe(AttendanceLog::SOURCE_SELF)
        ->and($log->date->toDateString())->toBe('2026-07-06');

    freezeAt('2026-07-06 17:10:00');
    $this->postJson('/api/v1/self/attendance/clock-out')
        ->assertOk()
        ->assertJsonPath('data.state', 'clocked_out')
        ->assertJsonPath('data.clock_out', '17:10');

    $this->getJson('/api/v1/self/attendance/today')
        ->assertOk()
        ->assertJsonPath('data.state', 'clocked_out')
        ->assertJsonPath('data.clock_in', '08:05')
        ->assertJsonPath('data.clock_out', '17:10');
});

test('double clock-in is rejected', function (): void {
    Shift::factory()->create();

    freezeAt('2026-07-06 08:00:00');
    $this->postJson('/api/v1/self/attendance/clock-in')->assertCreated();

    $this->postJson('/api/v1/self/attendance/clock-in')
        ->assertUnprocessable()
        ->assertJsonPath('message', 'You already clocked in today.');
});

test('clock-out without a prior clock-in is rejected', function (): void {
    Shift::factory()->create();

    freezeAt('2026-07-06 17:00:00');
    $this->postJson('/api/v1/self/attendance/clock-out')
        ->assertUnprocessable()
        ->assertJsonPath('message', "You haven't clocked in yet today.");
});

test('clock-out records late and overtime figures matching the calculator', function (): void {
    Shift::factory()->create(['start_time' => '08:00', 'end_time' => '17:00', 'grace_minutes' => 15]);

    freezeAt('2026-07-06 08:30:00'); // 30 min late, grace 15
    $this->postJson('/api/v1/self/attendance/clock-in')->assertCreated();

    freezeAt('2026-07-06 18:00:00'); // 60 min overtime
    $this->postJson('/api/v1/self/attendance/clock-out')
        ->assertOk()
        ->assertJsonPath('data.status', 'late')
        ->assertJsonPath('data.late_minutes', 30)
        ->assertJsonPath('data.overtime_minutes', 60);
});

test('clock-in is rejected when HR already recorded attendance for today', function (): void {
    $shift = Shift::factory()->create();

    freezeAt('2026-07-06 08:00:00');
    AttendanceLog::query()->create([
        'employee_id' => $this->employee->id,
        'date' => '2026-07-06',
        'clock_in' => '08:00',
        'source' => AttendanceLog::SOURCE_MANUAL,
        'created_by' => $this->hr->id,
    ]);

    $this->postJson('/api/v1/self/attendance/clock-in')
        ->assertUnprocessable()
        ->assertJsonPath('message', "Today's attendance was already recorded by HR. Contact HR to make changes.");
});

test('clock-in and clock-out are rejected once the period is finalized', function (): void {
    Shift::factory()->create();

    freezeAt('2026-07-06 08:00:00');
    AttendanceSummary::query()->create([
        'employee_id' => $this->employee->id,
        'period' => '2026-07',
        'status' => AttendanceSummary::STATUS_FINALIZED,
        'working_days' => 0,
        'days_present' => 0,
        'days_late' => 0,
        'days_absent' => 0,
        'days_on_leave' => 0,
        'late_minutes' => 0,
        'overtime_minutes' => 0,
    ]);

    $this->postJson('/api/v1/self/attendance/clock-in')
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Attendance for this period is finalized and can no longer be edited.');
});

test('an overnight shift clock-out after midnight lands on the previous day', function (): void {
    $shift = Shift::factory()->create(['start_time' => '19:00', 'end_time' => '07:00', 'grace_minutes' => 15]);
    ShiftAssignment::query()->create([
        'employee_id' => $this->employee->id,
        'shift_id' => $shift->id,
        'effective_from' => '2026-07-01',
        'is_current' => true,
    ]);

    freezeAt('2026-07-06 19:05:00');
    $this->postJson('/api/v1/self/attendance/clock-in')->assertCreated();

    freezeAt('2026-07-07 07:10:00');
    $this->postJson('/api/v1/self/attendance/clock-out')
        ->assertOk()
        ->assertJsonPath('data.clock_out', '07:10');

    $log = AttendanceLog::query()->where('employee_id', $this->employee->id)->sole();
    expect($log->date->toDateString())->toBe('2026-07-06')
        ->and($log->clock_out)->toContain('07:10');
});

test('clocking in requires the ess.attendance.clock permission', function (): void {
    Shift::factory()->create();

    // Manually strip the permission without touching the seeded role set.
    $this->employeeUser->removeRole('employee');

    freezeAt('2026-07-06 08:00:00');
    $this->postJson('/api/v1/self/attendance/clock-in')->assertForbidden();
});

test('self clock-in data is tenant isolated', function (): void {
    Shift::factory()->create();

    freezeAt('2026-07-06 08:00:00');
    $this->postJson('/api/v1/self/attendance/clock-in')->assertCreated();

    expect(AttendanceLog::query()->where('employee_id', $this->employee->id)->count())->toBe(1);

    $otherTenant = Tenant::factory()->create();
    app(TenantRoleProvisioner::class)->provision($otherTenant);
    app(CurrentTenant::class)->set($otherTenant);
    setPermissionsTeamId($otherTenant->id);

    $otherUser = \App\Models\User::factory()->create(['tenant_id' => $otherTenant->id]);
    $otherUser->assignRole('employee');
    $otherEmployee = Employee::factory()->create(['user_id' => $otherUser->id]);

    $this->actingAs($otherUser)
        ->getJson('/api/v1/self/attendance/today')
        ->assertOk()
        ->assertJsonPath('data.state', 'not_clocked_in');

    // Under tenant B's scope, neither tenant B's own employee nor tenant A's
    // employee (created under a different tenant context) show any logs —
    // proves the BelongsToTenant global scope, not just app-level filtering.
    expect(AttendanceLog::query()->where('employee_id', $otherEmployee->id)->count())->toBe(0)
        ->and(AttendanceLog::query()->where('employee_id', $this->employee->id)->count())->toBe(0);
});

test('self clock-in is blocked when the tenant has disabled it', function (): void {
    Shift::factory()->create();
    $this->tenant->update(['settings' => ['attendance' => ['self_clock_enabled' => false, 'kiosk_enabled' => true]]]);

    freezeAt('2026-07-06 08:00:00');

    $this->postJson('/api/v1/self/attendance/clock-in')
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Self clock-in is disabled for your organisation. Contact HR.');

    expect(AttendanceLog::query()->where('employee_id', $this->employee->id)->count())->toBe(0);
});

test('QR clock in and out remain available when only ESS buttons are disabled', function (): void {
    Shift::factory()->create(['start_time' => '08:00', 'end_time' => '17:00']);
    $kiosk = AttendanceKiosk::query()->create(['name' => 'Reception']);
    $this->tenant->update(['settings' => ['attendance' => ['self_clock_enabled' => false, 'kiosk_enabled' => true]]]);
    $this->employeeUser->unsetRelation('tenant');
    $token = KioskToken::mint($this->tenant->id, $kiosk->id);

    freezeAt('2026-07-06 08:00:00');
    $this->postJson('/api/v1/self/attendance/kiosk-clock', [
        'kiosk_token' => $token,
    ])->assertOk()->assertJsonPath('data.state', 'clocked_in');

    freezeAt('2026-07-06 08:01:00');
    $this->postJson('/api/v1/self/attendance/kiosk-clock', [
        'kiosk_token' => $token,
    ])->assertOk()->assertJsonPath('data.state', 'clocked_out');
});

test('an invalid kiosk code returns an expiry message instead of the ESS button policy', function (): void {
    $this->tenant->update(['settings' => ['attendance' => ['self_clock_enabled' => false, 'kiosk_enabled' => true]]]);
    $this->employeeUser->unsetRelation('tenant');

    $this->postJson('/api/v1/self/attendance/kiosk-clock', ['kiosk_token' => 'expired-token'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.kiosk_token.0', 'This QR code has expired. Scan the current code displayed on the kiosk.');
});

test('today reflects the tenant self-clock setting', function (): void {
    Shift::factory()->create();

    $this->getJson('/api/v1/self/attendance/today')
        ->assertOk()
        ->assertJsonPath('data.self_clock_enabled', true);

    $this->tenant->update(['settings' => ['attendance' => ['self_clock_enabled' => false, 'kiosk_enabled' => true]]]);
    // actingAs() reuses this exact User object across requests within the
    // test; the middleware's first `$user->tenant` access already cached
    // the (now stale) relation, so force a fresh lookup on the next request.
    $this->employeeUser->unsetRelation('tenant');

    $this->getJson('/api/v1/self/attendance/today')
        ->assertOk()
        ->assertJsonPath('data.self_clock_enabled', false);
});
