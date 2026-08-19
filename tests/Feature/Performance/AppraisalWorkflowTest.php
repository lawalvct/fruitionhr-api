<?php

use App\Models\User;
use App\Modules\Employee\Models\Employee;
use App\Modules\Performance\Models\AppraisalCycle;
use App\Modules\Performance\Models\AppraisalResult;
use App\Modules\Performance\Models\AppraisalTemplate;
use App\Modules\Performance\Models\CalibrationAdjustment;
use App\Modules\Performance\Models\PerformanceCategory;
use App\Modules\Performance\Models\PerformanceImprovementPlan;
use App\Modules\Performance\Models\PerformanceKpi;
use App\Modules\Performance\Models\RatingScale;
use App\Modules\Performance\Services\PerformanceDefaultsProvisioner;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantRoleProvisioner;
use App\Support\Tenancy\CurrentTenant;

function appraisalTenant(): array
{
    $tenant = Tenant::factory()->create();
    app(TenantRoleProvisioner::class)->provision($tenant);
    app(CurrentTenant::class)->set($tenant);
    setPermissionsTeamId($tenant->id);

    $owner = User::factory()->create(['tenant_id' => $tenant->id]);
    $owner->assignRole('owner');
    $employeeUser = User::factory()->create(['tenant_id' => $tenant->id]);
    $employeeUser->assignRole('employee');
    $employee = Employee::factory()->create(['user_id' => $employeeUser->id]);

    return [$tenant, $owner, $employeeUser, $employee];
}

/**
 * Template: two KPIs, the second optional. Manager-only reviewer for simple math.
 */
function workflowSetup(array $templateOverrides = [], array $cycleOverrides = []): array
{
    $category = PerformanceCategory::factory()->create(['name' => 'Delivery']);
    $mandatoryKpi = PerformanceKpi::factory()->create(['performance_category_id' => $category->id, 'name' => 'Quality']);
    $optionalKpi = PerformanceKpi::factory()->create(['performance_category_id' => $category->id, 'name' => 'Stretch']);

    $scale = RatingScale::query()->create(['name' => 'Bands']);
    foreach ([
        ['Poor', 0, 5999], ['Good', 6000, 8499], ['Excellent', 8500, 10000],
    ] as $index => [$label, $min, $max]) {
        $scale->options()->create(['label' => $label, 'min_score_basis_points' => $min, 'max_score_basis_points' => $max, 'sort_order' => $index + 1]);
    }

    $template = AppraisalTemplate::query()->create([
        'rating_scale_id' => $scale->id, 'name' => 'Workflow template', ...$templateOverrides,
    ]);
    $mandatoryItem = $template->items()->create(['performance_kpi_id' => $mandatoryKpi->id, 'weight' => 60, 'is_mandatory' => true]);
    $optionalItem = $template->items()->create(['performance_kpi_id' => $optionalKpi->id, 'weight' => 40, 'is_mandatory' => false]);
    $cycle = AppraisalCycle::factory()->create(['status' => 'open', ...$cycleOverrides]);

    return [$template, $mandatoryItem, $optionalItem, $cycle];
}

function createManagerOnlyAssignment(User $manager, Employee $employee, AppraisalTemplate $template, AppraisalCycle $cycle): array
{
    return test()->postJson('/api/v1/performance/assignments', [
        'appraisal_cycle_id' => $cycle->id,
        'appraisal_template_id' => $template->id,
        'employee_id' => $employee->id,
        'reviewers' => [['reviewer_user_id' => $manager->id, 'reviewer_type' => 'manager', 'weight' => 100]],
    ])->assertCreated()->json('data');
}

beforeEach(function () {
    [$this->tenant, $this->owner, $this->employeeUser, $this->employee] = appraisalTenant();
    $this->actingAs($this->owner);
});

test('optional KPIs can be skipped and weights re-normalize', function () {
    [$template, $mandatoryItem, , $cycle] = workflowSetup();
    $assignment = createManagerOnlyAssignment($this->owner, $this->employee, $template, $cycle);
    $reviewerId = $assignment['reviewers'][0]['id'];

    // Score only the mandatory KPI at 80% — the optional one is skipped, so the
    // re-normalized reviewer score must be exactly 8000, not 60% of it.
    $this->postJson("/api/v1/performance/assignments/{$assignment['id']}/reviewers/{$reviewerId}/submit", [
        'scores' => [['appraisal_template_item_id' => $mandatoryItem->id, 'score_basis_points' => 8000]],
    ])->assertOk()
        ->assertJsonPath('data.result.final_score_basis_points', 8000)
        ->assertJsonPath('data.result.status', AppraisalResult::STATUS_PENDING_APPROVAL);
});

test('a review missing a mandatory KPI is rejected', function () {
    [$template, , $optionalItem, $cycle] = workflowSetup();
    $assignment = createManagerOnlyAssignment($this->owner, $this->employee, $template, $cycle);
    $reviewerId = $assignment['reviewers'][0]['id'];

    $this->postJson("/api/v1/performance/assignments/{$assignment['id']}/reviewers/{$reviewerId}/submit", [
        'scores' => [['appraisal_template_item_id' => $optionalItem->id, 'score_basis_points' => 9000]],
    ])->assertUnprocessable()->assertJsonValidationErrors('scores');
});

test('result flows approval then employee acknowledgment', function () {
    [$template, $mandatoryItem, $optionalItem, $cycle] = workflowSetup();
    $assignment = createManagerOnlyAssignment($this->owner, $this->employee, $template, $cycle);
    $reviewerId = $assignment['reviewers'][0]['id'];

    $result = $this->postJson("/api/v1/performance/assignments/{$assignment['id']}/reviewers/{$reviewerId}/submit", [
        'scores' => [
            ['appraisal_template_item_id' => $mandatoryItem->id, 'score_basis_points' => 9000],
            ['appraisal_template_item_id' => $optionalItem->id, 'score_basis_points' => 8000],
        ],
    ])->assertOk()->json('data.result');

    expect($result['status'])->toBe(AppraisalResult::STATUS_PENDING_APPROVAL);

    // Employee cannot approve; HR can.
    $this->actingAs($this->employeeUser)
        ->postJson("/api/v1/performance/results/{$result['id']}/approve")->assertForbidden();

    $this->actingAs($this->owner)
        ->postJson("/api/v1/performance/results/{$result['id']}/approve")
        ->assertOk()->assertJsonPath('data.status', AppraisalResult::STATUS_APPROVED);

    // The employee acknowledges their own result; the owner cannot.
    $this->actingAs($this->owner)
        ->postJson("/api/v1/performance/results/{$result['id']}/acknowledge")->assertForbidden();

    $this->actingAs($this->employeeUser)
        ->postJson("/api/v1/performance/results/{$result['id']}/acknowledge")
        ->assertOk()->assertJsonPath('data.status', AppraisalResult::STATUS_ACKNOWLEDGED);
});

test('calibration adjusts the score with a mandatory justification and audit trail', function () {
    [$template, $mandatoryItem, $optionalItem, $cycle] = workflowSetup(cycleOverrides: ['calibration_enabled' => true]);
    $assignment = createManagerOnlyAssignment($this->owner, $this->employee, $template, $cycle);
    $reviewerId = $assignment['reviewers'][0]['id'];

    $result = $this->postJson("/api/v1/performance/assignments/{$assignment['id']}/reviewers/{$reviewerId}/submit", [
        'scores' => [
            ['appraisal_template_item_id' => $mandatoryItem->id, 'score_basis_points' => 7000],
            ['appraisal_template_item_id' => $optionalItem->id, 'score_basis_points' => 7000],
        ],
    ])->assertOk()->json('data.result');

    expect($result['status'])->toBe(AppraisalResult::STATUS_PENDING_CALIBRATION);

    // Justification is mandatory.
    $this->postJson("/api/v1/performance/results/{$result['id']}/calibrate", [
        'score_basis_points' => 7500,
    ])->assertUnprocessable();

    $this->postJson("/api/v1/performance/results/{$result['id']}/calibrate", [
        'score_basis_points' => 7500,
        'justification' => 'Aligned with department distribution.',
    ])->assertOk()->assertJsonPath('data.final_score_basis_points', 7500);

    expect(CalibrationAdjustment::query()->where('appraisal_result_id', $result['id'])->count())->toBe(1);

    // Finalizing calibration moves the result into the approval queue.
    $this->postJson("/api/v1/performance/cycles/{$cycle->id}/calibration/finalize")
        ->assertOk()->assertJsonPath('data.finalized', 1);

    expect(AppraisalResult::query()->find($result['id'])->status)->toBe(AppraisalResult::STATUS_PENDING_APPROVAL);
});

test('a score below the template passing floor auto-suggests a PIP', function () {
    [$template, $mandatoryItem, $optionalItem, $cycle] = workflowSetup(['min_passing_basis_points' => 5000]);
    $assignment = createManagerOnlyAssignment($this->owner, $this->employee, $template, $cycle);
    $reviewerId = $assignment['reviewers'][0]['id'];

    $this->postJson("/api/v1/performance/assignments/{$assignment['id']}/reviewers/{$reviewerId}/submit", [
        'scores' => [
            ['appraisal_template_item_id' => $mandatoryItem->id, 'score_basis_points' => 4000],
            ['appraisal_template_item_id' => $optionalItem->id, 'score_basis_points' => 3000],
        ],
    ])->assertOk();

    $pip = PerformanceImprovementPlan::query()->where('employee_id', $this->employee->id)->first();
    expect($pip)->not->toBeNull()
        ->and($pip->status)->toBe(PerformanceImprovementPlan::STATUS_DRAFT);

    // Managers can activate then close the PIP with an outcome.
    $this->postJson("/api/v1/performance/pips/{$pip->id}/activate")->assertOk()->assertJsonPath('data.status', 'active');
    $this->postJson("/api/v1/performance/pips/{$pip->id}/close", ['outcome' => 'successful', 'outcome_note' => 'Improved.'])
        ->assertOk()->assertJsonPath('data.status', 'closed_successful');
});

test('an appeal inside the window can be upheld with an audited score revision', function () {
    [$template, $mandatoryItem, $optionalItem, $cycle] = workflowSetup();
    $assignment = createManagerOnlyAssignment($this->owner, $this->employee, $template, $cycle);
    $reviewerId = $assignment['reviewers'][0]['id'];

    $result = $this->postJson("/api/v1/performance/assignments/{$assignment['id']}/reviewers/{$reviewerId}/submit", [
        'scores' => [
            ['appraisal_template_item_id' => $mandatoryItem->id, 'score_basis_points' => 6000],
            ['appraisal_template_item_id' => $optionalItem->id, 'score_basis_points' => 6000],
        ],
    ])->assertOk()->json('data.result');

    $this->postJson("/api/v1/performance/results/{$result['id']}/approve")->assertOk();

    $appealId = $this->actingAs($this->employeeUser)
        ->postJson("/api/v1/performance/results/{$result['id']}/appeal", ['reason' => 'Scores ignore the Q3 launch delivery.'])
        ->assertCreated()->json('data.id');

    $this->actingAs($this->owner)
        ->postJson("/api/v1/performance/appeals/{$appealId}/resolve", [
            'outcome' => 'upheld',
            'resolution_note' => 'Verified with project records.',
            'new_score_basis_points' => 7200,
        ])->assertOk()->assertJsonPath('data.status', 'upheld');

    $stored = AppraisalResult::query()->find($result['id']);
    expect($stored->final_score_basis_points)->toBe(7200)
        ->and($stored->status)->toBe(AppraisalResult::STATUS_APPEAL_RESOLVED)
        ->and(CalibrationAdjustment::query()->where('appraisal_result_id', $stored->id)->count())->toBe(1);
});

test('an appeal outside the window is rejected', function () {
    [$template, $mandatoryItem, $optionalItem, $cycle] = workflowSetup(cycleOverrides: ['appeal_window_days' => 7]);
    $assignment = createManagerOnlyAssignment($this->owner, $this->employee, $template, $cycle);
    $reviewerId = $assignment['reviewers'][0]['id'];

    $result = $this->postJson("/api/v1/performance/assignments/{$assignment['id']}/reviewers/{$reviewerId}/submit", [
        'scores' => [
            ['appraisal_template_item_id' => $mandatoryItem->id, 'score_basis_points' => 6000],
            ['appraisal_template_item_id' => $optionalItem->id, 'score_basis_points' => 6000],
        ],
    ])->assertOk()->json('data.result');

    $this->postJson("/api/v1/performance/results/{$result['id']}/approve")->assertOk();
    AppraisalResult::query()->find($result['id'])->forceFill(['approved_at' => now()->subDays(8)])->save();

    $this->actingAs($this->employeeUser)
        ->postJson("/api/v1/performance/results/{$result['id']}/appeal", ['reason' => 'Too late now.'])
        ->assertConflict();
});

test('return for revision reopens the review and clears the computed result', function () {
    [$template, $mandatoryItem, $optionalItem, $cycle] = workflowSetup();
    $assignment = createManagerOnlyAssignment($this->owner, $this->employee, $template, $cycle);
    $reviewerId = $assignment['reviewers'][0]['id'];

    $this->postJson("/api/v1/performance/assignments/{$assignment['id']}/reviewers/{$reviewerId}/submit", [
        'scores' => [
            ['appraisal_template_item_id' => $mandatoryItem->id, 'score_basis_points' => 5000],
            ['appraisal_template_item_id' => $optionalItem->id, 'score_basis_points' => 5000],
        ],
    ])->assertOk();

    $this->postJson("/api/v1/performance/assignments/{$assignment['id']}/reviewers/{$reviewerId}/return")
        ->assertOk()
        ->assertJsonPath('data.result', null)
        ->assertJsonPath('data.reviewers.0.status', 'pending');

    // The reviewer can resubmit and a fresh result is computed.
    $this->postJson("/api/v1/performance/assignments/{$assignment['id']}/reviewers/{$reviewerId}/submit", [
        'scores' => [
            ['appraisal_template_item_id' => $mandatoryItem->id, 'score_basis_points' => 9000],
            ['appraisal_template_item_id' => $optionalItem->id, 'score_basis_points' => 9000],
        ],
    ])->assertOk()->assertJsonPath('data.result.final_score_basis_points', 9000);
});

test('self reviewers are blocked when the cycle disables self review', function () {
    [$template, , , $cycle] = workflowSetup(cycleOverrides: ['self_review_enabled' => false]);

    $this->postJson('/api/v1/performance/assignments', [
        'appraisal_cycle_id' => $cycle->id,
        'appraisal_template_id' => $template->id,
        'employee_id' => $this->employee->id,
        'reviewers' => [['reviewer_user_id' => $this->employeeUser->id, 'reviewer_type' => 'self', 'weight' => 100]],
    ])->assertUnprocessable()->assertJsonValidationErrors('reviewers');
});

test('seeding defaults provisions the sample library idempotently', function () {
    $first = $this->postJson('/api/v1/performance/seed-defaults')->assertOk()->json('data');

    expect($first['categories'])->toBe(7)
        ->and($first['kpis'])->toBeGreaterThanOrEqual(70)
        ->and($first['templates'])->toBe(3);

    // Every seeded template must satisfy the 100% weight rule.
    AppraisalTemplate::query()->with('items')->get()
        ->each(fn ($template) => expect($template->items->sum('weight'))->toBe(100));

    // Rerunning must not duplicate anything.
    $this->postJson('/api/v1/performance/seed-defaults')->assertOk();
    expect(PerformanceKpi::query()->count())->toBe($first['kpis'])
        ->and(AppraisalTemplate::query()->count())->toBe(3);
});

test('the defaults provisioner is tenant scoped', function () {
    app(PerformanceDefaultsProvisioner::class)->provision($this->owner);
    expect(PerformanceKpi::query()->count())->toBeGreaterThan(0);

    [, $otherOwner] = appraisalTenant();
    $this->actingAs($otherOwner);
    $this->getJson('/api/v1/performance/kpis')->assertOk()->assertJsonCount(0, 'data');
});

test('reports summary aggregates distribution and KPI averages', function () {
    [$template, $mandatoryItem, $optionalItem, $cycle] = workflowSetup();
    $assignment = createManagerOnlyAssignment($this->owner, $this->employee, $template, $cycle);
    $reviewerId = $assignment['reviewers'][0]['id'];

    $this->postJson("/api/v1/performance/assignments/{$assignment['id']}/reviewers/{$reviewerId}/submit", [
        'scores' => [
            ['appraisal_template_item_id' => $mandatoryItem->id, 'score_basis_points' => 9000],
            ['appraisal_template_item_id' => $optionalItem->id, 'score_basis_points' => 8000],
        ],
    ])->assertOk();

    $summary = $this->getJson("/api/v1/performance/reports/summary?cycle_id={$cycle->id}")->assertOk()->json('data');

    expect($summary['results_count'])->toBe(1)
        ->and($summary['kpi_averages']['Quality'])->toBe(9000)
        ->and($summary['kpi_averages']['Stretch'])->toBe(8000);
});
