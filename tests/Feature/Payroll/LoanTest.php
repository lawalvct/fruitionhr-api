<?php

use App\Core\Workflow\Models\WorkflowRequest;
use App\Core\Workflow\WorkflowService;
use App\Models\User;
use App\Modules\Payroll\Models\LoanRepayment;
use App\Modules\Payroll\Models\StaffLoan;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantRoleProvisioner;
use App\Support\Tenancy\CurrentTenant;

// Scenario employee: gross ₦500,000; statutory ₦108,804.67; net ₦391,195.33.
const NET_BEFORE_RECOVERY = 39_119_533; // kobo

beforeEach(function () {
    [$this->tenant, $this->owner, $this->employee] = payrollScenario();
});

function approveLoan(User $owner): void
{
    $wf = WorkflowRequest::query()->where('module', 'loan')->latest('id')->firstOrFail();
    $service = app(WorkflowService::class);
    $service->act($wf, $owner, 'approve');
    $service->act($wf->refresh(), $owner, 'approve');
}

/** Run, approve and lock payroll for a period; returns the run id. */
function runAndLockPayroll(string $period, User $owner): int
{
    $run = test()->postJson('/api/v1/payroll-runs', ['period' => $period])->assertCreated();
    $runId = $run->json('data.id');
    test()->postJson("/api/v1/payroll-runs/{$runId}/submit")->assertOk();
    $wf = WorkflowRequest::query()->where('module', 'payroll')->latest('id')->firstOrFail();
    app(WorkflowService::class)->act($wf, $owner, 'approve');
    app(WorkflowService::class)->act($wf->refresh(), $owner, 'approve');
    test()->postJson("/api/v1/payroll-runs/{$runId}/lock")->assertOk();

    return $runId;
}

test('advance installment equals the full amount; a loan splits over months', function () {
    $advance = $this->postJson('/api/v1/loans', [
        'employee_id' => $this->employee->id, 'type' => 'advance',
        'principal' => 10_000_000, 'start_period' => '2026-07',
    ])->assertCreated();
    expect($advance->json('data.months'))->toBe(1)
        ->and($advance->json('data.monthly_installment'))->toBe(10_000_000);

    $loan = $this->postJson('/api/v1/loans', [
        'employee_id' => $this->employee->id, 'type' => 'loan',
        'principal' => 9_000_000, 'months' => 3, 'start_period' => '2026-07',
    ])->assertCreated();
    // ceil(9,000,000 / 3) = 3,000,000
    expect($loan->json('data.monthly_installment'))->toBe(3_000_000)
        ->and($loan->json('data.balance'))->toBe(9_000_000);
});

test('a loan is only active after approval', function () {
    $loan = $this->postJson('/api/v1/loans', [
        'employee_id' => $this->employee->id, 'type' => 'loan',
        'principal' => 9_000_000, 'months' => 3, 'start_period' => '2026-07',
    ])->json('data.id');

    $this->postJson("/api/v1/loans/{$loan}/submit")->assertOk()->assertJsonPath('data.status', 'pending');
    approveLoan($this->owner);

    $model = StaffLoan::query()->find($loan);
    expect($model->status)->toBe('active')->and($model->disbursed_at)->not->toBeNull();
});

test('an approved advance is recovered in full from the coming payroll and closes', function () {
    $advance = $this->postJson('/api/v1/loans', [
        'employee_id' => $this->employee->id, 'type' => 'advance',
        'principal' => 10_000_000, 'start_period' => '2026-07',
    ])->json('data.id');
    $this->postJson("/api/v1/loans/{$advance}/submit")->assertOk();
    approveLoan($this->owner);

    $run = $this->postJson('/api/v1/payroll-runs', ['period' => '2026-07'])->assertCreated();
    $runId = $run->json('data.id');
    $detail = $this->getJson("/api/v1/payroll-runs/{$runId}")->assertOk();

    // Net drops by the advance; a deduction line appears.
    expect($detail->json('data.total_net'))->toBe(NET_BEFORE_RECOVERY - 10_000_000);
    $reId = $detail->json('data.employees.0.id');
    $lines = $this->getJson("/api/v1/payroll-runs/{$runId}/employees/{$reId}")->assertOk();
    expect(collect($lines->json('data.items'))->firstWhere('code', "ADVANCE-{$advance}")['amount'])->toBe(10_000_000);

    // Lock settles the balance and closes the advance.
    $this->postJson("/api/v1/payroll-runs/{$runId}/submit")->assertOk();
    $wf = WorkflowRequest::query()->where('module', 'payroll')->latest('id')->firstOrFail();
    app(WorkflowService::class)->act($wf, $this->owner, 'approve');
    app(WorkflowService::class)->act($wf->refresh(), $this->owner, 'approve');
    $this->postJson("/api/v1/payroll-runs/{$runId}/lock")->assertOk();

    $settled = StaffLoan::query()->find($advance);
    expect($settled->balance)->toBe(0)->and($settled->status)->toBe('closed');
    expect(LoanRepayment::query()->where('staff_loan_id', $advance)->value('amount'))->toBe(10_000_000);
});

test('a loan deducts one installment per run and keeps a running balance', function () {
    $loan = $this->postJson('/api/v1/loans', [
        'employee_id' => $this->employee->id, 'type' => 'loan',
        'principal' => 9_000_000, 'months' => 3, 'start_period' => '2026-07',
    ])->json('data.id');
    $this->postJson("/api/v1/loans/{$loan}/submit")->assertOk();
    approveLoan($this->owner);

    runAndLockPayroll('2026-07', $this->owner);

    $model = StaffLoan::query()->find($loan);
    expect($model->balance)->toBe(6_000_000)->and($model->status)->toBe('active');
});

test('HR can pull the full balance in one run to settle a loan early', function () {
    $loan = $this->postJson('/api/v1/loans', [
        'employee_id' => $this->employee->id, 'type' => 'loan',
        'principal' => 9_000_000, 'months' => 3, 'start_period' => '2026-07',
    ])->json('data.id');
    $this->postJson("/api/v1/loans/{$loan}/submit")->assertOk();
    approveLoan($this->owner);

    // Override the coming run to take the entire balance (null = full balance).
    $this->postJson("/api/v1/loans/{$loan}/plan-deduction", [])
        ->assertOk()->assertJsonPath('data.next_deduction_override', 9_000_000);

    runAndLockPayroll('2026-07', $this->owner);

    $model = StaffLoan::query()->find($loan);
    expect($model->balance)->toBe(0)->and($model->status)->toBe('closed');
});

test('recovery is capped at available net; the remainder carries over', function () {
    // Advance larger than net → only what fits is taken this run.
    $advance = $this->postJson('/api/v1/loans', [
        'employee_id' => $this->employee->id, 'type' => 'advance',
        'principal' => 50_000_000, 'start_period' => '2026-07',
    ])->json('data.id');
    $this->postJson("/api/v1/loans/{$advance}/submit")->assertOk();
    approveLoan($this->owner);

    $runId = runAndLockPayroll('2026-07', $this->owner);

    // Whole net consumed; balance carries the shortfall; loan stays active.
    expect($this->getJson("/api/v1/payroll-runs/{$runId}")->json('data.total_net'))->toBe(0);
    $model = StaffLoan::query()->find($advance);
    expect($model->balance)->toBe(50_000_000 - NET_BEFORE_RECOVERY)->and($model->status)->toBe('active');
});

test('reversing a run restores the loan balance and reopens a closed loan', function () {
    $loan = $this->postJson('/api/v1/loans', [
        'employee_id' => $this->employee->id, 'type' => 'loan',
        'principal' => 9_000_000, 'months' => 3, 'start_period' => '2026-07',
    ])->json('data.id');
    $this->postJson("/api/v1/loans/{$loan}/submit")->assertOk();
    approveLoan($this->owner);

    // Pull the full balance so the loan closes on the first run.
    $this->postJson("/api/v1/loans/{$loan}/plan-deduction", [])->assertOk();
    $runId = runAndLockPayroll('2026-07', $this->owner);
    expect(StaffLoan::query()->find($loan)->status)->toBe('closed');

    // Reverse the run — balance is credited back and the loan reopens.
    $this->postJson("/api/v1/payroll-runs/{$runId}/reverse", ['reason' => 'Wrong figures'])->assertCreated();

    $model = StaffLoan::query()->find($loan);
    expect($model->balance)->toBe(9_000_000)->and($model->status)->toBe('active');
    expect(LoanRepayment::query()->where('staff_loan_id', $loan)->count())->toBe(0);
});

test('recording a loan requires the loans.manage permission', function () {
    $clerk = User::factory()->create(['tenant_id' => $this->tenant->id]);
    setPermissionsTeamId($this->tenant->id);
    $clerk->assignRole('employee');

    $this->actingAs($clerk)->postJson('/api/v1/loans', [
        'employee_id' => $this->employee->id, 'type' => 'advance',
        'principal' => 1_000_000, 'start_period' => '2026-07',
    ])->assertForbidden();
});

test('loans are tenant isolated', function () {
    $this->postJson('/api/v1/loans', [
        'employee_id' => $this->employee->id, 'type' => 'advance',
        'principal' => 1_000_000, 'start_period' => '2026-07',
    ])->assertCreated();

    $other = Tenant::factory()->create();
    app(TenantRoleProvisioner::class)->provision($other);
    app(CurrentTenant::class)->set($other);
    setPermissionsTeamId($other->id);
    $otherOwner = User::factory()->create(['tenant_id' => $other->id]);
    $otherOwner->assignRole('owner');

    $this->actingAs($otherOwner)->getJson('/api/v1/loans')->assertOk()->assertJsonCount(0, 'data');
});
