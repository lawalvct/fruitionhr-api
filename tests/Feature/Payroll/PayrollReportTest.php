<?php

use App\Core\Workflow\Models\WorkflowRequest;
use App\Core\Workflow\WorkflowService;
use App\Models\User;

// payrollScenario() lives in tests/Support/PayrollScenario.php.

function approveAndLock(int $runId, User $owner): void
{
    test()->postJson("/api/v1/payroll-runs/{$runId}/submit");
    $wf = WorkflowRequest::query()->where('module', 'payroll')->where('record_id', $runId)->firstOrFail();
    app(WorkflowService::class)->act($wf, $owner, 'approve');
    app(WorkflowService::class)->act($wf->refresh(), $owner, 'approve');
    test()->postJson("/api/v1/payroll-runs/{$runId}/lock");
}

beforeEach(function () {
    [$this->tenant, $this->owner, $this->employee] = payrollScenario();
});

test('the payroll journal is balanced and posts the expected accounts', function () {
    $run = $this->postJson('/api/v1/payroll-runs', ['period' => '2026-07'])->json('data');
    approveAndLock($run['id'], $this->owner);

    $journal = $this->getJson("/api/v1/payroll-runs/{$run['id']}/journal")->assertOk();

    expect($journal->json('data.balanced'))->toBeTrue()
        ->and($journal->json('data.total_debit'))->toBe($journal->json('data.total_credit'));

    $accounts = collect($journal->json('data.entries'))->pluck('account');
    expect($accounts)->toContain('Salary Expense', 'PAYE Payable', 'Pension Payable', 'NHF Payable', 'Net Salary Payable');

    // DR Salary Expense equals gross (₦500,000)
    $salaryExpense = collect($journal->json('data.entries'))->firstWhere('account', 'Salary Expense');
    expect($salaryExpense['amount'])->toBe(50_000_000)
        ->and($salaryExpense['type'])->toBe('debit');
});

test('the journal exports as xlsx', function () {
    $run = $this->postJson('/api/v1/payroll-runs', ['period' => '2026-07'])->json('data');
    approveAndLock($run['id'], $this->owner);

    $this->get("/api/v1/payroll-runs/{$run['id']}/journal.xlsx")
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

test('variance flags a new employee and reports no previous period on the first run', function () {
    $run = $this->postJson('/api/v1/payroll-runs', ['period' => '2026-07'])->json('data');
    approveAndLock($run['id'], $this->owner);

    $variance = $this->getJson("/api/v1/payroll-runs/{$run['id']}/variance")->assertOk();

    expect($variance->json('data.previous_period'))->toBeNull()
        ->and($variance->json('data.rows.0.flag'))->toBe('new')
        ->and($variance->json('data.rows.0.previous_net'))->toBe(0)
        ->and($variance->json('data.rows.0.current_net'))->toBe(39_119_533);
});

test('variance compares against the previous locked period', function () {
    // July run
    $july = $this->postJson('/api/v1/payroll-runs', ['period' => '2026-07'])->json('data');
    approveAndLock($july['id'], $this->owner);

    // Finalize August attendance, then run August (same salary → same net)
    app(\App\Modules\Attendance\Services\AttendanceService::class)->finalize('2026-08', $this->owner);
    $august = $this->postJson('/api/v1/payroll-runs', ['period' => '2026-08'])->json('data');
    approveAndLock($august['id'], $this->owner);

    $variance = $this->getJson("/api/v1/payroll-runs/{$august['id']}/variance")->assertOk();

    expect($variance->json('data.previous_period'))->toBe('2026-07')
        ->and($variance->json('data.rows.0.flag'))->toBe('changed')
        ->and($variance->json('data.rows.0.delta'))->toBe(0) // identical months
        ->and((float) $variance->json('data.rows.0.percent'))->toBe(0.0);
});
