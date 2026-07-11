<?php

use App\Core\Workflow\Models\WorkflowRequest;
use App\Core\Workflow\WorkflowProvisioner;
use App\Core\Workflow\WorkflowService;
use App\Models\User;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Employee\Models\Employee;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\SalaryComponent;
use App\Modules\Payroll\Models\SalaryStructure;
use App\Modules\Payroll\Support\StatutoryProvisioner;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantRoleProvisioner;
use App\Support\Tenancy\CurrentTenant;

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
    expect(fn () => app(\App\Modules\Payroll\Services\PayrollRunService::class)->process($run->refresh()))
        ->toThrow(Symfony\Component\HttpKernel\Exception\ConflictHttpException::class);

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
