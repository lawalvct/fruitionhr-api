<?php

namespace App\Modules\Performance\Models;

use App\Modules\Employee\Models\Employee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['employee_id', 'appraisal_result_id', 'reason', 'status', 'starts_at', 'ends_at', 'outcome_note', 'created_by'])]
class PerformanceImprovementPlan extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CLOSED_SUCCESSFUL = 'closed_successful';

    public const STATUS_CLOSED_UNSUCCESSFUL = 'closed_unsuccessful';

    protected function casts(): array { return ['starts_at' => 'date:Y-m-d', 'ends_at' => 'date:Y-m-d']; }

    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function result(): BelongsTo { return $this->belongsTo(AppraisalResult::class, 'appraisal_result_id'); }
    public function milestones(): HasMany { return $this->hasMany(PipMilestone::class, 'performance_improvement_plan_id'); }
}
