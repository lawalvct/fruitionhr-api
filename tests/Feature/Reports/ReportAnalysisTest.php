<?php

use App\Models\User;
use App\Modules\Attendance\Models\AttendanceSummary;
use App\Modules\Company\Models\Department;
use App\Modules\Employee\Models\Employee;
use App\Modules\Employee\Models\EmployeeEmploymentRecord;
use App\Modules\Payroll\Models\PayPeriod;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Reports\Services\ReportAnalysisService;
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

    $this->makeReportUser = function (array $permissions, string $roleName): User {
        $role = Role::query()->create([
            'tenant_id' => $this->tenant->id,
            'name' => $roleName,
            'guard_name' => 'web',
        ]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => User::STATUS_ACTIVE,
        ]);
        $user->assignRole($role);

        return $user;
    };
});

test('each supported module returns the common detailed analysis contract', function (): void {
    foreach (ReportAnalysisService::MODULES as $module) {
        $this->actingAs($this->owner)
            ->getJson("/api/v1/reports/{$module}/analysis?year=2026")
            ->assertOk()
            ->assertJsonPath('data.module', $module)
            ->assertJsonPath('data.year', 2026)
            ->assertJsonStructure([
                'data' => [
                    'module',
                    'title',
                    'year',
                    'generated_at',
                    'filters' => ['available', 'applied'],
                    'metrics' => [
                        '*' => ['key', 'label', 'value', 'format'],
                    ],
                    'datasets' => [
                        '*' => ['key', 'title', 'type', 'x_key', 'series', 'data'],
                    ],
                    'table' => [
                        'title',
                        'columns',
                        'rows',
                        'meta' => ['count', 'limit', 'limited'],
                    ],
                ],
            ]);
    }
});

test('detailed analysis requires both reports and source module permissions', function (): void {
    $reportsOnly = ($this->makeReportUser)([Permissions::REPORTS_VIEW], 'analysis_reports_only');
    $attendanceOnly = ($this->makeReportUser)([Permissions::ATTENDANCE_VIEW], 'analysis_attendance_only');
    $allowed = ($this->makeReportUser)([
        Permissions::REPORTS_VIEW,
        Permissions::ATTENDANCE_VIEW,
    ], 'analysis_attendance_reporter');

    $this->actingAs($reportsOnly)
        ->getJson('/api/v1/reports/attendance/analysis?year=2026')
        ->assertForbidden();

    $this->actingAs($attendanceOnly)
        ->getJson('/api/v1/reports/attendance/analysis?year=2026')
        ->assertForbidden();

    $this->actingAs($allowed)
        ->getJson('/api/v1/reports/attendance/analysis?year=2026')
        ->assertOk();
});

test('unknown modules and invalid module filters are rejected', function (): void {
    $this->actingAs($this->owner)
        ->getJson('/api/v1/reports/not-a-module/analysis?year=2026')
        ->assertNotFound();

    $this->actingAs($this->owner)
        ->getJson('/api/v1/reports/attendance/analysis?year=2026&period=2025-12')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('period');

    $this->actingAs($this->owner)
        ->getJson('/api/v1/reports/workforce/analysis?year=2026&period=2026-06')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('period');

    $this->actingAs($this->owner)
        ->getJson('/api/v1/reports/recruitment/analysis?year=2026&stage=unknown')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('stage');

    $this->actingAs($this->owner)
        ->getJson('/api/v1/reports/performance/analysis?year=2026&status=final')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');

    $this->actingAs($this->owner)
        ->getJson('/api/v1/reports/payroll/analysis?year=bad')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('year');
});

test('department filters are tenant scoped and analysis excludes other tenants', function (): void {
    $operations = Department::factory()->create(['name' => 'Operations']);
    $finance = Department::factory()->create(['name' => 'Finance']);

    $operationsEmployee = Employee::factory()->create([
        'employment_status' => Employee::STATUS_ACTIVE,
        'hired_at' => '2026-02-03',
    ]);
    EmployeeEmploymentRecord::factory()->create([
        'employee_id' => $operationsEmployee->id,
        'department_id' => $operations->id,
        'is_current' => true,
    ]);

    $financeEmployee = Employee::factory()->create([
        'employment_status' => Employee::STATUS_ACTIVE,
        'hired_at' => '2026-03-03',
    ]);
    EmployeeEmploymentRecord::factory()->create([
        'employee_id' => $financeEmployee->id,
        'department_id' => $finance->id,
        'is_current' => true,
    ]);

    $otherTenant = Tenant::factory()->create();
    app(TenantRoleProvisioner::class)->provision($otherTenant);
    app(CurrentTenant::class)->set($otherTenant);
    setPermissionsTeamId($otherTenant->id);
    $otherDepartment = Department::factory()->create(['name' => 'Other tenant']);
    Employee::factory()->create([
        'employment_status' => Employee::STATUS_ACTIVE,
        'hired_at' => '2026-04-03',
    ]);

    app(CurrentTenant::class)->set($this->tenant);
    setPermissionsTeamId($this->tenant->id);

    $this->actingAs($this->owner)
        ->getJson('/api/v1/reports/workforce/analysis?year=2026')
        ->assertOk()
        ->assertJsonPath('data.metrics.0.value', 2)
        ->assertJsonPath('data.table.meta.count', 2);

    $this->actingAs($this->owner)
        ->getJson("/api/v1/reports/workforce/analysis?year=2026&department_id={$operations->id}")
        ->assertOk()
        ->assertJsonPath('data.filters.applied.department_id', $operations->id)
        ->assertJsonPath('data.metrics.0.value', 1)
        ->assertJsonPath('data.table.meta.count', 1)
        ->assertJsonPath('data.table.rows.0.department', 'Operations');

    $this->actingAs($this->owner)
        ->getJson("/api/v1/reports/workforce/analysis?year=2026&department_id={$otherDepartment->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('department_id');
});

test('attendance period status and department filters scope metrics and rows', function (): void {
    $department = Department::factory()->create(['name' => 'Production']);
    $employee = Employee::factory()->create();
    EmployeeEmploymentRecord::factory()->create([
        'employee_id' => $employee->id,
        'department_id' => $department->id,
        'is_current' => true,
    ]);

    AttendanceSummary::query()->create([
        'employee_id' => $employee->id,
        'period' => '2026-06',
        'working_days' => 22,
        'days_present' => 20,
        'days_late' => 2,
        'days_absent' => 1,
        'days_on_leave' => 1,
        'late_minutes' => 30,
        'overtime_minutes' => 90,
        'status' => AttendanceSummary::STATUS_FINALIZED,
        'finalized_by' => $this->owner->id,
        'finalized_at' => '2026-06-30 17:00:00',
    ]);
    AttendanceSummary::query()->create([
        'employee_id' => $employee->id,
        'period' => '2026-07',
        'working_days' => 23,
        'days_present' => 5,
        'days_late' => 0,
        'days_absent' => 0,
        'days_on_leave' => 0,
        'late_minutes' => 0,
        'overtime_minutes' => 0,
        'status' => AttendanceSummary::STATUS_OPEN,
    ]);

    $this->actingAs($this->owner)
        ->getJson("/api/v1/reports/attendance/analysis?year=2026&period=2026-06&status=finalized&department_id={$department->id}")
        ->assertOk()
        ->assertJsonPath('data.filters.applied.period', '2026-06')
        ->assertJsonPath('data.metrics.0.value', 95.2)
        ->assertJsonPath('data.metrics.1.value', 20)
        ->assertJsonPath('data.table.meta.count', 1)
        ->assertJsonPath('data.table.rows.0.period', '2026-06');
});

test('payroll analysis remains aggregate and returns integer kobo', function (): void {
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

    $this->actingAs($this->owner)
        ->getJson('/api/v1/reports/payroll/analysis?year=2026&period=2026-06&status=paid')
        ->assertOk()
        ->assertJsonPath('data.metrics.2.value', 50000000)
        ->assertJsonPath('data.metrics.2.format', 'money')
        ->assertJsonPath('data.table.rows.0.gross', 50000000)
        ->assertJsonMissingPath('data.table.rows.0.employee')
        ->assertJsonMissingPath('data.table.rows.0.salary');
});
