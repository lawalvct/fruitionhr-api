<?php

use App\Models\User;
use App\Modules\Employee\Models\Employee;
use App\Modules\Payroll\Models\EmployeeSalary;
use App\Modules\Payroll\Models\SalaryComponent;
use App\Modules\Payroll\Models\SalaryStructure;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantRoleProvisioner;
use App\Support\Tenancy\CurrentTenant;

function salaryTenant(): array
{
    $tenant = Tenant::factory()->create();
    app(TenantRoleProvisioner::class)->provision($tenant);
    app(CurrentTenant::class)->set($tenant);
    setPermissionsTeamId($tenant->id);

    $owner = User::factory()->create(['tenant_id' => $tenant->id]);
    $owner->assignRole('owner'); // owner has view_salary + manage_salary

    return [$tenant, $owner];
}

beforeEach(function () {
    [$this->tenant, $this->owner] = salaryTenant();
    $this->actingAs($this->owner);
});

test('salary components can be created and listed', function () {
    $this->postJson('/api/v1/salary-components', [
        'name' => 'Housing Allowance', 'code' => 'HOU', 'type' => 'earning',
        'calc_type' => 'percent_of_basic', 'percent' => 25, 'is_pensionable' => true,
    ])->assertCreated()->assertJsonPath('data.percent', 25);

    $this->getJson('/api/v1/salary-components')->assertOk()->assertJsonPath('data.0.code', 'HOU');
});

test('a salary structure can bundle components with overrides', function () {
    $housing = SalaryComponent::factory()->create(['code' => 'HOU', 'is_pensionable' => true]);
    $transport = SalaryComponent::factory()->create(['code' => 'TRA']);

    $response = $this->postJson('/api/v1/salary-structures', [
        'name' => 'Senior Structure',
        'components' => [
            ['salary_component_id' => $housing->id, 'amount' => 5000000],
            ['salary_component_id' => $transport->id, 'percent' => 10],
        ],
    ])->assertCreated();

    expect($response->json('data.components'))->toHaveCount(2);
});

test('assigning a salary returns the resolved breakdown in kobo', function () {
    $housing = SalaryComponent::factory()->create(['code' => 'HOU', 'is_pensionable' => true, 'is_taxable' => true]);
    $structure = SalaryStructure::factory()->create();
    $structure->components()->create(['salary_component_id' => $housing->id, 'amount' => 5000000]); // ₦50k

    $employee = Employee::factory()->create();

    $response = $this->postJson("/api/v1/employees/{$employee->id}/salary", [
        'basic_salary' => 20000000, // ₦200,000
        'salary_structure_id' => $structure->id,
        'effective_from' => '2026-07-01',
    ])->assertCreated();

    expect($response->json('data.breakdown.basic'))->toBe(20000000)
        ->and($response->json('data.breakdown.gross'))->toBe(25000000)
        ->and($response->json('data.breakdown.pensionable_pay'))->toBe(25000000);

    // Reassigning closes the previous record (history preserved).
    $this->postJson("/api/v1/employees/{$employee->id}/salary", [
        'basic_salary' => 25000000, 'salary_structure_id' => $structure->id, 'effective_from' => '2026-08-01',
    ])->assertCreated();

    expect(EmployeeSalary::query()->where('employee_id', $employee->id)->count())->toBe(2)
        ->and(EmployeeSalary::query()->where('employee_id', $employee->id)->where('is_current', true)->count())->toBe(1);
});

test('salary endpoints are forbidden without the view_salary permission', function () {
    // A manager has employees.view but NOT employees.view_salary.
    $manager = User::factory()->create(['tenant_id' => $this->tenant->id]);
    setPermissionsTeamId($this->tenant->id);
    $manager->assignRole('manager');

    $employee = Employee::factory()->create();

    $this->actingAs($manager)->getJson('/api/v1/salary-components')->assertForbidden();
    $this->actingAs($manager)->getJson("/api/v1/employees/{$employee->id}/salary")->assertForbidden();
    $this->actingAs($manager)->postJson("/api/v1/employees/{$employee->id}/salary", [
        'basic_salary' => 10000000, 'effective_from' => '2026-07-01',
    ])->assertForbidden();
});

test('salary data is tenant isolated', function () {
    SalaryComponent::factory()->create(['code' => 'HOU']);

    $otherTenant = Tenant::factory()->create();
    app(TenantRoleProvisioner::class)->provision($otherTenant);
    app(CurrentTenant::class)->set($otherTenant);
    setPermissionsTeamId($otherTenant->id);
    $otherOwner = User::factory()->create(['tenant_id' => $otherTenant->id]);
    $otherOwner->assignRole('owner');

    $this->actingAs($otherOwner)->getJson('/api/v1/salary-components')
        ->assertOk()->assertJsonCount(0, 'data');
});
