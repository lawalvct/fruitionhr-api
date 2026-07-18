<?php

namespace App\Modules\Payroll\Services;

use App\Core\Workflow\WorkflowService;
use App\Models\User;
use App\Modules\Attendance\Models\AttendanceSummary;
use App\Modules\Employee\Models\Employee;
use App\Modules\Payroll\Models\EmployeeSalary;
use App\Modules\Payroll\Models\OvertimePayment;
use App\Modules\Payroll\Models\PayrollRun;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Records, prices and moves overtime payments through approval. All money in
 * kobo. Hourly overtime is priced from the employee's current basic salary:
 *
 *   hourly_rate = basic / config('payroll.overtime.standard_monthly_hours')
 *   amount      = round(hours × hourly_rate × multiplier)
 *
 * Fixed overtime is a flat amount entered by HR. Nothing is payable until the
 * 'overtime' workflow approves it.
 */
class OvertimeService
{
    public function __construct(private readonly WorkflowService $workflow)
    {
    }

    public function standardMonthlyHours(): int
    {
        return max(1, (int) config('payroll.overtime.standard_monthly_hours', 208));
    }

    /**
     * Derived hourly rate (kobo) from the employee's current basic salary.
     */
    public function hourlyRateFor(Employee $employee): int
    {
        $salary = EmployeeSalary::query()
            ->where('employee_id', $employee->id)
            ->where('is_current', true)
            ->first();

        if ($salary === null) {
            throw new ConflictHttpException('Employee has no current salary; cannot price hourly overtime.');
        }

        return (int) round($salary->basic_salary / $this->standardMonthlyHours());
    }

    /**
     * Create a manually-entered overtime record (hourly or fixed), in draft.
     *
     * @param  array{employee_id:int,period:string,pay_type:string,disbursement_mode:string,
     *               hours?:float|null,multiplier?:float|null,amount?:int|null,
     *               work_date?:string|null,reason?:string|null}  $data
     */
    public function createManual(array $data, User $user): OvertimePayment
    {
        $employee = Employee::query()->findOrFail($data['employee_id']);

        $payType = $data['pay_type'];
        $multiplier = (float) ($data['multiplier'] ?? 1);
        $hours = isset($data['hours']) ? (float) $data['hours'] : null;
        $hourlyRate = null;

        if ($payType === OvertimePayment::PAY_TYPE_HOURLY) {
            $hourlyRate = $this->hourlyRateFor($employee);
            $amount = $this->priceHourly($hours ?? 0, $hourlyRate, $multiplier);
        } else {
            $multiplier = 1;
            $hours = null;
            $amount = (int) ($data['amount'] ?? 0);
        }

        return OvertimePayment::query()->create([
            'employee_id' => $employee->id,
            'period' => $data['period'],
            'work_date' => $data['work_date'] ?? null,
            'source' => OvertimePayment::SOURCE_MANUAL,
            'pay_type' => $payType,
            'hours' => $hours,
            'multiplier' => $multiplier,
            'hourly_rate' => $hourlyRate,
            'amount' => $amount,
            'disbursement_mode' => $data['disbursement_mode'],
            'status' => OvertimePayment::STATUS_DRAFT,
            'reason' => $data['reason'] ?? null,
            'created_by' => $user->id,
        ]);
    }

    /**
     * Accept an attendance summary's tracked overtime as a priced payment.
     * The clocked overtime minutes become hourly overtime at the chosen
     * multiplier. "Overlooking" it is simply not calling this.
     */
    public function createFromAttendance(
        AttendanceSummary $summary,
        float $multiplier,
        string $disbursementMode,
        User $user,
    ): OvertimePayment {
        $employee = $summary->employee()->firstOrFail();
        $hours = round($summary->overtime_minutes / 60, 2);
        $hourlyRate = $this->hourlyRateFor($employee);

        return OvertimePayment::query()->create([
            'employee_id' => $employee->id,
            'period' => $summary->period,
            'work_date' => null,
            'source' => OvertimePayment::SOURCE_ATTENDANCE,
            'pay_type' => OvertimePayment::PAY_TYPE_HOURLY,
            'hours' => $hours,
            'multiplier' => $multiplier,
            'hourly_rate' => $hourlyRate,
            'amount' => $this->priceHourly($hours, $hourlyRate, $multiplier),
            'disbursement_mode' => $disbursementMode,
            'status' => OvertimePayment::STATUS_DRAFT,
            'reason' => 'Clocked overtime',
            'attendance_summary_id' => $summary->id,
            'created_by' => $user->id,
        ]);
    }

    /**
     * Re-price and update an editable (draft/rejected) record.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(OvertimePayment $overtime, array $data): OvertimePayment
    {
        if (! $overtime->isEditable()) {
            throw new ConflictHttpException('Only draft or rejected overtime can be edited.');
        }

        $payType = $data['pay_type'] ?? $overtime->pay_type;
        $multiplier = (float) ($data['multiplier'] ?? $overtime->multiplier);
        $hours = array_key_exists('hours', $data) ? (float) $data['hours'] : $overtime->hours;

        if ($payType === OvertimePayment::PAY_TYPE_HOURLY) {
            $hourlyRate = $this->hourlyRateFor($overtime->employee()->firstOrFail());
            $amount = $this->priceHourly($hours ?? 0, $hourlyRate, $multiplier);
            $overtime->fill(['hours' => $hours, 'multiplier' => $multiplier, 'hourly_rate' => $hourlyRate, 'amount' => $amount]);
        } else {
            $overtime->fill([
                'pay_type' => OvertimePayment::PAY_TYPE_FIXED,
                'hours' => null,
                'hourly_rate' => null,
                'multiplier' => 1,
                'amount' => (int) ($data['amount'] ?? $overtime->amount),
            ]);
        }

        $overtime->fill([
            'pay_type' => $payType,
            'period' => $data['period'] ?? $overtime->period,
            'work_date' => $data['work_date'] ?? $overtime->work_date,
            'disbursement_mode' => $data['disbursement_mode'] ?? $overtime->disbursement_mode,
            'reason' => $data['reason'] ?? $overtime->reason,
            'status' => OvertimePayment::STATUS_DRAFT,
        ]);

        $overtime->save();

        return $overtime;
    }

    /**
     * Submit for approval via the 'overtime' workflow.
     */
    public function submit(OvertimePayment $overtime, User $user): OvertimePayment
    {
        if (! in_array($overtime->status, [OvertimePayment::STATUS_DRAFT, OvertimePayment::STATUS_REJECTED], true)) {
            throw new ConflictHttpException('This overtime record is already in the approval process.');
        }

        if ($overtime->amount <= 0) {
            throw new ConflictHttpException('Overtime amount must be greater than zero before submitting.');
        }

        $overtime->update(['status' => OvertimePayment::STATUS_PENDING]);
        $this->workflow->submit($overtime, 'overtime', $user);

        return $overtime;
    }

    /**
     * Pay an approved off-cycle overtime record — gross, no tax (per spec).
     */
    public function payOffCycle(OvertimePayment $overtime): OvertimePayment
    {
        if ($overtime->status !== OvertimePayment::STATUS_APPROVED) {
            throw new ConflictHttpException('Only approved overtime can be paid.');
        }

        if ($overtime->disbursement_mode !== OvertimePayment::MODE_OFF_CYCLE) {
            throw new ConflictHttpException('This overtime is set to be paid inside payroll, not off-cycle.');
        }

        $overtime->update(['status' => OvertimePayment::STATUS_PAID, 'paid_at' => now()]);

        return $overtime;
    }

    /**
     * Total approved, in-payroll, not-yet-paid overtime (kobo) for one
     * employee in a period — pulled into that employee's payroll line.
     */
    public function payrollTotalFor(int $employeeId, string $period): int
    {
        return (int) $this->pendingPayrollQuery($period)
            ->where('employee_id', $employeeId)
            ->sum('amount');
    }

    /**
     * On lock, mark this run's in-payroll overtime as paid and link the run so
     * it can never be pulled into a second run.
     */
    public function markPaidForRun(PayrollRun $run): void
    {
        DB::transaction(function () use ($run): void {
            $this->pendingPayrollQuery($run->period)->update([
                'status' => OvertimePayment::STATUS_PAID,
                'payroll_run_id' => $run->id,
                'paid_at' => now(),
            ]);
        });
    }

    /**
     * On reversal of a locked run, release the in-payroll overtime it settled
     * back to approved+unpaid so a corrected run can pick it up again.
     */
    public function releaseForRun(PayrollRun $run): void
    {
        OvertimePayment::query()
            ->where('payroll_run_id', $run->id)
            ->where('status', OvertimePayment::STATUS_PAID)
            ->update([
                'status' => OvertimePayment::STATUS_APPROVED,
                'payroll_run_id' => null,
                'paid_at' => null,
            ]);
    }

    private function pendingPayrollQuery(string $period)
    {
        return OvertimePayment::query()
            ->where('period', $period)
            ->where('disbursement_mode', OvertimePayment::MODE_IN_PAYROLL)
            ->where('status', OvertimePayment::STATUS_APPROVED)
            ->whereNull('payroll_run_id');
    }

    private function priceHourly(float $hours, int $hourlyRate, float $multiplier): int
    {
        return (int) round($hours * $hourlyRate * $multiplier);
    }
}
