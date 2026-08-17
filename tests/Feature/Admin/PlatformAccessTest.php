<?php

use App\Models\User;
use App\Modules\Auth\Services\PlatformAdministratorService;
use App\Modules\Admin\Models\PlatformRole;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Notification;

/**
 * The platform console reads across every tenant, so EnsureSuperAdmin is the
 * only thing between a tenant user and the whole platform. Each condition of
 * that gate gets its own test — a regression in any one of them is a
 * cross-tenant data leak, the worst bug this codebase can ship.
 */
/** The built-in Owner role, seeded by the platform_roles migration. */
function ownerRole(): PlatformRole
{
    return PlatformRole::query()->where('slug', PlatformRole::OWNER_SLUG)->firstOrFail();
}

$endpoints = [
    'dashboard' => '/api/admin/v1/dashboard',
    'tenants' => '/api/admin/v1/tenants',
    'administrators' => '/api/admin/v1/administrators',
    'activity' => '/api/admin/v1/activity',
];

test('guests are rejected from every platform endpoint', function () use ($endpoints): void {
    foreach ($endpoints as $url) {
        $this->getJson($url)->assertUnauthorized();
    }
});

test('an ordinary tenant user is rejected from every platform endpoint', function () use ($endpoints): void {
    $tenant = Tenant::factory()->create();
    $this->actingAs(User::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => User::STATUS_ACTIVE,
    ]));

    foreach ($endpoints as $url) {
        $this->getJson($url)
            ->assertForbidden()
            ->assertJsonPath('message', 'Super admin access required.');
    }
});

test('an administrator who has not verified their email is held out', function (): void {
    // Admins provisioned through the platform UI are auto-verified, but the
    // guard still has to hold for any account whose address is unproven —
    // e.g. one whose email was later changed.
    $this->actingAs(User::factory()->platformAdministrator(verified: false)->create());

    $this->getJson('/api/admin/v1/dashboard')->assertForbidden();
});

test('an administrator still attached to a tenant is held out', function (): void {
    $tenant = Tenant::factory()->create();
    $this->actingAs(User::factory()->platformAdministrator()->create([
        'tenant_id' => $tenant->id,
    ]));

    $this->getJson('/api/admin/v1/dashboard')->assertForbidden();
});

test('a disabled administrator is held out', function (): void {
    $this->actingAs(User::factory()->platformAdministrator()->create([
        'status' => User::STATUS_DISABLED,
    ]));

    $this->getJson('/api/admin/v1/dashboard')->assertForbidden();
});

test('a fully provisioned administrator reaches every platform endpoint', function () use ($endpoints): void {
    $this->actingAs(User::factory()->platformAdministrator()->create());

    foreach ($endpoints as $name => $url) {
        $this->getJson($url)->assertOk("Expected {$name} to be reachable.");
    }
});

test('the tenant list spans every tenant on the platform', function (): void {
    $first = Tenant::factory()->create(['name' => 'Alpha Foods Ltd']);
    $second = Tenant::factory()->create(['name' => 'Beta Logistics Ltd']);

    $this->actingAs(User::factory()->platformAdministrator()->create());

    $this->getJson('/api/admin/v1/tenants')
        ->assertOk()
        ->assertJsonFragment(['name' => $first->name])
        ->assertJsonFragment(['name' => $second->name]);
});

test('a newly created administrator is auto-verified and needs no code', function (): void {
    Notification::fake();

    $this->actingAs(User::factory()->platformAdministrator()->create());

    $this->postJson('/api/admin/v1/administrators', [
        'name' => 'Nneka Balogun',
        'email' => 'nneka@fruitionhr.test',
        'password' => 'Sup3r-Secret!',
        'password_confirmation' => 'Sup3r-Secret!',
        'platform_role_id' => ownerRole()->id,
    ])->assertCreated()->assertJsonPath('data.is_email_verified', true);

    expect(User::query()->where('email', 'nneka@fruitionhr.test')->firstOrFail()->hasVerifiedEmail())
        ->toBeTrue();

    // An existing admin vouched for the address, so no verification code goes out.
    Notification::assertNothingSent();
});

test('an administrator created by the service reaches the console with no extra step', function (): void {
    // Provisioned directly so this test holds a single identity — the point is
    // that the created account clears EnsureSuperAdmin, not how it was posted.
    $created = app(PlatformAdministratorService::class)->create([
        'name' => 'Tunde Adeyemi',
        'email' => 'tunde@fruitionhr.test',
        'password' => 'Sup3r-Secret!',
        'platform_role_id' => ownerRole()->id,
    ])['administrator'];

    $this->actingAs($created)->getJson('/api/admin/v1/dashboard')->assertOk();
});
