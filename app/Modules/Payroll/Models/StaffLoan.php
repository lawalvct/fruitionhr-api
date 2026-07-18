<?php

namespace App\Modules\Payroll\Models;

use App\Models\User;
use App\Modules\Employee\Models\Employee;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\StaffLoanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A staff loan or salary advance, recovered from payroll. All money in kobo.
 *
 * An advance is a one-installment loan (months = 1, whole balance recovered in
 * the coming run). A loan spreads principal across `months`, deducting
 * `monthly_installment` per run until `balance` reaches zero. HR can pull more
 * than the installment in a single run via `next_deduction_override` (e.g. the
 * full balance) to settle early. Principal-only — no interest.
 */
#[Fillable([
    'employee_id', 'type', 'principal', 'months', 'monthly_installment',
    'balance', 'start_period', 'next_deduction_override', 'status', 'reason',
    'disbursed_at', 'closed_at', 'created_by',
])]
class StaffLoan extends Model
{
    use BelongsToTenant, HasFactory;

    protected static string $factory = StaffLoanFactory::class;

    public const TYPE_ADVANCE = 'advance';
    public const TYPE_LOAN = 'loan';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CLOSED = 'closed';

    protected function casts(): array
    {
        return [
            'principal' => 'integer',
            'months' => 'integer',
            'monthly_installment' => 'integer',
            'balance' => 'integer',
            'next_deduction_override' => 'integer',
            'disbursed_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(LoanRepayment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_REJECTED], true);
    }

    /** The amount scheduled to come off the coming run (before net capping). */
    public function scheduledDeduction(): int
    {
        return min($this->balance, $this->next_deduction_override ?? $this->monthly_installment);
    }

    /** Label shown in the approvals inbox. Avoids lazy-loading (strict mode). */
    public function workflowSummary(): string
    {
        $name = $this->relationLoaded('employee') ? $this->employee?->full_name : null;
        $label = $this->type === self::TYPE_ADVANCE ? 'Salary advance' : 'Loan';

        return $label.($name ? " — {$name}" : '');
    }
}
