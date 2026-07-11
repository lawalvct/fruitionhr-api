<?php

use App\Core\Workflow\Events\WorkflowApproved;
use App\Core\Workflow\Events\WorkflowRejected;
use App\Core\Workflow\Models\WorkflowDefinition;
use App\Core\Workflow\Models\WorkflowRequest;
use App\Core\Workflow\WorkflowService;
use App\Models\User;
use App\Modules\Employee\Models\Employee;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantRoleProvisioner;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Event;

function workflowTenant(): array
{
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    app(TenantRoleProvisioner::class)->provision($tenant);
    app(\App\Core\Workflow\WorkflowProvisioner::class)->provision($tenant);
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
})->throws(Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class);

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
        ->toThrow(Symfony\Component\HttpKernel\Exception\ConflictHttpException::class);
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
        ->toBe(['leave', 'payroll', 'profile_update', 'recruitment_requisition']);
});
