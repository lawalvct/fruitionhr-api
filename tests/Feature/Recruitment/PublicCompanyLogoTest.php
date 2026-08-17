<?php

use App\Modules\Recruitment\Models\Vacancy;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * A company's logo becomes public only while it is advertising. Anything
 * looser would let anyone discover, by guessing slugs, which companies use
 * FruitionHR and what their branding is.
 */
function companyWithLogo(array $vacancyState = []): Tenant
{
    Storage::fake('local');

    $tenant = Tenant::factory()->create([
        'name' => 'Alpha Foods Ltd',
        'slug' => 'alpha-foods',
        'status' => Tenant::STATUS_ACTIVE,
    ]);

    $path = UploadedFile::fake()->image('logo.png')->store("tenants/{$tenant->id}/branding", 'local');
    $tenant->forceFill(['logo_path' => $path])->save();

    app(CurrentTenant::class)->set($tenant);
    Vacancy::factory()->create(array_merge([
        'status' => Vacancy::STATUS_OPEN,
        'visibility' => Vacancy::VISIBILITY_PUBLIC,
        'public_slug' => 'alpha-accountant',
    ], $vacancyState));

    return $tenant;
}

test('a company advertising publicly serves its logo to anyone', function (): void {
    companyWithLogo();

    $this->get('/api/v1/careers/companies/alpha-foods/logo')
        ->assertOk()
        ->assertHeader('cache-control', 'max-age=86400, public');
});

test('the careers listing says which companies have a logo', function (): void {
    companyWithLogo();

    $row = $this->getJson('/api/v1/careers')->assertOk()->json('data.0');

    expect($row['company']['slug'])->toBe('alpha-foods')
        ->and($row['company']['has_logo'])->toBeTrue();
});

test('a company with no logo is reported as such rather than 404ing the page', function (): void {
    Storage::fake('local');
    $tenant = Tenant::factory()->create(['slug' => 'no-logo-ltd', 'status' => Tenant::STATUS_ACTIVE]);
    app(CurrentTenant::class)->set($tenant);
    Vacancy::factory()->create([
        'status' => Vacancy::STATUS_OPEN,
        'visibility' => Vacancy::VISIBILITY_PUBLIC,
        'public_slug' => 'some-role',
    ]);

    expect($this->getJson('/api/v1/careers')->json('data.0.company.has_logo'))->toBeFalse();

    $this->get('/api/v1/careers/companies/no-logo-ltd/logo')->assertNotFound();
});

test('a company that is not advertising keeps its logo private', function (): void {
    // Private vacancy only — the company uses FruitionHR but advertises nothing.
    companyWithLogo(['visibility' => Vacancy::VISIBILITY_PRIVATE]);

    $this->get('/api/v1/careers/companies/alpha-foods/logo')->assertNotFound();
});

test('a closed vacancy takes the logo private again', function (): void {
    companyWithLogo(['status' => Vacancy::STATUS_CLOSED]);

    $this->get('/api/v1/careers/companies/alpha-foods/logo')->assertNotFound();
});

test('a vacancy whose window has passed does not keep the logo public', function (): void {
    companyWithLogo(['closes_at' => now()->subWeek()->toDateString()]);

    $this->get('/api/v1/careers/companies/alpha-foods/logo')->assertNotFound();
});

test('a suspended company does not serve a logo', function (): void {
    $tenant = companyWithLogo();
    $tenant->forceFill(['status' => Tenant::STATUS_SUSPENDED])->save();

    $this->get('/api/v1/careers/companies/alpha-foods/logo')->assertNotFound();
});

test('an unknown company slug is a plain 404', function (): void {
    Storage::fake('local');

    $this->get('/api/v1/careers/companies/does-not-exist/logo')->assertNotFound();
});
