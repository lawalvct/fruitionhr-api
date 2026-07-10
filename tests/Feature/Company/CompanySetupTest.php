<?php

use App\Models\User;
use App\Modules\Company\Models\Branch;
use App\Modules\Company\Models\Department;
use App\Modules\Company\Models\EmploymentType;
use App\Modules\Company\Models\HolidayCalendar;
use App\Modules\Company\Models\JobGrade;
use App\Modules\Company\Models\Position;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantRoleProvisioner;
use App\Support\Tenancy\CurrentTenant;

function actingAsTenantOwner(): array
{
    $tenant = Tenant::factory()->create();
    app(TenantRoleProvisioner::class)->provision($tenant);
    app(CurrentTenant::class)->set($tenant);
    setPermissionsTeamId($tenant->id);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => User::STATUS_ACTIVE,
    ]);
    $user->assignRole('owner');

    test()->actingAs($user);

    return [$tenant, $user];
}

beforeEach(function (): void {
    [$this->tenant, $this->user] = actingAsTenantOwner();
});

test('branches can be created listed updated and deleted', function (): void {
    $response = $this->postJson('/api/v1/branches', [
        'name' => 'Lagos HQ',
        'code' => 'LAG',
        'address' => '1 Marina',
        'city' => 'Lagos',
        'state' => 'Lagos',
    ])->assertCreated()
        ->assertJsonPath('data.name', 'Lagos HQ')
        ->assertJsonPath('data.code', 'LAG');

    $id = $response->json('data.id');

    $this->getJson('/api/v1/branches?filter[search]=lag')
        ->assertOk()
        ->assertJsonPath('data.0.id', $id);

    $this->putJson("/api/v1/branches/{$id}", [
        'name' => 'Lagos Main',
        'code' => 'LAG',
        'is_active' => false,
    ])->assertOk()
        ->assertJsonPath('data.name', 'Lagos Main')
        ->assertJsonPath('data.is_active', false);

    $this->deleteJson("/api/v1/branches/{$id}")->assertNoContent();
    expect(Branch::query()->find($id))->toBeNull();
});

test('branches validate required fields', function (): void {
    $this->postJson('/api/v1/branches', ['name' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');
});

test('departments can be created listed updated and deleted', function (): void {
    $branch = Branch::factory()->create();

    $response = $this->postJson('/api/v1/departments', [
        'name' => 'People Operations',
        'code' => 'HR',
        'branch_id' => $branch->id,
    ])->assertCreated()
        ->assertJsonPath('data.branch.id', $branch->id);

    $id = $response->json('data.id');

    $this->getJson('/api/v1/departments?filter[branch_id]='.$branch->id)
        ->assertOk()
        ->assertJsonPath('data.0.id', $id);

    $this->putJson("/api/v1/departments/{$id}", [
        'name' => 'Human Resources',
        'code' => 'HR',
        'branch_id' => $branch->id,
    ])->assertOk()
        ->assertJsonPath('data.name', 'Human Resources');

    $this->deleteJson("/api/v1/departments/{$id}")->assertNoContent();
    expect(Department::query()->find($id))->toBeNull();
});

test('departments reject foreign tenant branch ids', function (): void {
    $foreignTenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($foreignTenant);
    $foreignBranch = Branch::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $this->postJson('/api/v1/departments', [
        'name' => 'Finance',
        'branch_id' => $foreignBranch->id,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('branch_id');
});

test('job grades can be created listed updated and deleted', function (): void {
    $response = $this->postJson('/api/v1/job-grades', [
        'name' => 'Professional',
        'code' => 'G2',
        'level' => 2,
        'min_salary' => 30000000,
        'max_salary' => 60000000,
    ])->assertCreated()
        ->assertJsonPath('data.level', 2);

    $id = $response->json('data.id');

    $this->getJson('/api/v1/job-grades?sort=level')
        ->assertOk()
        ->assertJsonPath('data.0.id', $id);

    $this->putJson("/api/v1/job-grades/{$id}", [
        'name' => 'Senior Professional',
        'code' => 'G2',
        'level' => 3,
    ])->assertOk()
        ->assertJsonPath('data.name', 'Senior Professional');

    $this->deleteJson("/api/v1/job-grades/{$id}")->assertNoContent();
    expect(JobGrade::query()->find($id))->toBeNull();
});

test('job grades validate salary range', function (): void {
    $this->postJson('/api/v1/job-grades', [
        'name' => 'Bad Grade',
        'level' => 1,
        'min_salary' => 1000,
        'max_salary' => 500,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('max_salary');
});

test('positions can be created listed updated and deleted', function (): void {
    $department = Department::factory()->create();
    $grade = JobGrade::factory()->create(['level' => 1]);

    $response = $this->postJson('/api/v1/positions', [
        'title' => 'Payroll Officer',
        'code' => 'PAY',
        'department_id' => $department->id,
        'job_grade_id' => $grade->id,
        'description' => 'Processes payroll',
    ])->assertCreated()
        ->assertJsonPath('data.department.id', $department->id)
        ->assertJsonPath('data.job_grade.id', $grade->id);

    $id = $response->json('data.id');

    $this->getJson('/api/v1/positions?filter[department_id]='.$department->id)
        ->assertOk()
        ->assertJsonPath('data.0.id', $id);

    $this->putJson("/api/v1/positions/{$id}", [
        'title' => 'Senior Payroll Officer',
        'code' => 'PAY',
        'department_id' => $department->id,
        'job_grade_id' => $grade->id,
    ])->assertOk()
        ->assertJsonPath('data.title', 'Senior Payroll Officer');

    $this->deleteJson("/api/v1/positions/{$id}")->assertNoContent();
    expect(Position::query()->find($id))->toBeNull();
});

test('positions validate required title', function (): void {
    $this->postJson('/api/v1/positions', ['title' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('title');
});

test('employment types can be created listed updated and deleted', function (): void {
    $response = $this->postJson('/api/v1/employment-types', [
        'name' => 'Full-time',
    ])->assertCreated()
        ->assertJsonPath('data.name', 'Full-time');

    $id = $response->json('data.id');

    $this->getJson('/api/v1/employment-types?filter[search]=full')
        ->assertOk()
        ->assertJsonPath('data.0.id', $id);

    $this->putJson("/api/v1/employment-types/{$id}", [
        'name' => 'Permanent',
        'is_active' => false,
    ])->assertOk()
        ->assertJsonPath('data.name', 'Permanent')
        ->assertJsonPath('data.is_active', false);

    $this->deleteJson("/api/v1/employment-types/{$id}")->assertNoContent();
    expect(EmploymentType::query()->find($id))->toBeNull();
});

test('employment types validate unique names per tenant', function (): void {
    EmploymentType::factory()->create(['name' => 'Contract']);

    $this->postJson('/api/v1/employment-types', ['name' => 'Contract'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');
});

test('holiday calendars can be created listed updated and deleted with dates', function (): void {
    $response = $this->postJson('/api/v1/holiday-calendars', [
        'year' => 2026,
        'name' => 'Nigeria Public Holidays',
        'dates' => [
            ['date' => '2026-01-01', 'name' => 'New Year Day', 'is_recurring' => true],
        ],
    ])->assertCreated()
        ->assertJsonPath('data.year', 2026)
        ->assertJsonPath('data.dates.0.name', 'New Year Day');

    $id = $response->json('data.id');

    $this->getJson('/api/v1/holiday-calendars?filter[year]=2026')
        ->assertOk()
        ->assertJsonPath('data.0.id', $id);

    $this->putJson("/api/v1/holiday-calendars/{$id}", [
        'year' => 2026,
        'name' => 'Observed Holidays',
        'dates' => [
            ['date' => '2026-05-01', 'name' => 'Workers Day', 'is_recurring' => true],
        ],
    ])->assertOk()
        ->assertJsonPath('data.name', 'Observed Holidays')
        ->assertJsonPath('data.dates.0.name', 'Workers Day');

    $this->deleteJson("/api/v1/holiday-calendars/{$id}")->assertNoContent();
    expect(HolidayCalendar::query()->find($id))->toBeNull();
});

test('holiday calendars validate required dates payload', function (): void {
    $this->postJson('/api/v1/holiday-calendars', [
        'year' => 2026,
        'name' => 'Bad Holidays',
        'dates' => [['name' => 'No date']],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('dates.0.date');
});
