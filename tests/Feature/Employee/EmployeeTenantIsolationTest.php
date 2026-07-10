<?php

use App\Models\User;
use App\Modules\Employee\Models\Employee;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantRoleProvisioner;
use App\Support\Tenancy\CurrentTenant;

test('tenant b cannot see tenant a employee', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    app(TenantRoleProvisioner::class)->provision($tenantA);
    app(TenantRoleProvisioner::class)->provision($tenantB);

    app(CurrentTenant::class)->set($tenantA);
    $employee = Employee::factory()->create([
        'employee_number' => 'EMP-0001',
        'first_name' => 'Hidden',
        'last_name' => 'Employee',
    ]);

    app(CurrentTenant::class)->set($tenantB);
    setPermissionsTeamId($tenantB->id);
    $userB = User::factory()->create([
        'tenant_id' => $tenantB->id,
        'status' => User::STATUS_ACTIVE,
    ]);
    $userB->assignRole('owner');

    $this->actingAs($userB);

    $this->getJson('/api/v1/employees')
        ->assertOk()
        ->assertJsonMissing(['employee_number' => 'EMP-0001']);

    $this->getJson("/api/v1/employees/{$employee->id}")
        ->assertNotFound();

    $this->putJson("/api/v1/employees/{$employee->id}", [
        'employee_number' => 'EMP-0001',
        'first_name' => 'Changed',
        'last_name' => 'Employee',
        'employment_status' => 'active',
        'hired_at' => '2026-01-01',
    ])->assertNotFound();
});
