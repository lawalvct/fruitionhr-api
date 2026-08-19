<?php

namespace App\Modules\Leave\Models;

use App\Models\User;
use App\Modules\Employee\Models\Employee;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\LeaveRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id', 'leave_type_id', 'start_date', 'end_date',
    'days', 'reason', 'status', 'requested_by',
])]
class LeaveRequest extends Model
{
    use BelongsToTenant, HasFactory;

    protected static string $factory = LeaveRequestFactory::class;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'days' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** Human-readable label shown in the approvals inbox. */
    public function workflowSummary(): string
    {
        $employee = ($this->relationLoaded('employee') ? $this->employee?->full_name : null) ?? 'Employee';
        $type = ($this->relationLoaded('leaveType') ? $this->leaveType?->name : null) ?? 'Leave';

        return "{$employee} · {$type} ({$this->days} day".($this->days === 1 ? '' : 's').')';
    }
}

