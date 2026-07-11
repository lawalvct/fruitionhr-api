<?php

namespace App\Modules\Payroll\Calculators;

/**
 * Nigerian PAYE (Pay As You Earn) income tax. All money in integer kobo.
 *
 * Interpretation implemented (the common Nigerian payroll model — VERIFY
 * against the client's payroll Excel before go-live, per the execution plan):
 *
 *   Gross Income (GI)   = annual gross emolument
 *                         − tax-exempt deductions (pension, NHF) when enabled
 *   Consolidated Relief = max(cra_min, cra_gross_percent% of GI)
 *                         + cra_percent% of GI
 *   Taxable Income      = GI − Consolidated Relief   (floored at 0)
 *   Annual PAYE         = progressive bands applied to Taxable Income
 *   Monthly PAYE        = round(Annual PAYE / 12)
 *
 * Everything (bands, CRA rates, whether pension/NHF are deductible) is driven
 * by config so a tax-law change is a data update, never a code change.
 */
class PayeCalculator
{
    /**
     * @param  array{
     *     cra_min:int, cra_percent:float, cra_gross_percent:float,
     *     relief_deduct_pension:bool, relief_deduct_nhf:bool,
     *     bands:list<array{width:?int, rate:float}>
     * }  $config
     * @param  int  $monthlyGross  taxable gross emolument (kobo/month)
     * @param  int  $monthlyPension  employee pension contribution (kobo/month)
     * @param  int  $monthlyNhf  NHF contribution (kobo/month)
     */
    public function monthly(array $config, int $monthlyGross, int $monthlyPension, int $monthlyNhf): int
    {
        $annualGross = $monthlyGross * 12;
        $exempt = 0;
        if ($config['relief_deduct_pension'] ?? true) {
            $exempt += $monthlyPension * 12;
        }
        if ($config['relief_deduct_nhf'] ?? true) {
            $exempt += $monthlyNhf * 12;
        }

        $grossIncome = max(0, $annualGross - $exempt);

        $cra = (int) round(
            max($config['cra_min'], $grossIncome * ($config['cra_gross_percent'] / 100))
            + $grossIncome * ($config['cra_percent'] / 100)
        );

        $taxable = max(0, $grossIncome - $cra);

        $annualTax = $this->applyBands($config['bands'], $taxable);

        return (int) round($annualTax / 12);
    }

    /**
     * Apply progressive bands to an annual taxable income (kobo).
     *
     * @param  list<array{width:?int, rate:float}>  $bands  last band's width null = remainder
     */
    public function applyBands(array $bands, int $taxable): int
    {
        $tax = 0.0;
        $remaining = $taxable;

        foreach ($bands as $band) {
            if ($remaining <= 0) {
                break;
            }

            $slice = $band['width'] === null ? $remaining : min($remaining, $band['width']);
            $tax += $slice * ($band['rate'] / 100);
            $remaining -= $slice;
        }

        return (int) round($tax);
    }
}
