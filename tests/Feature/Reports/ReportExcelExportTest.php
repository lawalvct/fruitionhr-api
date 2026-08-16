<?php

use App\Models\User;
use App\Modules\Company\Models\Department;
use App\Modules\Employee\Models\Employee;
use App\Modules\Employee\Models\EmployeeEmploymentRecord;
use App\Modules\Payroll\Models\PayPeriod;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Reports\Controllers\ReportExcelExportController;
use App\Modules\Reports\Services\ReportAnalysisService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantRoleProvisioner;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Spatie\Permission\Models\Role;

function loadReportExcelWorkbook(TestResponse $response): Spreadsheet
{
    $path = $response->baseResponse->getFile()->getPathname();
    Cell::setValueBinder(new DefaultValueBinder);
    $reader = IOFactory::createReader('Xlsx');
    $reader->setIncludeCharts(true);

    return $reader->load($path);
}

beforeEach(function (): void {
    if (! Route::has('v1.reports.export.xlsx')) {
        Route::get('/api/v1/reports/{module}/export.xlsx', ReportExcelExportController::class)
            ->whereIn('module', ReportAnalysisService::MODULES)
            ->name('testing.v1.reports.export.xlsx');
    }

    $this->tenant = Tenant::factory()->create(['name' => '=1+1 Packaging']);
    app(TenantRoleProvisioner::class)->provision($this->tenant);
    app(CurrentTenant::class)->set($this->tenant);
    setPermissionsTeamId($this->tenant->id);

    $this->owner = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'status' => User::STATUS_ACTIVE,
    ]);
    $this->owner->assignRole('owner');
});

test('filtered workforce excel is complete typed styled and formula safe', function (): void {
    $includedDepartment = Department::factory()->create(['name' => '@Operations']);
    $excludedDepartment = Department::factory()->create(['name' => 'Finance']);

    $dangerousEmployee = Employee::factory()->create([
        'first_name' => '=2+2',
        'middle_name' => null,
        'last_name' => 'Attack',
        'employment_status' => Employee::STATUS_ACTIVE,
        'hired_at' => '2026-06-01',
    ]);
    EmployeeEmploymentRecord::factory()->create([
        'employee_id' => $dangerousEmployee->id,
        'department_id' => $includedDepartment->id,
        'is_current' => true,
    ]);

    Employee::factory()->count(100)->create([
        'employment_status' => Employee::STATUS_ACTIVE,
        'hired_at' => '2026-06-01',
    ])->each(function (Employee $employee) use ($includedDepartment): void {
        EmployeeEmploymentRecord::factory()->create([
            'employee_id' => $employee->id,
            'department_id' => $includedDepartment->id,
            'is_current' => true,
        ]);
    });

    $filteredOut = Employee::factory()->create([
        'first_name' => 'Filtered',
        'last_name' => 'Out',
        'employment_status' => Employee::STATUS_ACTIVE,
        'hired_at' => '2026-06-01',
    ]);
    EmployeeEmploymentRecord::factory()->create([
        'employee_id' => $filteredOut->id,
        'department_id' => $excludedDepartment->id,
        'is_current' => true,
    ]);

    $response = $this->actingAs($this->owner)->get(
        "/api/v1/reports/workforce/export.xlsx?year=2026&department_id={$includedDepartment->id}&status=active",
    );

    $response->assertOk()
        ->assertDownload('workforce-report-2026.xlsx')
        ->assertHeader('x-content-type-options', 'nosniff');

    $workbook = loadReportExcelWorkbook($response);

    expect($workbook->getSheetNames())->toBe(['Summary', 'Trends & Breakdowns', 'Records']);
    expect($workbook->getProperties()->getCreator())->toBe('FruitionHR')
        ->and($workbook->getProperties()->getCompany())->toBe('=1+1 Packaging')
        ->and($workbook->getProperties()->getTitle())->toBe('Workforce analysis - 2026');

    $summary = $workbook->getSheetByName('Summary');
    $trends = $workbook->getSheetByName('Trends & Breakdowns');
    $records = $workbook->getSheetByName('Records');

    expect($summary)->not->toBeNull()
        ->and($trends)->not->toBeNull()
        ->and($records)->not->toBeNull()
        ->and($summary->getCell('B5')->getValue())->toBe("'=1+1 Packaging")
        ->and($summary->getCell('B8')->getDataType())->toBe(DataType::TYPE_NUMERIC)
        ->and($trends->getChartCollection())->not->toBeEmpty()
        ->and($records->getHighestDataRow())->toBe(105)
        ->and($records->getAutoFilter()->getRange())->toBe('A4:G105')
        ->and($records->getFreezePane())->toBe('A5')
        ->and($records->getCell('F5')->getDataType())->toBe(DataType::TYPE_NUMERIC)
        ->and($records->getStyle('F5')->getNumberFormat()->getFormatCode())->toBe('yyyy-mm-dd');

    $values = [];
    foreach ($workbook->getWorksheetIterator() as $worksheet) {
        foreach ($worksheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                if ($cell->getValue() !== null) {
                    $values[] = (string) $cell->getValue();
                }

                expect($cell->getDataType())->not->toBe(DataType::TYPE_FORMULA);
            }
        }
    }

    expect($values)->toContain("'=2+2 Attack")
        ->not->toContain('Filtered Out');

    $workbook->disconnectWorksheets();
});

test('excel report export requires reports and source module permissions', function (): void {
    $reportsRole = Role::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'excel_reports_only',
        'guard_name' => 'web',
    ]);
    $reportsRole->syncPermissions([Permissions::REPORTS_VIEW]);

    $sourceRole = Role::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'excel_workforce_only',
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
        ->get('/api/v1/reports/workforce/export.xlsx?year=2026')
        ->assertForbidden();

    $this->actingAs($sourceOnly)
        ->get('/api/v1/reports/workforce/export.xlsx?year=2026')
        ->assertForbidden();
});

test('payroll excel converts kobo to typed naira only at presentation boundary', function (): void {
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
        ->get('/api/v1/reports/payroll/export.xlsx?year=2026&period=2026-06&status=paid');

    $response->assertOk()->assertDownload('payroll-report-2026.xlsx');

    $workbook = loadReportExcelWorkbook($response);
    $records = $workbook->getSheetByName('Records');

    expect($records->getCell('D4')->getValue())->toBe('Gross (NGN)')
        ->and($records->getCell('D5')->getDataType())->toBe(DataType::TYPE_NUMERIC)
        ->and((float) $records->getCell('D5')->getValue())->toBe(500000.0)
        ->and($records->getStyle('D5')->getNumberFormat()->getFormatCode())->toBe('"NGN" #,##0.00')
        ->and((float) $records->getCell('G5')->getValue())->toBe(430000.0);

    $workbook->disconnectWorksheets();
});
