<?php

namespace App\Modules\Payroll\Support;

use App\Modules\Payroll\Models\SalaryComponent;
use App\Modules\Payroll\Models\SalaryStructure;

/**
 * Turns a basic salary + structure into a concrete kobo breakdown. Pure and
 * deterministic — unit-tested; payroll depends on these figures.
 */
class SalaryResolver
{
    /**
     * @param  int  $basic  monthly basic salary in kobo
     * @param  iterable<object{amount:?int,percent:?int,component:SalaryComponent}>  $structureComponents
     *         each having ->amount (kobo|null), ->percent (int|null), ->component (SalaryComponent)
     */
    public function resolve(int $basic, iterable $structureComponents): SalaryBreakdown
    {
        $earnings = [];
        $deductions = [];

        foreach ($structureComponents as $line) {
            $component = $line->component;
            $amount = $this->lineAmount($basic, $line, $component);

            if ($component->type === SalaryComponent::TYPE_DEDUCTION) {
                $deductions[] = [
                    'code' => $component->code,
                    'name' => $component->name,
                    'amount' => $amount,
                ];

                continue;
            }

            $earnings[] = [
                'code' => $component->code,
                'name' => $component->name,
                'amount' => $amount,
                'is_taxable' => (bool) $component->is_taxable,
                'is_pensionable' => (bool) $component->is_pensionable,
            ];
        }

        return new SalaryBreakdown($basic, $earnings, $deductions);
    }

    /**
     * Resolve one component's kobo value. Precedence:
     * 1. structure override amount (fixed kobo)
     * 2. structure override percent (% of basic)
     * 3. component's own calc_type (percent_of_basic uses component.percent;
     *    fixed with no override resolves to 0)
     */
    private function lineAmount(int $basic, object $line, SalaryComponent $component): int
    {
        if ($line->amount !== null) {
            return (int) $line->amount;
        }

        if ($line->percent !== null) {
            return $this->percentOfBasic($basic, (int) $line->percent);
        }

        if ($component->calc_type === SalaryComponent::CALC_PERCENT && $component->percent !== null) {
            return $this->percentOfBasic($basic, (int) $component->percent);
        }

        return 0;
    }

    private function percentOfBasic(int $basic, int $percent): int
    {
        return (int) round($basic * $percent / 100);
    }
}
