<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollRunEmployee;

/**
 * Compares a payroll run to the previous non-reversal run, per employee, so
 * reviewers can spot unexpected movements before approving.
 */
class PayrollVarianceService
{
    /**
     * @return array{
     *   current_period:string, previous_period:?string,
     *   totals:array{current_net:int, previous_net:int, delta:int, percent:?float},
     *   rows:list<array>
     * }
     */
    public function forRun(PayrollRun $run): array
    {
        $previous = PayrollRun::query()
            ->where('is_reversal', false)
            ->whereIn('status', [PayrollRun::STATUS_APPROVED, PayrollRun::STATUS_LOCKED, PayrollRun::STATUS_PAID])
            ->where('period', '<', $run->period)
            ->where('id', '!=', $run->id)
            ->orderByDesc('period')
            ->first();

        $currentLines = $this->netByEmployee($run);
        $previousLines = $previous ? $this->netByEmployee($previous) : [];

        $employeeIds = array_unique([...array_keys($currentLines), ...array_keys($previousLines)]);

        $rows = [];
        foreach ($employeeIds as $employeeId) {
            $current = $currentLines[$employeeId] ?? null;
            $prev = $previousLines[$employeeId] ?? null;

            $currentNet = $current['net'] ?? 0;
            $previousNet = $prev['net'] ?? 0;
            $delta = $currentNet - $previousNet;

            $rows[] = [
                'employee_id' => $employeeId,
                'name' => $current['name'] ?? $prev['name'],
                'current_net' => $currentNet,
                'previous_net' => $previousNet,
                'delta' => $delta,
                'percent' => $this->percent($delta, $previousNet),
                'flag' => $this->flag($current, $prev),
            ];
        }

        $currentTotal = array_sum(array_column($rows, 'current_net'));
        $previousTotal = array_sum(array_column($rows, 'previous_net'));

        return [
            'current_period' => $run->period,
            'previous_period' => $previous?->period,
            'totals' => [
                'current_net' => $currentTotal,
                'previous_net' => $previousTotal,
                'delta' => $currentTotal - $previousTotal,
                'percent' => $this->percent($currentTotal - $previousTotal, $previousTotal),
            ],
            'rows' => $rows,
        ];
    }

    /** @return array<int, array{name:string, net:int}> */
    private function netByEmployee(PayrollRun $run): array
    {
        return $run->runEmployees()
            ->with('employee')
            ->get()
            ->mapWithKeys(fn (PayrollRunEmployee $re) => [
                $re->employee_id => ['name' => $re->employee->full_name, 'net' => $re->net],
            ])
            ->all();
    }

    private function percent(int $delta, int $base): ?float
    {
        if ($base === 0) {
            return null; // new starter or previously zero — undefined %
        }

        return round($delta / abs($base) * 100, 2);
    }

    private function flag(?array $current, ?array $prev): string
    {
        if ($current === null) {
            return 'removed';   // was paid last period, not this one
        }
        if ($prev === null) {
            return 'new';       // new on this run
        }

        return 'changed';
    }
}
