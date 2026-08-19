<?php

use App\Core\Workflow\Events\WorkflowApproved;
use App\Core\Workflow\Events\WorkflowRejected;
use App\Core\Workflow\Models\WorkflowDefinition;
use App\Core\Workflow\Models\WorkflowRequest;
use App\Core\Workflow\WorkflowProvisioner;
use App\Core\Workflow\WorkflowService;
use App\Models\User;
use App\Modules\Employee\Models\Employee;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Payroll\Models\OvertimePayment;
use App\Modules\Payroll\Models\StaffLoan;
use App\Modules\Recruitment\Models\ManpowerRequisition;
use App\Modules\SelfService\Models\ProfileUpdateRequest;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantRoleProvisioner;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

function workflowTenant(): array
{
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    app(TenantRoleProvisioner::class)->provision($tenant);
    app(WorkflowProvisioner::class)->provision($tenant);
    setPermissionsTeamId($tenant->id);

    $employee = User::factory()->create(['tenant_id' => $tenant->id]);
    $employee->assignRole('employee');

    $manager = User::factory()->create(['tenant_id' => $tenant->id]);
    $manager->assignRole('manager');

    $hr = User::factory()->create(['tenant_id' => $tenant->id]);
    $hr->assignRole('hr_admin');

    $record = Employee::factory()->create();

    return [$tenant, $employee, $manager, $hr, $record];
}

test('a two-step workflow approves end to end and fires WorkflowApproved', function () {
    Event::fake([WorkflowApproved::class]);
    [, $employeeUser, $manager, $hr, $record] = workflowTenant();

    $service = app(WorkflowService::class);
    $request = $service->submit($record, 'leave', $employeeUser);

    expect($request->status)->toBe(WorkflowRequest::STATUS_PENDING)
        ->and($request->currentStep->approver_role)->toBe('manager');

    $service->act($request, $manager, 'approve', 'Fine by me');
    $request->refresh();
    expect($request->currentStep->approver_role)->toBe('hr_admin');

    $service->act($request, $hr, 'approve');
    $request->refresh();

    expect($request->status)->toBe(WorkflowRequest::STATUS_APPROVED)
        ->and($request->actions)->toHaveCount(2)
        ->and($request->completed_at)->not->toBeNull();

    Event::assertDispatched(WorkflowApproved::class);

    // Requester was notified of the outcome.
    expect($employeeUser->notifications()->count())->toBeGreaterThan(0);
});

test('an owner can act on any step even without the step role', function () {
    [, $employeeUser, , , $record] = workflowTenant();

    $owner = User::factory()->create(['tenant_id' => $employeeUser->tenant_id]);
    $owner->assignRole('owner');

    $service = app(WorkflowService::class);
    $request = $service->submit($record, 'leave', $employeeUser);

    $service->act($request, $owner, 'approve');
    $request->refresh();

    expect($request->currentStep->approver_role)->toBe('hr_admin');

    // Owner sees pending steps in the inbox too.
    $response = $this->actingAs($owner)->getJson('/api/v1/approvals')->assertOk();
    expect($response->json('data.pending_for_me'))->toHaveCount(1);
});

test('a non-approver cannot act on the current step', function () {
    [, $employeeUser, , , $record] = workflowTenant();

    $service = app(WorkflowService::class);
    $request = $service->submit($record, 'leave', $employeeUser);

    // The requester holds only the employee role — not a manager.
    $service->act($request, $employeeUser, 'approve');
})->throws(AccessDeniedHttpException::class);

test('rejection stops the flow and fires WorkflowRejected', function () {
    Event::fake([WorkflowRejected::class]);
    [, $employeeUser, $manager, $hr, $record] = workflowTenant();

    $service = app(WorkflowService::class);
    $request = $service->submit($record, 'leave', $employeeUser);

    $service->act($request, $manager, 'reject', 'Not enough cover');
    $request->refresh();

    expect($request->status)->toBe(WorkflowRequest::STATUS_REJECTED);
    Event::assertDispatched(WorkflowRejected::class);

    // Completed requests cannot be acted on again.
    expect(fn () => $service->act($request, $hr, 'approve'))
        ->toThrow(ConflictHttpException::class);
});

test('approvals inbox lists requests pending for my role via the API', function () {
    [, $employeeUser, $manager, , $record] = workflowTenant();

    app(WorkflowService::class)->submit($record, 'leave', $employeeUser);

    $response = $this->actingAs($manager)->getJson('/api/v1/approvals')->assertOk();

    expect($response->json('data.pending_for_me'))->toHaveCount(1)
        ->and($response->json('data.pending_for_me.0.module'))->toBe('leave');

    // Approve through the API
    $id = $response->json('data.pending_for_me.0.id');
    $this->actingAs($manager)
        ->postJson("/api/v1/approvals/{$id}/approve", ['comments' => 'ok'])
        ->assertOk()
        ->assertJsonPath('data.current_step.approver_role', 'hr_admin');
});

test('workflow requests are tenant isolated', function () {
    [, $employeeUser, , , $record] = workflowTenant();
    app(WorkflowService::class)->submit($record, 'leave', $employeeUser);

    // A manager from another tenant sees nothing.
    $otherTenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($otherTenant);
    app(TenantRoleProvisioner::class)->provision($otherTenant);
    setPermissionsTeamId($otherTenant->id);
    $otherManager = User::factory()->create(['tenant_id' => $otherTenant->id]);
    $otherManager->assignRole('manager');

    $response = $this->actingAs($otherManager)->getJson('/api/v1/approvals')->assertOk();

    expect($response->json('data.pending_for_me'))->toHaveCount(0);
});

test('registration provisions default workflow definitions', function () {
    $this->postJson('/api/v1/register', [
        'company_name' => 'Flow Co',
        'name' => 'Flo Owner',
        'email' => 'flo@flowco.test',
        'password' => 'Sup3r-Secret!',
        'password_confirmation' => 'Sup3r-Secret!',
    ])->assertCreated();

    $tenant = Tenant::query()->where('slug', 'flow-co')->firstOrFail();
    app(CurrentTenant::class)->set($tenant);

    expect(WorkflowDefinition::query()->pluck('module')->sort()->values()->all())
        ->toBe(['leave', 'loan', 'overtime', 'payroll', 'profile_update', 'recruitment_requisition']);
});

test('the inbox loads for someone who submitted their own request', function () {
    // Regression: `my_requests` omitted `record` from its eager load while
    // loadMorph() reads it via pluck(). With lazy loading disabled that threw,
    // so the whole inbox 500'd for anyone who had ever submitted anything —
    // and every other test here views the inbox as a non-submitter.
    [, $employeeUser, , , $record] = workflowTenant();

    app(WorkflowService::class)->submit($record, 'leave', $employeeUser);

    $response = $this->actingAs($employeeUser)->getJson('/api/v1/approvals')->assertOk();

    expect($response->json('data.my_requests'))->toHaveCount(1)
        ->and($response->json('data.my_requests.0.module'))->toBe('leave');
});

test('an owner who submitted a request still sees the pending queue', function () {
    // The reported bug: an owner raises a requisition, then finds their own
    // approvals page empty because the endpoint errored rather than rendered.
    [, $employeeUser, , , $record] = workflowTenant();
    $owner = User::factory()->create(['tenant_id' => $record->tenant_id]);
    setPermissionsTeamId($record->tenant_id);
    $owner->assignRole('owner');

    // The owner submits something of their own...
    app(WorkflowService::class)->submit($record, 'leave', $owner);
    // ...and someone else submits too.
    app(WorkflowService::class)->submit(Employee::factory()->create(), 'leave', $employeeUser);

    $response = $this->actingAs($owner)->getJson('/api/v1/approvals')->assertOk();

    // Owners see every pending step, including the one they raised.
    expect($response->json('data.pending_for_me'))->toHaveCount(2)
        ->and($response->json('data.my_requests'))->toHaveCount(1);
});

test('the inbox renders every record type without lazy loading its relations', function () {
    // Regression: the inbox only eager loaded StaffLoan's employee, so any
    // record whose workflowSummary() reaches for a relation — LeaveRequest
    // wants employee + leaveType — threw a LazyLoadingViolationException and
    // 500'd the page. Every other test here submits a bare Employee, which has
    // no workflowSummary(), so the real record types went unexercised.
    [$tenant, $employeeUser, , , $employee] = workflowTenant();

    $owner = User::factory()->create(['tenant_id' => $tenant->id]);
    $owner->assignRole('owner');

    // Two leave requests on purpose: Eloquent only arms preventsLazyLoading on
    // hydrated models when a query returns more than one row (Builder::hydrate),
    // so a single pending leave request lazy loads quietly and hides the bug.
    $leaveType = LeaveType::factory()->create(['name' => 'Annual Leave']);
    $leave = LeaveRequest::factory()->create([
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'requested_by' => $employeeUser->id,
    ]);
    $secondLeave = LeaveRequest::factory()->create([
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'requested_by' => $employeeUser->id,
    ]);
    $loan = StaffLoan::factory()->create(['employee_id' => $employee->id]);
    $overtime = OvertimePayment::factory()->create(['employee_id' => $employee->id]);
    $requisition = ManpowerRequisition::factory()->create(['requested_by' => $employeeUser->id]);
    $profileUpdate = ProfileUpdateRequest::create([
        'employee_id' => $employee->id,
        'requested_by' => $employeeUser->id,
        'current_values' => ['phone' => '08010000000'],
        'requested_values' => ['phone' => '08020000000'],
        'status' => ProfileUpdateRequest::STATUS_PENDING,
    ]);

    $service = app(WorkflowService::class);
    $service->submit($leave, 'leave', $employeeUser);
    $service->submit($secondLeave, 'leave', $employeeUser);
    $service->submit($loan, 'loan', $employeeUser);
    $service->submit($overtime, 'overtime', $employeeUser);
    $service->submit($requisition, 'recruitment_requisition', $employeeUser);
    $service->submit($profileUpdate, 'profile_update', $employeeUser);

    $response = $this->actingAs($owner)->getJson('/api/v1/approvals')->assertOk();

    $summaries = collect($response->json('data.pending_for_me'))
        ->pluck('record_summary', 'module');

    expect($response->json('data.pending_for_me'))->toHaveCount(6)
        ->and($summaries)->toHaveCount(5)
        // Relations resolved, so the real names land in the summary rather than
        // the 'Employee' / 'Leave' placeholders that signal an unloaded relation.
        ->and($summaries['leave'])->toContain($employee->full_name)
        ->and($summaries['leave'])->toContain('Annual Leave')
        ->and($summaries['loan'])->toContain($employee->full_name)
        ->and($summaries['overtime'])->toContain($employee->full_name)
        ->and($summaries['profile_update'])->toContain($employee->full_name)
        ->and($summaries['recruitment_requisition'])->toContain($requisition->title);
});
