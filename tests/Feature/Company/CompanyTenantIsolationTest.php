<?php

use App\Models\User;
use App\Modules\Company\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantRoleProvisioner;
use App\Support\Tenancy\CurrentTenant;

test('tenant b cannot see or update tenant a branch', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    app(TenantRoleProvisioner::class)->provision($tenantA);
    app(TenantRoleProvisioner::class)->provision($tenantB);

    app(CurrentTenant::class)->set($tenantA);
    $branch = Branch::factory()->create(['name' => 'Tenant A HQ', 'code' => 'A-HQ']);

    app(CurrentTenant::class)->set($tenantB);
    setPermissionsTeamId($tenantB->id);
    $userB = User::factory()->create([
        'tenant_id' => $tenantB->id,
        'status' => User::STATUS_ACTIVE,
    ]);
    $userB->assignRole('owner');

    $this->actingAs($userB);

    $this->getJson('/api/v1/branches')
        ->assertOk()
        ->assertJsonMissing(['name' => 'Tenant A HQ']);

    $this->putJson("/api/v1/branches/{$branch->id}", [
        'name' => 'Stolen HQ',
    ])->assertNotFound();

    app(CurrentTenant::class)->set($tenantA);
    expect($branch->refresh()->name)->toBe('Tenant A HQ');
});
