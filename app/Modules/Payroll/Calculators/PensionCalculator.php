<?php

namespace App\Modules\Payroll\Calculators;

/**
 * Nigerian Pension (PRA 2014): employee 8% / employer 10% of pensionable pay
 * (basic + housing + transport). All money in integer kobo.
 */
class PensionCalculator
{
    /**
     * @param  array{employee_percent:float, employer_percent:float}  $config
     */
    public function employee(array $config, int $pensionablePay): int
    {
        return (int) round($pensionablePay * ($config['employee_percent'] / 100));
    }

    public function employer(array $config, int $pensionablePay): int
    {
        return (int) round($pensionablePay * ($config['employer_percent'] / 100));
    }
}
