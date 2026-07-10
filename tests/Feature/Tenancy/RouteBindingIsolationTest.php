<?php

use App\Models\User;
use App\Modules\Company\Models\Branch;
use App\Modules\Employee\Models\Employee;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantRoleProvisioner;
use App\Support\Tenancy\CurrentTenant;

/**
 * Regression: route-model binding must resolve AFTER the tenant middleware
 * sets context. Before the middleware-priority fix, {employee} bindings
 * resolved unscoped and leaked cross-tenant records (a user from tenant B
 * could GET tenant A's employee by id).
 */
function twoTenantsWithHr(): array
{
    $tenantA = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenantA);
    app(TenantRoleProvisioner::class)->provision($tenantA);
    setPermissionsTeamId($tenantA->id);
    $employeeA = Employee::factory()->create();
    $branchA = Branch::factory()->create();

    $tenantB = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenantB);
    app(TenantRoleProvisioner::class)->provision($tenantB);
    setPermissionsTeamId($tenantB->id);
    $hrB = User::factory()->create(['tenant_id' => $tenantB->id]);
    $hrB->assignRole('hr_admin');

    // Clear ambient context — the HTTP middleware must establish it.
    app(CurrentTenant::class)->forget();

    return [$employeeA, $branchA, $hrB];
}

test('a user cannot fetch another tenant\'s employee by id over HTTP', function () {
    [$employeeA, , $hrB] = twoTenantsWithHr();

    $this->actingAs($hrB)
        ->getJson("/api/v1/employees/{$employeeA->id}")
        ->assertNotFound();
});

test('a user cannot update or delete another tenant\'s records by id over HTTP', function () {
    [$employeeA, $branchA, $hrB] = twoTenantsWithHr();

    $this->actingAs($hrB)
        ->putJson("/api/v1/employees/{$employeeA->id}", ['first_name' => 'Hacked'])
        ->assertNotFound();

    $this->actingAs($hrB)
        ->deleteJson("/api/v1/branches/{$branchA->id}")
        ->assertNotFound();

    expect(Employee::withoutGlobalScopes()->find($employeeA->id)->first_name)
        ->not->toBe('Hacked');
});

test('querying a tenant-owned model without context fails closed (empty, not everything)', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    Employee::factory()->create();

    app(CurrentTenant::class)->forget();

    expect(Employee::query()->count())->toBe(0)
        ->and(Employee::withoutGlobalScopes()->count())->toBe(1);
});
