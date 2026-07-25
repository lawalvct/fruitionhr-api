<?php

namespace App\Modules\Performance\Models;

use App\Models\User;
use App\Modules\Employee\Models\Employee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['appraisal_result_id', 'employee_id', 'reason', 'status', 'resolution_note', 'resolved_by', 'resolved_at'])]
class AppraisalAppeal extends Model
{
    use BelongsToTenant;

    public const STATUS_OPEN = 'open';

    public const STATUS_UPHELD = 'upheld';

    public const STATUS_REJECTED = 'rejected';

    protected function casts(): array { return ['resolved_at' => 'datetime']; }

    public function result(): BelongsTo { return $this->belongsTo(AppraisalResult::class, 'appraisal_result_id'); }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function resolvedBy(): BelongsTo { return $this->belongsTo(User::class, 'resolved_by'); }
}
