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

test('basic salary is reserved for employee compensation', function () {
    $this->postJson('/api/v1/salary-components', [
        'name' => 'Basic Salary', 'code' => 'BASIC', 'type' => 'earning',
        'calc_type' => 'fixed', 'is_taxable' => true, 'is_pensionable' => true,
    ])->assertUnprocessable()->assertJsonValidationErrors('name');
});

test('employer contribution components are accepted and normalized', function () {
    $this->postJson('/api/v1/salary-components', [
        'name' => 'Company Pension', 'code' => 'CP001',
        'type' => SalaryComponent::TYPE_EMPLOYER_CONTRIBUTOR,
        'calc_type' => 'percent_of_basic', 'percent' => 10,
        'is_taxable' => true, 'is_pensionable' => true,
    ])->assertCreated()
        ->assertJsonPath('data.type', SalaryComponent::TYPE_EMPLOYER_CONTRIBUTOR)
        ->assertJsonPath('data.is_taxable', false)
        ->assertJsonPath('data.is_pensionable', false);
});

test('fringe benefit components are accepted and normalized as taxable non-cash benefits', function () {
    $this->postJson('/api/v1/salary-components', [
        'name' => 'Company Car', 'code' => 'CAR',
        'type' => SalaryComponent::TYPE_FRINGE_BENEFIT,
        'calc_type' => 'fixed',
        'is_taxable' => false, 'is_pensionable' => true,
    ])->assertCreated()
        ->assertJsonPath('data.type', SalaryComponent::TYPE_FRINGE_BENEFIT)
        ->assertJsonPath('data.is_taxable', true)
        ->assertJsonPath('data.is_pensionable', false);
});

test('salary components can be updated and deleted when unused', function () {
    $component = SalaryComponent::factory()->create(['name' => 'Transport', 'code' => 'TRA']);

    $this->putJson("/api/v1/salary-components/{$component->id}", [
        'name' => 'Transport Allowance', 'code' => 'TRAN',
        'type' => 'earning', 'calc_type' => 'fixed',
        'is_taxable' => true, 'is_pensionable' => false, 'is_active' => false,
    ])->assertOk()
        ->assertJsonPath('data.name', 'Transport Allowance')
        ->assertJsonPath('data.is_active', false);

    $this->deleteJson("/api/v1/salary-components/{$component->id}")->assertNoContent();
});

test('components used by a salary structure cannot be deleted', function () {
    $component = SalaryComponent::factory()->create();
    $structure = SalaryStructure::factory()->create();
    $structure->components()->create(['salary_component_id' => $component->id, 'amount' => 100_000]);

    $this->deleteJson("/api/v1/salary-components/{$component->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('component');
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

test('basic salary components cannot be added to salary structures', function () {
    $legacyBasic = SalaryComponent::factory()->create(['name' => 'Basic Salary', 'code' => 'BASIC']);

    $this->postJson('/api/v1/salary-structures', [
        'name' => 'Invalid Structure',
        'components' => [['salary_component_id' => $legacyBasic->id, 'amount' => 5_000_000]],
    ])->assertUnprocessable()->assertJsonValidationErrors('components');
});

test('salary structures can be updated and deleted when unassigned', function () {
    $component = SalaryComponent::factory()->create();
    $structure = SalaryStructure::factory()->create(['name' => 'Standard']);
    $structure->components()->create(['salary_component_id' => $component->id, 'amount' => 100_000]);

    $this->putJson("/api/v1/salary-structures/{$structure->id}", [
        'name' => 'Executive', 'description' => 'Executive compensation', 'is_active' => false,
        'components' => [['salary_component_id' => $component->id, 'amount' => 200_000]],
    ])->assertOk()
        ->assertJsonPath('data.name', 'Executive')
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.components.0.amount', 200_000);

    $this->deleteJson("/api/v1/salary-structures/{$structure->id}")->assertNoContent();
});

test('structures assigned to an employee cannot be deleted', function () {
    $structure = SalaryStructure::factory()->create();
    $employee = Employee::factory()->create();
    EmployeeSalary::query()->create([
        'employee_id' => $employee->id,
        'salary_structure_id' => $structure->id,
        'basic_salary' => 100_000,
        'effective_from' => '2026-07-01',
        'is_current' => true,
        'created_by' => $this->owner->id,
    ]);

    $this->deleteJson("/api/v1/salary-structures/{$structure->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('structure');
});

test('assigning a salary returns the resolved breakdown in kobo', function () {
    $housing = SalaryComponent::factory()->create(['code' => 'HOU', 'is_pensionable' => true, 'is_taxable' => true]);
    $legacyBasic = SalaryComponent::factory()->create(['name' => 'Basic Salary', 'code' => 'BASIC']);
    $structure = SalaryStructure::factory()->create();
    $structure->components()->create(['salary_component_id' => $housing->id, 'amount' => 5000000]); // ₦50k
    $structure->components()->create(['salary_component_id' => $legacyBasic->id, 'amount' => 6000000]); // ignored legacy basic

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

test('employee salary supports structure overrides and employee-only components', function () {
    $transport = SalaryComponent::factory()->create(['name' => 'Transport', 'code' => 'TRA']);
    $housing = SalaryComponent::factory()->create(['name' => 'Housing', 'code' => 'HOU']);
    $meal = SalaryComponent::factory()->create(['name' => 'Meal', 'code' => 'MEAL']);
    $structure = SalaryStructure::factory()->create();
    $structure->components()->create(['salary_component_id' => $transport->id, 'amount' => 500_000]);
    $structure->components()->create(['salary_component_id' => $housing->id, 'amount' => 1_000_000]);
    $employee = Employee::factory()->create();

    $response = $this->postJson("/api/v1/employees/{$employee->id}/salary", [
        'basic_salary' => 10_000_000,
        'salary_structure_id' => $structure->id,
        'effective_from' => '2026-07-01',
        'component_overrides' => [
            ['salary_component_id' => $transport->id, 'mode' => 'override', 'amount' => 600_000],
            ['salary_component_id' => $housing->id, 'mode' => 'excluded'],
            ['salary_component_id' => $meal->id, 'mode' => 'additional', 'amount' => 300_000],
        ],
    ])->assertCreated();

    expect($response->json('data.breakdown.gross'))->toBe(10_900_000)
        ->and($response->json('data.component_overrides'))->toHaveCount(3)
        ->and($this->getJson("/api/v1/employees/{$employee->id}/salary")->json('data.breakdown.gross'))->toBe(10_900_000);
});

test('a basic salary increase preserves components and creates dated history', function () {
    $transport = SalaryComponent::factory()->create(['name' => 'Transport', 'code' => 'TRA']);
    $structure = SalaryStructure::factory()->create();
    $structure->components()->create(['salary_component_id' => $transport->id, 'amount' => 500_000]);
    $employee = Employee::factory()->create();

    $this->postJson("/api/v1/employees/{$employee->id}/salary", [
        'basic_salary' => 20_000_000,
        'salary_structure_id' => $structure->id,
        'effective_from' => '2026-07-01',
        'component_overrides' => [[
            'salary_component_id' => $transport->id,
            'mode' => 'override',
            'amount' => 600_000,
        ]],
    ])->assertCreated();

    $this->postJson("/api/v1/employees/{$employee->id}/salary/increase", [
        'basic_salary' => 25_000_000,
        'effective_from' => '2026-08-01',
        'change_reason' => 'Annual salary review',
    ])->assertCreated()
        ->assertJsonPath('data.change_type', EmployeeSalary::CHANGE_BASIC_INCREASE)
        ->assertJsonPath('data.status', 'scheduled')
        ->assertJsonPath('data.breakdown.gross', 25_600_000);

    $history = $this->getJson("/api/v1/employees/{$employee->id}/salary-history")
        ->assertOk()
        ->assertJsonCount(2, 'data');

    expect($history->json('data.0.effective_from'))->toBe('2026-08-01')
        ->and($history->json('data.0.change_reason'))->toBe('Annual salary review')
        ->and($history->json('data.1.effective_to'))->toBe('2026-07-31')
        ->and($history->json('data.1.status'))->toBe('current')
        ->and($history->json('data.0.component_overrides'))->toHaveCount(1)
        ->and($this->getJson("/api/v1/employees/{$employee->id}/salary")->json('data.basic_salary'))->toBe(20_000_000);
});

test('salary increases must be larger and start on a payroll month boundary', function () {
    $employee = Employee::factory()->create();
    $this->postJson("/api/v1/employees/{$employee->id}/salary", [
        'basic_salary' => 20_000_000,
        'effective_from' => '2026-07-01',
    ])->assertCreated();

    $this->postJson("/api/v1/employees/{$employee->id}/salary/increase", [
        'basic_salary' => 20_000_000,
        'effective_from' => '2026-08-01',
        'change_reason' => 'Review',
    ])->assertUnprocessable()->assertJsonValidationErrors('basic_salary');

    $this->postJson("/api/v1/employees/{$employee->id}/salary/increase", [
        'basic_salary' => 21_000_000,
        'effective_from' => '2026-08-15',
        'change_reason' => 'Review',
    ])->assertUnprocessable()->assertJsonValidationErrors('effective_from');
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
