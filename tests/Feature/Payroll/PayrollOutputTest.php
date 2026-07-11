<?php

use App\Core\Workflow\Models\WorkflowRequest;
use App\Core\Workflow\WorkflowService;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollRunEmployee;

// payrollScenario() is defined in tests/Support/PayrollScenario.php.

beforeEach(function () {
    [$this->tenant, $this->owner, $this->employee] = payrollScenario();

    // Give the employee a primary bank account for the schedule.
    \App\Modules\Employee\Models\EmployeeBankAccount::query()->create([
        'employee_id' => $this->employee->id,
        'bank_name' => 'Zenith Bank',
        'bank_code' => '057',
        'account_number' => '1234567890',
        'account_name' => 'Employee Test',
        'is_primary' => true,
    ]);

    // Create + approve + lock a run.
    $run = $this->postJson('/api/v1/payroll-runs', ['period' => '2026-07'])->json('data');
    $this->runId = $run['id'];
    $this->postJson("/api/v1/payroll-runs/{$this->runId}/submit");
    $wf = WorkflowRequest::query()->where('module', 'payroll')->firstOrFail();
    app(WorkflowService::class)->act($wf, $this->owner, 'approve');
    app(WorkflowService::class)->act($wf->refresh(), $this->owner, 'approve');
    $this->postJson("/api/v1/payroll-runs/{$this->runId}/lock");
});

test('a payslip PDF can be downloaded for a locked run', function () {
    $re = PayrollRunEmployee::query()->where('payroll_run_id', $this->runId)->firstOrFail();

    $response = $this->get("/api/v1/payroll-runs/{$this->runId}/employees/{$re->id}/payslip");

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

test('the bank schedule and statutory reports download as xlsx', function () {
    $this->get("/api/v1/payroll-runs/{$this->runId}/bank-schedule")
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    foreach (['paye', 'pension', 'nhf', 'nsitf'] as $type) {
        $this->get("/api/v1/payroll-runs/{$this->runId}/statutory-report?type={$type}")
            ->assertOk();
    }
});

test('an unknown statutory report type is rejected', function () {
    $this->get("/api/v1/payroll-runs/{$this->runId}/statutory-report?type=bogus")
        ->assertStatus(422);
});

test('outputs are unavailable before the run is approved', function () {
    // A fresh run in review cannot be downloaded.
    $newPeriodRun = PayrollRun::query()->create([
        'pay_period_id' => \App\Modules\Payroll\Models\PayPeriod::query()->first()->id,
        'period' => '2026-08', 'status' => PayrollRun::STATUS_REVIEW,
    ]);

    $this->get("/api/v1/payroll-runs/{$newPeriodRun->id}/bank-schedule")
        ->assertStatus(409);
});
