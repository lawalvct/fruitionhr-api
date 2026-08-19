<?php

namespace App\Modules\SelfService\Models;

use App\Models\User;
use App\Modules\Employee\Models\Employee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id', 'requested_by', 'current_values', 'requested_values',
    'status', 'submitted_at', 'completed_at',
])]
class ProfileUpdateRequest extends Model
{
    use BelongsToTenant;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected function casts(): array
    {
        return [
            'current_values' => 'array',
            'requested_values' => 'array',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function workflowSummary(): string
    {
        $name = $this->relationLoaded('employee') ? $this->employee?->full_name : null;

        return 'Profile update for '.($name ?? 'employee #'.$this->employee_id);
    }
}
