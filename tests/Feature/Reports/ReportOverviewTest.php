<?php

use App\Models\User;
use App\Modules\Attendance\Models\AttendanceSummary;
use App\Modules\Company\Models\Department;
use App\Modules\Employee\Models\Employee;
use App\Modules\Employee\Models\EmployeeEmploymentRecord;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
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

test('owner can view yearly report aggregates', function (): void {
    $department = Department::factory()->create(['name' => 'Operations']);
    $activeEmployee = Employee::factory()->create([
        'employment_status' => Employee::STATUS_ACTIVE,
        'gender' => 'female',
        'hired_at' => '2026-01-15',
    ]);
    EmployeeEmploymentRecord::factory()->create([
        'employee_id' => $activeEmployee->id,
        'department_id' => $department->id,
    ]);
    Employee::factory()->create([
        'employment_status' => Employee::STATUS_EXITED,
        'hired_at' => '2025-02-01',
        'exited_at' => '2026-04-10',
    ]);

    AttendanceSummary::query()->create([
        'employee_id' => $activeEmployee->id,
        'period' => '2026-06',
        'working_days' => 22,
        'days_present' => 20,
        'days_late' => 2,
        'days_absent' => 1,
        'days_on_leave' => 1,
        'late_minutes' => 25,
        'overtime_minutes' => 120,
        'status' => AttendanceSummary::STATUS_FINALIZED,
        'finalized_by' => $this->owner->id,
        'finalized_at' => '2026-06-30 17:00:00',
    ]);

    $leaveType = LeaveType::factory()->create(['name' => 'Annual leave']);
    LeaveRequest::factory()->create([
        'employee_id' => $activeEmployee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-18',
        'end_date' => '2026-06-19',
        'days' => 2,
        'status' => LeaveRequest::STATUS_APPROVED,
        'requested_by' => $this->owner->id,
    ]);

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
        'employee_count' => 1,
        'total_gross' => 50000000,
        'total_statutory' => 5000000,
        'total_deductions' => 7000000,
        'total_net' => 43000000,
        'total_employer_cost' => 3000000,
        'created_by' => $this->owner->id,
    ]);

    $this->actingAs($this->owner)
        ->getJson('/api/v1/reports/overview?year=2026')
        ->assertOk()
        ->assertJsonPath('data.year', 2026)
        ->assertJsonPath('data.access.workforce', true)
        ->assertJsonPath('data.workforce.total', 1)
        ->assertJsonPath('data.workforce.new_hires', 1)
        ->assertJsonPath('data.workforce.exits', 1)
        ->assertJsonPath('data.workforce.by_department.0.label', 'Operations')
        ->assertJsonPath('data.attendance.finalized_periods', 1)
        ->assertJsonPath('data.attendance.attendance_rate', 95.2)
        ->assertJsonPath('data.leave.approved_days', 2)
        ->assertJsonPath('data.payroll.completed_runs', 1)
        ->assertJsonPath('data.payroll.total_net', 43000000);
});

test('report access does not bypass source module permissions', function (): void {
    $role = Role::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'report_viewer',
        'guard_name' => 'web',
    ]);
    $role->syncPermissions([Permissions::REPORTS_VIEW]);

    $viewer = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'status' => User::STATUS_ACTIVE,
    ]);
    $viewer->assignRole($role);

    $this->actingAs($viewer)
        ->getJson('/api/v1/reports/overview?year=2026')
        ->assertOk()
        ->assertJsonPath('data.access.workforce', false)
        ->assertJsonPath('data.access.payroll', false)
        ->assertJsonPath('data.workforce', null)
        ->assertJsonPath('data.payroll', null);
});

test('users without reports permission are forbidden', function (): void {
    $employee = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'status' => User::STATUS_ACTIVE,
    ]);
    $employee->assignRole('employee');

    $this->actingAs($employee)
        ->getJson('/api/v1/reports/overview?year=2026')
        ->assertForbidden();
});

test('report aggregates exclude records from other tenants', function (): void {
    $otherTenant = Tenant::factory()->create();
    app(TenantRoleProvisioner::class)->provision($otherTenant);
    app(CurrentTenant::class)->set($otherTenant);
    Employee::factory()->create([
        'employment_status' => Employee::STATUS_ACTIVE,
        'hired_at' => '2026-03-01',
    ]);

    app(CurrentTenant::class)->set($this->tenant);
    setPermissionsTeamId($this->tenant->id);

    $this->actingAs($this->owner)
        ->getJson('/api/v1/reports/overview?year=2026')
        ->assertOk()
        ->assertJsonPath('data.workforce.total', 0)
        ->assertJsonPath('data.workforce.new_hires', 0);
});

test('report year is validated', function (): void {
    $this->actingAs($this->owner)
        ->getJson('/api/v1/reports/overview?year=not-a-year')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('year');
});
