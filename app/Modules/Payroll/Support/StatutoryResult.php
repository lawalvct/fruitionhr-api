<?php

namespace App\Modules\Payroll\Support;

/**
 * Computed statutory figures for one employee for one period, in kobo.
 * Employee deductions (paye, pensionEmployee, nhf) reduce net pay; employer
 * costs (pensionEmployer, nsitf) are liabilities for remittance only.
 */
final class StatutoryResult
{
    public function __construct(
        public readonly int $paye,
        public readonly int $pensionEmployee,
        public readonly int $pensionEmployer,
        public readonly int $nhf,
        public readonly int $nsitf,
    ) {
    }

    /** Statutory deductions that reduce the employee's net pay. */
    public function employeeDeductions(): int
    {
        return $this->paye + $this->pensionEmployee + $this->nhf;
    }
}
