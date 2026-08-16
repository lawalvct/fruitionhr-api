<?php

use App\Models\User;
use App\Modules\Company\Models\Department;
use App\Modules\Employee\Models\Employee;
use App\Modules\Employee\Models\EmployeeEmploymentRecord;
use App\Modules\Payroll\Models\PayPeriod;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantRoleProvisioner;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\CurrentTenant;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    app(TenantRoleProvisioner::class)->provision($this->tenant);
    app(CurrentTenant::class)->set($this->tenant);
    setPermissionsTeamId($this->tenant->id);

    $this->owner = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'status' => User::STATUS_ACTIVE,
    ]);
    $this->owner->assignRole('owner');
});

test('filtered workforce csv exports every tenant record and neutralizes formulas', function (): void {
    $includedDepartment = Department::factory()->create(['name' => '@Operations']);
    $excludedDepartment = Department::factory()->create(['name' => 'Finance']);

    $dangerousEmployee = Employee::factory()->create([
        'first_name' => '=2+2',
        'middle_name' => null,
        'last_name' => 'Attack',
        'employment_status' => Employee::STATUS_ACTIVE,
    ]);
    EmployeeEmploymentRecord::factory()->create([
        'employee_id' => $dangerousEmployee->id,
        'department_id' => $includedDepartment->id,
    ]);

    Employee::factory()->count(100)->create([
        'employment_status' => Employee::STATUS_ACTIVE,
    ])->each(function (Employee $employee) use ($includedDepartment): void {
        EmployeeEmploymentRecord::factory()->create([
            'employee_id' => $employee->id,
            'department_id' => $includedDepartment->id,
        ]);
    });

    $filteredOut = Employee::factory()->create([
        'first_name' => 'Filtered',
        'last_name' => 'Out',
        'employment_status' => Employee::STATUS_ACTIVE,
    ]);
    EmployeeEmploymentRecord::factory()->create([
        'employee_id' => $filteredOut->id,
        'department_id' => $excludedDepartment->id,
    ]);

    $otherTenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($otherTenant);
    $otherDepartment = Department::factory()->create(['name' => 'Other tenant']);
    $otherEmployee = Employee::factory()->create([
        'first_name' => 'Cross',
        'last_name' => 'Tenant',
    ]);
    EmployeeEmploymentRecord::factory()->create([
        'employee_id' => $otherEmployee->id,
        'department_id' => $otherDepartment->id,
    ]);

    app(CurrentTenant::class)->set($this->tenant);
    setPermissionsTeamId($this->tenant->id);

    $response = $this->actingAs($this->owner)->get(
        "/api/v1/reports/workforce/export.csv?year=2026&department_id={$includedDepartment->id}&status=active",
    );

    $response->assertOk()
        ->assertDownload('workforce-report-2026.csv')
        ->assertHeader('content-type', 'text/csv; charset=UTF-8')
        ->assertHeader('x-content-type-options', 'nosniff');

    $csv = $response->streamedContent();
    $lines = array_values(array_filter(
        preg_split('/\r\n|\n|\r/', $csv) ?: [],
        fn (string $line): bool => $line !== '',
    ));

    expect($lines)->toHaveCount(102)
        ->and($csv)->toContain("'=2+2 Attack")
        ->and($csv)->toContain("'@Operations")
        ->and($csv)->not->toContain('Filtered Out')
        ->and($csv)->not->toContain('Cross Tenant');
});

test('report csv export requires both reports and source module permissions', function (): void {
    $reportsRole = Role::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'reports_only',
        'guard_name' => 'web',
    ]);
    $reportsRole->syncPermissions([Permissions::REPORTS_VIEW]);

    $sourceRole = Role::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'workforce_only',
        'guard_name' => 'web',
    ]);
    $sourceRole->syncPermissions([Permissions::EMPLOYEES_VIEW]);

    $reportsOnly = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'status' => User::STATUS_ACTIVE,
    ]);
    $reportsOnly->assignRole($reportsRole);

    $sourceOnly = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'status' => User::STATUS_ACTIVE,
    ]);
    $sourceOnly->assignRole($sourceRole);

    $this->actingAs($reportsOnly)
        ->get('/api/v1/reports/workforce/export.csv?year=2026')
        ->assertForbidden();

    $this->actingAs($sourceOnly)
        ->get('/api/v1/reports/workforce/export.csv?year=2026')
        ->assertForbidden();
});

test('payroll csv identifies monetary values as kobo', function (): void {
    $period = PayPeriod::query()->create([
        'period' => '2026-06',
        'year' => 2026,
        'month' => 6,
        'status' => 'closed',
    ]);
    PayrollRun::query()->create([
        'pay_period_id' => $period->id,
        'period' => '2026-06',
        'status' => PayrollRun::STATUS_PAID,
        'is_reversal' => false,
        'employee_count' => 3,
        'total_gross' => 50000000,
        'total_statutory' => 5000000,
        'total_deductions' => 7000000,
        'total_net' => 43000000,
        'total_employer_cost' => 3000000,
        'created_by' => $this->owner->id,
    ]);

    $response = $this->actingAs($this->owner)
        ->get('/api/v1/reports/payroll/export.csv?year=2026&period=2026-06');

    $response->assertOk()->assertDownload('payroll-report-2026.csv');

    expect($response->streamedContent())
        ->toContain('Gross (kobo)')
        ->toContain('Net (kobo)')
        ->toContain('50000000')
        ->toContain('43000000');
});
