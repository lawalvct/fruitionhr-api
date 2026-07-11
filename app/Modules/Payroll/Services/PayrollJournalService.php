<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Models\PayrollItem;
use App\Modules\Payroll\Models\PayrollRun;

/**
 * Builds a balanced double-entry payroll journal for a run (dev plan §13.7),
 * so companies can export payroll into their accounting software. All kobo.
 *
 *   DR Salary Expense                (gross earnings)
 *   DR Employer Pension Expense
 *   DR NSITF Expense
 *   CR Net Salary Payable            (net pay)
 *   CR PAYE Payable
 *   CR Pension Payable               (employee + employer)
 *   CR NHF Payable
 *   CR NSITF Payable
 *   CR Other Deductions Payable      (absence + component deductions)
 */
class PayrollJournalService
{
    /**
     * @return array{
     *   period:string,
     *   entries:list<array{account:string, type:string, amount:int}>,
     *   total_debit:int, total_credit:int, balanced:bool
     * }
     */
    public function forRun(PayrollRun $run): array
    {
        $ids = $run->runEmployees()->pluck('id');
        $items = PayrollItem::query()->whereIn('payroll_run_employee_id', $ids)->get();

        $sumByCode = fn (string $code) => (int) $items->where('code', $code)->sum('amount');
        $sumByCategory = fn (string $cat) => (int) $items->where('category', $cat)->sum('amount');

        $gross = $sumByCategory(PayrollItem::CATEGORY_EARNING);
        $paye = $sumByCode('PAYE');
        $pensionEmployee = $sumByCode('PENSION');
        $pensionEmployer = $sumByCode('PENSION_ER');
        $nhf = $sumByCode('NHF');
        $nsitf = $sumByCode('NSITF');
        $otherDeductions = $sumByCategory(PayrollItem::CATEGORY_DEDUCTION);
        $net = $gross - $paye - $pensionEmployee - $nhf - $otherDeductions;

        $entries = array_values(array_filter([
            $this->entry('Salary Expense', 'debit', $gross),
            $this->entry('Employer Pension Expense', 'debit', $pensionEmployer),
            $this->entry('NSITF Expense', 'debit', $nsitf),
            $this->entry('Net Salary Payable', 'credit', $net),
            $this->entry('PAYE Payable', 'credit', $paye),
            $this->entry('Pension Payable', 'credit', $pensionEmployee + $pensionEmployer),
            $this->entry('NHF Payable', 'credit', $nhf),
            $this->entry('NSITF Payable', 'credit', $nsitf),
            $this->entry('Other Deductions Payable', 'credit', $otherDeductions),
        ], fn ($e) => $e !== null));

        $totalDebit = array_sum(array_column(array_filter($entries, fn ($e) => $e['type'] === 'debit'), 'amount'));
        $totalCredit = array_sum(array_column(array_filter($entries, fn ($e) => $e['type'] === 'credit'), 'amount'));

        return [
            'period' => $run->period,
            'entries' => $entries,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'balanced' => $totalDebit === $totalCredit,
        ];
    }

    private function entry(string $account, string $type, int $amount): ?array
    {
        if ($amount === 0) {
            return null; // omit zero lines
        }

        return ['account' => $account, 'type' => $type, 'amount' => $amount];
    }
}
