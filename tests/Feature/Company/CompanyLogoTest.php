<?php

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantRoleProvisioner;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    app(TenantRoleProvisioner::class)->provision($this->tenant);
    app(CurrentTenant::class)->set($this->tenant);
    setPermissionsTeamId($this->tenant->id);

    $this->owner = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'status' => User::STATUS_ACTIVE,
    ]);
    $this->owner->assignRole('owner');
});

test('the company owner can upload, serve, and remove a logo', function (): void {
    $this->actingAs($this->owner);

    $this->postJson('/api/v1/company/logo', [
        'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
    ])->assertOk()->assertJsonPath('data.logo_url', '/api/v1/company/logo');

    $this->tenant->refresh();
    expect($this->tenant->logo_path)->not->toBeNull();
    Storage::disk('local')->assertExists($this->tenant->logo_path);

    $this->get('/api/v1/company/logo')->assertOk();

    $this->deleteJson('/api/v1/company/logo')
        ->assertOk()
        ->assertJsonPath('data.logo_url', null);

    expect($this->tenant->refresh()->logo_path)->toBeNull();
});

test('logo upload rejects non-image files', function (): void {
    $this->actingAs($this->owner);

    $this->postJson('/api/v1/company/logo', [
        'logo' => UploadedFile::fake()->create('logo.pdf', 100, 'application/pdf'),
    ])->assertUnprocessable()->assertJsonValidationErrors('logo');
});

test('a user without company.manage cannot upload a logo', function (): void {
    $staff = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'status' => User::STATUS_ACTIVE,
    ]);
    $staff->assignRole('employee');
    $this->actingAs($staff);

    $this->postJson('/api/v1/company/logo', [
        'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
    ])->assertForbidden();
});

test('any authenticated tenant user can view the logo, even without company.view', function (): void {
    $this->actingAs($this->owner);
    $this->postJson('/api/v1/company/logo', [
        'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
    ])->assertOk();

    $staff = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'status' => User::STATUS_ACTIVE,
    ]);
    $staff->assignRole('employee');
    expect($staff->can('company.view'))->toBeFalse();

    $this->actingAs($staff);
    $this->get('/api/v1/company/logo')->assertOk();
});
