<?php

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;

test('a tenant user can log in and fetch /me with roles and permissions', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'hr@company.test',
        'password' => 'Sup3r-Secret!',
    ]);

    app(\App\Modules\Tenancy\Services\TenantRoleProvisioner::class)->provision($tenant);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('hr_admin');

    $this->postJson('/api/v1/login', [
        'email' => 'hr@company.test',
        'password' => 'Sup3r-Secret!',
    ])->assertOk()->assertJsonPath('data.email', 'hr@company.test');

    $me = $this->getJson('/api/v1/me')->assertOk();

    expect($me->json('data.roles'))->toContain('hr_admin')
        ->and($me->json('data.permissions'))->toContain('payroll.process')
        ->and($me->json('data.permissions'))->not->toContain('payroll.approve')
        ->and($me->json('data.tenant.id'))->toBe($tenant->id);
});

test('login fails with wrong password', function () {
    User::factory()->create(['email' => 'x@y.test', 'password' => 'correct-password-1!']);

    $this->postJson('/api/v1/login', [
        'email' => 'x@y.test',
        'password' => 'wrong',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');

    $this->assertGuest('web');
});

test('a disabled user cannot log in', function () {
    $tenant = Tenant::factory()->create();
    User::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'off@company.test',
        'password' => 'Sup3r-Secret!',
        'status' => User::STATUS_DISABLED,
    ]);

    $this->postJson('/api/v1/login', [
        'email' => 'off@company.test',
        'password' => 'Sup3r-Secret!',
    ])->assertUnprocessable();

    $this->assertGuest('web');
});

test('a user from a suspended tenant cannot access tenant routes', function () {
    $tenant = Tenant::factory()->suspended()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    Route::middleware(['auth:sanctum', 'tenant'])->get('/api/v1/_test-tenant-route', fn () => ['ok' => true]);

    $this->actingAs($user)
        ->getJson('/api/v1/_test-tenant-route')
        ->assertForbidden();
});

test('logout invalidates the session', function () {
    $user = User::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);

    $this->actingAs($user)->postJson('/api/v1/logout')->assertOk();
    $this->assertGuest('web');
});
