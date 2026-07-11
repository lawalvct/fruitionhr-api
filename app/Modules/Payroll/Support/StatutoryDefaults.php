<?php

namespace App\Modules\Payroll\Support;

use App\Modules\Payroll\Models\StatutoryRule;

/**
 * Default Nigerian statutory configuration (all money in kobo, annual figures
 * where noted). Seeded per tenant; tenants can add newer effective-dated rows
 * to override. VERIFY PAYE against the client's payroll Excel before go-live.
 */
class StatutoryDefaults
{
    public const EFFECTIVE_FROM = '2020-01-01';

    /**
     * @return array<int, array{type:string, config:array}>
     */
    public static function all(): array
    {
        return [
            [
                'type' => StatutoryRule::TYPE_PAYE,
                'config' => [
                    'cra_min' => 20_000_000,        // ₦200,000 annual
                    'cra_percent' => 20.0,          // + 20% of gross income
                    'cra_gross_percent' => 1.0,     // higher-of vs ₦200,000
                    'relief_deduct_pension' => true,
                    'relief_deduct_nhf' => true,
                    'bands' => [
                        ['width' => 30_000_000, 'rate' => 7.0],   // first ₦300,000
                        ['width' => 30_000_000, 'rate' => 11.0],  // next ₦300,000
                        ['width' => 50_000_000, 'rate' => 15.0],  // next ₦500,000
                        ['width' => 50_000_000, 'rate' => 19.0],  // next ₦500,000
                        ['width' => 160_000_000, 'rate' => 21.0], // next ₦1,600,000
                        ['width' => null, 'rate' => 24.0],        // above ₦3,200,000
                    ],
                ],
            ],
            [
                'type' => StatutoryRule::TYPE_PENSION,
                'config' => ['employee_percent' => 8.0, 'employer_percent' => 10.0],
            ],
            [
                'type' => StatutoryRule::TYPE_NHF,
                'config' => ['percent' => 2.5],
            ],
            [
                'type' => StatutoryRule::TYPE_NSITF,
                'config' => ['percent' => 1.0],
            ],
        ];
    }
}
