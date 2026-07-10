<?php

use App\Core\Workflow\Models\WorkflowRequest;
use App\Core\Workflow\WorkflowService;
use App\Models\User;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Models\ShiftAssignment;
use App\Modules\Employee\Models\Employee;
use App\Modules\Leave\Models\LeaveBalance;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantRoleProvisioner;
use App\Core\Workflow\WorkflowProvisioner;
use App\Support\Tenancy\CurrentTenant;

function leaveTenant(): array
{
    $tenant = Tenant::factory()->create();
    app(TenantRoleProvisioner::class)->provision($tenant);
    app(WorkflowProvisioner::class)->provision($tenant);
    app(CurrentTenant::class)->set($tenant);
    setPermissionsTeamId($tenant->id);

    $owner = User::factory()->create(['tenant_id' => $tenant->id]);
    $owner->assignRole('owner');

    $manager = User::factory()->create(['tenant_id' => $tenant->id]);
    $manager->assignRole('manager');

    $hr = User::factory()->create(['tenant_id' => $tenant->id]);
    $hr->assignRole('hr_admin');

    return [$tenant, $owner, $manager, $hr];
}

beforeEach(function () {
    [$this->tenant, $this->owner, $this->manager, $this->hr] = leaveTenant();
    $this->actingAs($this->owner);
});

test('a leave type with annual allocation can be created and listed', function () {
    $this->postJson('/api/v1/leave-types', [
        'name' => 'Annual Leave',
        'code' => 'ANN',
        'is_paid' => true,
        'days_per_year' => 20,
    ])->assertCreated()->assertJsonPath('data.days_per_year', 20);

    $this->getJson('/api/v1/leave-types')->assertOk()->assertJsonPath('data.0.name', 'Annual Leave');
});

test('applying for leave computes working days and enters the approval workflow', function () {
    $type = LeaveType::factory()->create(['name' => 'Annual Leave']);
    // seed allocation
    $this->putJson("/api/v1/leave-types/{$type->id}", [
        'name' => 'Annual Leave', 'days_per_year' => 20,
    ])->assertOk();

    $employee = Employee::factory()->create();

    // Mon 2026-07-06 to Fri 2026-07-10 = 5 working days
    $response = $this->postJson('/api/v1/leave-requests', [
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'start_date' => '2026-07-06',
        'end_date' => '2026-07-10',
        'reason' => 'Family time',
    ])->assertCreated();

    expect($response->json('data.days'))->toBe(5)
        ->and($response->json('data.status'))->toBe('pending');

    // A workflow request now exists for the leave module.
    expect(WorkflowRequest::query()->where('module', 'leave')->count())->toBe(1);
});

test('a weekend-only range is rejected as no working days', function () {
    $type = LeaveType::factory()->create();
    $employee = Employee::factory()->create();

    $this->postJson('/api/v1/leave-requests', [
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'start_date' => '2026-07-11', // Sat
        'end_date' => '2026-07-12',   // Sun
    ])->assertUnprocessable()->assertJsonValidationErrors('start_date');
});

test('applying beyond the available balance is rejected', function () {
    $type = LeaveType::factory()->create();
    $this->putJson("/api/v1/leave-types/{$type->id}", [
        'name' => $type->name, 'days_per_year' => 3,
    ])->assertOk();

    $employee = Employee::factory()->create();

    // 5 working days requested but only 3 allocated
    $this->postJson('/api/v1/leave-requests', [
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'start_date' => '2026-07-06',
        'end_date' => '2026-07-10',
    ])->assertUnprocessable()->assertJsonValidationErrors('start_date');
});

test('full flow: apply, approve through workflow, balance debited and attendance shows on-leave', function () {
    $type = LeaveType::factory()->create();
    $this->putJson("/api/v1/leave-types/{$type->id}", [
        'name' => $type->name, 'days_per_year' => 20,
    ])->assertOk();

    $shift = Shift::factory()->create();
    $employee = Employee::factory()->create();
    ShiftAssignment::query()->create([
        'employee_id' => $employee->id, 'shift_id' => $shift->id,
        'effective_from' => '2026-07-01', 'is_current' => true,
    ]);

    // Apply (Monâ€“Wed = 3 working days)
    $this->postJson('/api/v1/leave-requests', [
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'start_date' => '2026-07-06',
        'end_date' => '2026-07-08',
    ])->assertCreated();

    $workflowRequest = WorkflowRequest::query()->where('module', 'leave')->firstOrFail();
    $service = app(WorkflowService::class);

    // Two-step: manager then HR
    $service->act($workflowRequest, $this->manager, 'approve');
    $service->act($workflowRequest->refresh(), $this->hr, 'approve');

    // Leave request approved, balance debited
    $leaveRequest = LeaveRequest::query()->firstOrFail();
    expect($leaveRequest->status)->toBe(LeaveRequest::STATUS_APPROVED);

    $balance = LeaveBalance::query()->where('employee_id', $employee->id)->firstOrFail();
    expect($balance->taken)->toBe(3)
        ->and($balance->allocated - $balance->taken)->toBe(17);

    // Attendance grid now shows those days as on_leave
    $grid = $this->getJson('/api/v1/attendance?period=2026-07')->assertOk();
    $row = collect($grid->json('data.rows'))->firstWhere('employee.id', $employee->id);
    expect($row['days']['2026-07-06']['status'])->toBe('on_leave')
        ->and($row['days']['2026-07-07']['status'])->toBe('on_leave');
});

test('rejecting a leave request does not debit the balance', function () {
    $type = LeaveType::factory()->create();
    $this->putJson("/api/v1/leave-types/{$type->id}", ['name' => $type->name, 'days_per_year' => 20])->assertOk();
    $employee = Employee::factory()->create();

    $this->postJson('/api/v1/leave-requests', [
        'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'start_date' => '2026-07-06', 'end_date' => '2026-07-08',
    ])->assertCreated();

    $workflowRequest = WorkflowRequest::query()->where('module', 'leave')->firstOrFail();
    app(WorkflowService::class)->act($workflowRequest, $this->manager, 'reject', 'No cover');

    expect(LeaveRequest::query()->first()->status)->toBe(LeaveRequest::STATUS_REJECTED)
        ->and(LeaveBalance::query()->where('employee_id', $employee->id)->first()?->taken ?? 0)->toBe(0);
});

test('leave data is tenant isolated', function () {
    $type = LeaveType::factory()->create();
    $employee = Employee::factory()->create();
    LeaveRequest::factory()->create([
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
    ]);

    $otherTenant = Tenant::factory()->create();
    app(TenantRoleProvisioner::class)->provision($otherTenant);
    app(CurrentTenant::class)->set($otherTenant);
    setPermissionsTeamId($otherTenant->id);
    $otherOwner = User::factory()->create(['tenant_id' => $otherTenant->id]);
    $otherOwner->assignRole('owner');

    $this->actingAs($otherOwner)->getJson('/api/v1/leave-requests')
        ->assertOk()->assertJsonCount(0, 'data');
});

