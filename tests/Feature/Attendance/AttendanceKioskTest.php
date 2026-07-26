<?php

use App\Models\User;
use App\Modules\Attendance\Models\AttendanceKiosk;
use App\Modules\Attendance\Models\AttendanceLog;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Support\KioskToken;
use App\Modules\Employee\Models\Employee;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantRoleProvisioner;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Carbon;

function kioskOwner(): array
{
    $tenant = Tenant::factory()->create();
    app(TenantRoleProvisioner::class)->provision($tenant);
    app(CurrentTenant::class)->set($tenant);
    setPermissionsTeamId($tenant->id);

    $owner = User::factory()->create(['tenant_id' => $tenant->id]);
    $owner->assignRole('owner');
    test()->actingAs($owner);

    return [$tenant, $owner];
}

beforeEach(function (): void {
    [$this->tenant, $this->owner] = kioskOwner();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

test('a kiosk can be created, listed, updated and deleted', function (): void {
    $create = $this->postJson('/api/v1/attendance-kiosks', [
        'name' => 'Lagos Reception',
        'location' => 'Ground floor entrance',
    ])->assertCreated();

    $id = $create->json('data.id');

    $this->getJson('/api/v1/attendance-kiosks')->assertOk()->assertJsonCount(1, 'data');

    $this->putJson("/api/v1/attendance-kiosks/{$id}", [
        'name' => 'Lagos Reception',
        'location' => 'First floor',
        'is_active' => false,
    ])->assertOk()->assertJsonPath('data.location', 'First floor');

    $this->deleteJson("/api/v1/attendance-kiosks/{$id}")->assertNoContent();
});

test('kiosk management requires attendance.manage', function (): void {
    $employee = User::factory()->create(['tenant_id' => $this->tenant->id]);
    setPermissionsTeamId($this->tenant->id);
    $employee->assignRole('employee');

    $this->actingAs($employee)
        ->postJson('/api/v1/attendance-kiosks', ['name' => 'X'])
        ->assertForbidden();
});

test('minting a kiosk token returns a short-lived token', function (): void {
    $kiosk = AttendanceKiosk::query()->create(['name' => 'Lagos Reception']);

    $response = $this->getJson("/api/v1/attendance-kiosks/{$kiosk->id}/token")
        ->assertOk();

    $token = $response->json('data.token');
    expect($token)->toBeString()->not->toBeEmpty()
        ->and($response->json('data.expires_in'))->toBe(KioskToken::TTL_SECONDS)
        ->and(KioskToken::consume($token, $this->tenant->id))->toBe($kiosk->id);
});

test('minting a kiosk token is blocked when kiosk scanning is disabled for the tenant', function (): void {
    $kiosk = AttendanceKiosk::query()->create(['name' => 'Lagos Reception']);
    $this->tenant->update(['settings' => ['attendance' => ['self_clock_enabled' => true, 'kiosk_enabled' => false]]]);

    $this->getJson("/api/v1/attendance-kiosks/{$kiosk->id}/token")
        ->assertForbidden();
});

test('clocking in with a valid kiosk token stamps the kiosk on the log', function (): void {
    Shift::factory()->create();
    $kiosk = AttendanceKiosk::query()->create(['name' => 'Lagos Reception']);

    $employeeUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
    setPermissionsTeamId($this->tenant->id);
    $employeeUser->assignRole('employee');
    $employee = Employee::factory()->create(['user_id' => $employeeUser->id]);

    $token = KioskToken::mint($this->tenant->id, $kiosk->id);

    Carbon::setTestNow(Carbon::parse('2026-07-06 08:00:00', 'Africa/Lagos'));

    $this->actingAs($employeeUser)
        ->postJson('/api/v1/self/attendance/clock-in', ['kiosk_token' => $token])
        ->assertCreated()
        ->assertJsonPath('data.kiosk', 'Lagos Reception');

    $log = AttendanceLog::query()->where('employee_id', $employee->id)->sole();
    expect($log->kiosk_id)->toBe($kiosk->id);

    // Single-use: the same token can't be reused by a second clock-in.
    expect(KioskToken::consume($token, $this->tenant->id))->toBeNull();
});

test('clocking in without a kiosk token still works and has no kiosk attribution', function (): void {
    Shift::factory()->create();
    $employeeUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
    setPermissionsTeamId($this->tenant->id);
    $employeeUser->assignRole('employee');
    Employee::factory()->create(['user_id' => $employeeUser->id]);

    Carbon::setTestNow(Carbon::parse('2026-07-06 08:00:00', 'Africa/Lagos'));

    $this->actingAs($employeeUser)
        ->postJson('/api/v1/self/attendance/clock-in')
        ->assertCreated()
        ->assertJsonPath('data.kiosk', null);
});

test('an expired or unknown kiosk token fails open — clock-in still succeeds without attribution', function (): void {
    Shift::factory()->create();
    $employeeUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
    setPermissionsTeamId($this->tenant->id);
    $employeeUser->assignRole('employee');
    Employee::factory()->create(['user_id' => $employeeUser->id]);

    Carbon::setTestNow(Carbon::parse('2026-07-06 08:00:00', 'Africa/Lagos'));

    $this->actingAs($employeeUser)
        ->postJson('/api/v1/self/attendance/clock-in', ['kiosk_token' => 'bogus-token'])
        ->assertCreated()
        ->assertJsonPath('data.kiosk', null);
});

test('a kiosk token minted for another tenant does not attribute the log', function (): void {
    Shift::factory()->create();
    $otherTenant = Tenant::factory()->create();
    app(TenantRoleProvisioner::class)->provision($otherTenant);

    app(CurrentTenant::class)->set($otherTenant);
    setPermissionsTeamId($otherTenant->id);
    $otherKiosk = AttendanceKiosk::query()->create(['name' => 'Other Co Kiosk']);
    $token = KioskToken::mint($otherTenant->id, $otherKiosk->id);

    // Switch back to the original tenant before the employee acts.
    app(CurrentTenant::class)->set($this->tenant);
    setPermissionsTeamId($this->tenant->id);

    $employeeUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
    setPermissionsTeamId($this->tenant->id);
    $employeeUser->assignRole('employee');
    Employee::factory()->create(['user_id' => $employeeUser->id]);

    Carbon::setTestNow(Carbon::parse('2026-07-06 08:00:00', 'Africa/Lagos'));

    $this->actingAs($employeeUser)
        ->postJson('/api/v1/self/attendance/clock-in', ['kiosk_token' => $token])
        ->assertCreated()
        ->assertJsonPath('data.kiosk', null);
});

test('kiosks are tenant isolated', function (): void {
    AttendanceKiosk::query()->create(['name' => 'Lagos Reception']);

    $otherTenant = Tenant::factory()->create();
    app(TenantRoleProvisioner::class)->provision($otherTenant);
    app(CurrentTenant::class)->set($otherTenant);
    setPermissionsTeamId($otherTenant->id);
    $otherOwner = User::factory()->create(['tenant_id' => $otherTenant->id]);
    $otherOwner->assignRole('owner');

    $this->actingAs($otherOwner)
        ->getJson('/api/v1/attendance-kiosks')
        ->assertOk()->assertJsonCount(0, 'data');
});
