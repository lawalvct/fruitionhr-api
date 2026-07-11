<?php

use App\Core\Workflow\WorkflowProvisioner;
use App\Models\User;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Employee\Models\Employee;
use App\Modules\Payroll\Models\EmployeeSalary;
use App\Modules\Payroll\Models\SalaryComponent;
use App\Modules\Payroll\Models\SalaryStructure;
use App\Modules\Payroll\Support\StatutoryProvisioner;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantRoleProvisioner;
use App\Support\Tenancy\CurrentTenant;

if (! function_exists('payrollScenario')) {
    /**
     * Builds a tenant with one employee earning ₦500,000/month (basic
     * ₦250,000 + ₦200,000 pensionable allowances + ₦50,000 taxable meal),
     * statutory rules provisioned, attendance finalized (no shift → zero
     * absence). Returns [tenant, owner, employee].
     */
    function payrollScenario(): array
    {
        $tenant = Tenant::factory()->create();
        app(TenantRoleProvisioner::class)->provision($tenant);
        app(WorkflowProvisioner::class)->provision($tenant);
        app(StatutoryProvisioner::class)->provision($tenant);
        app(CurrentTenant::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $owner = User::factory()->create(['tenant_id' => $tenant->id]);
        $owner->assignRole('owner');
        test()->actingAs($owner);

        $housing = SalaryComponent::factory()->create(['code' => 'HOU', 'is_taxable' => true, 'is_pensionable' => true]);
        $transport = SalaryComponent::factory()->create(['code' => 'TRA', 'is_taxable' => true, 'is_pensionable' => true]);
        $meal = SalaryComponent::factory()->create(['code' => 'MEAL', 'is_taxable' => true, 'is_pensionable' => false]);

        $structure = SalaryStructure::factory()->create();
        $structure->components()->createMany([
            ['salary_component_id' => $housing->id, 'amount' => 12_500_000],  // ₦125,000
            ['salary_component_id' => $transport->id, 'amount' => 7_500_000], // ₦75,000
            ['salary_component_id' => $meal->id, 'amount' => 5_000_000],      // ₦50,000
        ]);

        $employee = Employee::factory()->create(['employment_status' => Employee::STATUS_ACTIVE]);
        EmployeeSalary::query()->create([
            'employee_id' => $employee->id,
            'salary_structure_id' => $structure->id,
            'basic_salary' => 25_000_000, // ₦250,000
            'effective_from' => '2026-07-01',
            'is_current' => true,
        ]);

        app(AttendanceService::class)->finalize('2026-07', $owner);

        return [$tenant, $owner, $employee];
    }
}
