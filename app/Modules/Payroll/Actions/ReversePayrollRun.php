<?php

namespace App\Modules\Payroll\Actions;

use App\Models\User;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Services\LoanService;
use App\Modules\Payroll\Services\OvertimeService;
use App\Modules\Payroll\Support\PayrollRunState;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Reverses a locked payroll run. The original snapshot is never touched
 * (immutability); instead a mirror run with negated figures is posted and the
 * original is marked reversed. Original + reversal net to zero.
 */
class ReversePayrollRun
{
    public function __construct(
        private readonly PayrollRunState $state,
        private readonly OvertimeService $overtime,
        private readonly LoanService $loans,
    ) {
    }

    public function execute(PayrollRun $run, User $user, string $reason): PayrollRun
    {
        if (! $run->isLocked()) {
            throw new ConflictHttpException('Only a locked or paid payroll run can be reversed.');
        }

        if ($run->is_reversal) {
            throw new ConflictHttpException('A reversal run cannot itself be reversed.');
        }

        return DB::transaction(function () use ($run, $user, $reason): PayrollRun {
            $reversal = PayrollRun::query()->create([
                'pay_period_id' => $run->pay_period_id,
                'period' => $run->period,
                'status' => PayrollRun::STATUS_LOCKED, // posted correcting entry
                'is_reversal' => true,
                'reversed_of_run_id' => $run->id,
                'reversal_reason' => $reason,
                'employee_count' => $run->employee_count,
                'total_gross' => -$run->total_gross,
                'total_statutory' => -$run->total_statutory,
                'total_deductions' => -$run->total_deductions,
                'total_net' => -$run->total_net,
                'total_employer_cost' => -$run->total_employer_cost,
                'created_by' => $user->id,
                'locked_at' => now(),
            ]);

            // Mirror each employee line and item with negated amounts.
            $run->runEmployees()->with('items')->get()->each(function ($line) use ($reversal): void {
                $mirror = $reversal->runEmployees()->create([
                    'employee_id' => $line->employee_id,
                    'snapshot' => ['reversal_of' => $line->id] + $line->snapshot,
                    'gross' => -$line->gross,
                    'taxable_pay' => -$line->taxable_pay,
                    'pensionable_pay' => -$line->pensionable_pay,
                    'total_statutory' => -$line->total_statutory,
                    'total_deductions' => -$line->total_deductions,
                    'net' => -$line->net,
                    'pension_employer' => -$line->pension_employer,
                    'nsitf' => -$line->nsitf,
                ]);

                $mirror->items()->createMany(
                    $line->items->map(fn ($item) => [
                        'category' => $item->category,
                        'code' => $item->code,
                        'name' => $item->name,
                        'amount' => -$item->amount,
                    ])->all()
                );
            });

            // Unwind recoveries the original run applied: release its overtime
            // back to approved and credit loan balances (reopening closed loans).
            $this->overtime->releaseForRun($run);
            $this->loans->reverseRecoveriesForRun($run);

            // The original transitions locked -> reversed.
            $this->state->transition($run, PayrollRun::STATUS_REVERSED);

            return $reversal;
        });
    }
}
