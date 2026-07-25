<?php

namespace App\Modules\Performance\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['performance_improvement_plan_id', 'description', 'due_at', 'status', 'notes'])]
class PipMilestone extends Model
{
    use BelongsToTenant;

    protected function casts(): array { return ['due_at' => 'date:Y-m-d']; }

    public function plan(): BelongsTo { return $this->belongsTo(PerformanceImprovementPlan::class, 'performance_improvement_plan_id'); }
}
