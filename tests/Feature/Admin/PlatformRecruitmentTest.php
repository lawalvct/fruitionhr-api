<?php

use App\Models\User;
use App\Modules\Recruitment\Models\Applicant;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\Vacancy;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;

/**
 * Careers oversight reads across every tenant on purpose — the inverse of the
 * usual isolation rule. These tests pin both halves: a super admin sees all
 * companies, and removing the scope here must not weaken it anywhere else.
 */
function seedVacancyFor(Tenant $tenant, string $title, string $status = Vacancy::STATUS_OPEN): Vacancy
{
    app(CurrentTenant::class)->set($tenant);

    return Vacancy::factory()->create(['title' => $title, 'status' => $status]);
}

test('the vacancy list spans every tenant', function (): void {
    $alpha = Tenant::factory()->create(['name' => 'Alpha Foods Ltd']);
    $beta = Tenant::factory()->create(['name' => 'Beta Logistics Ltd']);

    seedVacancyFor($alpha, 'Alpha Accountant');
    seedVacancyFor($beta, 'Beta Driver');

    // A tenant context is still set from seeding: the console must ignore it
    // rather than inherit it.
    $this->actingAs(User::factory()->platformAdministrator()->create());

    $response = $this->getJson('/api/admin/v1/recruitment/vacancies')->assertOk();
    $titles = collect($response->json('data'))->pluck('title');

    expect($titles)->toContain('Alpha Accountant')->toContain('Beta Driver');

    // Each row carries the owning company so rows stay attributable.
    $companies = collect($response->json('data'))->pluck('company.name')->unique();
    expect($companies)->toContain('Alpha Foods Ltd')->toContain('Beta Logistics Ltd');
});

test('the summary counts across the whole platform', function (): void {
    $alpha = Tenant::factory()->create();
    $beta = Tenant::factory()->create();

    seedVacancyFor($alpha, 'Open one', Vacancy::STATUS_OPEN);
    seedVacancyFor($alpha, 'Draft one', Vacancy::STATUS_DRAFT);
    seedVacancyFor($beta, 'Open two', Vacancy::STATUS_OPEN);

    $this->actingAs(User::factory()->platformAdministrator()->create());

    $this->getJson('/api/admin/v1/recruitment/vacancies')
        ->assertOk()
        ->assertJsonPath('summary.total_vacancies', 3)
        ->assertJsonPath('summary.open_vacancies', 2)
        ->assertJsonPath('summary.hiring_companies', 2);
});

test('vacancies can be filtered by company and status', function (): void {
    $alpha = Tenant::factory()->create(['name' => 'Alpha Foods Ltd']);
    $beta = Tenant::factory()->create(['name' => 'Beta Logistics Ltd']);

    seedVacancyFor($alpha, 'Alpha Open', Vacancy::STATUS_OPEN);
    seedVacancyFor($alpha, 'Alpha Closed', Vacancy::STATUS_CLOSED);
    seedVacancyFor($beta, 'Beta Open', Vacancy::STATUS_OPEN);

    $this->actingAs(User::factory()->platformAdministrator()->create());

    $byTenant = $this->getJson("/api/admin/v1/recruitment/vacancies?tenant_id={$alpha->id}")->assertOk();
    expect(collect($byTenant->json('data'))->pluck('title'))
        ->toContain('Alpha Open')->toContain('Alpha Closed')->not->toContain('Beta Open');

    $byStatus = $this->getJson('/api/admin/v1/recruitment/vacancies?status=closed')->assertOk();
    expect(collect($byStatus->json('data'))->pluck('title')->all())->toBe(['Alpha Closed']);
});

test('searching matches the hiring company name', function (): void {
    $alpha = Tenant::factory()->create(['name' => 'Alpha Foods Ltd']);
    $beta = Tenant::factory()->create(['name' => 'Beta Logistics Ltd']);

    seedVacancyFor($alpha, 'Accountant');
    seedVacancyFor($beta, 'Driver');

    $this->actingAs(User::factory()->platformAdministrator()->create());

    $response = $this->getJson('/api/admin/v1/recruitment/vacancies?search=Beta')->assertOk();

    expect(collect($response->json('data'))->pluck('title')->all())->toBe(['Driver']);
});

test('applications span every tenant and carry applicant and vacancy', function (): void {
    $alpha = Tenant::factory()->create(['name' => 'Alpha Foods Ltd']);
    $vacancy = seedVacancyFor($alpha, 'Alpha Accountant');
    $applicant = Applicant::factory()->create(['first_name' => 'Ada', 'last_name' => 'Nwosu']);
    Application::factory()->create([
        'vacancy_id' => $vacancy->id,
        'applicant_id' => $applicant->id,
        'stage' => 'shortlisted',
    ]);

    $beta = Tenant::factory()->create(['name' => 'Beta Logistics Ltd']);
    $betaVacancy = seedVacancyFor($beta, 'Beta Driver');
    $betaApplicant = Applicant::factory()->create(['first_name' => 'Chidi', 'last_name' => 'Okafor']);
    Application::factory()->create([
        'vacancy_id' => $betaVacancy->id,
        'applicant_id' => $betaApplicant->id,
    ]);

    $this->actingAs(User::factory()->platformAdministrator()->create());

    $response = $this->getJson('/api/admin/v1/recruitment/applications')->assertOk();
    $names = collect($response->json('data'))->pluck('applicant.name');

    expect($names)->toContain('Ada Nwosu')->toContain('Chidi Okafor');
    expect(collect($response->json('data'))->pluck('vacancy.title'))
        ->toContain('Alpha Accountant')->toContain('Beta Driver');
});

test('applications can be filtered by stage', function (): void {
    $tenant = Tenant::factory()->create();
    $vacancy = seedVacancyFor($tenant, 'Role');
    $shortlisted = Applicant::factory()->create(['first_name' => 'Short', 'last_name' => 'Listed']);
    $applied = Applicant::factory()->create(['first_name' => 'Just', 'last_name' => 'Applied']);

    Application::factory()->create([
        'vacancy_id' => $vacancy->id, 'applicant_id' => $shortlisted->id, 'stage' => 'shortlisted',
    ]);
    Application::factory()->create([
        'vacancy_id' => $vacancy->id, 'applicant_id' => $applied->id, 'stage' => 'applied',
    ]);

    $this->actingAs(User::factory()->platformAdministrator()->create());

    $response = $this->getJson('/api/admin/v1/recruitment/applications?stage=shortlisted')->assertOk();

    expect(collect($response->json('data'))->pluck('applicant.name')->all())->toBe(['Short Listed']);
});

test('a single vacancy is readable regardless of which tenant owns it', function (): void {
    $tenant = Tenant::factory()->create(['name' => 'Alpha Foods Ltd']);
    $vacancy = seedVacancyFor($tenant, 'Alpha Accountant');

    $this->actingAs(User::factory()->platformAdministrator()->create());

    $this->getJson("/api/admin/v1/recruitment/vacancies/{$vacancy->id}")
        ->assertOk()
        ->assertJsonPath('data.title', 'Alpha Accountant')
        ->assertJsonPath('data.company.name', 'Alpha Foods Ltd');
});

test('careers oversight is closed to tenant users and guests', function (): void {
    $this->getJson('/api/admin/v1/recruitment/vacancies')->assertUnauthorized();

    $this->actingAs(User::factory()->create());
    $this->getJson('/api/admin/v1/recruitment/vacancies')->assertForbidden();
    $this->getJson('/api/admin/v1/recruitment/applications')->assertForbidden();
});

test('removing the scope here does not leak into ordinary tenant queries', function (): void {
    $alpha = Tenant::factory()->create();
    $beta = Tenant::factory()->create();
    seedVacancyFor($alpha, 'Alpha Only');
    seedVacancyFor($beta, 'Beta Only');

    // Back in normal tenant context, isolation must still hold.
    app(CurrentTenant::class)->set($alpha);

    expect(Vacancy::query()->pluck('title')->all())->toBe(['Alpha Only']);
});
