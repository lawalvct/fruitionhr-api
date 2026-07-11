<?php

use App\Core\Workflow\Models\WorkflowRequest;
use App\Core\Workflow\WorkflowService;
use App\Models\User;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollRunEmployee;

// payrollScenario() lives in tests/Support/PayrollScenario.php.

/** Create → submit → approve → lock a run, returning its id. */
function lockedRun(User $owner): int
{
    $run = test()->postJson('/api/v1/payroll-runs', ['period' => '2026-07'])->json('data');
    test()->postJson("/api/v1/payroll-runs/{$run['id']}/submit");
    $wf = WorkflowRequest::query()->where('module', 'payroll')->firstOrFail();
    app(WorkflowService::class)->act($wf, $owner, 'approve');
    app(WorkflowService::class)->act($wf->refresh(), $owner, 'approve');
    test()->postJson("/api/v1/payroll-runs/{$run['id']}/lock");

    return $run['id'];
}

beforeEach(function () {
    [$this->tenant, $this->owner, $this->employee] = payrollScenario();
    $this->runId = lockedRun($this->owner);
});

test('reversing a locked run creates a negated mirror and marks the original reversed', function () {
    $original = PayrollRun::query()->find($this->runId);

    $response = $this->postJson("/api/v1/payroll-runs/{$this->runId}/reverse", [
        'reason' => 'Wrong salary used for one employee',
    ])->assertCreated();

    expect($response->json('data.is_reversal'))->toBeTrue()
        ->and($response->json('data.reversed_of_run_id'))->toBe($this->runId)
        ->and($response->json('data.total_net'))->toBe(-$original->total_net)
        ->and($response->json('data.total_gross'))->toBe(-$original->total_gross);

    // Original is now reversed; original + reversal net to zero.
    expect(PayrollRun::query()->find($this->runId)->status)->toBe(PayrollRun::STATUS_REVERSED);

    $reversalId = $response->json('data.id');
    $mirror = PayrollRunEmployee::query()->where('payroll_run_id', $reversalId)->first();
    $orig = PayrollRunEmployee::query()->where('payroll_run_id', $this->runId)->first();
    expect($mirror->net)->toBe(-$orig->net)
        ->and($mirror->items()->sum('amount'))->toBe(-$orig->items()->sum('amount'));
});

test('reversal requires the payroll.reverse permission', function () {
    // hr_admin has process but not reverse.
    $hr = User::factory()->create(['tenant_id' => $this->tenant->id]);
    setPermissionsTeamId($this->tenant->id);
    $hr->assignRole('hr_admin');

    $this->actingAs($hr)->postJson("/api/v1/payroll-runs/{$this->runId}/reverse", ['reason' => 'x'])
        ->assertForbidden();
});

test('only a locked run can be reversed and reversals cannot be reversed', function () {
    // Reverse once (ok)
    $reversalId = $this->postJson("/api/v1/payroll-runs/{$this->runId}/reverse", ['reason' => 'fix'])
        ->assertCreated()->json('data.id');

    // Original is now reversed → reversing again fails
    $this->postJson("/api/v1/payroll-runs/{$this->runId}/reverse", ['reason' => 'again'])
        ->assertStatus(409);

    // The reversal run itself cannot be reversed
    $this->postJson("/api/v1/payroll-runs/{$reversalId}/reverse", ['reason' => 'nope'])
        ->assertStatus(409);
});

test('after reversal a fresh run can be created for the same period', function () {
    $this->postJson("/api/v1/payroll-runs/{$this->runId}/reverse", ['reason' => 'redo'])->assertCreated();

    // The period is free again for a corrected run.
    $this->postJson('/api/v1/payroll-runs', ['period' => '2026-07'])->assertCreated();
});
