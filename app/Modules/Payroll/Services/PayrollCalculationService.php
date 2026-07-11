<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Attendance\Models\AttendanceSummary;
use App\Modules\Employee\Models\Employee;
use App\Modules\Payroll\Models\EmployeeSalary;
use App\Modules\Payroll\Models\PayrollItem;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollRunEmployee;
use App\Modules\Payroll\Support\SalaryResolver;
use App\Modules\Payroll\Support\StatutoryCalculator;

/**
 * Computes and persists one employee's payroll line for a run. All money in
 * kobo. Writes a reproducible snapshot + itemised breakdown so the payslip is
 * reproducible forever, even after salary/config changes.
 *
 * Net = gross − (PAYE + employee pension + NHF) − component deductions
 *       − absence deduction.
 * Employer costs (employer pension, NSITF) are tracked but do not reduce net.
 */
class PayrollCalculationService
{
    public function __construct(
        private readonly SalaryResolver $resolver,
        private readonly StatutoryCalculator $statutory,
    ) {
    }

    public function calculateFor(PayrollRun $run, Employee $employee): ?PayrollRunEmployee
    {
        $salary = EmployeeSalary::query()
            ->where('employee_id', $employee->id)
            ->where('is_current', true)
            ->with('structure.components.component')
            ->first();

        if ($salary === null) {
            return null; // preflight prevents this; guard anyway
        }

        $breakdown = $this->resolver->resolve(
            $salary->basic_salary,
            $salary->structure?->components ?? collect(),
        );

        $statutory = $this->statutory->compute($breakdown, $run->period);

        $absence = $this->absenceDeduction($employee, $run->period, $breakdown->gross());
        $componentDeductions = $breakdown->componentDeductions();

        $gross = $breakdown->gross();
        $employeeStatutory = $statutory->employeeDeductions();
        $totalDeductions = $employeeStatutory + $componentDeductions + $absence;
        $net = $gross - $totalDeductions;

        $runEmployee = $run->runEmployees()->create([
            'employee_id' => $employee->id,
            'snapshot' => $this->snapshot($employee, $salary, $breakdown, $statutory, $absence),
            'gross' => $gross,
            'taxable_pay' => $breakdown->taxablePay(),
            'pensionable_pay' => $breakdown->pensionablePay(),
            'total_statutory' => $employeeStatutory,
            'total_deductions' => $totalDeductions,
            'net' => $net,
            'pension_employer' => $statutory->pensionEmployer,
            'nsitf' => $statutory->nsitf,
        ]);

        $this->writeItems($runEmployee, $breakdown, $statutory, $componentDeductions, $absence);

        return $runEmployee;
    }

    private function absenceDeduction(Employee $employee, string $period, int $gross): int
    {
        $summary = AttendanceSummary::query()
            ->where('employee_id', $employee->id)
            ->where('period', $period)
            ->where('status', AttendanceSummary::STATUS_FINALIZED)
            ->first();

        if ($summary === null || $summary->working_days <= 0 || $summary->days_absent <= 0) {
            return 0;
        }

        // Unpaid absence: pro-rata of gross over working days.
        return (int) round($gross * $summary->days_absent / $summary->working_days);
    }

    private function writeItems(
        PayrollRunEmployee $runEmployee,
        $breakdown,
        $statutory,
        int $componentDeductions,
        int $absence,
    ): void {
        $items = [];

        $items[] = ['category' => PayrollItem::CATEGORY_EARNING, 'code' => 'BASIC', 'name' => 'Basic Salary', 'amount' => $breakdown->basic];
        foreach ($breakdown->earnings as $e) {
            $items[] = ['category' => PayrollItem::CATEGORY_EARNING, 'code' => $e['code'], 'name' => $e['name'], 'amount' => $e['amount']];
        }

        $items[] = ['category' => PayrollItem::CATEGORY_STATUTORY, 'code' => 'PAYE', 'name' => 'PAYE Tax', 'amount' => $statutory->paye];
        $items[] = ['category' => PayrollItem::CATEGORY_STATUTORY, 'code' => 'PENSION', 'name' => 'Pension (Employee)', 'amount' => $statutory->pensionEmployee];
        $items[] = ['category' => PayrollItem::CATEGORY_STATUTORY, 'code' => 'NHF', 'name' => 'NHF', 'amount' => $statutory->nhf];

        foreach ($breakdown->deductions as $d) {
            $items[] = ['category' => PayrollItem::CATEGORY_DEDUCTION, 'code' => $d['code'], 'name' => $d['name'], 'amount' => $d['amount']];
        }

        if ($absence > 0) {
            $items[] = ['category' => PayrollItem::CATEGORY_DEDUCTION, 'code' => 'ABSENCE', 'name' => 'Absence Deduction', 'amount' => $absence];
        }

        // Employer costs (not deducted from net; shown for reporting)
        $items[] = ['category' => PayrollItem::CATEGORY_EMPLOYER, 'code' => 'PENSION_ER', 'name' => 'Pension (Employer)', 'amount' => $statutory->pensionEmployer];
        $items[] = ['category' => PayrollItem::CATEGORY_EMPLOYER, 'code' => 'NSITF', 'name' => 'NSITF (Employer)', 'amount' => $statutory->nsitf];

        $runEmployee->items()->createMany($items);
    }

    private function snapshot(Employee $employee, EmployeeSalary $salary, $breakdown, $statutory, int $absence): array
    {
        return [
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->full_name,
                'employee_number' => $employee->employee_number,
            ],
            'basic_salary' => $salary->basic_salary,
            'structure' => $salary->structure?->name,
            'earnings' => $breakdown->earnings,
            'component_deductions' => $breakdown->deductions,
            'statutory' => [
                'paye' => $statutory->paye,
                'pension_employee' => $statutory->pensionEmployee,
                'pension_employer' => $statutory->pensionEmployer,
                'nhf' => $statutory->nhf,
                'nsitf' => $statutory->nsitf,
            ],
            'absence_deduction' => $absence,
            'computed_at' => now()->toISOString(),
        ];
    }
}
