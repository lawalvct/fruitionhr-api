<?php

namespace App\Modules\Payroll\Support;

/**
 * Resolved monthly salary breakdown, all amounts in integer kobo. Produced by
 * SalaryResolver and consumed by both the compensation display and payroll.
 */
final class SalaryBreakdown
{
    /**
     * @param  list<array{code:string,name:string,amount:int,is_taxable:bool,is_pensionable:bool}>  $earnings
     * @param  list<array{code:string,name:string,amount:int}>  $deductions  component-based (voluntary) deductions
     */
    public function __construct(
        public readonly int $basic,
        public readonly array $earnings,
        public readonly array $deductions,
    ) {
    }

    /** Basic + all earning components. */
    public function gross(): int
    {
        return $this->basic + array_sum(array_column($this->earnings, 'amount'));
    }

    /** Basic + taxable earning components (the PAYE gross before reliefs). */
    public function taxablePay(): int
    {
        return $this->basic + array_sum(
            array_column(array_filter($this->earnings, fn ($e) => $e['is_taxable']), 'amount')
        );
    }

    /** Basic + pensionable earning components (the pension base). */
    public function pensionablePay(): int
    {
        return $this->basic + array_sum(
            array_column(array_filter($this->earnings, fn ($e) => $e['is_pensionable']), 'amount')
        );
    }

    /** Sum of component-based (voluntary) deductions. */
    public function componentDeductions(): int
    {
        return array_sum(array_column($this->deductions, 'amount'));
    }
}
