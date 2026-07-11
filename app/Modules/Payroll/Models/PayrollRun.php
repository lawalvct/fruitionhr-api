<?php

namespace App\Modules\Payroll\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'pay_period_id', 'period', 'status', 'is_reversal', 'reversed_of_run_id',
    'reversal_reason', 'employee_count',
    'total_gross', 'total_statutory', 'total_deductions', 'total_net',
    'total_employer_cost', 'notes', 'created_by',
    'submitted_at', 'approved_at', 'locked_at',
])]
class PayrollRun extends Model
{
    use BelongsToTenant;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_CALCULATING = 'calculating';

    public const STATUS_REVIEW = 'review';

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_LOCKED = 'locked';

    public const STATUS_PAID = 'paid';

    public const STATUS_REVERSED = 'reversed';

    protected function casts(): array
    {
        return [
            'is_reversal' => 'boolean',
            'total_gross' => 'integer',
            'total_statutory' => 'integer',
            'total_deductions' => 'integer',
            'total_net' => 'integer',
            'total_employer_cost' => 'integer',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'reversed_of_run_id');
    }

    public function payPeriod(): BelongsTo
    {
        return $this->belongsTo(PayPeriod::class);
    }

    public function runEmployees(): HasMany
    {
        return $this->hasMany(PayrollRunEmployee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isLocked(): bool
    {
        return in_array($this->status, [self::STATUS_LOCKED, self::STATUS_PAID], true);
    }

    /** Label shown in the approvals inbox. */
    public function workflowSummary(): string
    {
        return "Payroll {$this->period} ({$this->employee_count} employees)";
    }
}
