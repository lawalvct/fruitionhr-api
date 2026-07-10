<?php

namespace App\Modules\Leave\Models;

use App\Modules\Employee\Models\Employee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['employee_id', 'leave_type_id', 'year', 'allocated', 'carried_forward', 'taken'])]
class LeaveBalance extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'allocated' => 'integer',
            'carried_forward' => 'integer',
            'taken' => 'integer',
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

    public function getRemainingAttribute(): int
    {
        return $this->allocated + $this->carried_forward - $this->taken;
    }
}
