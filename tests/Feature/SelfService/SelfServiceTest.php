<?php

use App\Core\Workflow\Models\WorkflowRequest;
use App\Core\Workflow\WorkflowProvisioner;
use App\Core\Workflow\WorkflowService;
use App\Models\User;
use App\Modules\Employee\Models\Employee;
use App\Modules\Leave\Models\LeavePolicy;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Payroll\Models\StaffLoan;
use App\Modules\SelfService\Models\ProfileUpdateRequest;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantRoleProvisioner;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\CurrentTenant;

function selfServiceTenant(): array
{
    $tenant = Tenant::factory()->create();
    app(TenantRoleProvisioner::class)->provision($tenant);
    app(WorkflowProvisioner::class)->provision($tenant);
    app(CurrentTenant::class)->set($tenant);
    setPermissionsTeamId($tenant->id);

    $employeeUser = User::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => User::STATUS_ACTIVE,
    ]);
    $employeeUser->assignRole('employee');

    $hr = User::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => User::STATUS_ACTIVE,
    ]);
    $hr->assignRole('hr_admin');

    $employee = Employee::factory()->create([
        'user_id' => $employeeUser->id,
        'first_name' => 'Ada',
        'last_name' => 'Okafor',
        'employee_number' => 'EMP-0001',
        'phone' => '+2348000000000',
    ]);

    test()->actingAs($employeeUser);

    return [$tenant, $employeeUser, $hr, $employee];
}

beforeEach(function (): void {
    [$this->tenant, $this->employeeUser, $this->hr, $this->employee] = selfServiceTenant();
});

test('employee role receives self service permissions by default', function (): void {
    expect($this->employeeUser->can(Permissions::ESS_PROFILE_VIEW))->toBeTrue()
        ->and($this->employeeUser->can(Permissions::ESS_LEAVE_APPLY))->toBeTrue()
        ->and($this->employeeUser->can(Permissions::ESS_LOANS_REQUEST))->toBeTrue()
        ->and($this->employeeUser->can(Permissions::PAYROLL_VIEW))->toBeFalse();
});

test('employee can request an IOU or loan and receives the approval decision notification', function (): void {
    $manager = User::factory()->create(['tenant_id' => $this->tenant->id, 'status' => User::STATUS_ACTIVE]);
    $manager->assignRole('manager');
    $owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'status' => User::STATUS_ACTIVE]);
    $owner->assignRole('owner');

    $this->postJson('/api/v1/self/loan-requests', [
        'type' => StaffLoan::TYPE_ADVANCE,
        'principal' => 5_000_000,
        'start_period' => '2026-08',
        'reason' => 'Emergency household expense',
    ])->assertCreated()
        ->assertJsonPath('data.employee_id', $this->employee->id)
        ->assertJsonPath('data.status', StaffLoan::STATUS_PENDING)
        ->assertJsonPath('data.months', 1);

    expect($manager->unreadNotifications()->count())->toBe(1)
        ->and($this->hr->unreadNotifications()->count())->toBe(1)
        ->and($owner->unreadNotifications()->count())->toBe(1)
        ->and($owner->unreadNotifications()->firstOrFail()->data['action_url'])->toBe('/approvals');

    $this->actingAs($manager)->getJson('/api/v1/approvals')
        ->assertOk()
        ->assertJsonPath('data.pending_for_me.0.record_details.kind', 'money_request')
        ->assertJsonPath('data.pending_for_me.0.record_details.principal', 5_000_000)
        ->assertJsonPath('data.pending_for_me.0.record_details.monthly_installment', 5_000_000)
        ->assertJsonPath('data.pending_for_me.0.record_details.reason', 'Emergency household expense')
        ->assertJsonPath('data.pending_for_me.0.record_details.employee.number', 'EMP-0001');

    $this->actingAs($this->employeeUser);

    $this->getJson('/api/v1/self/loan-requests')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.principal', 5_000_000);

    $workflow = WorkflowRequest::query()->where('module', 'loan')->firstOrFail();
    app(WorkflowService::class)->act($workflow, $manager, 'approve');
    app(WorkflowService::class)->act($workflow->refresh(), $this->hr, 'approve');

    expect(StaffLoan::query()->firstOrFail()->status)->toBe(StaffLoan::STATUS_ACTIVE)
        ->and($this->employeeUser->unreadNotifications()->count())->toBeGreaterThan(0)
        ->and($this->employeeUser->notifications()->latest()->firstOrFail()->data['title'])->toContain('approved')
        ->and($this->employeeUser->notifications()->latest()->firstOrFail()->data['action_url'])->toBe('/self-service/loans');
});

test('employee can view their linked profile only', function (): void {
    $this->getJson('/api/v1/self/profile')
        ->assertOk()
        ->assertJsonPath('data.id', $this->employee->id)
        ->assertJsonPath('data.full_name', 'Ada Okafor');

    $unlinked = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'status' => User::STATUS_ACTIVE,
    ]);
    $unlinked->assignRole('employee');

    $this->actingAs($unlinked)
        ->getJson('/api/v1/self/profile')
        ->assertNotFound();
});

test('profile update requests enter workflow and approved values are applied', function (): void {
    $this->postJson('/api/v1/self/profile-update-requests', [
        'phone' => '+2348111111111',
        'city' => 'Lagos',
    ])->assertCreated()
        ->assertJsonPath('data.status', ProfileUpdateRequest::STATUS_PENDING)
        ->assertJsonPath('data.requested_values.phone', '+2348111111111');

    $workflow = WorkflowRequest::query()
        ->where('module', 'profile_update')
        ->firstOrFail();

    app(WorkflowService::class)->act($workflow, $this->hr, 'approve');

    expect($this->employee->refresh()->phone)->toBe('+2348111111111')
        ->and($this->employee->city)->toBe('Lagos')
        ->and(ProfileUpdateRequest::query()->first()->status)->toBe(ProfileUpdateRequest::STATUS_APPROVED);
});

test('employee can apply for their own leave and see own history and balance', function (): void {
    $type = LeaveType::factory()->create(['name' => 'Annual Leave']);
    LeavePolicy::query()->create([
        'leave_type_id' => $type->id,
        'days_per_year' => 10,
    ]);

    $this->getJson('/api/v1/self/leave-types')
        ->assertOk()
        ->assertJsonPath('data.0.id', $type->id);

    $this->postJson('/api/v1/self/leave-requests', [
        'leave_type_id' => $type->id,
        'start_date' => '2026-07-06',
        'end_date' => '2026-07-07',
        'reason' => 'Rest',
    ])->assertCreated()
        ->assertJsonPath('data.employee.id', $this->employee->id)
        ->assertJsonPath('data.days', 2)
        ->assertJsonPath('data.status', 'pending');

    $this->getJson('/api/v1/self/leave-requests')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.employee.id', $this->employee->id);

    $this->getJson('/api/v1/self/leave-balances?year=2026')
        ->assertOk()
        ->assertJsonPath('data.0.allocated', 10)
        ->assertJsonPath('data.0.remaining', 10);
});

test('employee can view their own attendance period', function (): void {
    $this->getJson('/api/v1/self/attendance?period=2026-07')
        ->assertOk()
        ->assertJsonPath('data.period', '2026-07')
        ->assertJsonPath('data.employee.id', $this->employee->id)
        ->assertJsonStructure(['data' => ['days']]);
});
