<?php

namespace App\Modules\Payroll\Exports;

use App\Modules\Employee\Models\EmployeeBankAccount;
use App\Modules\Payroll\Models\PayrollRun;
use App\Support\Money\Naira;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Bank payment schedule for a payroll run: who gets paid how much, to which
 * account. Uses each employee's primary bank account.
 */
class BankScheduleExport implements FromCollection, WithHeadings
{
    public function __construct(private readonly PayrollRun $run)
    {
    }

    public function headings(): array
    {
        return ['S/N', 'Employee', 'Employee No', 'Bank', 'Account Name', 'Account Number', 'Net Pay (NGN)'];
    }

    public function collection()
    {
        $lines = $this->run->runEmployees()->with('employee')->get();

        $accounts = EmployeeBankAccount::query()
            ->whereIn('employee_id', $lines->pluck('employee_id'))
            ->where('is_primary', true)
            ->get()
            ->keyBy('employee_id');

        return $lines->values()->map(function ($line, $index) use ($accounts) {
            $account = $accounts->get($line->employee_id);

            return [
                $index + 1,
                $line->employee->full_name,
                $line->employee->employee_number,
                $account?->bank_name ?? '—',
                $account?->account_name ?? '—',
                $account?->account_number ?? '—',
                Naira::toNaira($line->net),
            ];
        });
    }
}
