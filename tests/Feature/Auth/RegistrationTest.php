<?php

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;

test('a company can register and gets a tenant with an owner user', function () {
    $response = $this->postJson('/api/v1/register', [
        'company_name' => 'Acme Industries',
        'name' => 'Ada Obi',
        'email' => 'ada@acme.test',
        'phone' => '+2348012345678',
        'password' => 'Sup3r-Secret!',
        'password_confirmation' => 'Sup3r-Secret!',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.email', 'ada@acme.test')
        ->assertJsonPath('data.tenant.name', 'Acme Industries')
        ->assertJsonPath('data.tenant.slug', 'acme-industries');

    $tenant = Tenant::query()->where('slug', 'acme-industries')->firstOrFail();
    $user = User::query()->where('email', 'ada@acme.test')->firstOrFail();

    expect($user->tenant_id)->toBe($tenant->id);

    setPermissionsTeamId($tenant->id);
    expect($user->hasRole('owner'))->toBeTrue()
        ->and($user->can('payroll.approve'))->toBeTrue();

    $this->assertAuthenticatedAs($user, 'web');
});

test('registration creates a welcome in-app notification for the new owner', function () {
    $this->postJson('/api/v1/register', [
        'company_name' => 'Bright Labs',
        'name' => 'Chidi Nwosu',
        'email' => 'chidi@bright.test',
        'password' => 'Sup3r-Secret!',
        'password_confirmation' => 'Sup3r-Secret!',
    ])->assertCreated();

    $user = User::query()->where('email', 'chidi@bright.test')->firstOrFail();
    $notification = $user->notifications()->first();

    expect($user->notifications()->count())->toBe(1)
        ->and($notification->data['title'])->toContain('Chidi')
        ->and($notification->data['body'])->toContain('Bright Labs')
        ->and($notification->data['type'])->toBe('success')
        ->and($notification->data['action_url'])->toBe('/onboarding')
        ->and($notification->read_at)->toBeNull();
});

test('registration rejects duplicate email', function () {
    User::factory()->create(['email' => 'taken@acme.test']);

    $this->postJson('/api/v1/register', [
        'company_name' => 'Acme',
        'name' => 'Ada',
        'email' => 'taken@acme.test',
        'password' => 'Sup3r-Secret!',
        'password_confirmation' => 'Sup3r-Secret!',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');
});

test('two companies with the same name get distinct slugs', function () {
    foreach ([1, 2] as $i) {
        $this->postJson('/api/v1/register', [
            'company_name' => 'Duplicate Ltd',
            'name' => "User {$i}",
            'email' => "user{$i}@dup.test",
            'password' => 'Sup3r-Secret!',
            'password_confirmation' => 'Sup3r-Secret!',
        ])->assertCreated();

        auth('web')->logout();
    }

    expect(Tenant::query()->pluck('slug')->all())
        ->toBe(['duplicate-ltd', 'duplicate-ltd-2']);
});
