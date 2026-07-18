<?php

namespace App\Modules\Payroll\Services;

use App\Core\Workflow\WorkflowService;
use App\Models\User;
use App\Modules\Employee\Models\Employee;
use App\Modules\Payroll\Models\LoanRepayment;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\StaffLoan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Staff loans & salary advances, recovered from payroll. All money in kobo,
 * principal-only (no interest).
 *
 * Recoveries are computed at payroll calculation time (capped at available net,
 * remainder carried) and committed to loan balances at run lock — mirroring
 * how overtime settles, so the calculation stays re-runnable.
 */
class LoanService
{
    public function __construct(private readonly WorkflowService $workflow)
    {
    }

    public function installmentFor(string $type, int $principal, int $months): int
    {
        if ($type === StaffLoan::TYPE_ADVANCE || $months <= 1) {
            return $principal;
        }

        // Round up so the loan closes within `months`; the final run takes only
        // the remaining balance.
        return (int) ceil($principal / $months);
    }

    /**
     * @param  array{employee_id:int,type:string,principal:int,months?:int|null,
     *               start_period:string,reason?:string|null}  $data
     */
    public function create(array $data, User $user): StaffLoan
    {
        $employee = Employee::query()->findOrFail($data['employee_id']);
        $type = $data['type'];
        $principal = (int) $data['principal'];
        $months = $type === StaffLoan::TYPE_ADVANCE ? 1 : max(1, (int) ($data['months'] ?? 1));

        return StaffLoan::query()->create([
            'employee_id' => $employee->id,
            'type' => $type,
            'principal' => $principal,
            'months' => $months,
            'monthly_installment' => $this->installmentFor($type, $principal, $months),
            'balance' => $principal,
            'start_period' => $data['start_period'],
            'status' => StaffLoan::STATUS_DRAFT,
            'reason' => $data['reason'] ?? null,
            'created_by' => $user->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(StaffLoan $loan, array $data): StaffLoan
    {
        if (! $loan->isEditable()) {
            throw new ConflictHttpException('Only draft or rejected loans can be edited.');
        }

        $type = $data['type'] ?? $loan->type;
        $principal = (int) ($data['principal'] ?? $loan->principal);
        $months = $type === StaffLoan::TYPE_ADVANCE ? 1 : max(1, (int) ($data['months'] ?? $loan->months));

        $loan->update([
            'type' => $type,
            'principal' => $principal,
            'months' => $months,
            'monthly_installment' => $this->installmentFor($type, $principal, $months),
            'balance' => $principal,
            'start_period' => $data['start_period'] ?? $loan->start_period,
            'reason' => $data['reason'] ?? $loan->reason,
            'status' => StaffLoan::STATUS_DRAFT,
        ]);

        return $loan;
    }

    public function submit(StaffLoan $loan, User $user): StaffLoan
    {
        if (! $loan->isEditable()) {
            throw new ConflictHttpException('This loan is already in the approval process.');
        }

        if ($loan->principal <= 0) {
            throw new ConflictHttpException('Loan amount must be greater than zero before submitting.');
        }

        $loan->update(['status' => StaffLoan::STATUS_PENDING]);
        $this->workflow->submit($loan, 'loan', $user);

        return $loan;
    }

    /**
     * Change the monthly installment on an active loan (permanent).
     */
    public function setInstallment(StaffLoan $loan, int $amount): StaffLoan
    {
        $this->assertActive($loan);

        if ($amount <= 0) {
            throw new ConflictHttpException('Installment must be greater than zero.');
        }

        $loan->update(['monthly_installment' => min($amount, $loan->balance)]);

        return $loan;
    }

    /**
     * Set a one-time override for the coming run (e.g. pull the full balance to
     * settle early). Pass null for the full remaining balance.
     */
    public function planNextDeduction(StaffLoan $loan, ?int $amount): StaffLoan
    {
        $this->assertActive($loan);

        $override = $amount === null ? $loan->balance : min(max(1, $amount), $loan->balance);
        $loan->update(['next_deduction_override' => $override]);

        return $loan;
    }

    public function clearNextDeduction(StaffLoan $loan): StaffLoan
    {
        $this->assertActive($loan);
        $loan->update(['next_deduction_override' => null]);

        return $loan;
    }

    /**
     * Recoveries planned for one employee in a period, capped at available net
     * (never a negative payslip). Advances are recovered before loans.
     *
     * @return list<array{staff_loan_id:int,type:string,code:string,name:string,scheduled:int,amount:int}>
     */
    public function plannedRecoveries(int $employeeId, string $period, int $availableNet): array
    {
        $loans = StaffLoan::query()
            ->where('employee_id', $employeeId)
            ->where('status', StaffLoan::STATUS_ACTIVE)
            ->where('balance', '>', 0)
            ->where('start_period', '<=', $period)
            ->orderByRaw("CASE WHEN type = ? THEN 0 ELSE 1 END", [StaffLoan::TYPE_ADVANCE])
            ->orderBy('id')
            ->get();

        $recoveries = [];
        $remaining = max(0, $availableNet);

        foreach ($loans as $loan) {
            $scheduled = $loan->scheduledDeduction();
            $amount = min($scheduled, $remaining);

            if ($amount <= 0) {
                continue;
            }

            $recoveries[] = [
                'staff_loan_id' => $loan->id,
                'type' => $loan->type,
                'code' => ($loan->type === StaffLoan::TYPE_ADVANCE ? 'ADVANCE-' : 'LOAN-').$loan->id,
                'name' => $loan->type === StaffLoan::TYPE_ADVANCE ? 'Salary advance' : 'Loan repayment',
                'scheduled' => $scheduled,
                'amount' => $amount,
            ];

            $remaining -= $amount;
        }

        return $recoveries;
    }

    /**
     * On lock, apply each employee's snapshotted recoveries to loan balances,
     * write repayment rows, close settled loans, and clear one-time overrides.
     */
    public function applyRecoveriesForRun(PayrollRun $run): void
    {
        DB::transaction(function () use ($run): void {
            $run->runEmployees()->get()->each(function ($runEmployee) use ($run): void {
                $recoveries = $runEmployee->snapshot['loan_recoveries'] ?? [];

                foreach ($recoveries as $recovery) {
                    $loan = StaffLoan::query()->find($recovery['staff_loan_id']);

                    if ($loan === null || $loan->status !== StaffLoan::STATUS_ACTIVE) {
                        continue;
                    }

                    $amount = min((int) $recovery['amount'], $loan->balance);
                    $balanceAfter = $loan->balance - $amount;

                    $loan->repayments()->create([
                        'payroll_run_id' => $run->id,
                        'period' => $run->period,
                        'amount' => $amount,
                        'balance_after' => $balanceAfter,
                    ]);

                    $loan->update([
                        'balance' => $balanceAfter,
                        'next_deduction_override' => null,
                        'status' => $balanceAfter <= 0 ? StaffLoan::STATUS_CLOSED : StaffLoan::STATUS_ACTIVE,
                        'closed_at' => $balanceAfter <= 0 ? now() : null,
                    ]);
                }
            });
        });
    }

    /**
     * On reversal of a locked run, undo the recoveries it applied: add the
     * amounts back to each loan's balance, reopen any that were closed, and
     * remove this run's repayment rows (the reversed run is the audit record).
     */
    public function reverseRecoveriesForRun(PayrollRun $run): void
    {
        LoanRepayment::query()
            ->where('payroll_run_id', $run->id)
            ->get()
            ->each(function (LoanRepayment $repayment): void {
                $loan = StaffLoan::query()->find($repayment->staff_loan_id);

                if ($loan !== null) {
                    $wasClosed = $loan->status === StaffLoan::STATUS_CLOSED;
                    $loan->update([
                        'balance' => $loan->balance + $repayment->amount,
                        'status' => $wasClosed ? StaffLoan::STATUS_ACTIVE : $loan->status,
                        'closed_at' => $wasClosed ? null : $loan->closed_at,
                    ]);
                }

                $repayment->delete();
            });
    }

    private function assertActive(StaffLoan $loan): void
    {
        if ($loan->status !== StaffLoan::STATUS_ACTIVE) {
            throw new ConflictHttpException('Only an active loan can be adjusted.');
        }
    }
}
