<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Overtime
    |--------------------------------------------------------------------------
    |
    | standard_monthly_hours: divisor used to derive an hourly rate from an
    | employee's monthly basic salary (hourly = basic / standard_monthly_hours).
    | 208 ≈ 26 working days × 8 hours. Tune per client if needed.
    |
    | multipliers: the overtime rate multipliers HR may choose per record
    | (e.g. 1× normal, 1.5× after-hours, 2× weekend/public holiday).
    |
    */

    'overtime' => [
        'standard_monthly_hours' => (int) env('PAYROLL_OVERTIME_STANDARD_HOURS', 208),
        'multipliers' => [1, 1.5, 2],
    ],

];
