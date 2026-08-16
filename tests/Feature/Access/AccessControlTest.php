<?php

use App\Models\User;
use App\Modules\Employee\Models\Employee;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantRoleProvisioner;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\CurrentTenant;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    app(TenantRoleProvisioner::class)->provision($this->tenant);

    setPermissionsTeamId($this->tenant->id);

    $this->owner = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'status' => User::STATUS_ACTIVE,
    ]);
    $this->owner->assignRole('owner');

    $this->employee = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'status' => User::STATUS_ACTIVE,
    ]);
    $this->employee->assignRole('employee');

    $this->actingAs($this->owner);
});

test('owner can inspect the permission catalogue roles and users', function (): void {
    app(CurrentTenant::class)->set($this->tenant);
    Employee::factory()->create([
        'user_id' => $this->employee->id,
        'first_name' => 'Ada',
        'middle_name' => 'Chidi',
        'last_name' => 'Okafor',
    ]);

    $this->getJson('/api/v1/access/permissions')
        ->assertOk()
        ->assertJsonFragment([
            'module' => 'company',
            'label' => 'Company',
        ])
        ->assertJsonFragment([
            'name' => Permissions::ROLES_MANAGE,
            'label' => 'Manage Roles',
        ]);

    $this->getJson('/api/v1/access/roles')
        ->assertOk()
        ->assertJsonFragment([
            'name' => 'owner',
            'is_system' => true,
            'is_owner' => true,
        ])
        ->assertJsonFragment([
            'name' => 'employee',
            'is_system' => true,
            'is_owner' => false,
        ]);

    $this->getJson('/api/v1/access/users')
        ->assertOk()
        ->assertJsonFragment([
            'id' => $this->owner->id,
            'is_current_user' => true,
        ])
        ->assertJsonFragment([
            'id' => $this->employee->id,
            'is_current_user' => false,
        ])
        ->assertJsonFragment([
            'name' => 'Ada Chidi Okafor',
        ]);
});

test('owner can create update and assign a custom role', function (): void {
    $created = $this->postJson('/api/v1/access/roles', [
        'name' => 'Payroll Auditor',
        'permissions' => [Permissions::PAYROLL_VIEW, Permissions::REPORTS_VIEW],
    ])->assertCreated()
        ->assertJsonPath('data.name', 'payroll_auditor')
        ->assertJsonPath('data.is_system', false)
        ->json('data');

    $this->putJson('/api/v1/access/roles/'.$created['id'], [
        'name' => 'Payroll Reviewer',
        'permissions' => [Permissions::PAYROLL_VIEW],
    ])->assertOk()
        ->assertJsonPath('data.name', 'payroll_reviewer')
        ->assertJsonPath('data.permissions.0', Permissions::PAYROLL_VIEW);

    $this->putJson('/api/v1/access/users/'.$this->employee->id.'/roles', [
        'role_ids' => [$created['id']],
    ])->assertOk()
        ->assertJsonPath('data.roles.0.name', 'payroll_reviewer');

    setPermissionsTeamId($this->tenant->id);
    expect($this->employee->refresh()->hasRole('payroll_reviewer'))->toBeTrue();
});

test('built in roles are protected and assigned custom roles cannot be deleted', function (): void {
    $ownerRole = Role::query()
        ->where('tenant_id', $this->tenant->id)
        ->where('name', 'owner')
        ->firstOrFail();

    $this->putJson('/api/v1/access/roles/'.$ownerRole->id, [
        'name' => 'owner',
        'permissions' => Permissions::all(),
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('role');

    $this->deleteJson('/api/v1/access/roles/'.$ownerRole->id)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('role');

    $custom = $this->postJson('/api/v1/access/roles', [
        'name' => 'Recruiter',
        'permissions' => [Permissions::RECRUITMENT_VIEW],
    ])->assertCreated()->json('data');

    $this->putJson('/api/v1/access/users/'.$this->employee->id.'/roles', [
        'role_ids' => [$custom['id']],
    ])->assertOk();

    $this->deleteJson('/api/v1/access/roles/'.$custom['id'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('role');
});

test('users without role management permission are forbidden', function (): void {
    $this->actingAs($this->employee)
        ->getJson('/api/v1/access/roles')
        ->assertForbidden();

    $this->actingAs($this->employee)
        ->getJson('/api/v1/access/users')
        ->assertForbidden();
});

test('access management never resolves roles or users from another tenant', function (): void {
    $otherTenant = Tenant::factory()->create();
    app(TenantRoleProvisioner::class)->provision($otherTenant);

    setPermissionsTeamId($otherTenant->id);
    $otherUser = User::factory()->create([
        'tenant_id' => $otherTenant->id,
        'status' => User::STATUS_ACTIVE,
    ]);
    $otherUser->assignRole('employee');
    $otherRole = Role::query()
        ->where('tenant_id', $otherTenant->id)
        ->where('name', 'employee')
        ->firstOrFail();

    setPermissionsTeamId($this->tenant->id);
    $this->actingAs($this->owner);

    $this->putJson('/api/v1/access/roles/'.$otherRole->id, [
        'name' => 'employee',
        'permissions' => [],
    ])->assertNotFound();

    $this->putJson('/api/v1/access/users/'.$otherUser->id.'/roles', [
        'role_ids' => [$otherRole->id],
    ])->assertNotFound();

    $this->putJson('/api/v1/access/users/'.$this->employee->id.'/roles', [
        'role_ids' => [$otherRole->id],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('role_ids');
});

test('an access manager cannot change their own roles or remove the final owner', function (): void {
    $accessManagerRole = Role::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'access_manager',
        'guard_name' => 'web',
    ]);
    $accessManagerRole->syncPermissions([Permissions::ROLES_MANAGE]);

    $accessManager = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'status' => User::STATUS_ACTIVE,
    ]);
    $accessManager->assignRole($accessManagerRole);

    $employeeRole = Role::query()
        ->where('tenant_id', $this->tenant->id)
        ->where('name', 'employee')
        ->firstOrFail();

    $this->actingAs($accessManager)
        ->putJson('/api/v1/access/users/'.$accessManager->id.'/roles', [
            'role_ids' => [$employeeRole->id],
        ])->assertUnprocessable()
        ->assertJsonValidationErrors('user');

    $this->actingAs($accessManager)
        ->putJson('/api/v1/access/users/'.$this->owner->id.'/roles', [
            'role_ids' => [$employeeRole->id],
        ])->assertUnprocessable()
        ->assertJsonValidationErrors('role_ids');
});
