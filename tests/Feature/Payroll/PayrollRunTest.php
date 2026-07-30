<?php

use App\Core\Workflow\Models\WorkflowRequest;
use App\Core\Workflow\WorkflowService;
use App\Models\User;
use App\Modules\Payroll\Models\EmployeeSalary;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollRunEmployee;
use App\Modules\Payroll\Models\SalaryComponent;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantRoleProvisioner;
use App\Support\Tenancy\CurrentTenant;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

// payrollScenario() is defined in tests/Support/PayrollScenario.php.

beforeEach(function () {
    [$this->tenant, $this->owner, $this->employee] = payrollScenario();
});

test('preflight passes when attendance, salary and statutory are ready', function () {
    $this->getJson('/api/v1/payroll/preflight?period=2026-07')
        ->assertOk()
        ->assertJsonPath('data.passed', true);
});

test('a payroll run calculates correct kobo totals (hand-verified)', function () {
    $run = $this->postJson('/api/v1/payroll-runs', ['period' => '2026-07'])->assertCreated();

    // Queue is sync in tests, so calculation has already completed.
    $data = $this->getJson('/api/v1/payroll-runs/'.$run->json('data.id'))->assertOk();

    expect($data->json('data.status'))->toBe(PayrollRun::STATUS_REVIEW)
        ->and($data->json('data.employee_count'))->toBe(1)
        ->and($data->json('data.total_gross'))->toBe(50_000_000)          // ₦500,000
        ->and($data->json('data.total_statutory'))->toBe(10_880_467)      // PAYE+pension+NHF
        ->and($data->json('data.total_deductions'))->toBe(10_880_467)     // no absence
        ->and($data->json('data.total_net'))->toBe(39_119_533)            // 500,000 - deductions
        ->and($data->json('data.total_employer_cost'))->toBe(5_000_000);  // pension ER + NSITF
});

test('the employee payslip breakdown itemises earnings and statutory lines', function () {
    $run = $this->postJson('/api/v1/payroll-runs', ['period' => '2026-07'])->assertCreated();
    $detail = $this->getJson('/api/v1/payroll-runs/'.$run->json('data.id'))->assertOk();
    $reId = $detail->json('data.employees.0.id');

    $lines = $this->getJson("/api/v1/payroll-runs/{$run->json('data.id')}/employees/{$reId}")->assertOk();

    $codes = collect($lines->json('data.items'))->pluck('code');
    expect($codes)->toContain('BASIC', 'HOU', 'PAYE', 'PENSION', 'NHF')
        ->and($lines->json('data.net'))->toBe(39_119_533);
});

test('configured employer contributions increase employer cost without changing employee pay', function () {
    $component = SalaryComponent::factory()->percentOfBasic(10)->create([
        'name' => 'Company Pension',
        'code' => 'CP001',
        'type' => SalaryComponent::TYPE_EMPLOYER_CONTRIBUTOR,
        'is_taxable' => false,
        'is_pensionable' => false,
    ]);

    $salary = EmployeeSalary::query()
        ->where('employee_id', $this->employee->id)
        ->firstOrFail();
    $salary->structure->components()->create(['salary_component_id' => $component->id]);

    $run = $this->postJson('/api/v1/payroll-runs', ['period' => '2026-07'])->assertCreated();
    $detail = $this->getJson('/api/v1/payroll-runs/'.$run->json('data.id'))->assertOk();

    expect($detail->json('data.total_gross'))->toBe(50_000_000)
        ->and($detail->json('data.total_net'))->toBe(39_119_533)
        ->and($detail->json('data.total_employer_cost'))->toBe(7_500_000);

    $runEmployeeId = $detail->json('data.employees.0.id');
    $items = $this->getJson("/api/v1/payroll-runs/{$run->json('data.id')}/employees/{$runEmployeeId}")
        ->assertOk()
        ->json('data.items');
    $companyPension = collect($items)->firstWhere('code', 'CP001');

    expect($companyPension['category'])->toBe('employer')
        ->and($companyPension['amount'])->toBe(2_500_000);
});

test('payroll uses employee component overrides additions and exclusions', function () {
    $salary = EmployeeSalary::query()
        ->where('employee_id', $this->employee->id)
        ->firstOrFail();
    $transport = SalaryComponent::query()->where('code', 'TRA')->firstOrFail();
    $meal = SalaryComponent::query()->where('code', 'MEAL')->firstOrFail();
    $special = SalaryComponent::factory()->create(['name' => 'Special Allowance', 'code' => 'SPECIAL']);

    $salary->componentOverrides()->createMany([
        ['salary_component_id' => $transport->id, 'mode' => 'override', 'amount' => 8_500_000],
        ['salary_component_id' => $meal->id, 'mode' => 'excluded'],
        ['salary_component_id' => $special->id, 'mode' => 'additional', 'amount' => 1_000_000],
    ]);

    $run = $this->postJson('/api/v1/payroll-runs', ['period' => '2026-07'])->assertCreated();
    $detail = $this->getJson('/api/v1/payroll-runs/'.$run->json('data.id'))->assertOk();
    $runEmployeeId = $detail->json('data.employees.0.id');
    $items = collect($this->getJson("/api/v1/payroll-runs/{$run->json('data.id')}/employees/{$runEmployeeId}")
        ->assertOk()
        ->json('data.items'));

    expect($detail->json('data.total_gross'))->toBe(47_000_000)
        ->and($items->firstWhere('code', 'TRA')['amount'])->toBe(8_500_000)
        ->and($items->contains('code', 'MEAL'))->toBeFalse()
        ->and($items->firstWhere('code', 'SPECIAL')['amount'])->toBe(1_000_000);
});

test('fringe benefits increase taxable pay but not cash gross or pensionable pay', function () {
    $component = SalaryComponent::factory()->create([
        'name' => 'Company Car',
        'code' => 'CAR',
        'type' => SalaryComponent::TYPE_FRINGE_BENEFIT,
        'is_taxable' => true,
        'is_pensionable' => false,
    ]);

    $salary = EmployeeSalary::query()
        ->where('employee_id', $this->employee->id)
        ->firstOrFail();
    $salary->structure->components()->create([
        'salary_component_id' => $component->id,
        'amount' => 5_000_000,
    ]);

    $run = $this->postJson('/api/v1/payroll-runs', ['period' => '2026-07'])->assertCreated();
    $detail = $this->getJson('/api/v1/payroll-runs/'.$run->json('data.id'))->assertOk();
    $payrollLine = PayrollRunEmployee::query()
        ->where('employee_id', $this->employee->id)
        ->firstOrFail();

    expect($detail->json('data.total_gross'))->toBe(50_000_000)
        ->and($payrollLine->taxable_pay)->toBe(55_000_000)
        ->and($payrollLine->pensionable_pay)->toBe(45_000_000)
        ->and($detail->json('data.total_net'))->toBeLessThan(39_119_533);

    $item = $payrollLine->items()->where('code', 'CAR')->firstOrFail();
    expect($item->category)->toBe('fringe_benefit')
        ->and($item->amount)->toBe(5_000_000);
});

test('full flow: run, submit, approve via workflow, lock; locked run is immutable', function () {
    $run = $this->postJson('/api/v1/payroll-runs', ['period' => '2026-07'])->assertCreated();
    $runId = $run->json('data.id');

    // Submit for approval
    $this->postJson("/api/v1/payroll-runs/{$runId}/submit")
        ->assertOk()->assertJsonPath('data.status', PayrollRun::STATUS_PENDING_APPROVAL);

    // Approve through the workflow (payroll: HR then owner; owner can act on both)
    $wf = WorkflowRequest::query()->where('module', 'payroll')->firstOrFail();
    $service = app(WorkflowService::class);
    $service->act($wf, $this->owner, 'approve');
    $service->act($wf->refresh(), $this->owner, 'approve');

    $run = PayrollRun::query()->find($runId);
    expect($run->status)->toBe(PayrollRun::STATUS_APPROVED);

    // Lock
    $this->postJson("/api/v1/payroll-runs/{$runId}/lock")
        ->assertOk()->assertJsonPath('data.status', PayrollRun::STATUS_LOCKED);

    // A locked run cannot be recalculated or resubmitted.
    expect(fn () => app(PayrollRunService::class)->process($run->refresh()))
        ->toThrow(ConflictHttpException::class);

    $this->postJson("/api/v1/payroll-runs/{$runId}/submit")->assertStatus(409);
});

test('a run cannot be created twice for the same period', function () {
    $this->postJson('/api/v1/payroll-runs', ['period' => '2026-07'])->assertCreated();
    $this->postJson('/api/v1/payroll-runs', ['period' => '2026-07'])->assertStatus(409);
});

test('processing requires the payroll.process permission', function () {
    $clerk = User::factory()->create(['tenant_id' => $this->tenant->id]);
    setPermissionsTeamId($this->tenant->id);
    $clerk->assignRole('employee');

    $this->actingAs($clerk)->postJson('/api/v1/payroll-runs', ['period' => '2026-07'])->assertForbidden();
});

test('payroll runs are tenant isolated', function () {
    $this->postJson('/api/v1/payroll-runs', ['period' => '2026-07'])->assertCreated();

    $other = Tenant::factory()->create();
    app(TenantRoleProvisioner::class)->provision($other);
    app(CurrentTenant::class)->set($other);
    setPermissionsTeamId($other->id);
    $otherOwner = User::factory()->create(['tenant_id' => $other->id]);
    $otherOwner->assignRole('owner');

    $this->actingAs($otherOwner)->getJson('/api/v1/payroll-runs')
        ->assertOk()->assertJsonCount(0, 'data');
});
