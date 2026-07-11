<?php

use App\Models\User;
use App\Modules\Employee\Models\Employee;
use App\Modules\Performance\Models\AppraisalCycle;
use App\Modules\Performance\Models\AppraisalTemplate;
use App\Modules\Performance\Models\Goal;
use App\Modules\Performance\Models\PerformanceCategory;
use App\Modules\Performance\Models\PerformanceKpi;
use App\Modules\Performance\Models\RatingScale;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantRoleProvisioner;
use App\Support\Tenancy\CurrentTenant;

function performanceTenant(): array
{
    $tenant = Tenant::factory()->create();
    app(TenantRoleProvisioner::class)->provision($tenant);
    app(CurrentTenant::class)->set($tenant);
    setPermissionsTeamId($tenant->id);

    $owner = User::factory()->create(['tenant_id' => $tenant->id]);
    $owner->assignRole('owner');
    $manager = User::factory()->create(['tenant_id' => $tenant->id]);
    $manager->assignRole('manager');
    $employeeUser = User::factory()->create(['tenant_id' => $tenant->id]);
    $employeeUser->assignRole('employee');
    $employee = Employee::factory()->create(['user_id' => $employeeUser->id]);

    return [$tenant, $owner, $manager, $employeeUser, $employee];
}

function performanceSetup(): array
{
    $category = PerformanceCategory::factory()->create(['name' => 'Delivery']);
    $quality = PerformanceKpi::factory()->create(['performance_category_id' => $category->id, 'name' => 'Quality']);
    $timeliness = PerformanceKpi::factory()->create(['performance_category_id' => $category->id, 'name' => 'Timeliness']);
    $scale = RatingScale::query()->create(['name' => 'Percentage grade']);
    foreach ([
        ['label' => 'Poor', 'min_score_basis_points' => 0, 'max_score_basis_points' => 4999],
        ['label' => 'Good', 'min_score_basis_points' => 5000, 'max_score_basis_points' => 6999],
        ['label' => 'Very Good', 'min_score_basis_points' => 7000, 'max_score_basis_points' => 7999],
        ['label' => 'Excellent', 'min_score_basis_points' => 8000, 'max_score_basis_points' => 8999],
        ['label' => 'Outstanding', 'min_score_basis_points' => 9000, 'max_score_basis_points' => 10000],
    ] as $index => $option) {
        $scale->options()->create([...$option, 'sort_order' => $index + 1]);
    }
    $template = AppraisalTemplate::query()->create(['rating_scale_id' => $scale->id, 'name' => 'Annual review']);
    $firstItem = $template->items()->create(['performance_kpi_id' => $quality->id, 'weight' => 40]);
    $secondItem = $template->items()->create(['performance_kpi_id' => $timeliness->id, 'weight' => 60]);
    $cycle = AppraisalCycle::factory()->create(['status' => 'open']);

    return [$category, $quality, $timeliness, $scale, $template, $firstItem, $secondItem, $cycle];
}

beforeEach(function () {
    [$this->tenant, $this->owner, $this->manager, $this->employeeUser, $this->employee] = performanceTenant();
    $this->actingAs($this->owner);
});

test('template KPI weights must total exactly one hundred percent', function () {
    [, $quality, $timeliness, $scale] = performanceSetup();

    $this->postJson('/api/v1/performance/templates', [
        'rating_scale_id' => $scale->id,
        'name' => 'Invalid template',
        'items' => [
            ['performance_kpi_id' => $quality->id, 'weight' => 40],
            ['performance_kpi_id' => $timeliness->id, 'weight' => 50],
        ],
    ])->assertUnprocessable()->assertJsonValidationErrors('items');
});

test('reviewer weights must total exactly one hundred percent', function () {
    [, , , , $template, , , $cycle] = performanceSetup();

    $this->postJson('/api/v1/performance/assignments', [
        'appraisal_cycle_id' => $cycle->id,
        'appraisal_template_id' => $template->id,
        'employee_id' => $this->employee->id,
        'reviewers' => [
            ['reviewer_user_id' => $this->employeeUser->id, 'reviewer_type' => 'self', 'weight' => 20],
            ['reviewer_user_id' => $this->manager->id, 'reviewer_type' => 'manager', 'weight' => 70],
        ],
    ])->assertUnprocessable()->assertJsonValidationErrors('reviewers');
});

test('multi-source reviews calculate a deterministic weighted final score and grade', function () {
    [, , , , $template, $firstItem, $secondItem, $cycle] = performanceSetup();
    $assignment = $this->postJson('/api/v1/performance/assignments', [
        'appraisal_cycle_id' => $cycle->id,
        'appraisal_template_id' => $template->id,
        'employee_id' => $this->employee->id,
        'reviewers' => [
            ['reviewer_user_id' => $this->employeeUser->id, 'reviewer_type' => 'self', 'weight' => 10],
            ['reviewer_user_id' => $this->manager->id, 'reviewer_type' => 'manager', 'weight' => 90],
        ],
    ])->assertCreated()->json('data');

    $selfReviewer = collect($assignment['reviewers'])->firstWhere('reviewer_type', 'self');
    $managerReviewer = collect($assignment['reviewers'])->firstWhere('reviewer_type', 'manager');

    $this->actingAs($this->employeeUser)->postJson(
        '/api/v1/performance/assignments/'.$assignment['id'].'/reviewers/'.$selfReviewer['id'].'/submit',
        [
            'comments' => 'Self assessment',
            'scores' => [
                ['appraisal_template_item_id' => $firstItem->id, 'score_basis_points' => 10000],
                ['appraisal_template_item_id' => $secondItem->id, 'score_basis_points' => 8000],
            ],
        ],
    )->assertOk()->assertJsonPath('data.status', 'in_progress');

    $response = $this->actingAs($this->manager)->postJson(
        '/api/v1/performance/assignments/'.$assignment['id'].'/reviewers/'.$managerReviewer['id'].'/submit',
        [
            'comments' => 'Manager assessment',
            'scores' => [
                ['appraisal_template_item_id' => $firstItem->id, 'score_basis_points' => 8000],
                ['appraisal_template_item_id' => $secondItem->id, 'score_basis_points' => 6000],
            ],
        ],
    )->assertOk();

    $response->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.result.final_score_basis_points', 7000)
        ->assertJsonPath('data.result.grade', 'Very Good');
});

test('a reviewer cannot submit another users review', function () {
    [, , , , $template, $firstItem, $secondItem, $cycle] = performanceSetup();
    $outsider = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $outsider->assignRole('employee');

    $assignment = $this->postJson('/api/v1/performance/assignments', [
        'appraisal_cycle_id' => $cycle->id,
        'appraisal_template_id' => $template->id,
        'employee_id' => $this->employee->id,
        'reviewers' => [['reviewer_user_id' => $this->employeeUser->id, 'reviewer_type' => 'self', 'weight' => 100]],
    ])->assertCreated()->json('data');

    $reviewerId = $assignment['reviewers'][0]['id'];
    $this->actingAs($outsider)->postJson('/api/v1/performance/assignments/'.$assignment['id'].'/reviewers/'.$reviewerId.'/submit', [
        'scores' => [
            ['appraisal_template_item_id' => $firstItem->id, 'score_basis_points' => 8000],
            ['appraisal_template_item_id' => $secondItem->id, 'score_basis_points' => 8000],
        ],
    ])->assertForbidden();
});

test('employees create individual goals and complete them through check-ins', function () {
    $goalId = $this->actingAs($this->employeeUser)->postJson('/api/v1/goals', [
        'level' => 'company',
        'title' => 'Complete professional certification',
        'description' => 'Finish all course modules and examination.',
        'weight' => 25,
        'target_value' => 10,
        'current_value' => 0,
        'measurement_unit' => 'modules',
        'status' => 'active',
        'due_at' => '2026-12-15',
    ])->assertCreated()
        ->assertJsonPath('data.level', 'individual')
        ->assertJsonPath('data.employee.id', $this->employee->id)
        ->json('data.id');

    $this->postJson('/api/v1/goals/'.$goalId.'/check-ins', [
        'progress' => 100,
        'current_value' => 10,
        'comment' => 'Certification completed.',
    ])->assertOk()
        ->assertJsonPath('data.progress', 100)
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonCount(1, 'data.checkins');
});

test('employees only see their own goals', function () {
    Goal::factory()->create(['employee_id' => $this->employee->id, 'owner_user_id' => $this->employeeUser->id]);
    $otherUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $otherUser->assignRole('employee');
    Employee::factory()->create(['user_id' => $otherUser->id]);

    $this->actingAs($otherUser)->getJson('/api/v1/goals')->assertOk()->assertJsonCount(0, 'data');
});

test('assignments cannot be created in a draft cycle', function () {
    [, , , , $template] = performanceSetup();
    $cycle = AppraisalCycle::factory()->create(['status' => 'draft']);

    $this->postJson('/api/v1/performance/assignments', [
        'appraisal_cycle_id' => $cycle->id,
        'appraisal_template_id' => $template->id,
        'employee_id' => $this->employee->id,
        'reviewers' => [['reviewer_user_id' => $this->manager->id, 'reviewer_type' => 'manager', 'weight' => 100]],
    ])->assertConflict();
});

test('performance and goal records are tenant isolated', function () {
    PerformanceCategory::factory()->create(['name' => 'Tenant A category']);
    Goal::factory()->create(['title' => 'Tenant A goal']);

    [, $otherOwner] = performanceTenant();
    $this->actingAs($otherOwner);

    $this->getJson('/api/v1/performance/categories')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/goals')->assertOk()->assertJsonCount(0, 'data');
});
