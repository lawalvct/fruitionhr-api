<?php

namespace App\Modules\Payroll\Models;

use App\Models\User;
use App\Modules\Attendance\Models\AttendanceSummary;
use App\Modules\Employee\Models\Employee;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\OvertimePaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One overtime payment for an employee in a period. All money in kobo.
 *
 * An overtime record either rides the next payroll run for its period
 * (disbursement_mode = in_payroll — taxed inside the run) or is paid
 * separately off-cycle (off_cycle — gross, no tax). Every record passes
 * through the 'overtime' approval workflow before it can be paid.
 */
#[Fillable([
    'employee_id', 'period', 'work_date', 'source', 'pay_type',
    'hours', 'multiplier', 'hourly_rate', 'amount',
    'disbursement_mode', 'status', 'reason',
    'attendance_summary_id', 'payroll_run_id', 'paid_at', 'created_by',
])]
class OvertimePayment extends Model
{
    use BelongsToTenant, HasFactory;

    protected static string $factory = OvertimePaymentFactory::class;

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_ATTENDANCE = 'attendance';

    public const PAY_TYPE_HOURLY = 'hourly';
    public const PAY_TYPE_FIXED = 'fixed';

    public const MODE_IN_PAYROLL = 'in_payroll';
    public const MODE_OFF_CYCLE = 'off_cycle';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_PAID = 'paid';

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d',
            'hours' => 'float',
            'multiplier' => 'float',
            'hourly_rate' => 'integer',
            'amount' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function attendanceSummary(): BelongsTo
    {
        return $this->belongsTo(AttendanceSummary::class);
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Draft/rejected/returned records are still editable; the rest are locked. */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_REJECTED], true);
    }

    /** Label shown in the approvals inbox. Avoids lazy-loading (strict mode). */
    public function workflowSummary(): string
    {
        $name = $this->relationLoaded('employee') ? $this->employee?->full_name : null;

        return 'Overtime '.$this->period.($name ? " — {$name}" : '');
    }
}
