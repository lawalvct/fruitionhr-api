<?php

namespace App\Modules\Leave\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'leave_type_id', 'days_per_year', 'carry_forward_max',
    'applies_to_employment_type_id', 'created_by',
])]
class LeavePolicy extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'days_per_year' => 'integer',
            'carry_forward_max' => 'integer',
        ];
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }
}
