<?php

use App\Models\User;
use App\Modules\Company\Models\Branch;
use App\Modules\Company\Models\Department;
use App\Modules\Company\Models\EmploymentType;
use App\Modules\Company\Models\HolidayCalendar;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Payroll\Models\SalaryComponent;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantRoleProvisioner;
use App\Support\Tenancy\CurrentTenant;

function actingAsOnboardingOwner(): array
{
    $tenant = Tenant::factory()->create();
    app(TenantRoleProvisioner::class)->provision($tenant);
    app(CurrentTenant::class)->set($tenant);
    setPermissionsTeamId($tenant->id);

    $owner = User::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => User::STATUS_ACTIVE,
    ]);
    $owner->assignRole('owner');
    test()->actingAs($owner);

    return [$tenant, $owner];
}

beforeEach(function (): void {
    [$this->tenant, $this->owner] = actingAsOnboardingOwner();
});

test('an owner can save and resume onboarding progress', function (): void {
    $this->patchJson('/api/v1/onboarding', [
        'step' => 2,
        'company_name' => 'Fruition Services',
        'industry' => 'Professional services',
        'company_size' => '11-50',
        'state' => 'Lagos',
    ])->assertOk()
        ->assertJsonPath('data.status', Tenant::ONBOARDING_IN_PROGRESS)
        ->assertJsonPath('data.step', 2)
        ->assertJsonPath('data.data.industry', 'Professional services');

    $this->getJson('/api/v1/onboarding')
        ->assertOk()
        ->assertJsonPath('data.data.company_name', 'Fruition Services');

    expect($this->tenant->fresh()->name)->toBe('Fruition Services');
});

test('skipping onboarding provisions editable starter data only once', function (): void {
    $payload = [
        'step' => 2,
        'country' => 'Ghana',
        'address' => '1 Marina Road',
        'city' => 'Lagos',
        'state' => 'Lagos',
    ];
    $this->patchJson('/api/v1/onboarding', $payload)->assertOk();

    $this->postJson('/api/v1/onboarding/skip')
        ->assertOk()
        ->assertJsonPath('data.status', Tenant::ONBOARDING_SKIPPED);

    expect(Branch::query()->count())->toBe(1)
        ->and(Department::query()->count())->toBe(4)
        ->and(EmploymentType::query()->count())->toBe(4)
        ->and(LeaveType::query()->count())->toBe(4)
        ->and(SalaryComponent::query()->count())->toBe(4)
        ->and(Branch::query()->first()->address)->toBe('1 Marina Road')
        ->and(HolidayCalendar::query()->first()->name)->toBe('Ghana Public Holidays');

    $this->postJson('/api/v1/onboarding/skip')->assertOk();

    expect(Branch::query()->count())->toBe(1)
        ->and(Department::query()->count())->toBe(4)
        ->and(EmploymentType::query()->count())->toBe(4)
        ->and(LeaveType::query()->count())->toBe(4)
        ->and(SalaryComponent::query()->count())->toBe(4);
});

test('a skipped owner can return and complete onboarding later', function (): void {
    $this->postJson('/api/v1/onboarding/skip')->assertOk();

    $this->patchJson('/api/v1/onboarding', [
        'step' => 2,
        'pay_frequency' => 'monthly',
        'pay_day' => 25,
        'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
    ])->assertOk()->assertJsonPath('data.status', Tenant::ONBOARDING_IN_PROGRESS);

    $this->postJson('/api/v1/onboarding/complete')
        ->assertOk()
        ->assertJsonPath('data.status', Tenant::ONBOARDING_COMPLETED);

    expect($this->tenant->fresh()->onboarding_completed_at)->not->toBeNull();
});
