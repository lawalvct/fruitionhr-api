<?php

namespace App\Modules\Payroll\Services;

use App\Core\Workflow\WorkflowService;
use App\Models\User;
use App\Modules\Employee\Models\Employee;
use App\Modules\Payroll\Jobs\CalculatePayrollRun;
use App\Modules\Payroll\Models\PayPeriod;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Support\PayrollPreflight;
use App\Modules\Payroll\Support\PayrollRunState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PayrollRunService
{
    public function __construct(
        private readonly PayrollCalculationService $calculator,
        private readonly PayrollRunState $state,
        private readonly PayrollPreflight $preflight,
        private readonly WorkflowService $workflow,
    ) {
    }

    /**
     * Create a run for the period (preflight must pass) and dispatch the
     * calculation job. Only one active run per period is allowed.
     */
    public function createRun(string $period, User $user): PayrollRun
    {
        if (! $this->preflight->passes($period)) {
            throw new ConflictHttpException('Payroll preflight checks are not all passing for this period.');
        }

        // A period is free for a new run if its prior run was reversed. Ignore
        // reversal (mirror) runs and reversed originals when checking.
        $existing = PayrollRun::query()
            ->where('period', $period)
            ->where('is_reversal', false)
            ->whereNotIn('status', [PayrollRun::STATUS_REVERSED])
            ->exists();

        if ($existing) {
            throw new ConflictHttpException('An active payroll run already exists for this period.');
        }

        $run = DB::transaction(function () use ($period, $user): PayrollRun {
            $date = Carbon::createFromFormat('Y-m', $period)->startOfMonth();

            $payPeriod = PayPeriod::query()->firstOrCreate(
                ['period' => $period],
                ['year' => $date->year, 'month' => $date->month, 'status' => 'open'],
            );

            return PayrollRun::query()->create([
                'pay_period_id' => $payPeriod->id,
                'period' => $period,
                'status' => PayrollRun::STATUS_DRAFT,
                'created_by' => $user->id,
            ]);
        });

        $this->state->transition($run, PayrollRun::STATUS_CALCULATING);

        CalculatePayrollRun::dispatch($run);

        return $run;
    }

    /**
     * Run the calculation for every active employee and total the run.
     * Called from the queued job; re-entrant while the run is still mutable.
     */
    public function process(PayrollRun $run): void
    {
        $this->state->assertMutable($run);

        DB::transaction(function () use ($run): void {
            // Clear any prior partial results (safe: run is pre-approval).
            $run->runEmployees()->each(fn ($re) => $re->items()->delete());
            $run->runEmployees()->delete();

            $totals = [
                'employee_count' => 0, 'total_gross' => 0, 'total_statutory' => 0,
                'total_deductions' => 0, 'total_net' => 0, 'total_employer_cost' => 0,
            ];

            Employee::query()
                ->where('employment_status', Employee::STATUS_ACTIVE)
                ->chunkById(200, function ($employees) use ($run, &$totals): void {
                    foreach ($employees as $employee) {
                        $line = $this->calculator->calculateFor($run, $employee);
                        if ($line === null) {
                            continue;
                        }

                        $totals['employee_count']++;
                        $totals['total_gross'] += $line->gross;
                        $totals['total_statutory'] += $line->total_statutory;
                        $totals['total_deductions'] += $line->total_deductions;
                        $totals['total_net'] += $line->net;
                        $totals['total_employer_cost'] += $line->pension_employer + $line->nsitf;
                    }
                });

            $run->update($totals);
        });

        $this->state->transition($run, PayrollRun::STATUS_REVIEW);
    }

    public function submit(PayrollRun $run, User $user): void
    {
        if ($run->status !== PayrollRun::STATUS_REVIEW) {
            throw new ConflictHttpException('Only a run in review can be submitted for approval.');
        }

        $this->state->transition($run, PayrollRun::STATUS_PENDING_APPROVAL);
        $run->update(['submitted_at' => now()]);

        $this->workflow->submit($run, 'payroll', $user);
    }

    public function markApproved(PayrollRun $run): void
    {
        if ($run->status !== PayrollRun::STATUS_PENDING_APPROVAL) {
            return;
        }

        $this->state->transition($run, PayrollRun::STATUS_APPROVED);
        $run->update(['approved_at' => now()]);
    }

    public function markReturnedToReview(PayrollRun $run): void
    {
        if ($run->status === PayrollRun::STATUS_PENDING_APPROVAL) {
            $this->state->transition($run, PayrollRun::STATUS_REVIEW);
        }
    }

    public function lock(PayrollRun $run): void
    {
        $this->state->transition($run, PayrollRun::STATUS_LOCKED);
        $run->update(['locked_at' => now()]);
    }
}
