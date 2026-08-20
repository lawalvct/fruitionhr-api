<?php

use App\Core\Workflow\WorkflowProvisioner;
use App\Models\User;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Employee\Models\Employee;
use App\Modules\Payroll\Formula\SalaryFormulaEngine;
use App\Modules\Payroll\Formula\SalaryFormulaException;
use App\Modules\Payroll\Models\EmployeeSalary;
use App\Modules\Payroll\Models\PayPeriod;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollRunEmployee;
use App\Modules\Payroll\Models\SalaryComponent;
use App\Modules\Payroll\Models\SalaryFormulaRevision;
use App\Modules\Payroll\Models\SalaryStructure;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Modules\Payroll\Support\SalaryResolver;
use App\Modules\Payroll\Support\StatutoryProvisioner;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantRoleProvisioner;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\CurrentTenant;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function advancedFormulaDefinition(array $calculation, ?array $condition = null): array
{
    return [
        'schema_version' => 1,
        'rules' => [[
            'condition' => $condition,
            'calculation' => $calculation,
        ]],
    ];
}

function advancedFormulaComponent(string $code, string $name = 'Custom allowance'): SalaryComponent
{
    $id = test()->postJson('/api/v1/salary-components', [
        'name' => $name,
        'code' => $code,
        'type' => SalaryComponent::TYPE_EARNING,
        'calc_type' => SalaryComponent::CALC_FORMULA,
        'percent' => null,
        'is_taxable' => true,
        'is_pensionable' => false,
    ])->assertCreated()->json('data.id');

    return SalaryComponent::query()->findOrFail($id);
}

function publishAdvancedFormula(SalaryComponent $component, array $definition): SalaryFormulaRevision
{
    $current = $component->draftFormulaRevision()->first();
    $workspace = test()->putJson("/api/v1/salary-components/{$component->id}/formula/draft", [
        'definition' => $definition,
        'expected_draft_id' => $current?->id,
        'expected_checksum' => $current?->checksum,
    ])->assertOk();
    test()->postJson("/api/v1/salary-components/{$component->id}/formula/publish", [
        'expected_draft_id' => $workspace->json('data.draft.id'),
        'expected_checksum' => $workspace->json('data.draft.checksum'),
    ])
        ->assertOk();

    return $component->publishedFormulaRevision()->firstOrFail();
}

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantRoleProvisioner::class)->provision($this->tenant);
    app(CurrentTenant::class)->set($this->tenant);
    setPermissionsTeamId($this->tenant->id);

    $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->owner->assignRole('owner');
    $this->actingAs($this->owner);
});

test('advanced formulas are default off and formula writes are explicitly gated', function () {
    $this->getJson('/api/v1/payroll-settings')
        ->assertOk()
        ->assertJsonPath('data.advanced_salary_formulas_enabled', false)
        ->assertJsonPath('data.active_formula_salary_count', 0);

    $this->postJson('/api/v1/salary-components', [
        'name' => 'Commission', 'code' => 'COMM', 'type' => 'earning',
        'calc_type' => SalaryComponent::CALC_FORMULA,
    ])->assertUnprocessable()->assertJsonValidationErrors('calc_type');

    $this->postJson('/api/v1/payroll-settings/advanced-salary-formulas/enable')
        ->assertOk()
        ->assertJsonPath('data.advanced_salary_formulas_enabled', true);

    $formula = advancedFormulaComponent('COMM', 'Commission');
    expect($formula->calc_type)->toBe(SalaryComponent::CALC_FORMULA);

    $fixed = SalaryComponent::factory()->create();
    $this->getJson("/api/v1/salary-components/{$fixed->id}/formula")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('definition');

    $this->postJson('/api/v1/payroll-settings/advanced-salary-formulas/disable')
        ->assertOk()
        ->assertJsonPath('data.advanced_salary_formulas_enabled', false);
});

test('formula evaluation is exact and fails on missing inputs negative results division by zero and overflow', function () {
    $this->postJson('/api/v1/payroll-settings/advanced-salary-formulas/enable')->assertOk();
    $dependency = SalaryComponent::factory()->create(['name' => 'Sales value', 'code' => 'SALES']);
    $formula = advancedFormulaComponent('COMM');

    $definition = [
        'schema_version' => 1,
        'rules' => [
            [
                'condition' => [
                    'left' => ['type' => 'component', 'component_id' => $dependency->id],
                    'comparator' => 'gt',
                    'right' => ['type' => 'amount', 'value_kobo' => 100],
                ],
                'calculation' => [
                    ['type' => 'component', 'component_id' => $dependency->id],
                    ['type' => 'operator', 'value' => '*'],
                    ['type' => 'percentage', 'basis_points' => 1250],
                ],
            ],
            [
                'condition' => null,
                'calculation' => [
                    ['type' => 'basic'],
                    ['type' => 'operator', 'value' => '*'],
                    ['type' => 'percentage', 'basis_points' => 5000],
                ],
            ],
        ],
    ];

    $this->putJson("/api/v1/salary-components/{$formula->id}/formula/draft", [
        'definition' => $definition,
        'expected_draft_id' => null,
        'expected_checksum' => null,
    ])->assertOk()->assertJsonPath('data.draft.status', 'draft');

    $this->postJson("/api/v1/salary-components/{$formula->id}/formula/evaluate", [
        'basic_salary' => 1,
    ])->assertUnprocessable()->assertJsonValidationErrors('definition');

    $legacyBasic = SalaryComponent::factory()->create(['name' => 'Basic Salary', 'code' => 'BASIC']);
    $this->putJson("/api/v1/salary-components/{$formula->id}/formula/draft", [
        'definition' => advancedFormulaDefinition([
            ['type' => 'component', 'component_id' => $legacyBasic->id],
        ]),
        'expected_draft_id' => $formula->draftFormulaRevision()->first()->id,
        'expected_checksum' => $formula->draftFormulaRevision()->first()->checksum,
    ])->assertUnprocessable()->assertJsonValidationErrors('definition');

    $this->postJson("/api/v1/salary-components/{$formula->id}/formula/evaluate", [
        'basic_salary' => 1,
        'component_values' => [[
            'salary_component_id' => $dependency->id,
            'amount' => 100,
        ]],
    ])->assertOk()->assertJsonPath('data.result_kobo', 1); // 0.5 rounds half-up.

    $this->postJson("/api/v1/salary-components/{$formula->id}/formula/evaluate", [
        'definition' => advancedFormulaDefinition([
            ['type' => 'amount', 'value_kobo' => 1],
            ['type' => 'operator', 'value' => '-'],
            ['type' => 'basic'],
        ]),
        'basic_salary' => 2,
    ])->assertUnprocessable()->assertJsonValidationErrors('definition');

    $this->postJson("/api/v1/salary-components/{$formula->id}/formula/evaluate", [
        'definition' => advancedFormulaDefinition([
            ['type' => 'basic'],
            ['type' => 'operator', 'value' => '/'],
            ['type' => 'percentage', 'basis_points' => 0],
        ]),
        'basic_salary' => 1,
    ])->assertUnprocessable()->assertJsonValidationErrors('definition');

    $this->postJson("/api/v1/salary-components/{$formula->id}/formula/evaluate", [
        'definition' => advancedFormulaDefinition([
            ['type' => 'basic'],
            ['type' => 'operator', 'value' => '*'],
            ['type' => 'percentage', 'basis_points' => 100_000],
        ]),
        'basic_salary' => 9_000_000_000_000_000,
    ])->assertUnprocessable()->assertJsonValidationErrors('definition');
});

test('published formula revisions are immutable and dependency cycles or unpublished formula dependencies are rejected', function () {
    $this->postJson('/api/v1/payroll-settings/advanced-salary-formulas/enable')->assertOk();
    $baseFormula = advancedFormulaComponent('BASEF', 'Base formula');
    $baseRevision = publishAdvancedFormula($baseFormula, advancedFormulaDefinition([
        ['type' => 'basic'],
        ['type' => 'operator', 'value' => '*'],
        ['type' => 'percentage', 'basis_points' => 1000],
    ]));

    expect(fn () => $baseRevision->update(['summary' => 'changed']))
        ->toThrow(LogicException::class);

    $this->putJson("/api/v1/salary-components/{$baseFormula->id}", [
        'name' => $baseFormula->name,
        'code' => 'CHANGED',
        'type' => $baseFormula->type,
        'calc_type' => SalaryComponent::CALC_FORMULA,
        'percent' => null,
        'is_taxable' => true,
        'is_pensionable' => false,
        'is_active' => true,
    ])->assertUnprocessable()->assertJsonValidationErrors('code');

    $dependent = advancedFormulaComponent('DEPF', 'Dependent formula');
    publishAdvancedFormula($dependent, advancedFormulaDefinition([
        ['type' => 'component', 'component_id' => $baseFormula->id],
        ['type' => 'operator', 'value' => '+'],
        ['type' => 'amount', 'value_kobo' => 10],
    ]));

    $this->putJson("/api/v1/salary-components/{$baseFormula->id}/formula/draft", [
        'definition' => advancedFormulaDefinition([
            ['type' => 'component', 'component_id' => $dependent->id],
            ['type' => 'operator', 'value' => '+'],
            ['type' => 'amount', 'value_kobo' => 1],
        ]),
        'expected_draft_id' => null,
        'expected_checksum' => null,
    ])->assertUnprocessable()->assertJsonValidationErrors('definition');

    $draftOnly = advancedFormulaComponent('DRAFTONLY', 'Draft only');
    $this->putJson("/api/v1/salary-components/{$draftOnly->id}/formula/draft", [
        'definition' => advancedFormulaDefinition([['type' => 'basic']]),
        'expected_draft_id' => null,
        'expected_checksum' => null,
    ])->assertOk();
    $unpublishable = advancedFormulaComponent('UNPUBDEP', 'Unpublished dependency');
    $this->putJson("/api/v1/salary-components/{$unpublishable->id}/formula/draft", [
        'definition' => advancedFormulaDefinition([
            ['type' => 'component', 'component_id' => $draftOnly->id],
        ]),
        'expected_draft_id' => null,
        'expected_checksum' => null,
    ])->assertOk();
    $unpublishableDraft = $unpublishable->draftFormulaRevision()->firstOrFail();
    $this->postJson("/api/v1/salary-components/{$unpublishable->id}/formula/publish", [
        'expected_draft_id' => $unpublishableDraft->id,
        'expected_checksum' => $unpublishableDraft->checksum,
    ])
        ->assertUnprocessable()->assertJsonValidationErrors('definition');
});

test('stale formula editors cannot overwrite or publish a newer draft', function () {
    $this->postJson('/api/v1/payroll-settings/advanced-salary-formulas/enable')->assertOk();
    $formula = advancedFormulaComponent('CONCURRENT', 'Concurrent formula');
    $first = $this->putJson("/api/v1/salary-components/{$formula->id}/formula/draft", [
        'definition' => advancedFormulaDefinition([['type' => 'basic']]),
        'expected_draft_id' => null,
        'expected_checksum' => null,
    ])->assertOk();
    $firstId = $first->json('data.draft.id');
    $firstChecksum = $first->json('data.draft.checksum');

    $secondDefinition = advancedFormulaDefinition([
        ['type' => 'basic'],
        ['type' => 'operator', 'value' => '*'],
        ['type' => 'percentage', 'basis_points' => 2000],
    ]);
    $second = $this->putJson("/api/v1/salary-components/{$formula->id}/formula/draft", [
        'definition' => $secondDefinition,
        'expected_draft_id' => $firstId,
        'expected_checksum' => $firstChecksum,
    ])->assertOk();
    $secondChecksum = $second->json('data.draft.checksum');

    $this->putJson("/api/v1/salary-components/{$formula->id}/formula/draft", [
        'definition' => advancedFormulaDefinition([['type' => 'amount', 'value_kobo' => 99]]),
        'expected_draft_id' => $firstId,
        'expected_checksum' => $firstChecksum,
    ])->assertStatus(409)
        ->assertJsonPath('code', 'SALARY_FORMULA_DRAFT_CONFLICT')
        ->assertJsonPath('data.draft.checksum', $secondChecksum);

    $this->postJson("/api/v1/salary-components/{$formula->id}/formula/publish", [
        'expected_draft_id' => $firstId,
        'expected_checksum' => $firstChecksum,
    ])->assertStatus(409)
        ->assertJsonPath('code', 'SALARY_FORMULA_DRAFT_CONFLICT')
        ->assertJsonPath('data.draft.checksum', $secondChecksum);

    $this->postJson("/api/v1/salary-components/{$formula->id}/formula/publish", [
        'expected_draft_id' => $firstId,
        'expected_checksum' => $secondChecksum,
    ])->assertOk()->assertJsonPath('data.published.definition', $secondDefinition);
});

test('assignment pins a closed dependency graph supports fixed formula replacement and blocks disabling', function () {
    $this->postJson('/api/v1/payroll-settings/advanced-salary-formulas/enable')->assertOk();
    $fixed = SalaryComponent::factory()->create(['name' => 'Fixed base', 'code' => 'FIX']);
    $first = advancedFormulaComponent('FONE', 'First formula');
    $firstRevision = publishAdvancedFormula($first, advancedFormulaDefinition([
        ['type' => 'basic'],
        ['type' => 'operator', 'value' => '*'],
        ['type' => 'percentage', 'basis_points' => 1000],
    ]));
    $second = advancedFormulaComponent('FTWO', 'Second formula');
    publishAdvancedFormula($second, advancedFormulaDefinition([
        ['type' => 'component', 'component_id' => $first->id],
        ['type' => 'operator', 'value' => '+'],
        ['type' => 'component', 'component_id' => $fixed->id],
    ]));

    $this->postJson('/api/v1/salary-structures', [
        'name' => 'Incomplete formula structure',
        'components' => [['salary_component_id' => $second->id]],
    ])->assertUnprocessable();

    $structureId = $this->postJson('/api/v1/salary-structures', [
        'name' => 'Advanced structure',
        'components' => [
            ['salary_component_id' => $fixed->id, 'amount' => 100_000],
            ['salary_component_id' => $first->id],
            ['salary_component_id' => $second->id],
        ],
    ])->assertCreated()
        ->assertJsonPath('data.components.1.formula_revision_id', $firstRevision->id)
        ->json('data.id');

    $employee = Employee::factory()->create();
    $assigned = $this->postJson("/api/v1/employees/{$employee->id}/salary", [
        'basic_salary' => 1_000_000,
        'salary_structure_id' => $structureId,
        'effective_from' => '2026-08-01',
    ])->assertCreated()->assertJsonPath('data.uses_advanced_formula', true);

    $earnings = collect($assigned->json('data.breakdown.earnings'))->keyBy('code');
    expect($earnings['FONE']['amount'])->toBe(100_000)
        ->and($earnings['FTWO']['amount'])->toBe(200_000)
        ->and($earnings['FTWO']['formula']['inputs']['components'])->toHaveCount(2);

    $salary = EmployeeSalary::query()->where('employee_id', $employee->id)->firstOrFail();
    expect($salary->definition_snapshot['components'][1]['formula_revision']['id'])->toBe($firstRevision->id);

    $this->postJson('/api/v1/payroll-settings/advanced-salary-formulas/disable')
        ->assertStatus(409)
        ->assertJsonPath('code', 'ADVANCED_SALARY_FORMULAS_IN_USE')
        ->assertJsonPath('data.blocking_employee_salaries', 1);

    $replacementEmployee = Employee::factory()->create();
    $replacement = $this->postJson("/api/v1/employees/{$replacementEmployee->id}/salary", [
        'basic_salary' => 1_000_000,
        'salary_structure_id' => $structureId,
        'effective_from' => '2026-08-01',
        'component_overrides' => [[
            'salary_component_id' => $first->id,
            'mode' => 'override',
            'amount' => 300_000,
        ]],
    ])->assertCreated();
    $replacementEarnings = collect($replacement->json('data.breakdown.earnings'))->keyBy('code');
    expect($replacementEarnings['FONE']['amount'])->toBe(300_000)
        ->and($replacementEarnings['FONE']['formula']['bypassed_by_override'])->toBeTrue()
        ->and($replacementEarnings['FTWO']['amount'])->toBe(400_000);

    $missingEmployee = Employee::factory()->create();
    $this->postJson("/api/v1/employees/{$missingEmployee->id}/salary", [
        'basic_salary' => 1_000_000,
        'salary_structure_id' => $structureId,
        'effective_from' => '2026-08-01',
        'component_overrides' => [[
            'salary_component_id' => $fixed->id,
            'mode' => 'excluded',
        ]],
    ])->assertUnprocessable()->assertJsonValidationErrors('component_overrides');

    publishAdvancedFormula($first, advancedFormulaDefinition([
        ['type' => 'basic'],
        ['type' => 'operator', 'value' => '*'],
        ['type' => 'percentage', 'basis_points' => 2000],
    ]));

    $stable = $this->getJson("/api/v1/employees/{$employee->id}/salary")->assertOk();
    expect(collect($stable->json('data.breakdown.earnings'))->firstWhere('code', 'FONE')['amount'])
        ->toBe(100_000);
});

test('invalid formula assignment returns validation errors and leaves salary history unchanged', function () {
    $this->postJson('/api/v1/payroll-settings/advanced-salary-formulas/enable')->assertOk();
    $formula = advancedFormulaComponent('DIVZERO', 'Division by zero formula');
    publishAdvancedFormula($formula, advancedFormulaDefinition([
        ['type' => 'basic'],
        ['type' => 'operator', 'value' => '/'],
        ['type' => 'percentage', 'basis_points' => 0],
    ]));
    $structureId = $this->postJson('/api/v1/salary-structures', [
        'name' => 'Invalid at runtime',
        'components' => [['salary_component_id' => $formula->id]],
    ])->assertCreated()->json('data.id');
    $employee = Employee::factory()->create();

    $this->postJson("/api/v1/employees/{$employee->id}/salary", [
        'basic_salary' => 1_000_000,
        'salary_structure_id' => $structureId,
        'effective_from' => '2026-08-01',
    ])->assertUnprocessable()->assertJsonValidationErrors('salary_formula');

    expect(EmployeeSalary::query()->where('employee_id', $employee->id)->count())->toBe(0);
});

test('legacy salary structures cannot gain formulas and legacy resolution never silently returns zero', function () {
    $fixed = SalaryComponent::factory()->create(['name' => 'Legacy allowance', 'code' => 'LEG']);
    $structure = SalaryStructure::factory()->create(['name' => 'Legacy structure']);
    $structure->components()->create(['salary_component_id' => $fixed->id, 'amount' => 100_000]);
    $employee = Employee::factory()->create();
    EmployeeSalary::query()->create([
        'employee_id' => $employee->id,
        'salary_structure_id' => $structure->id,
        'basic_salary' => 1_000_000,
        'effective_from' => '2026-08-01',
        'is_current' => true,
        'created_by' => $this->owner->id,
    ]);

    $this->postJson('/api/v1/payroll-settings/advanced-salary-formulas/enable')->assertOk();
    $formula = advancedFormulaComponent('LEGFORM', 'Legacy guard formula');
    $revision = publishAdvancedFormula($formula, advancedFormulaDefinition([['type' => 'basic']]));

    $this->putJson("/api/v1/salary-structures/{$structure->id}", [
        'name' => $structure->name,
        'is_active' => true,
        'components' => [['salary_component_id' => $formula->id]],
    ])->assertUnprocessable()->assertJsonValidationErrors('components');

    $this->putJson("/api/v1/salary-structures/{$structure->id}", [
        'name' => 'Renamed legacy structure',
        'is_active' => true,
        'components' => [['salary_component_id' => $fixed->id, 'amount' => 100_000]],
    ])->assertOk()->assertJsonPath('data.name', 'Renamed legacy structure');

    $this->putJson("/api/v1/salary-structures/{$structure->id}", [
        'name' => 'Renamed legacy structure',
        'is_active' => true,
        'components' => [['salary_component_id' => $fixed->id, 'amount' => 200_000]],
    ])->assertUnprocessable()->assertJsonValidationErrors('components');

    $this->putJson("/api/v1/salary-components/{$fixed->id}", [
        'name' => $fixed->name,
        'code' => $fixed->code,
        'type' => $fixed->type,
        'calc_type' => SalaryComponent::CALC_FORMULA,
        'percent' => null,
        'is_taxable' => true,
        'is_pensionable' => false,
        'is_active' => true,
    ])->assertUnprocessable()->assertJsonValidationErrors('calc_type');

    $this->putJson("/api/v1/salary-components/{$fixed->id}", [
        'name' => 'Changed legacy label',
        'code' => $fixed->code,
        'type' => $fixed->type,
        'calc_type' => $fixed->calc_type,
        'percent' => $fixed->percent,
        'is_taxable' => $fixed->is_taxable,
        'is_pensionable' => $fixed->is_pensionable,
        'is_active' => true,
    ])->assertUnprocessable()->assertJsonValidationErrors('name');

    $line = (object) [
        'amount' => null,
        'percent' => null,
        'component' => $formula,
        'formula_revision_id' => $revision->id,
    ];
    expect(fn () => app(SalaryResolver::class)->resolve(1_000_000, [$line]))
        ->toThrow(SalaryFormulaException::class, 'immutable employee salary definition snapshot');
});

test('formula routes are permission and tenant isolated', function () {
    $this->postJson('/api/v1/payroll-settings/advanced-salary-formulas/enable')->assertOk();
    $formula = advancedFormulaComponent('ISOLATED');

    $manager = User::factory()->create(['tenant_id' => $this->tenant->id]);
    setPermissionsTeamId($this->tenant->id);
    $manager->assignRole('manager');
    $this->actingAs($manager)
        ->getJson('/api/v1/salary-formulas/catalog')
        ->assertForbidden();

    $otherTenant = Tenant::factory()->create([
        'settings' => ['payroll' => ['advanced_salary_formulas_enabled' => true]],
    ]);
    app(TenantRoleProvisioner::class)->provision($otherTenant);
    app(CurrentTenant::class)->set($otherTenant);
    setPermissionsTeamId($otherTenant->id);
    $otherOwner = User::factory()->create(['tenant_id' => $otherTenant->id]);
    $otherOwner->assignRole('owner');

    $this->actingAs($otherOwner)
        ->getJson("/api/v1/salary-components/{$formula->id}/formula")
        ->assertNotFound();
});

test('existing tenant permission migration adds only the formula permission', function () {
    setPermissionsTeamId($this->tenant->id);
    $custom = Permission::findOrCreate('custom.permission.kept', 'web');
    $hrAdmin = Role::findByName('hr_admin', 'web')->load('permissions');
    $manager = Role::findByName('manager', 'web')->load('permissions');
    $hrAdmin->givePermissionTo($custom);
    $hrAdmin->unsetRelation('permissions');
    $hrAdmin->load('permissions');
    $hrAdmin->revokePermissionTo(Permissions::PAYROLL_FORMULAS_MANAGE);
    $hrAdmin->unsetRelation('permissions');
    $hrAdmin->load('permissions');
    $manager->unsetRelation('permissions');
    $manager->load('permissions');
    $managerBefore = $manager->getPermissionNames()->sort()->values()->all();

    $migration = require database_path('migrations/2026_08_20_100100_provision_payroll_formula_permission.php');
    $migration->up();
    setPermissionsTeamId($this->tenant->id);

    $hrAdmin = $hrAdmin->refresh()->load('permissions');
    $manager = $manager->refresh()->load('permissions');

    expect($hrAdmin->hasPermissionTo($custom))->toBeTrue()
        ->and($hrAdmin->hasPermissionTo(Permissions::PAYROLL_FORMULAS_MANAGE))->toBeTrue()
        ->and($manager->getPermissionNames()->sort()->values()->all())->toBe($managerBefore);
});

test('payroll persists formula revision definition inputs result and rounding policy', function () {
    app(WorkflowProvisioner::class)->provision($this->tenant);
    app(StatutoryProvisioner::class)->provision($this->tenant);
    $this->postJson('/api/v1/payroll-settings/advanced-salary-formulas/enable')->assertOk();

    $formula = advancedFormulaComponent('PAYFORM', 'Payroll formula');
    $revision = publishAdvancedFormula($formula, advancedFormulaDefinition([
        ['type' => 'basic'],
        ['type' => 'operator', 'value' => '*'],
        ['type' => 'percentage', 'basis_points' => 1000],
    ]));
    $structureId = $this->postJson('/api/v1/salary-structures', [
        'name' => 'Payroll formula structure',
        'components' => [['salary_component_id' => $formula->id]],
    ])->assertCreated()->json('data.id');
    $employee = Employee::factory()->create(['employment_status' => Employee::STATUS_ACTIVE]);
    $this->postJson("/api/v1/employees/{$employee->id}/salary", [
        'basic_salary' => 10_000_000,
        'salary_structure_id' => $structureId,
        'effective_from' => '2026-08-01',
    ])->assertCreated();
    app(AttendanceService::class)->finalize('2026-08', $this->owner);

    $this->postJson('/api/v1/payroll-runs', ['period' => '2026-08'])->assertCreated();
    $line = PayrollRunEmployee::query()->where('employee_id', $employee->id)->firstOrFail();
    $formulaLine = collect($line->snapshot['earnings'])->firstWhere('code', 'PAYFORM');

    expect($line->snapshot['salary_definition']['engine']['rounding'])->toBe('half_up_at_final_result')
        ->and($line->snapshot['salary_definition']['formula_revisions'][0]['revision_id'])->toBe($revision->id)
        ->and($line->snapshot['salary_definition']['formula_revisions'][0]['definition'])->toBeArray()
        ->and($formulaLine['formula']['inputs']['basic_salary'])->toBe(10_000_000)
        ->and($formulaLine['formula']['result_kobo'])->toBe(1_000_000);
});

test('a formula calculation failure returns the run to a retryable draft and retry can recover', function () {
    app(StatutoryProvisioner::class)->provision($this->tenant);
    $dependency = SalaryComponent::factory()->create(['name' => 'Required value', 'code' => 'REQ']);
    $formula = SalaryComponent::factory()->create([
        'name' => 'Broken snapshot formula',
        'code' => 'BROKEN',
        'calc_type' => SalaryComponent::CALC_FORMULA,
    ]);
    $definition = advancedFormulaDefinition([
        ['type' => 'component', 'component_id' => $dependency->id],
    ]);
    $employee = Employee::factory()->create(['employment_status' => Employee::STATUS_ACTIVE]);
    $salary = EmployeeSalary::query()->create([
        'employee_id' => $employee->id,
        'basic_salary' => 10_000_000,
        'uses_advanced_formula' => true,
        'definition_snapshot' => [
            'schema_version' => 1,
            'components' => [[
                'salary_component_id' => $formula->id,
                'name' => $formula->name,
                'code' => $formula->code,
                'type' => $formula->type,
                'calc_type' => SalaryComponent::CALC_FORMULA,
                'component_percent' => null,
                'amount' => null,
                'percent' => null,
                'is_taxable' => true,
                'is_pensionable' => false,
                'source' => 'test',
                'formula_revision' => [
                    'id' => 999,
                    'version' => 1,
                    'definition' => $definition,
                    'summary' => 'test',
                    'checksum' => app(SalaryFormulaEngine::class)->checksum($definition),
                ],
            ]],
        ],
        'effective_from' => '2026-08-01',
        'is_current' => true,
        'created_by' => $this->owner->id,
    ]);
    $period = PayPeriod::query()->create([
        'period' => '2026-08', 'year' => 2026, 'month' => 8, 'status' => 'open',
    ]);
    $run = PayrollRun::query()->create([
        'pay_period_id' => $period->id,
        'period' => '2026-08',
        'status' => PayrollRun::STATUS_CALCULATING,
        'created_by' => $this->owner->id,
    ]);

    expect(fn () => app(PayrollRunService::class)->process($run))
        ->toThrow(SalaryFormulaException::class);
    $run->refresh();
    expect($run->status)->toBe(PayrollRun::STATUS_DRAFT)
        ->and($run->calculation_error_code)->toBe('FORMULA_MISSING_INPUT')
        ->and($run->calculation_failed_at)->not->toBeNull();

    $salary->delete();
    EmployeeSalary::query()->create([
        'employee_id' => $employee->id,
        'basic_salary' => 10_000_000,
        'uses_advanced_formula' => false,
        'definition_snapshot' => ['schema_version' => 1, 'components' => []],
        'effective_from' => '2026-08-01',
        'is_current' => true,
        'created_by' => $this->owner->id,
    ]);
    app(AttendanceService::class)->finalize('2026-08', $this->owner);

    $this->postJson("/api/v1/payroll-runs/{$run->id}/retry-calculation")
        ->assertOk()
        ->assertJsonPath('data.status', PayrollRun::STATUS_REVIEW)
        ->assertJsonPath('data.calculation_failure', null);
});
