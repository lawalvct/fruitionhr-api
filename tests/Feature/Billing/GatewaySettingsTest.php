<?php

use App\Models\User;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Services\GatewaySettings;
use App\Modules\Employee\Models\Employee;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Two things gate a gateway: credentials, and the admin switch. Being
 * configured is not the same as being live.
 */
beforeEach(function (): void {
    Cache::flush();
    config()->set('services.paystack.secret_key', 'sk_test_dummy');
    config()->set('services.nomba.account_id', 'acct');
    config()->set('services.nomba.client_id', 'client');
    config()->set('services.nomba.private_key', 'secret');
});

test('with nothing configured by an admin, every credentialled gateway is offered', function (): void {
    // A fresh install must work before anyone visits the settings screen.
    expect(app(GatewaySettings::class)->usable())->toBe(['paystack', 'nomba']);
});

test('an admin can switch a gateway off and tenants stop being offered it', function (): void {
    $settings = app(GatewaySettings::class);

    $settings->update(['paystack'], 'paystack');

    expect($settings->usable())->toBe(['paystack'])
        ->and($settings->default())->toBe('paystack');
});

test('an admin can run both and choose which is preselected', function (): void {
    $settings = app(GatewaySettings::class);

    $settings->update(['paystack', 'nomba'], 'nomba');

    expect($settings->usable())->toBe(['paystack', 'nomba'])
        ->and($settings->default())->toBe('nomba');
});

test('a gateway without credentials cannot be switched on', function (): void {
    config()->set('services.nomba.account_id', null);

    app(GatewaySettings::class)->update(['nomba']);
})->throws(ValidationException::class);

test('switching every gateway off is refused', function (): void {
    // Otherwise no customer could pay at all.
    app(GatewaySettings::class)->update([]);
})->throws(ValidationException::class);

test('the default must be one of the enabled gateways', function (): void {
    app(GatewaySettings::class)->update(['paystack'], 'nomba');
})->throws(ValidationException::class);

test('a gateway that loses its credentials drops out even while switched on', function (): void {
    $settings = app(GatewaySettings::class);
    $settings->update(['paystack', 'nomba'], 'nomba');

    // Keys pulled — e.g. rotated and not yet replaced.
    config()->set('services.nomba.account_id', null);
    Cache::flush();

    expect($settings->usable())->toBe(['paystack'])
        // The default falls back rather than pointing at a dead gateway.
        ->and($settings->default())->toBe('paystack');
});

test('the admin console reports credentials and switch state separately', function (): void {
    config()->set('services.nomba.account_id', null);
    app(GatewaySettings::class)->update(['paystack'], 'paystack');

    $this->actingAs(User::factory()->platformAdministrator()->create());

    $response = $this->getJson('/api/admin/v1/billing/gateways')->assertOk();
    $rows = collect($response->json('data'))->keyBy('slug');

    expect($rows['paystack']['enabled'])->toBeTrue()
        ->and($rows['paystack']['configured'])->toBeTrue()
        ->and($rows['paystack']['is_default'])->toBeTrue()
        ->and($rows['nomba']['enabled'])->toBeFalse()
        // Reported as unconfigured so the admin knows *why* it cannot be used.
        ->and($rows['nomba']['configured'])->toBeFalse();
});

test('an admin can update the gateways over http and it is audited', function (): void {
    $this->actingAs(User::factory()->platformAdministrator()->create());

    $this->putJson('/api/admin/v1/billing/gateways', [
        'enabled' => ['nomba'],
        'default' => 'nomba',
    ])->assertOk()->assertJsonPath('meta.default', 'nomba');

    $this->assertDatabaseHas('platform_activities', ['action' => 'billing.gateways_updated']);
});

test('gateway settings are closed to tenant users and guests', function (): void {
    $this->getJson('/api/admin/v1/billing/gateways')->assertUnauthorized();

    $this->actingAs(User::factory()->create());
    $this->getJson('/api/admin/v1/billing/gateways')->assertForbidden();
    $this->putJson('/api/admin/v1/billing/gateways', ['enabled' => ['paystack']])->assertForbidden();
});

test('a tenant is only offered gateways the platform switched on', function (): void {
    app(GatewaySettings::class)->update(['paystack'], 'paystack');

    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    Employee::factory()->count(3)->create(['employment_status' => Employee::STATUS_ACTIVE]);
    Plan::factory()->create();
    $owner = User::factory()->create(['tenant_id' => $tenant->id, 'email_verified_at' => now()]);

    $this->actingAs($owner);

    $response = $this->getJson('/api/v1/billing/plans')->assertOk();

    expect(collect($response->json('meta.gateways'))->pluck('slug')->all())->toBe(['paystack']);
    expect(collect($response->json('meta.gateways'))->pluck('label')->all())->toBe(['Paystack']);
});

test('paying with a switched-off gateway is refused', function (): void {
    Http::fake();
    app(GatewaySettings::class)->update(['paystack'], 'paystack');

    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    Employee::factory()->count(3)->create(['employment_status' => Employee::STATUS_ACTIVE]);
    $plan = Plan::factory()->create(['min_employees' => 1]);
    $owner = User::factory()->create(['tenant_id' => $tenant->id, 'email_verified_at' => now()]);

    $this->actingAs($owner);
    $this->postJson('/api/v1/billing/subscribe', ['plan_id' => $plan->id])->assertOk();

    // Nomba has credentials but is switched off — it must not be usable.
    $this->postJson('/api/v1/billing/payments', ['gateway' => 'nomba'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('gateway');

    Http::assertNothingSent();
});
