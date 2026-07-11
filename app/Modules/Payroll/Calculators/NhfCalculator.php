<?php

namespace App\Modules\Payroll\Calculators;

/**
 * National Housing Fund: 2.5% of basic salary (employee deduction).
 * All money in integer kobo.
 */
class NhfCalculator
{
    /**
     * @param  array{percent:float}  $config
     */
    public function monthly(array $config, int $basic): int
    {
        return (int) round($basic * ($config['percent'] / 100));
    }
}
