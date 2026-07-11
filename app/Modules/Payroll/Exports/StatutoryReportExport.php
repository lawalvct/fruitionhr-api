<?php

namespace App\Modules\Payroll\Exports;

use App\Modules\Payroll\Models\PayrollRun;
use App\Support\Money\Naira;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Statutory remittance schedule for one deduction type (paye|pension|nhf|
 * nsitf) across a payroll run. Reads the itemised payroll lines so figures
 * match the payslips exactly.
 */
class StatutoryReportExport implements FromCollection, WithHeadings
{
    /** Item codes contributing to each report type. */
    private const CODES = [
        'paye' => ['PAYE'],
        'pension' => ['PENSION', 'PENSION_ER'],
        'nhf' => ['NHF'],
        'nsitf' => ['NSITF'],
    ];

    public function __construct(
        private readonly PayrollRun $run,
        private readonly string $type,
    ) {
    }

    public function headings(): array
    {
        return match ($this->type) {
            'pension' => ['S/N', 'Employee', 'Employee No', 'Employee (NGN)', 'Employer (NGN)', 'Total (NGN)'],
            default => ['S/N', 'Employee', 'Employee No', 'Amount (NGN)'],
        };
    }

    public function collection()
    {
        $codes = self::CODES[$this->type];

        $lines = $this->run->runEmployees()->with(['employee', 'items'])->get();

        return $lines->values()->map(function ($line, $index) use ($codes) {
            $byCode = $line->items->whereIn('code', $codes)->keyBy('code');

            if ($this->type === 'pension') {
                $employee = (int) ($byCode['PENSION']->amount ?? 0);
                $employer = (int) ($byCode['PENSION_ER']->amount ?? 0);

                return [
                    $index + 1,
                    $line->employee->full_name,
                    $line->employee->employee_number,
                    Naira::toNaira($employee),
                    Naira::toNaira($employer),
                    Naira::toNaira($employee + $employer),
                ];
            }

            $amount = (int) collect($codes)->sum(fn ($c) => $byCode[$c]->amount ?? 0);

            return [
                $index + 1,
                $line->employee->full_name,
                $line->employee->employee_number,
                Naira::toNaira($amount),
            ];
        });
    }
}
