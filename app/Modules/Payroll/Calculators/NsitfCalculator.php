<?php

namespace App\Modules\Payroll\Calculators;

/**
 * NSITF (Employee Compensation Scheme): 1% of monthly emolument, paid by the
 * EMPLOYER. It is an employer liability for remittance/reporting and does NOT
 * reduce the employee's net pay. All money in integer kobo.
 */
class NsitfCalculator
{
    /**
     * @param  array{percent:float}  $config
     */
    public function monthly(array $config, int $gross): int
    {
        return (int) round($gross * ($config['percent'] / 100));
    }
}
