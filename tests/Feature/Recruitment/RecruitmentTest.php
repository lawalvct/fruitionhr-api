<?php

use App\Core\Workflow\Models\WorkflowRequest;
use App\Core\Workflow\WorkflowProvisioner;
use App\Core\Workflow\WorkflowService;
use App\Models\User;
use App\Modules\Company\Models\Department;
use App\Modules\Company\Models\EmploymentType;
use App\Modules\Company\Models\Position;
use App\Modules\Employee\Models\Employee;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\ManpowerRequisition;
use App\Modules\Recruitment\Models\Vacancy;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantRoleProvisioner;
use App\Support\Tenancy\CurrentTenant;

function recruitmentTenant(): array
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
    [$this->tenant, $this->owner, $this->manager, $this->hr] = recruitmentTenant();
    $this->actingAs($this->owner);
    $this->department = Department::factory()->create();
    $this->position = Position::factory()->create(['department_id' => $this->department->id]);
    $this->employmentType = EmploymentType::factory()->create();
});

function createApprovedRequisition($test, int $headcount = 1): ManpowerRequisition
{
    $response = $test->postJson('/api/v1/recruitment/requisitions', [
        'department_id' => $test->department->id,
        'position_id' => $test->position->id,
        'employment_type_id' => $test->employmentType->id,
        'title' => 'Senior Accountant',
        'headcount' => $headcount,
        'target_start_date' => '2026-09-01',
        'reason' => 'Approved team expansion',
    ])->assertCreated();

    $requisition = ManpowerRequisition::query()->findOrFail($response->json('data.id'));
    $test->postJson('/api/v1/recruitment/requisitions/'.$requisition->id.'/submit')->assertOk();

    $workflow = WorkflowRequest::query()->where('module', 'recruitment_requisition')->firstOrFail();
    app(WorkflowService::class)->act($workflow, $test->manager, 'approve');
    app(WorkflowService::class)->act($workflow->refresh(), $test->hr, 'approve');

    return $requisition->refresh();
}

test('requisition approval unlocks vacancy creation', function () {
    $draft = ManpowerRequisition::factory()->create([
        'requested_by' => $this->owner->id,
        'department_id' => $this->department->id,
        'position_id' => $this->position->id,
        'headcount' => 1,
    ]);

    $payload = [
        'manpower_requisition_id' => $draft->id,
        'title' => 'Senior Accountant',
        'description' => 'Own month-end reporting and controls.',
        'positions_available' => 1,
    ];
    $this->postJson('/api/v1/recruitment/vacancies', $payload)->assertUnprocessable();

    $approved = createApprovedRequisition($this);
    expect($approved->status)->toBe(ManpowerRequisition::STATUS_APPROVED);

    $this->postJson('/api/v1/recruitment/vacancies', [...$payload, 'manpower_requisition_id' => $approved->id])
        ->assertCreated()->assertJsonPath('data.status', 'draft');
});

test('vacancy headcount cannot exceed the approved requisition', function () {
    $approved = createApprovedRequisition($this, 1);

    $this->postJson('/api/v1/recruitment/vacancies', [
        'manpower_requisition_id' => $approved->id,
        'title' => 'Accountant',
        'description' => 'Finance role',
        'positions_available' => 2,
    ])->assertConflict();
});

test('candidate pipeline records every stage transition', function () {
    $approved = createApprovedRequisition($this);
    $vacancyId = $this->postJson('/api/v1/recruitment/vacancies', [
        'manpower_requisition_id' => $approved->id,
        'title' => 'Accountant',
        'description' => 'Finance role',
        'positions_available' => 1,
    ])->assertCreated()->json('data.id');
    $this->postJson('/api/v1/recruitment/vacancies/'.$vacancyId.'/open')->assertOk();

    $applicationId = $this->postJson('/api/v1/recruitment/applications', [
        'vacancy_id' => $vacancyId,
        'first_name' => 'Ada',
        'last_name' => 'Okoro',
        'email' => 'ada.recruit@example.com',
        'phone' => '08030000000',
        'source' => 'referral',
    ])->assertCreated()->json('data.id');

    $this->postJson('/api/v1/recruitment/applications/'.$applicationId.'/move', [
        'stage' => 'shortlisted',
        'notes' => 'Strong relevant experience',
    ])->assertOk()->assertJsonPath('data.stage', 'shortlisted');

    $application = Application::query()->findOrFail($applicationId);
    expect($application->stageHistory()->pluck('to_stage')->all())->toBe(['applied', 'shortlisted']);
});

test('accepted candidate completes onboarding and becomes an employee', function () {
    $approved = createApprovedRequisition($this);
    $vacancyId = $this->postJson('/api/v1/recruitment/vacancies', [
        'manpower_requisition_id' => $approved->id,
        'employment_type_id' => $this->employmentType->id,
        'title' => 'Accountant',
        'description' => 'Finance role',
        'positions_available' => 1,
    ])->assertCreated()->json('data.id');
    $this->postJson('/api/v1/recruitment/vacancies/'.$vacancyId.'/open')->assertOk();

    $applicationId = $this->postJson('/api/v1/recruitment/applications', [
        'vacancy_id' => $vacancyId,
        'first_name' => 'Bola',
        'last_name' => 'Akin',
        'email' => 'bola.hire@example.com',
    ])->assertCreated()->json('data.id');

    $offerId = $this->postJson('/api/v1/recruitment/applications/'.$applicationId.'/offers', [
        'annual_salary' => 1200000000,
        'start_date' => '2026-09-01',
        'terms' => 'Full-time employment',
    ])->assertCreated()->json('data.id');
    $this->postJson('/api/v1/recruitment/applications/'.$applicationId.'/offers/'.$offerId.'/send')->assertOk();
    $this->postJson('/api/v1/recruitment/applications/'.$applicationId.'/offers/'.$offerId.'/accept')->assertOk();

    $application = Application::query()->findOrFail($applicationId);
    foreach ($application->onboardingTasks as $task) {
        $this->postJson('/api/v1/recruitment/applications/'.$applicationId.'/onboarding-tasks/'.$task->id.'/complete')->assertOk();
    }

    $this->postJson('/api/v1/recruitment/applications/'.$applicationId.'/hire')
        ->assertCreated()->assertJsonPath('data.personal_email', 'bola.hire@example.com');

    expect(Employee::query()->where('personal_email', 'bola.hire@example.com')->exists())->toBeTrue()
        ->and($application->refresh()->stage)->toBe('hired');
});

test('recruitment data is tenant isolated', function () {
    $requisition = ManpowerRequisition::factory()->create(['requested_by' => $this->owner->id]);
    $vacancy = Vacancy::factory()->create(['manpower_requisition_id' => $requisition->id]);
    $application = Application::factory()->create(['vacancy_id' => $vacancy->id]);

    [, $otherOwner] = recruitmentTenant();
    $this->actingAs($otherOwner);

    $this->getJson('/api/v1/recruitment/requisitions')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/recruitment/applications/'.$application->id)->assertNotFound();
});

test('employee role has no recruitment access', function () {
    $employeeUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
    setPermissionsTeamId($this->tenant->id);
    $employeeUser->assignRole('employee');

    $this->actingAs($employeeUser)->getJson('/api/v1/recruitment/requisitions')->assertForbidden();
});
