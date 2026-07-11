<?php

namespace App\Modules\Payroll\Support;

use App\Modules\Payroll\Calculators\NhfCalculator;
use App\Modules\Payroll\Calculators\NsitfCalculator;
use App\Modules\Payroll\Calculators\PayeCalculator;
use App\Modules\Payroll\Calculators\PensionCalculator;
use App\Modules\Payroll\Models\StatutoryRule;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Orchestrates the statutory calculators against a salary breakdown, using the
 * rules in effect for the given period. Pension and NHF are computed first
 * because PAYE relief depends on them.
 */
class StatutoryCalculator
{
    public function __construct(
        private readonly PayeCalculator $paye,
        private readonly PensionCalculator $pension,
        private readonly NhfCalculator $nhf,
        private readonly NsitfCalculator $nsitf,
    ) {
    }

    public function compute(SalaryBreakdown $breakdown, string $period): StatutoryResult
    {
        $configs = $this->configsFor($period);

        $pensionEmployee = $this->pension->employee($configs['pension'], $breakdown->pensionablePay());
        $pensionEmployer = $this->pension->employer($configs['pension'], $breakdown->pensionablePay());
        $nhf = $this->nhf->monthly($configs['nhf'], $breakdown->basic);

        $paye = $this->paye->monthly(
            $configs['paye'],
            $breakdown->taxablePay(),
            $pensionEmployee,
            $nhf,
        );

        $nsitf = $this->nsitf->monthly($configs['nsitf'], $breakdown->gross());

        return new StatutoryResult($paye, $pensionEmployee, $pensionEmployer, $nhf, $nsitf);
    }

    /**
     * Resolve the active config for each statutory type as of the period.
     *
     * @return array<string, array>
     */
    public function configsFor(string $period): array
    {
        $asOf = Carbon::createFromFormat('Y-m', $period)->startOfMonth()->toDateString();

        $types = [
            StatutoryRule::TYPE_PAYE,
            StatutoryRule::TYPE_PENSION,
            StatutoryRule::TYPE_NHF,
            StatutoryRule::TYPE_NSITF,
        ];

        $configs = [];

        foreach ($types as $type) {
            $rule = StatutoryRule::query()
                ->where('type', $type)
                ->where('is_active', true)
                ->where('effective_from', '<=', $asOf)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $asOf))
                ->orderByDesc('effective_from')
                ->first();

            if ($rule === null) {
                throw new RuntimeException("No active [{$type}] statutory rule effective for {$period}.");
            }

            $configs[$type] = $rule->config;
        }

        return $configs;
    }
}
