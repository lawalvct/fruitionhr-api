<?php

namespace App\Modules\Attendance\Models;

use App\Models\User;
use App\Modules\Employee\Models\Employee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id', 'period', 'working_days', 'days_present', 'days_late',
    'days_absent', 'days_on_leave', 'late_minutes', 'overtime_minutes',
    'status', 'finalized_by', 'finalized_at',
])]
class AttendanceSummary extends Model
{
    use BelongsToTenant;

    public const STATUS_OPEN = 'open';

    public const STATUS_FINALIZED = 'finalized';

    protected function casts(): array
    {
        return ['finalized_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function isFinalized(): bool
    {
        return $this->status === self::STATUS_FINALIZED;
    }
}
