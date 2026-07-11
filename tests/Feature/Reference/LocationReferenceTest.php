<?php

use App\Models\User;
use App\Modules\Reference\Models\Country;
use App\Modules\Reference\Models\State;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantRoleProvisioner;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\LocationSeeder;

beforeEach(function (): void {
    $this->seed(LocationSeeder::class);

    $tenant = Tenant::factory()->create();
    app(TenantRoleProvisioner::class)->provision($tenant);
    app(CurrentTenant::class)->set($tenant);
    setPermissionsTeamId($tenant->id);

    $owner = User::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => User::STATUS_ACTIVE,
    ]);
    $owner->assignRole('owner');
    $this->actingAs($owner);
});

test('countries and their states are available as dependent reference data', function (): void {
    $this->getJson('/api/v1/reference/countries')
        ->assertOk()
        ->assertJsonFragment([
            'name' => 'Nigeria',
            'code' => 'NG',
            'currency_code' => 'NGN',
        ]);

    $this->getJson('/api/v1/reference/countries/NG/states')
        ->assertOk()
        ->assertJsonFragment(['name' => 'Lagos']);

    expect(Country::query()->count())->toBeGreaterThanOrEqual(240)
        ->and(State::query()->count())->toBeGreaterThanOrEqual(5000);
});

test('onboarding rejects a state that does not belong to the selected country', function (): void {
    $this->patchJson('/api/v1/onboarding', [
        'step' => 2,
        'country' => 'Ghana',
        'country_code' => 'GH',
        'state' => 'Lagos',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('state');

    $ghanaState = State::query()
        ->whereRelation('country', 'code', 'GH')
        ->orderBy('name')
        ->firstOrFail();

    $this->patchJson('/api/v1/onboarding', [
        'step' => 2,
        'country' => 'Ghana',
        'country_code' => 'GH',
        'state' => $ghanaState->name,
        'tax_state' => $ghanaState->name,
    ])->assertOk()
        ->assertJsonPath('data.data.country_code', 'GH')
        ->assertJsonPath('data.data.state', $ghanaState->name);
});
