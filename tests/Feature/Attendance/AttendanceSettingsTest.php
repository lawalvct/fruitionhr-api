<?php

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;

beforeEach(function (): void {
    [$this->tenant, $this->owner] = kioskOwner();
});

test('attendance settings default to both features enabled', function (): void {
    $this->getJson('/api/v1/attendance-settings')
        ->assertOk()
        ->assertJsonPath('data.self_clock_enabled', true)
        ->assertJsonPath('data.kiosk_enabled', true);

    expect($this->tenant->fresh()->attendanceSelfClockEnabled())->toBeTrue()
        ->and($this->tenant->fresh()->attendanceKioskEnabled())->toBeTrue();
});

test('attendance settings can be updated and persist', function (): void {
    $this->putJson('/api/v1/attendance-settings', [
        'self_clock_enabled' => false,
        'kiosk_enabled' => false,
    ])->assertOk()
        ->assertJsonPath('data.self_clock_enabled', false)
        ->assertJsonPath('data.kiosk_enabled', false);

    $this->getJson('/api/v1/attendance-settings')
        ->assertOk()
        ->assertJsonPath('data.self_clock_enabled', false)
        ->assertJsonPath('data.kiosk_enabled', false);

    expect($this->tenant->fresh()->attendanceSelfClockEnabled())->toBeFalse()
        ->and($this->tenant->fresh()->attendanceKioskEnabled())->toBeFalse();
});

test('attendance settings require attendance.manage', function (): void {
    $employee = User::factory()->create(['tenant_id' => $this->tenant->id]);
    setPermissionsTeamId($this->tenant->id);
    $employee->assignRole('employee');

    $this->actingAs($employee)
        ->getJson('/api/v1/attendance-settings')
        ->assertForbidden();

    $this->actingAs($employee)
        ->putJson('/api/v1/attendance-settings', ['self_clock_enabled' => false, 'kiosk_enabled' => false])
        ->assertForbidden();
});

test('attendance settings are tenant isolated', function (): void {
    $this->putJson('/api/v1/attendance-settings', [
        'self_clock_enabled' => false,
        'kiosk_enabled' => false,
    ])->assertOk();

    $otherTenant = Tenant::factory()->create();
    expect($otherTenant->attendanceSelfClockEnabled())->toBeTrue()
        ->and($otherTenant->attendanceKioskEnabled())->toBeTrue();
});
