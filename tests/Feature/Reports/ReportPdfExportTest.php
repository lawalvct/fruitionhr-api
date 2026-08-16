<?php

use App\Models\User;
use App\Modules\Company\Models\Department;
use App\Modules\Employee\Models\Employee;
use App\Modules\Employee\Models\EmployeeEmploymentRecord;
use App\Modules\Payroll\Models\PayPeriod;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Reports\Services\ReportPdfExportService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantRoleProvisioner;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create([
        'name' => 'Sure Packaging Limited',
    ]);
    app(TenantRoleProvisioner::class)->provision($this->tenant);
    app(CurrentTenant::class)->set($this->tenant);
    setPermissionsTeamId($this->tenant->id);

    $this->owner = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'status' => User::STATUS_ACTIVE,
    ]);
    $this->owner->assignRole('owner');
});

test('filtered report pdf downloads with the expected mime type and filename', function (): void {
    $department = Department::factory()->create(['name' => 'Production']);
    $employee = Employee::factory()->create([
        'first_name' => 'Ada',
        'middle_name' => null,
        'last_name' => 'Okafor',
        'employment_status' => Employee::STATUS_ACTIVE,
    ]);
    EmployeeEmploymentRecord::factory()->create([
        'employee_id' => $employee->id,
        'department_id' => $department->id,
        'is_current' => true,
    ]);

    $response = $this->actingAs($this->owner)->get(
        "/api/v1/reports/workforce/export.pdf?year=2026&department_id={$department->id}&status=active",
    );

    $response->assertOk()
        ->assertDownload('workforce-analysis-2026.pdf')
        ->assertHeader('content-type', 'application/pdf')
        ->assertHeader('x-content-type-options', 'nosniff');

    expect($response->getContent())
        ->toStartWith('%PDF-')
        ->and(strlen((string) $response->getContent()))->toBeGreaterThan(1000);
});

test('report pdf requires both reports and source module permissions', function (): void {
    $reportsRole = Role::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'pdf_reports_only',
        'guard_name' => 'web',
    ]);
    $reportsRole->syncPermissions([Permissions::REPORTS_VIEW]);

    $sourceRole = Role::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'pdf_workforce_only',
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
        ->get('/api/v1/reports/workforce/export.pdf?year=2026')
        ->assertForbidden();

    $this->actingAs($sourceOnly)
        ->get('/api/v1/reports/workforce/export.pdf?year=2026')
        ->assertForbidden();
});

test('pdf presentation contains tenant branding filters and a limited record preview', function (): void {
    Storage::fake('local');
    $logoPath = "tenants/{$this->tenant->id}/branding/logo.png";
    Storage::disk('local')->put($logoPath, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
    ));
    $this->tenant->update(['logo_path' => $logoPath]);

    $department = Department::factory()->create(['name' => 'Operations']);
    Employee::factory()->count(13)->create([
        'employment_status' => Employee::STATUS_ACTIVE,
    ])->each(function (Employee $employee) use ($department): void {
        EmployeeEmploymentRecord::factory()->create([
            'employee_id' => $employee->id,
            'department_id' => $department->id,
            'is_current' => true,
        ]);
    });

    $data = app(ReportPdfExportService::class)->viewData('workforce', 2026, [
        'department_id' => $department->id,
        'status' => Employee::STATUS_ACTIVE,
    ]);
    $html = view('reports.analysis-pdf', $data)->render();

    expect($data['logo_data_uri'])->toStartWith('data:image/png;base64,')
        ->and($data['report']['table']['rows'])->toHaveCount(12)
        ->and($data['report']['table']['meta']['count'])->toBe(13)
        ->and($data['report']['table']['meta']['limited'])->toBeTrue()
        ->and($html)->toContain('Sure Packaging Limited')
        ->and($html)->toContain('Workforce analysis')
        ->and($html)->toContain('Operations')
        ->and($html)->toContain('Active')
        ->and($html)->toContain('Previewing the first 12 of 13 matching records')
        ->and($html)->toContain('Download the Excel workbook for the full filtered dataset');
});

test('pdf formats payroll money as naira only at the presentation boundary', function (): void {
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

    $data = app(ReportPdfExportService::class)->viewData('payroll', 2026, [
        'period' => '2026-06',
        'status' => PayrollRun::STATUS_PAID,
    ]);
    $html = view('reports.analysis-pdf', $data)->render();

    expect($html)
        ->toContain('NGN 500,000.00')
        ->toContain('NGN 430,000.00')
        ->not->toContain('NGN 50,000,000.00');
});
