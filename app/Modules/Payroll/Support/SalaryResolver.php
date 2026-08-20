<?php

namespace App\Modules\Payroll\Support;

use App\Modules\Payroll\Formula\SalaryFormulaException;
use App\Modules\Payroll\Models\EmployeeSalaryComponentOverride;
use App\Modules\Payroll\Models\SalaryComponent;

/**
 * Turns a basic salary + structure into a concrete kobo breakdown. Pure and
 * deterministic — unit-tested; payroll depends on these figures.
 */
class SalaryResolver
{
    /**
     * @param  int  $basic  monthly basic salary in kobo
     * @param  iterable<object{amount:?int,percent:?int,component:SalaryComponent}>  $structureComponents
     *                                                                                                     each having ->amount (kobo|null), ->percent (int|null), ->component (SalaryComponent)
     * @param  iterable<EmployeeSalaryComponentOverride>  $componentOverrides
     */
    public function resolve(
        int $basic,
        iterable $structureComponents,
        iterable $componentOverrides = [],
        ?array $definitionSnapshot = null,
    ): SalaryBreakdown {
        if ($definitionSnapshot !== null) {
            return app(SalarySnapshotResolver::class)->resolve($basic, $definitionSnapshot);
        }

        $effectiveLines = [];

        foreach ($structureComponents as $line) {
            if ($line->component === null) {
                continue;
            }

            $effectiveLines[$this->componentKey($line->component)] = $line;
        }

        foreach ($componentOverrides as $override) {
            if ($override->component === null || $override->component->isReservedBasicSalaryComponent()) {
                continue;
            }

            $key = $this->componentKey($override->component);
            if ($override->mode === EmployeeSalaryComponentOverride::MODE_EXCLUDED) {
                unset($effectiveLines[$key]);

                continue;
            }

            $effectiveLines[$key] = (object) [
                'amount' => $override->amount,
                'percent' => $override->percent,
                'component' => $override->component,
            ];
        }

        // Basic salary is the canonical employee-level value. Ignore any legacy
        // structure component that would otherwise count it twice.
        $lines = [];
        foreach ($effectiveLines as $line) {
            if (! $line->component->isReservedBasicSalaryComponent()) {
                $lines[] = $line;
            }
        }

        $amounts = $this->amountsFor($basic, $lines);

        $earnings = [];
        $deductions = [];
        $employerContributions = [];
        $fringeBenefits = [];

        foreach ($lines as $index => $line) {
            $component = $line->component;
            $amount = $amounts[$index];

            if ($component->type === SalaryComponent::TYPE_DEDUCTION) {
                $deductions[] = [
                    'code' => $component->code,
                    'name' => $component->name,
                    'amount' => $amount,
                ];

                continue;
            }

            if ($component->type === SalaryComponent::TYPE_EMPLOYER_CONTRIBUTOR) {
                $employerContributions[] = [
                    'code' => $component->code,
                    'name' => $component->name,
                    'amount' => $amount,
                ];

                continue;
            }

            if ($component->type === SalaryComponent::TYPE_FRINGE_BENEFIT) {
                $fringeBenefits[] = [
                    'code' => $component->code,
                    'name' => $component->name,
                    'amount' => $amount,
                    'is_taxable' => (bool) $component->is_taxable,
                    'is_pensionable' => (bool) $component->is_pensionable,
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

        return new SalaryBreakdown($basic, $earnings, $deductions, $employerContributions, $fringeBenefits);
    }

    /**
     * Every line's kobo value, keyed by its position in $lines.
     *
     * Resolved in stages because a percent-of-gross line cannot be priced until
     * the thing it is a percentage of exists:
     *
     *  1. Lines that don't follow the gross — fixed amounts and percent-of-basic.
     *  2. Percent-of-gross EARNINGS, against basic + the stage-1 earnings.
     *     They are excluded from their own base on purpose: gross is defined as
     *     basic + every earning, so counting them would make each one depend on
     *     the others and on itself.
     *  3. Percent-of-gross deductions, employer costs and benefits in kind,
     *     against the finished gross. Nothing here is part of gross, so the
     *     full figure is available and there is no loop to break.
     *
     * @param  list<object{amount:?int,percent:?int,component:SalaryComponent}>  $lines
     * @return array<int, int>
     */
    private function amountsFor(int $basic, array $lines): array
    {
        $amounts = [];
        $followsGross = [];

        foreach ($lines as $index => $line) {
            if ($line->component->calc_type === SalaryComponent::CALC_FORMULA) {
                throw new SalaryFormulaException(
                    'Formula salary components require an immutable employee salary definition snapshot.',
                    'FORMULA_SNAPSHOT_REQUIRED',
                );
            }

            $percent = $this->grossPercentFor($line, $line->component);

            if ($percent === null) {
                $amounts[$index] = $this->fixedOrBasicAmount($basic, $line, $line->component);

                continue;
            }

            $followsGross[$index] = $percent;
        }

        $earningsBase = $basic;
        foreach ($amounts as $index => $amount) {
            if ($lines[$index]->component->type === SalaryComponent::TYPE_EARNING) {
                $earningsBase += $amount;
            }
        }

        $gross = $earningsBase;
        foreach ($followsGross as $index => $percent) {
            if ($lines[$index]->component->type !== SalaryComponent::TYPE_EARNING) {
                continue;
            }

            $amounts[$index] = $this->percentOf($earningsBase, $percent);
            $gross += $amounts[$index];
        }

        foreach ($followsGross as $index => $percent) {
            if ($lines[$index]->component->type === SalaryComponent::TYPE_EARNING) {
                continue;
            }

            $amounts[$index] = $this->percentOf($gross, $percent);
        }

        return $amounts;
    }

    /**
     * The percentage of gross this line follows, or null when it doesn't.
     *
     * A structure amount or a structure percent still wins over the component's
     * own calc_type, so overriding a percent-of-gross component with a flat
     * figure keeps it out of the gross-dependent stage entirely.
     */
    private function grossPercentFor(object $line, SalaryComponent $component): ?int
    {
        if ($line->amount !== null || $line->percent !== null) {
            return null;
        }

        return $component->calc_type === SalaryComponent::CALC_PERCENT_OF_GROSS && $component->percent !== null
            ? (int) $component->percent
            : null;
    }

    /**
     * Resolve one gross-independent line. Precedence:
     * 1. structure override amount (fixed kobo)
     * 2. structure override percent (% of basic)
     * 3. component's own calc_type (percent_of_basic uses component.percent;
     *    fixed with no override resolves to 0)
     */
    private function fixedOrBasicAmount(int $basic, object $line, SalaryComponent $component): int
    {
        if ($line->amount !== null) {
            return (int) $line->amount;
        }

        if ($line->percent !== null) {
            return $this->percentOf($basic, (int) $line->percent);
        }

        if ($component->calc_type === SalaryComponent::CALC_PERCENT && $component->percent !== null) {
            return $this->percentOf($basic, (int) $component->percent);
        }

        return 0;
    }

    private function percentOf(int $base, int $percent): int
    {
        return (int) round($base * $percent / 100);
    }

    private function componentKey(SalaryComponent $component): string
    {
        return $component->getKey() !== null
            ? 'id:'.$component->getKey()
            : 'code:'.strtoupper($component->code);
    }
}
