<?php

use App\Core\Workflow\Models\WorkflowRequest;
use App\Core\Workflow\WorkflowService;
use App\Models\User;
use App\Modules\Attendance\Models\AttendanceSummary;
use App\Modules\Payroll\Models\OvertimePayment;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantRoleProvisioner;
use App\Support\Tenancy\CurrentTenant;

// Employee basic = ₦250,000 (25,000,000 kobo). Standard month = 208 hours.
// Hourly rate = round(25,000,000 / 208) = 120,192 kobo/hour.
const OT_HOURLY_RATE = 120_192;

beforeEach(function () {
    [$this->tenant, $this->owner, $this->employee] = payrollScenario();
});

/** Approve an overtime record through its (manager → HR) workflow; owner can act on both. */
function approveOvertime(User $owner): void
{
    $wf = WorkflowRequest::query()->where('module', 'overtime')->latest('id')->firstOrFail();
    $service = app(WorkflowService::class);
    $service->act($wf, $owner, 'approve');
    $service->act($wf->refresh(), $owner, 'approve');
}

test('hourly overtime is priced from basic salary × multiplier (hand-verified)', function () {
    $res = $this->postJson('/api/v1/overtime', [
        'employee_id' => $this->employee->id,
        'period' => '2026-07',
        'pay_type' => 'hourly',
        'hours' => 10,
        'multiplier' => 1.5,
        'disbursement_mode' => 'in_payroll',
    ])->assertCreated();

    // 10 × 120,192 × 1.5 = 1,802,880 kobo (₦18,028.80)
    expect($res->json('data.amount'))->toBe(1_802_880)
        ->and($res->json('data.hourly_rate'))->toBe(OT_HOURLY_RATE)
        ->and($res->json('data.status'))->toBe('draft');
});

test('fixed overtime uses the entered amount as-is', function () {
    $res = $this->postJson('/api/v1/overtime', [
        'employee_id' => $this->employee->id,
        'period' => '2026-07',
        'pay_type' => 'fixed',
        'amount' => 5_000_000, // ₦50,000
        'disbursement_mode' => 'off_cycle',
    ])->assertCreated();

    expect($res->json('data.amount'))->toBe(5_000_000)
        ->and($res->json('data.pay_type'))->toBe('fixed');
});

test('overtime moves draft → pending → approved through the workflow', function () {
    $ot = $this->postJson('/api/v1/overtime', [
        'employee_id' => $this->employee->id,
        'period' => '2026-07', 'pay_type' => 'fixed', 'amount' => 5_000_000,
        'disbursement_mode' => 'off_cycle',
    ])->json('data.id');

    $this->postJson("/api/v1/overtime/{$ot}/submit")
        ->assertOk()->assertJsonPath('data.status', 'pending');

    approveOvertime($this->owner);

    expect(OvertimePayment::query()->find($ot)->status)->toBe('approved');
});

test('approved in-payroll overtime rides the run as a taxable earning and is settled on lock', function () {
    $ot = $this->postJson('/api/v1/overtime', [
        'employee_id' => $this->employee->id,
        'period' => '2026-07', 'pay_type' => 'hourly', 'hours' => 10, 'multiplier' => 1.5,
        'disbursement_mode' => 'in_payroll',
    ])->json('data.id');
    $this->postJson("/api/v1/overtime/{$ot}/submit")->assertOk();
    approveOvertime($this->owner);

    // Run payroll — overtime (₦18,028.80) is added to the ₦500,000 gross.
    $run = $this->postJson('/api/v1/payroll-runs', ['period' => '2026-07'])->assertCreated();
    $runId = $run->json('data.id');
    $detail = $this->getJson("/api/v1/payroll-runs/{$runId}")->assertOk();

    expect($detail->json('data.total_gross'))->toBe(50_000_000 + 1_802_880);

    $reId = $detail->json('data.employees.0.id');
    $lines = $this->getJson("/api/v1/payroll-runs/{$runId}/employees/{$reId}")->assertOk();
    expect(collect($lines->json('data.items'))->pluck('code'))->toContain('OVERTIME');

    // Approve + lock the run; overtime becomes paid and bound to the run.
    $this->postJson("/api/v1/payroll-runs/{$runId}/submit")->assertOk();
    $wf = WorkflowRequest::query()->where('module', 'payroll')->firstOrFail();
    app(WorkflowService::class)->act($wf, $this->owner, 'approve');
    app(WorkflowService::class)->act($wf->refresh(), $this->owner, 'approve');
    $this->postJson("/api/v1/payroll-runs/{$runId}/lock")->assertOk();

    $paid = OvertimePayment::query()->find($ot);
    expect($paid->status)->toBe('paid')->and($paid->payroll_run_id)->toBe($runId);
});

test('off-cycle overtime is excluded from payroll and paid gross', function () {
    $ot = $this->postJson('/api/v1/overtime', [
        'employee_id' => $this->employee->id,
        'period' => '2026-07', 'pay_type' => 'fixed', 'amount' => 5_000_000,
        'disbursement_mode' => 'off_cycle',
    ])->json('data.id');
    $this->postJson("/api/v1/overtime/{$ot}/submit")->assertOk();
    approveOvertime($this->owner);

    // Payroll gross is unchanged — off-cycle overtime never enters the run.
    $run = $this->postJson('/api/v1/payroll-runs', ['period' => '2026-07'])->assertCreated();
    $detail = $this->getJson('/api/v1/payroll-runs/'.$run->json('data.id'))->assertOk();
    expect($detail->json('data.total_gross'))->toBe(50_000_000);

    // Pay it off-cycle (gross, no tax).
    $this->postJson("/api/v1/overtime/{$ot}/pay")
        ->assertOk()->assertJsonPath('data.status', 'paid');
});

test('clocked attendance overtime can be accepted into a priced record', function () {
    // Give the finalized July summary some clocked overtime (120 min = 2h).
    AttendanceSummary::query()
        ->where('employee_id', $this->employee->id)
        ->where('period', '2026-07')
        ->update(['overtime_minutes' => 120]);

    $summaryId = AttendanceSummary::query()
        ->where('employee_id', $this->employee->id)->where('period', '2026-07')->value('id');

    $this->getJson('/api/v1/overtime/attendance-candidates?period=2026-07')
        ->assertOk()
        ->assertJsonPath('data.0.overtime_hours', 2)
        ->assertJsonPath('data.0.already_recorded', false);

    $res = $this->postJson('/api/v1/overtime/from-attendance', [
        'attendance_summary_id' => $summaryId,
        'multiplier' => 2,
        'disbursement_mode' => 'in_payroll',
    ])->assertCreated();

    // 2h × 120,192 × 2 = 480,768 kobo
    expect($res->json('data.amount'))->toBe(480_768)
        ->and($res->json('data.source'))->toBe('attendance');
});

test('reversing a run releases its in-payroll overtime back to approved', function () {
    $ot = $this->postJson('/api/v1/overtime', [
        'employee_id' => $this->employee->id,
        'period' => '2026-07', 'pay_type' => 'hourly', 'hours' => 10, 'multiplier' => 1.5,
        'disbursement_mode' => 'in_payroll',
    ])->json('data.id');
    $this->postJson("/api/v1/overtime/{$ot}/submit")->assertOk();
    approveOvertime($this->owner);

    $run = $this->postJson('/api/v1/payroll-runs', ['period' => '2026-07'])->assertCreated();
    $runId = $run->json('data.id');
    $this->postJson("/api/v1/payroll-runs/{$runId}/submit")->assertOk();
    $wf = WorkflowRequest::query()->where('module', 'payroll')->firstOrFail();
    app(WorkflowService::class)->act($wf, $this->owner, 'approve');
    app(WorkflowService::class)->act($wf->refresh(), $this->owner, 'approve');
    $this->postJson("/api/v1/payroll-runs/{$runId}/lock")->assertOk();
    expect(OvertimePayment::query()->find($ot)->status)->toBe('paid');

    // Reverse the run — the overtime is released back to approved & unbound.
    $this->postJson("/api/v1/payroll-runs/{$runId}/reverse", ['reason' => 'Wrong figures'])->assertCreated();

    $released = OvertimePayment::query()->find($ot);
    expect($released->status)->toBe('approved')->and($released->payroll_run_id)->toBeNull();
});

test('recording overtime requires the overtime.manage permission', function () {
    $clerk = User::factory()->create(['tenant_id' => $this->tenant->id]);
    setPermissionsTeamId($this->tenant->id);
    $clerk->assignRole('employee');

    $this->actingAs($clerk)->postJson('/api/v1/overtime', [
        'employee_id' => $this->employee->id,
        'period' => '2026-07', 'pay_type' => 'fixed', 'amount' => 1_000_000,
        'disbursement_mode' => 'off_cycle',
    ])->assertForbidden();
});

test('overtime records are tenant isolated', function () {
    $this->postJson('/api/v1/overtime', [
        'employee_id' => $this->employee->id,
        'period' => '2026-07', 'pay_type' => 'fixed', 'amount' => 1_000_000,
        'disbursement_mode' => 'off_cycle',
    ])->assertCreated();

    $other = Tenant::factory()->create();
    app(TenantRoleProvisioner::class)->provision($other);
    app(CurrentTenant::class)->set($other);
    setPermissionsTeamId($other->id);
    $otherOwner = User::factory()->create(['tenant_id' => $other->id]);
    $otherOwner->assignRole('owner');

    $this->actingAs($otherOwner)->getJson('/api/v1/overtime')
        ->assertOk()->assertJsonCount(0, 'data');
});
